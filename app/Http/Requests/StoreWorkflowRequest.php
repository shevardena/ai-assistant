<?php

namespace App\Http\Requests;

use App\Models\Workflow;
use App\Services\Workflows\WorkflowMetadataService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreWorkflowRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Gate::allows('create', Workflow::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return self::definitionRules();
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $team = $this->user()?->currentTeam;
            if (! $team) {
                return;
            }

            foreach (app(WorkflowMetadataService::class)->validateDefinition($team, $this->validated()) as $key => $message) {
                $validator->errors()->add((string) $key, $message);
            }
        }];
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public static function definitionRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'trigger_type' => ['required', 'string', Rule::in(['lead_captured', 'appointment_booked', 'support_ticket_created', 'human_handoff_requested'])],
            'conditions' => ['nullable', 'array', 'max:10'],
            'conditions.*' => ['required', 'array'],
            'conditions.*.type' => ['required', 'string'],
            'conditions.*.operator' => ['required', 'string', Rule::in(['equals', 'not_equals'])],
            'conditions.*.value' => ['required'],
            'actions' => ['required', 'array', 'min:1', 'max:10'],
            'actions.*' => ['required', 'array'],
            'actions.*.type' => ['required', 'string'],
            'actions.*.config' => ['nullable', 'array'],
        ];
    }
}
