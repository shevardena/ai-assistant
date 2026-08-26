<?php

namespace App\Http\Requests;

use App\Models\Customer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CustomerIndexRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Customer::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['search' => ['nullable', 'string', 'max:120'], 'status' => ['nullable', 'string', 'max:20'], 'owner_id' => ['nullable', 'integer'], 'tag' => ['nullable', 'integer'], 'segment' => ['nullable', 'integer']];
    }
}
