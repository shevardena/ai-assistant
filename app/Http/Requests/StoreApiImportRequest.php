<?php

namespace App\Http\Requests;

use App\Models\ApiOperation;
use App\Models\Dataset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreApiImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $dataset = $this->route('dataset');

        return $dataset instanceof Dataset && Gate::allows('update', $dataset);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'api_operation_id' => [
                'required',
                'integer',
                Rule::exists((new ApiOperation)->getTable(), 'id'),
            ],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $dataset = $this->route('dataset');
            $operationId = $this->integer('api_operation_id');

            if (! $dataset instanceof Dataset || $operationId < 1) {
                return;
            }

            $operation = ApiOperation::query()->find($operationId);

            if (! $operation || $dataset->data_source_id !== $operation->data_source_id) {
                $validator->errors()->add('api_operation_id', 'Choose an API operation belonging to this dataset data source.');
            }
        }];
    }
}
