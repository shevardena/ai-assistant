<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WidgetMessagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'visitor_id' => ['required', 'uuid'],
            'conversation_id' => ['required', 'uuid'],
            'after_message_id' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
