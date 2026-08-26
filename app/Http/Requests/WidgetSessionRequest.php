<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class WidgetSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'visitor_id' => ['nullable', 'uuid'],
            'conversation_id' => ['nullable', 'uuid'],
            'new_conversation' => ['sometimes', 'boolean'],
        ];
    }
}
