<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class WidgetTranscriptionRequest extends FormRequest
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
            'audio' => [
                'required',
                'file',
                'max:'.(int) config('speech_to_text.max_upload_kilobytes', 10240),
                'mimetypes:'.implode(',', config('speech_to_text.mimetypes', [])),
            ],
            'language' => ['nullable', 'string', 'max:16', 'regex:/^[a-zA-Z]{2,3}(-[a-zA-Z]{2,8})?$/'],
        ];
    }
}
