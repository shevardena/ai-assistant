<?php

namespace App\Http\Requests;

use App\Enums\BotTestExpectationType;
use App\Models\Bot;
use App\Services\Ai\BotToolRegistry;
use App\Services\Conversations\Blocks\ConversationBlockType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreBotTestScenarioRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Gate::allows('manageTests', $this->route('bot'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'input_message' => ['required', 'string', 'max:4000'],
            'is_enabled' => ['sometimes', 'boolean'],
            'expectations' => ['required', 'array', 'max:12'],
            'expectations.*' => ['required', 'array:type,value'],
            'expectations.*.type' => ['required', 'string', Rule::in(array_column(BotTestExpectationType::cases(), 'value'))],
            'expectations.*.value' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * Apply validation that depends on the current Bot's registered tools.
     */
    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $bot = $this->route('bot');

            if (! $bot instanceof Bot) {
                return;
            }

            $knownTools = app(BotToolRegistry::class)->knownToolNames();
            $knownBlocks = array_column(ConversationBlockType::cases(), 'value');

            foreach ((array) $this->input('expectations', []) as $index => $expectation) {
                if (! is_array($expectation)) {
                    continue;
                }

                $type = $expectation['type'] ?? null;
                $value = $expectation['value'] ?? null;

                if (! is_string($type) || ! is_string($value)) {
                    continue;
                }

                if (in_array($type, [BotTestExpectationType::ToolCalled->value, BotTestExpectationType::ToolNotCalled->value], true)
                    && ! in_array($value, $knownTools, true)) {
                    $validator->errors()->add("expectations.{$index}.value", 'Choose a registered Bot tool.');
                }

                if (in_array($type, [BotTestExpectationType::BlockPresent->value, BotTestExpectationType::BlockAbsent->value], true)
                    && ! in_array($value, $knownBlocks, true)) {
                    $validator->errors()->add("expectations.{$index}.value", 'Choose a supported response block.');
                }

                if ($type === BotTestExpectationType::ActionStatus->value
                    && ! in_array($value, ['proposed', 'not_proposed'], true)) {
                    $validator->errors()->add("expectations.{$index}.value", 'Choose proposed or not proposed.');
                }
            }
        });
    }
}
