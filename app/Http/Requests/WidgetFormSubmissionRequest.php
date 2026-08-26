<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class WidgetFormSubmissionRequest extends FormRequest
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
            'values' => ['required', 'array', 'max:10'],
            'values.*' => ['string', 'max:4000'],
        ];
    }
}
