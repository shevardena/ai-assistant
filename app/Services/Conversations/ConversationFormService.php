<?php

namespace App\Services\Conversations;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\ConversationState;
use App\Models\WidgetVisitor;
use App\Services\Ai\Tools\ToolExecutionContext;
use App\Services\Ai\Tools\ToolResult;
use App\Services\Conversations\Blocks\FormBlock;
use App\Services\Conversations\Blocks\FormBlockFieldType;
use App\Services\Conversations\Blocks\FormBlockStatus;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ConversationFormService
{
    public function __construct(private readonly DatabaseManager $database) {}

    /**
     * Create or reuse the one trusted pending form for this conversation.
     *
     * @param  array<string, mixed>  $definition
     */
    public function request(
        ToolExecutionContext $context,
        string $toolName,
        array $definition,
    ): ToolResult {
        $conversation = $context->conversation;

        if ($conversation === null
            || (int) $conversation->bot_id !== (int) $context->bot->id
            || (int) $context->team->id !== (int) $context->bot->team_id) {
            return ToolResult::failure(
                'invalid_request',
                'The requested form cannot be shown in this conversation.',
            );
        }

        $form = $this->database->connection()->transaction(function () use ($conversation, $context, $toolName, $definition): ?FormBlock {
            $state = ConversationState::query()
                ->where('conversation_id', $conversation->id)
                ->lockForUpdate()
                ->first();

            $memory = $state?->getAttribute('memory');
            $memory = is_array($memory) ? $memory : [];
            $active = is_array($memory['active_form'] ?? null) ? $memory['active_form'] : null;

            if (($active['status'] ?? null) === FormBlockStatus::Pending->value
                && ($active['tool_name'] ?? null) === $toolName
                && $this->activeBelongsToConversation($active, $context, $conversation)) {
                $existing = $this->blockFromActive($active);

                if ($existing !== null) {
                    return $existing;
                }
            }

            $form = FormBlock::fromDefinition((string) Str::uuid(), $definition);

            if ($form === null) {
                return null;
            }

            $state ??= ConversationState::create([
                'conversation_id' => $conversation->id,
                'active_search' => null,
                'last_result_ids' => [],
                'memory' => [],
                'version' => 1,
            ]);

            $data = $form->toArray()['data'];
            unset($data['form_reference'], $data['status']);
            $record = [
                'form_reference' => $form->formReference,
                'tool_name' => $toolName,
                'status' => FormBlockStatus::Pending->value,
                'team_id' => (int) $context->team->id,
                'bot_id' => (int) $context->bot->id,
                'conversation_id' => (int) $conversation->id,
                'visitor_id' => $conversation->visitor_id,
                'schema' => $data,
            ];
            $forms = is_array($memory['forms'] ?? null) ? $memory['forms'] : [];

            if (is_array($active) && is_string($active['form_reference'] ?? null)
                && ($active['status'] ?? null) === FormBlockStatus::Pending->value) {
                $forms[$active['form_reference']]['status'] = FormBlockStatus::Cancelled->value;
            }

            $forms[$form->formReference] = $record;
            $memory['forms'] = $forms;
            $memory['active_form'] = $record;
            $state->update([
                'memory' => $memory,
                'version' => ((int) $state->version) + 1,
            ]);

            return $form;
        });

        if (! $form instanceof FormBlock) {
            return ToolResult::failure(
                'invalid_request',
                'The requested form could not be prepared safely.',
            );
        }

        return ToolResult::success([
            'ok' => false,
            'error' => 'missing_input',
            'message' => 'Additional information is required before this request can continue.',
        ], blocks: [$form->toArray()]);
    }

    /**
     * Validate and atomically mark a pending form as submitted.
     *
     * @param  array<string, mixed>  $values
     */
    public function submit(
        Bot $bot,
        Conversation $conversation,
        string $formReference,
        array $values,
        ?WidgetVisitor $visitor = null,
    ): FormSubmission {
        abort_unless((int) $conversation->bot_id === (int) $bot->id, 404);

        return $this->database->connection()->transaction(function () use ($bot, $conversation, $formReference, $values, $visitor): FormSubmission {
            $state = ConversationState::query()
                ->where('conversation_id', $conversation->id)
                ->lockForUpdate()
                ->first();
            $memory = $state?->getAttribute('memory');
            $memory = is_array($memory) ? $memory : [];
            $active = is_array($memory['active_form'] ?? null) ? $memory['active_form'] : null;

            if ($active === null
                || ($active['form_reference'] ?? null) !== $formReference
                || (int) ($active['team_id'] ?? 0) !== (int) $bot->team_id
                || (int) ($active['bot_id'] ?? 0) !== (int) $bot->id
                || (int) ($active['conversation_id'] ?? 0) !== (int) $conversation->id
                || ($visitor !== null && (int) ($active['visitor_id'] ?? 0) !== (int) $visitor->id)) {
                abort(404);
            }

            $block = $this->blockFromActive($active);

            if ($block === null) {
                abort(404);
            }

            if ($block->status !== FormBlockStatus::Pending) {
                abort(409, 'This form has already been completed.');
            }

            if ((int) ($active['visitor_id'] ?? 0) !== (int) ($conversation->visitor_id ?? 0)) {
                abort(404);
            }

            $validatedValues = $this->validateValues($block, $values);
            $memory['active_form']['status'] = FormBlockStatus::Submitted->value;
            $memory['active_form']['submitted_at'] = now()->toIso8601String();
            if (is_array($memory['forms'][$formReference] ?? null)) {
                $memory['forms'][$formReference]['status'] = FormBlockStatus::Submitted->value;
                $memory['forms'][$formReference]['submitted_at'] = $memory['active_form']['submitted_at'];
            }
            $state->update([
                'memory' => $memory,
                'version' => ((int) $state->version) + 1,
            ]);

            $submittedBlock = FormBlock::fromDefinition(
                $block->formReference,
                [
                    'title' => $block->title,
                    'description' => $block->description,
                    'fields' => $block->fields,
                    'submit_label' => $block->submitLabel,
                ],
                FormBlockStatus::Submitted,
            );

            if ($submittedBlock === null) {
                abort(500, 'The form state could not be restored.');
            }

            return new FormSubmission(
                block: $submittedBlock,
                values: $validatedValues,
                displayMessage: $this->displayMessage($block),
            );
        });
    }

    /**
     * @param  array<string, mixed>  $active
     */
    private function blockFromActive(array $active): ?FormBlock
    {
        $reference = $active['form_reference'] ?? null;
        $schema = $active['schema'] ?? null;
        $status = $active['status'] ?? null;

        if (! is_string($reference) || ! is_array($schema) || ! is_string($status)) {
            return null;
        }

        $formStatus = FormBlockStatus::tryFrom($status);

        return $formStatus === null
            ? null
            : FormBlock::fromDefinition($reference, $schema, $formStatus);
    }

    /**
     * @param  array<string, mixed>  $active
     */
    private function activeBelongsToConversation(
        array $active,
        ToolExecutionContext $context,
        Conversation $conversation,
    ): bool {
        return (int) ($active['team_id'] ?? 0) === (int) $context->team->id
            && (int) ($active['bot_id'] ?? 0) === (int) $context->bot->id
            && (int) ($active['conversation_id'] ?? 0) === (int) $conversation->id
            && (int) ($active['visitor_id'] ?? 0) === (int) ($conversation->visitor_id ?? 0);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, string>
     */
    private function validateValues(FormBlock $block, array $values): array
    {
        $fieldsByName = [];
        foreach ($block->fields as $field) {
            $fieldsByName[$field['name']] = $field;
        }

        $errors = [];
        foreach (array_keys($values) as $name) {
            if (! isset($fieldsByName[$name])) {
                $errors[(string) $name] = 'This field was not requested.';
            }
        }

        $validated = [];
        foreach ($fieldsByName as $name => $field) {
            $value = $values[$name] ?? '';

            if (! is_string($value)) {
                $errors[$name] = 'Enter a valid value.';

                continue;
            }

            $value = trim($value);
            $maximum = $field['type'] === FormBlockFieldType::Textarea->value ? 4000 : 1000;

            if ($field['required'] && $value === '') {
                $errors[$name] = 'This field is required.';

                continue;
            }

            if ($value !== '' && (mb_strlen($value) > $maximum || preg_match('/[\x00-\x1F\x7F]/', $value) === 1)) {
                $errors[$name] = 'Enter a shorter valid value.';

                continue;
            }

            if ($field['type'] === FormBlockFieldType::Email->value
                && $value !== ''
                && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                $errors[$name] = 'Enter a valid email address.';

                continue;
            }

            if ($field['type'] === FormBlockFieldType::Number->value
                && $value !== ''
                && (! is_numeric($value) || ! is_finite((float) $value))) {
                $errors[$name] = 'Enter a valid number.';

                continue;
            }

            if ($field['type'] === FormBlockFieldType::Select->value
                && $value !== ''
                && ! in_array($value, array_column($field['options'] ?? [], 'value'), true)) {
                $errors[$name] = 'Choose one of the available options.';

                continue;
            }

            $validated[$name] = $value;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $validated;
    }

    private function displayMessage(FormBlock $block): string
    {
        return $block->title === null ? 'Submitted the requested details.' : 'Submitted '.$block->title.'.';
    }
}
