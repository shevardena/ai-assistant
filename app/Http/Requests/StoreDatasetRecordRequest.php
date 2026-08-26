<?php

namespace App\Http\Requests;

use App\Models\Dataset;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreDatasetRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        $dataset = $this->route('dataset');

        return $dataset instanceof Dataset && Gate::allows('update', $dataset);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'values' => ['required', 'array', 'max:200'],
            'values.*' => ['nullable'],
        ];

        $dataset = $this->route('dataset');

        if ($dataset instanceof Dataset && $dataset->entity_type === 'knowledge') {
            $rules['values.title'] = ['required', 'string', 'max:255'];
            $rules['values.content'] = ['required', 'string', 'max:50000'];
            $rules['values.category'] = ['nullable', 'string', 'max:255'];
            $rules['values.source_url'] = ['nullable', 'url:http,https', 'max:2048'];
            $rules['values.language'] = ['nullable', 'string', 'max:16'];
        }

        return $rules;
    }
}
