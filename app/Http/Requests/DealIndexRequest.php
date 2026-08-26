<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DealIndexRequest extends FormRequest
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
            'view' => ['nullable', Rule::in(['board', 'list'])],
            'pipeline_id' => ['nullable', 'integer'],
            'stage_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['all', 'open', 'won', 'lost'])],
            'owner_user_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:120'],
            'expected_close' => ['nullable', Rule::in(['overdue', '30d'])],
        ];
    }
}
