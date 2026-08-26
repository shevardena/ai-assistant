<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class WidgetMessageRequest extends FormRequest
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
            'visitor_id' => ['required', 'uuid'],
            'conversation_id' => ['required', 'uuid'],
            'message' => ['required', 'string', 'max:'.(int) config('widget.message_max_length', 4000)],
        ];
    }
}
