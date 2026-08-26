<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDealRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'pipeline_id' => ['required', 'integer'],
            'stage_id' => ['required', 'integer'],
            'owner_user_id' => ['nullable', 'integer'],
            'value_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999999999.99', 'decimal:0,2'],
            'currency' => ['required', 'string', 'size:3', 'uppercase', Rule::in(['USD', 'EUR', 'GBP', 'GEL', 'CAD', 'AUD'])],
            'probability' => ['nullable', 'numeric', 'between:0,100'],
            'expected_close_date' => ['nullable', 'date'],
        ];
    }
}
