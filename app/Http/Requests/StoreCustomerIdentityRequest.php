<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerIdentityRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('customer')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['type' => ['required', Rule::in(['email', 'phone', 'channel_user'])], 'value' => ['required', 'string', 'max:320'], 'provider' => ['nullable', 'string', 'max:50'], 'provider_external_id' => ['nullable', 'string', 'max:255'], 'is_primary' => ['sometimes', 'boolean'], 'is_verified' => ['sometimes', 'boolean']];
    }
}
