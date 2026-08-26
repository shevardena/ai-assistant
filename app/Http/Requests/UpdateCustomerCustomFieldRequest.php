<?php

namespace App\Http\Requests;

use App\Enums\CustomerCustomFieldType;
use App\Models\Customer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCustomerCustomFieldRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Customer::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['label' => ['required', 'string', 'max:160'], 'type' => ['required', Rule::enum(CustomerCustomFieldType::class)], 'required' => ['sometimes', 'boolean'], 'active' => ['sometimes', 'boolean'], 'sort_order' => ['nullable', 'integer', 'min:0'], 'options' => ['array'], 'options.*' => ['string', 'max:160']];
    }
}
