<?php

namespace App\Http\Requests;

use App\Enums\ApiOperationSyncFrequency;
use App\Enums\ApiOperationSyncStrategy;
use App\Models\ApiOperation;
use App\Models\DataSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateApiOperationSyncScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $dataSource = $this->route('data_source');

        return $dataSource instanceof DataSource && Gate::allows('manageApiOperations', $dataSource);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'dataset_id' => ['nullable', 'integer', 'exists:datasets,id'],
            'frequency' => ['required', Rule::enum(ApiOperationSyncFrequency::class)],
            'strategy' => ['required', Rule::enum(ApiOperationSyncStrategy::class)],
            'configuration' => ['nullable', 'array'],
            'configuration.updated_since' => ['nullable', 'array'],
            'configuration.cursor' => ['nullable', 'array'],
            'configuration.updated_since.target' => ['nullable', Rule::in(['query', 'graphql_variable'])],
            'configuration.cursor.target' => ['nullable', Rule::in(['query', 'graphql_variable'])],
            'configuration.updated_since.name' => ['nullable', 'string', 'max:255'],
            'configuration.cursor.name' => ['nullable', 'string', 'max:255'],
            'configuration.updated_since.response_path' => ['nullable', 'string', 'max:255'],
            'configuration.cursor.response_path' => ['nullable', 'string', 'max:255'],
            'configuration.updated_since.format' => ['nullable', Rule::in(['iso8601', 'unix_seconds', 'unix_milliseconds'])],
            'configuration.cursor.format' => ['nullable', Rule::in(['iso8601', 'unix_seconds', 'unix_milliseconds'])],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $operation = $this->route('api_operation');
            $dataSource = $this->route('data_source');

            if (! $operation instanceof ApiOperation || ! $dataSource instanceof DataSource
                || (int) $operation->data_source_id !== (int) $dataSource->id) {
                $validator->errors()->add('frequency', 'The API operation does not belong to this connection.');

                return;
            }

            if ($operation->execution_mode !== 'read'
                || $operation->type !== 'query'
                || data_get($operation->response_mapping, 'sync_mode') !== ApiOperationSyncStrategy::FullSnapshot->value) {
                $validator->errors()->add('frequency', 'Only synced API operations can be scheduled.');
            }

            $datasetId = $this->integer('dataset_id');

            if ($datasetId > 0 && ! $dataSource->datasets()->whereKey($datasetId)->where('team_id', $dataSource->team_id)->exists()) {
                $validator->errors()->add('dataset_id', 'Choose a dataset belonging to this connection.');
            }

            $configuration = (array) $this->input('configuration', []);
            $strategy = (string) $this->input('strategy');

            if ($strategy !== ApiOperationSyncStrategy::FullSnapshot->value) {
                $config = (array) ($configuration[$strategy] ?? []);

                if (trim((string) ($config['name'] ?? $config['parameter'] ?? $config['variable'] ?? '')) === '') {
                    $validator->errors()->add('configuration', 'Configure where the incremental checkpoint is sent.');
                }

                if (trim((string) ($config['response_path'] ?? $config['checkpoint_path'] ?? '')) === '') {
                    $validator->errors()->add('configuration', 'Configure where the next incremental checkpoint is returned.');
                }
            }
        }];
    }
}
