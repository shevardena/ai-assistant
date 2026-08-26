<?php

namespace App\Http\Requests;

use App\Models\Workflow;
use App\Services\Workflows\WorkflowMetadataService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class UpdateWorkflowRequest extends StoreWorkflowRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $workflow = $this->route('workflow');

        return $workflow instanceof Workflow && Gate::allows('update', $workflow);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::definitionRules(),
            'status' => ['nullable', 'string', 'in:draft,active,disabled'],
        ];
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
}
