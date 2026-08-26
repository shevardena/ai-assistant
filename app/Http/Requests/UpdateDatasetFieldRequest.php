<?php

namespace App\Http\Requests;

use App\Models\Dataset;
use App\Models\DatasetField;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use JsonException;

class UpdateDatasetFieldRequest extends FormRequest
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
        $dataset = $this->route('dataset');
        $field = $this->route('field');

        abort_if(! $dataset instanceof Dataset || ! $field instanceof DatasetField, 403);

        return [
            'source_path' => ['required', 'string', 'max:255'],
            'key' => [
                'required',
                'string',
                'alpha_dash',
                'max:255',
                Rule::unique((new DatasetField)->getTable(), 'key')
                    ->where(fn ($query) => $query->where('dataset_id', $dataset->id))
                    ->ignore($field),
            ],
            'canonical_name' => ['nullable', 'string', 'max:255'],
            'label' => ['required', 'string', 'max:255'],
            'data_type' => ['required', 'string', 'in:string,integer,decimal,boolean,date,datetime,url'],
            'semantic_type' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_searchable' => ['required', 'boolean'],
            'is_filterable' => ['required', 'boolean'],
            'is_sortable' => ['required', 'boolean'],
            'is_semantic' => ['required', 'boolean'],
            'is_displayable' => ['required', 'boolean'],
            'normalizer' => ['nullable', 'string', 'max:255'],
            'config' => ['nullable', 'array'],
            'position' => ['required', 'integer', 'min:0'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $config = $this->input('config');

        if (! is_string($config)) {
            return;
        }

        if (trim($config) === '') {
            $this->merge(['config' => null]);

            return;
        }

        try {
            $this->merge([
                'config' => json_decode($config, true, 512, JSON_THROW_ON_ERROR),
            ]);
        } catch (JsonException) {
            // Leave invalid input as a string so the array rule rejects it.
        }
    }
}
