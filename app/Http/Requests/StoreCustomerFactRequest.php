<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerFactRequest extends FormRequest
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
        return ['key' => ['required', 'string', 'max:80'], 'value' => ['required', 'string', 'max:2000'], 'value_type' => ['nullable', 'string', 'max:30'], 'source' => ['nullable', Rule::in(['manual', 'conversation', 'lead', 'appointment', 'support_ticket', 'imported'])], 'confidence' => ['nullable', 'numeric', 'between:0,1']];
    }
}
