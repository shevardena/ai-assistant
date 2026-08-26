<?php

namespace App\Http\Requests;

use App\Models\Dataset;
use App\Models\SourceFile;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreDatasetImportRequest extends FormRequest
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
        return [
            'source_file_id' => [
                'required',
                'integer',
                Rule::exists((new SourceFile)->getTable(), 'id'),
            ],
        ];
    }

    /**
     * Reject a source file that is not owned by the dataset's data source.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $dataset = $this->route('dataset');
                $sourceFileId = $this->input('source_file_id');

                if (! $dataset instanceof Dataset || ! is_numeric($sourceFileId)) {
                    return;
                }

                $sourceFile = SourceFile::query()->find((int) $sourceFileId);

                if (! $sourceFile || $sourceFile->data_source_id !== $dataset->data_source_id) {
                    $validator->errors()->add(
                        'source_file_id',
                        'Choose a source file belonging to this dataset data source.',
                    );
                }
            },
        ];
    }
}
