<?php

namespace App\Http\Requests;

use App\Enums\TaskPriority;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TaskIndexRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'scope' => ['nullable', Rule::in(['my', 'all', 'overdue', 'upcoming', 'completed'])],
            'status' => ['nullable', Rule::in(['all', 'open', 'in_progress', 'completed', 'cancelled'])],
            'priority' => ['nullable', Rule::enum(TaskPriority::class)],
            'assigned_user_id' => ['nullable', 'integer'],
            'customer_id' => ['nullable', 'integer'],
            'lead_id' => ['nullable', 'integer'],
            'deal_id' => ['nullable', 'integer'],
            'due_from' => ['nullable', 'date'],
            'due_to' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:120'],
        ];
    }
}
