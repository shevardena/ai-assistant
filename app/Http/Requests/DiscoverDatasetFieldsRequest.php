<?php

namespace App\Http\Requests;

use App\Models\Dataset;
use App\Models\SourceFile;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class DiscoverDatasetFieldsRequest extends FormRequest
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
        ];
    }

    /**
     * Reject a manually selected SourceFile outside the Dataset's source.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $dataset = $this->route('dataset');
                $sourceFileId = $this->input('source_file_id');

                if (! $dataset instanceof Dataset || $sourceFileId === null || $sourceFileId === '') {
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
