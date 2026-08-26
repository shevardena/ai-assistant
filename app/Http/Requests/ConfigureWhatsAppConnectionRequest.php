<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConfigureWhatsAppConnectionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'phone_number_id' => ['required', 'string', 'max:120'],
            'business_account_id' => ['nullable', 'string', 'max:120'],
            'display_phone_number' => ['nullable', 'string', 'max:40'],
            'verified_name' => ['nullable', 'string', 'max:255'],
            'access_token' => ['nullable', 'string', 'max:10000'],
            'webhook_verify_token' => ['nullable', 'string', 'max:255'],
            'app_secret' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
