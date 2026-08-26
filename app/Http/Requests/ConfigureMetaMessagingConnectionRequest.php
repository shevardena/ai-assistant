<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConfigureMetaMessagingConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'instagram_account_id' => ['nullable', 'string', 'max:120'],
            'facebook_page_id' => ['nullable', 'string', 'max:120'],
            'display_name' => ['nullable', 'string', 'max:255'],
            'username' => ['nullable', 'string', 'max:255'],
            'page_name' => ['nullable', 'string', 'max:255'],
            'access_token' => ['nullable', 'string', 'max:10000'],
            'webhook_verify_token' => ['nullable', 'string', 'max:255'],
            'app_secret' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
