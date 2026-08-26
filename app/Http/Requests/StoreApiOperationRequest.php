<?php

namespace App\Http\Requests;

use App\Enums\ApiOperationMode;
use App\Models\DataSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreApiOperationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $dataSource = $this->route('data_source');

        return $dataSource instanceof DataSource && Gate::allows('update', $dataSource);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'alpha_dash', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'protocol' => ['nullable', Rule::in(['rest', 'graphql'])],
            'purpose' => ['nullable', 'string', 'max:1000'],
            'usage' => ['nullable', Rule::in(['synced', 'live_read', 'live_write'])],
            'type' => ['nullable', 'string', 'max:50'],
            'execution_mode' => ['nullable', 'string', Rule::enum(ApiOperationMode::class)],
            'method' => ['required', 'string', 'in:GET,POST,PUT,PATCH,DELETE'],
            'path' => ['required', 'string', 'max:2000', 'regex:/^\/(?!\/)/'],
            'request_schema' => ['nullable', 'array'],
            'request_mapping' => ['nullable', 'array'],
            'query_parameters' => ['nullable', 'array'],
            'body_parameters' => ['nullable', 'array'],
            'response_fields' => ['nullable', 'array'],
            'headers' => ['nullable', 'array'],
            'response_mapping' => ['nullable', 'array'],
            'records_path' => ['nullable', 'string', 'max:255'],
            'pagination' => ['nullable', 'array'],
            'timeout_ms' => ['nullable', 'integer', 'min:1000', 'max:30000'],
            'is_enabled' => ['sometimes', 'boolean'],
            'capability' => ['nullable', 'string', 'alpha_dash', 'max:100'],
            'bot' => ['nullable', 'integer', 'min:1'],
            'input_mapping' => ['nullable', 'array'],
            'test_arguments' => ['nullable', 'array'],
            'graphql_document' => ['nullable', 'string', 'max:100000'],
            'graphql_operation_name' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z_][A-Za-z0-9_]*$/'],
            'graphql_variables' => ['nullable', 'array'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $usage = (string) ($this->input('usage') ?? ($this->input('execution_mode') === 'write' ? 'live_write' : 'live_read'));
            $method = strtoupper((string) $this->input('method'));
            $dataSource = $this->route('data_source');
            $protocol = (string) ($this->input('protocol')
                ?: ($dataSource instanceof DataSource && $dataSource->type === 'graphql_api' ? 'graphql' : 'rest'));

            if ($protocol === 'graphql' && $method !== 'POST') {
                $validator->errors()->add('method', 'GraphQL operations use POST requests.');
            }

            if ($protocol !== 'graphql' && $usage === 'synced' && $method !== 'GET') {
                $validator->errors()->add('method', 'Synced API data uses GET requests.');
            }

            if ($protocol !== 'graphql' && $usage === 'live_read' && ! in_array($method, ['GET', 'POST'], true)) {
                $validator->errors()->add('method', 'Live read operations support GET or POST.');
            }

            if ($protocol !== 'graphql' && $usage === 'live_write' && ! in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                $validator->errors()->add('method', 'Live write operations support POST, PUT, PATCH, or DELETE.');
            }

            foreach ((array) $this->input('headers', []) as $name => $value) {
                if (array_is_list((array) $this->input('headers', [])) && is_array($value)) {
                    $name = $value['name'] ?? null;
                    $value = $value['value'] ?? null;
                }

                if (! is_string($name)
                    || preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $name) !== 1
                    || in_array(strtolower($name), ['host', 'content-length', 'connection', 'transfer-encoding', 'authorization'], true)
                    || (! is_scalar($value) && $value !== null)) {
                    $validator->errors()->add('headers', 'Routing and authorization headers are managed by the connection.');

                    break;
                }
            }

            if ($usage === 'synced' && trim((string) $this->input('records_path', data_get($this->input('response_mapping'), 'records_path', ''))) === '') {
                $validator->errors()->add('records_path', 'Choose where the API records are located.');
            }

            $paginationType = data_get($this->input('pagination'), 'type', data_get($this->input('response_mapping'), 'pagination.type', 'none'));

            $allowedPagination = $protocol === 'graphql'
                ? ['none', 'relay_cursor']
                : ['none', 'page', 'next_url'];

            if (! in_array($paginationType, $allowedPagination, true)) {
                $validator->errors()->add('pagination', 'This pagination strategy is not supported yet.');
            }

            if ($protocol === 'graphql' && trim((string) $this->input('graphql_document')) === '') {
                $validator->errors()->add('graphql_document', 'Enter a GraphQL query or mutation.');
            }

            $this->validateGraphqlVariables($validator, $protocol);

            if ($this->containsSecretKey((array) $this->input('request_mapping', []))) {
                $validator->errors()->add('request_mapping', 'Credentials must be configured on the API connection.');
            }

            $this->validateInputMapping($validator);
        }];
    }

    /** @param array<mixed, mixed> $values */
    private function containsSecretKey(array $values): bool
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && preg_match('/(?:authorization|credential|password|secret|token|api[_-]?key)/i', $key) === 1) {
                return true;
            }

            if (is_array($value) && $this->containsSecretKey($value)) {
                return true;
            }
        }

        return false;
    }

    private function validateInputMapping(Validator $validator): void
    {
        $mapping = $this->input('input_mapping', []);

        if (! is_array($mapping)) {
            return;
        }

        foreach ($mapping as $key => $definition) {
            if (! is_array($definition)) {
                $validator->errors()->add('input_mapping', 'Each capability mapping must be an object.');

                return;
            }

            $modelInput = array_is_list($mapping)
                ? (string) ($definition['model_input'] ?? '')
                : (string) $key;
            $source = (string) ($definition['source'] ?? 'model_input');
            $operationArgument = (string) ($definition['operation_argument'] ?? $definition['argument'] ?? '');

            if (preg_match('/^[A-Za-z_][A-Za-z0-9_.-]{0,254}$/', $modelInput) !== 1
                || ! in_array($source, ['context_value', 'dataset_field', 'model_input'], true)
                || preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,254}$/', $operationArgument) !== 1) {
                $validator->errors()->add('input_mapping', 'Capability mappings contain an unsupported value.');

                return;
            }

            if ($source === 'context_value'
                && preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,99}$/', (string) ($definition['context_key'] ?? $definition['context'] ?? '')) !== 1) {
                $validator->errors()->add('input_mapping', 'Context mappings require a safe context key.');

                return;
            }

            if ($source === 'dataset_field'
                && preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,254}$/', (string) ($definition['dataset_field'] ?? $definition['field'] ?? '')) !== 1) {
                $validator->errors()->add('input_mapping', 'Dataset mappings require a safe field key.');

                return;
            }
        }
    }

    private function validateGraphqlVariables(Validator $validator, string $protocol): void
    {
        if ($protocol !== 'graphql' || ! is_array($this->input('graphql_variables', []))) {
            return;
        }

        foreach ($this->input('graphql_variables', []) as $row) {
            if (! is_array($row)) {
                $validator->errors()->add('graphql_variables', 'Each GraphQL variable must be an object.');

                return;
            }

            $name = (string) ($row['name'] ?? '');
            $source = (string) ($row['source'] ?? 'tool_argument');

            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1
                || ! in_array($source, ['fixed', 'tool_argument', 'context'], true)) {
                $validator->errors()->add('graphql_variables', 'GraphQL variable mappings contain an unsupported value.');

                return;
            }

            if ($source === 'tool_argument'
                && preg_match('/^[A-Za-z_][A-Za-z0-9_.-]{0,254}$/', (string) ($row['argument'] ?? $name)) !== 1) {
                $validator->errors()->add('graphql_variables', 'Tool argument mappings require a safe argument name.');

                return;
            }

            if ($source === 'context'
                && preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,99}$/', (string) ($row['context_key'] ?? '')) !== 1) {
                $validator->errors()->add('graphql_variables', 'Context mappings require a safe context key.');

                return;
            }
        }
    }
}
