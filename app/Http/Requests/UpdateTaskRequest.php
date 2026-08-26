<?php

namespace App\Http\Requests;

use App\Enums\TaskPriority;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTaskRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'priority' => ['required', Rule::enum(TaskPriority::class)],
            'assigned_user_id' => ['nullable', 'integer'],
            'due_at' => ['nullable', 'date'],
            'customer_id' => ['nullable', 'integer'],
            'lead_id' => ['nullable', 'integer'],
            'deal_id' => ['nullable', 'integer'],
            'conversation_id' => ['nullable', 'integer'],
            'support_ticket_id' => ['nullable', 'integer'],
            'appointment_id' => ['nullable', 'integer'],
        ];
    }
}
