<?php

namespace App\Http\Requests;

use App\Models\DataSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class RunApiOperationSyncRequest extends FormRequest
{
    public function authorize(): bool
    {
        $dataSource = $this->route('data_source');

        return $dataSource instanceof DataSource && Gate::allows('manageApiOperations', $dataSource);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['dataset_id' => ['nullable', 'integer', 'exists:datasets,id']];
    }
}
