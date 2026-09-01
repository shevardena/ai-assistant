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
            'client_message_id' => ['nullable', 'uuid'],
            'message' => [
                'nullable',
                'string',
                'max:'.(int) config('widget.message_max_length', 4000),
                'required_without:image',
            ],
            'image' => [
                'nullable',
                'file',
                'image',
                'mimetypes:image/jpeg,image/png,image/webp',
                'mimes:jpg,jpeg,png,webp',
                'max:'.(int) config('widget.image_max_size_kb', 10240),
                'required_without:message',
            ],
        ];
    }
}
