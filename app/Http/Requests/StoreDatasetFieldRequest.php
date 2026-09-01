<?php

namespace App\Http\Requests;

use App\Enums\PriceSemanticRole;
use App\Models\Dataset;
use App\Models\DatasetField;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use JsonException;

class StoreDatasetFieldRequest extends FormRequest
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

        abort_if(! $dataset instanceof Dataset, 403);

        return [
            'source_path' => ['required', 'string', 'max:255'],
            'key' => [
                'required',
                'string',
                'alpha_dash',
                'max:255',
                Rule::unique((new DatasetField)->getTable(), 'key')
                    ->where(fn ($query) => $query->where('dataset_id', $dataset->id)),
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

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $role = PriceSemanticRole::normalize($this->input('semantic_type'), $this->input('key'));
            $type = (string) $this->input('data_type', 'string');

            if ($role instanceof PriceSemanticRole && ! $role->supportsType($type)) {
                $validator->errors()->add('semantic_type', 'Semantic price roles require a compatible numeric field type.');
            }
        }];
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
