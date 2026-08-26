<?php

namespace App\Http\Requests;

use App\Models\Dataset;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;
use JsonException;

class UpdateDatasetFieldsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $dataset = $this->route('dataset');

        return $dataset instanceof Dataset && Gate::allows('update', $dataset);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'fields' => ['required', 'array', 'max:200'],
            'fields.*' => ['required', 'array'],
            'fields.*.id' => ['nullable', 'integer', 'min:1'],
            'fields.*.source_path' => ['required', 'string', 'max:255'],
            'fields.*.key' => ['required', 'string', 'alpha_dash', 'max:255'],
            'fields.*.canonical_name' => ['nullable', 'string', 'max:255'],
            'fields.*.label' => ['required', 'string', 'max:255'],
            'fields.*.data_type' => ['required', 'string', 'in:string,integer,decimal,boolean,date,datetime,url'],
            'fields.*.semantic_type' => ['nullable', 'string', 'max:255'],
            'fields.*.description' => ['nullable', 'string'],
            'fields.*.is_searchable' => ['required', 'boolean'],
            'fields.*.is_filterable' => ['required', 'boolean'],
            'fields.*.is_sortable' => ['required', 'boolean'],
            'fields.*.is_semantic' => ['required', 'boolean'],
            'fields.*.is_displayable' => ['required', 'boolean'],
            'fields.*.normalizer' => ['nullable', 'string', 'max:255', 'in:lowercase,percentage,currency,gb'],
            'fields.*.config' => ['nullable', 'array'],
            'fields.*.position' => ['required', 'integer', 'min:0'],
            'fields.*.included' => ['required', 'boolean'],
        ];
    }

    /**
     * Validate ownership and uniqueness across the complete submitted mapping.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $dataset = $this->route('dataset');
                $fields = $this->input('fields', []);

                if (! is_array($fields)) {
                    return;
                }

                if (! $dataset instanceof Dataset) {
                    return;
                }

                $fieldIds = array_values(array_filter(array_map(
                    fn (mixed $field): ?int => is_array($field) && is_numeric($field['id'] ?? null)
                        ? (int) $field['id']
                        : null,
                    $fields,
                )));
                $uniqueFieldIds = array_values(array_unique($fieldIds));

                if (count($fieldIds) !== count($uniqueFieldIds)) {
                    $validator->errors()->add('fields', 'Each existing field may appear only once.');
                }

                if ($uniqueFieldIds !== []) {
                    $ownedCount = $dataset->fields()->whereIn('id', $uniqueFieldIds)->count();

                    if ($ownedCount !== count($uniqueFieldIds)) {
                        $validator->errors()->add('fields', 'All existing fields must belong to this dataset.');
                    }
                }

                $keys = array_values(array_filter(array_map(
                    fn (mixed $field): ?string => is_array($field) && is_string($field['key'] ?? null)
                        ? $field['key']
                        : null,
                    $fields,
                )));
                $sourcePaths = array_values(array_filter(array_map(
                    fn (mixed $field): ?string => is_array($field) && is_string($field['source_path'] ?? null)
                        ? $field['source_path']
                        : null,
                    $fields,
                )));

                if (count($keys) !== count(array_unique($keys))) {
                    $validator->errors()->add('fields', 'Field keys must be unique within this dataset.');
                }

                if (count($sourcePaths) !== count(array_unique($sourcePaths))) {
                    $validator->errors()->add('fields', 'Source paths must be unique within this dataset.');
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        $fields = $this->input('fields');

        if (! is_array($fields)) {
            return;
        }

        foreach ($fields as $index => $field) {
            if (! is_array($field) || ! is_string($field['config'] ?? null)) {
                continue;
            }

            $config = trim($field['config']);

            if ($config === '') {
                $fields[$index]['config'] = null;

                continue;
            }

            try {
                $fields[$index]['config'] = json_decode($config, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                // Leave invalid input as a string so the array rule rejects it.
            }
        }

        $this->merge(['fields' => $fields]);
    }
}
