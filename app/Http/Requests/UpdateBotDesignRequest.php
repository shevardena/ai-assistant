<?php

namespace App\Http\Requests;

use App\Enums\ApiOperationMode;
use App\Models\Bot;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class UpdateBotDesignRequest extends FormRequest
{
    public function authorize(): bool
    {
        $bot = $this->route('bot');

        return $bot instanceof Bot && Gate::allows('updateContent', $bot);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'appearance' => ['required', 'array'],
            'appearance.widget_title' => ['nullable', 'string', 'max:120'],
            'appearance.input_placeholder' => ['nullable', 'string', 'max:120'],
            'appearance.assistant_display_name' => ['nullable', 'string', 'max:80'],
            'appearance.assistant_subtitle' => ['nullable', 'string', 'max:80'],
            'appearance.primary_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'appearance.accent_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'appearance.header_text_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'appearance.background_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'appearance.text_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'appearance.send_button_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'appearance.send_button_text_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'appearance.send_button_label' => ['nullable', 'string', 'max:40'],
            'appearance.send_button_mode' => ['nullable', 'in:icon-text,text-only,icon-only'],
            'appearance.send_button_icon' => ['nullable', 'in:send,arrow-right,message'],
            'appearance.user_message_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'appearance.user_message_text_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'appearance.launcher_position' => ['required', 'in:bottom-right,bottom-left'],
            'assistant_avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_avatar' => ['nullable', 'boolean'],
            'welcome_message' => ['nullable', 'string', 'max:1000'],
            'dataset_id' => ['nullable', 'integer', 'min:1'],
            'product_source' => ['nullable', 'in:dataset,live'],
            'live_operation_id' => ['nullable', 'integer', 'min:1'],
            'mapping' => ['nullable', 'array'],
            'mapping.title' => ['required_with:dataset_id,live_operation_id', 'nullable', 'string', 'max:120'],
            'mapping.subtitle' => ['nullable', 'string', 'max:120'],
            'mapping.description' => ['nullable', 'string', 'max:120'],
            'mapping.image' => ['nullable', 'string', 'max:120'],
            'mapping.price' => ['nullable', 'string', 'max:120'],
            'mapping.old_price' => ['nullable', 'string', 'max:120'],
            'mapping.discount' => ['nullable', 'string', 'max:120'],
            'mapping.url' => ['nullable', 'string', 'max:120'],
            'button_label' => ['nullable', 'string', 'max:80'],
            'card_style' => ['nullable', 'array'],
            'card_style.background_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'card_style.text_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'card_style.muted_text_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'card_style.price_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'card_style.old_price_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'card_style.discount_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'card_style.button_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'card_style.button_text_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }

    /**
     * Validate that the selected dataset and field IDs are inside this Bot's team.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $bot = $this->route('bot');
            $datasetId = $this->datasetId();

            if ($this->productSource() === 'live' || $this->liveOperationId() !== null) {
                $operation = $bot->botApiOperations()
                    ->where('is_enabled', true)
                    ->where('tool_name', 'search_catalog')
                    ->whereHas('apiOperation', function ($query) use ($bot): void {
                        $query->whereKey($this->liveOperationId())
                            ->where('is_enabled', true)
                            ->where('execution_mode', ApiOperationMode::Read->value)
                            ->whereNotNull('response_mapping')
                            ->whereHas('dataSource', fn ($source) => $source
                                ->where('team_id', $bot->team_id)
                                ->whereIn('type', ['rest_api', 'graphql_api'])
                                ->liveUsable());
                    })
                    ->exists();

                if (! $operation) {
                    $validator->errors()->add('live_operation_id', 'Choose a valid enabled live catalog operation.');
                }

                return;
            }

            if (! $bot instanceof Bot || $datasetId === null) {
                return;
            }

            $dataset = $bot->datasets()
                ->wherePivot('is_enabled', true)
                ->where('datasets.team_id', $bot->team_id)
                ->whereKey($datasetId)
                ->first();

            if ($dataset === null) {
                $validator->errors()->add('dataset_id', 'Choose an enabled dataset attached to this Bot.');

                return;
            }

            $fieldIds = array_values($this->mappingData());
            $fields = $dataset->fields()
                ->whereIn('id', $fieldIds)
                ->where('is_displayable', true)
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();

            if (count($fields) !== count($fieldIds)) {
                $validator->errors()->add(
                    'mapping',
                    'Every card field must be a displayable field from the selected dataset.',
                );
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function appearanceData(): array
    {
        return array_filter(
            (array) $this->validated('appearance', []),
            fn (mixed $value, string $key): bool => in_array($key, [
                'widget_title',
                'input_placeholder',
                'primary_color',
                'accent_color',
                'header_text_color',
                'background_color',
                'text_color',
                'send_button_color',
                'send_button_text_color',
                'send_button_label',
                'send_button_mode',
                'send_button_icon',
                'user_message_color',
                'user_message_text_color',
                'launcher_position',
                'assistant_display_name',
                'assistant_subtitle',
            ], true),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * @return array<string, int|string>
     */
    public function mappingData(): array
    {
        $mapping = [];

        foreach ((array) $this->validated('mapping', []) as $slot => $value) {
            if (is_int($value) || is_string($value)) {
                $mapping[(string) $slot] = ctype_digit((string) $value)
                    ? (int) $value
                    : trim($value);
            }
        }

        return $mapping;
    }

    public function productSource(): string
    {
        return $this->validated('product_source') === 'live' ? 'live' : 'dataset';
    }

    public function liveOperationId(): ?int
    {
        $operationId = $this->validated('live_operation_id');

        return is_int($operationId) || (is_string($operationId) && ctype_digit($operationId))
            ? (int) $operationId
            : null;
    }

    public function datasetId(): ?int
    {
        $datasetId = $this->validated('dataset_id');

        return is_int($datasetId) || (is_string($datasetId) && ctype_digit($datasetId))
            ? (int) $datasetId
            : null;
    }

    public function avatarFile(): ?UploadedFile
    {
        $file = $this->file('assistant_avatar');

        return $file instanceof UploadedFile ? $file : null;
    }

    public function removeAvatar(): bool
    {
        return $this->boolean('remove_avatar');
    }

    public function welcomeMessage(): ?string
    {
        $message = $this->validated('welcome_message');

        return is_string($message) && trim($message) !== '' ? trim($message) : null;
    }

    public function buttonLabel(): string
    {
        $buttonLabel = $this->validated('button_label');

        return is_string($buttonLabel) && trim($buttonLabel) !== ''
            ? trim($buttonLabel)
            : 'View product';
    }

    /**
     * @return array<string, string>
     */
    public function cardStyleData(): array
    {
        return array_filter(
            (array) $this->validated('card_style', []),
            fn (mixed $value, string $key): bool => in_array($key, [
                'background_color',
                'text_color',
                'muted_text_color',
                'price_color',
                'old_price_color',
                'discount_color',
                'button_color',
                'button_text_color',
            ], true),
            ARRAY_FILTER_USE_BOTH,
        );
    }
}
