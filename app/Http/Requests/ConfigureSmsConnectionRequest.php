<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConfigureSmsConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'phone_number' => ['nullable', 'string', 'max:20', 'regex:/^\+[1-9]\d{1,14}$/'],
            'account_sid' => ['nullable', 'string', 'max:120'],
            'auth_token' => ['nullable', 'string', 'max:1000'],
            'display_name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
