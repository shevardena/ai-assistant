<?php

namespace App\Http\Requests;

use App\Models\Dataset;
use App\Models\SourceFile;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class StoreUnmappedDatasetFieldsRequest extends FormRequest
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
            'source_file_id' => ['nullable', 'integer', 'min:1'],
            'fields' => ['required', 'array', 'min:1', 'max:200'],
            'fields.*' => ['required', 'array'],
            'fields.*.source_path' => ['required', 'string', 'max:255'],
            'fields.*.key' => ['required', 'string', 'alpha_dash', 'max:255'],
            'fields.*.label' => ['required', 'string', 'max:255'],
            'fields.*.data_type' => ['required', 'string', 'in:string,integer,decimal,boolean,date,datetime,url'],
        ];
    }

    /**
     * Validate source ownership and uniqueness across the selected rows.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $dataset = $this->route('dataset');
                $sourceFileId = $this->input('source_file_id');
                $fields = $this->input('fields');

                if ($dataset instanceof Dataset && $sourceFileId !== null && $sourceFileId !== '') {
                    $sourceFile = SourceFile::query()->find((int) $sourceFileId);

                    if (! $sourceFile || $sourceFile->data_source_id !== $dataset->data_source_id) {
                        $validator->errors()->add(
                            'source_file_id',
                            'Choose a source file belonging to this dataset data source.',
                        );
                    }
                }

                if (! is_array($fields)) {
                    return;
                }

                $sourcePaths = array_column($fields, 'source_path');
                $keys = array_column($fields, 'key');

                if (count($sourcePaths) !== count(array_unique($sourcePaths))) {
                    $validator->errors()->add('fields', 'Source paths may only be selected once.');
                }

                if (count($keys) !== count(array_unique($keys))) {
                    $validator->errors()->add('fields', 'Internal keys must be unique.');
                }
            },
        ];
    }
}
