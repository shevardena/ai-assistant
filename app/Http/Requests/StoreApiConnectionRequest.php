<?php

namespace App\Http\Requests;

use App\Models\DataSource;
use App\Services\Imports\Exceptions\ImportException;
use App\Services\Imports\RestApiRequestExecutor;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreApiConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $dataSource = $this->route('data_source');

        return $dataSource instanceof DataSource
            ? Gate::allows('update', $dataSource)
            : Gate::allows('create', DataSource::class);
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $isUpdate = $this->route('data_source') instanceof DataSource;

        return [
            'protocol' => ['nullable', Rule::in(['rest', 'graphql'])],
            'name' => ['required', 'string', 'max:255'],
            'base_url' => ['nullable', 'url:http,https', 'max:2000'],
            'endpoint' => ['nullable', 'url:http,https', 'max:2000'],
            'auth_type' => ['required', Rule::in(['none', 'bearer', 'api_key', 'basic', 'custom_header'])],
            'api_key_placement' => ['nullable', Rule::in(['header', 'query'])],
            'api_key_name' => ['nullable', 'string', 'max:255'],
            'custom_header_name' => ['nullable', 'string', 'max:255'],
            'bearer_token' => ['exclude_unless:auth_type,bearer', $isUpdate ? 'nullable' : 'required', 'string', 'max:10000'],
            'api_key' => ['exclude_unless:auth_type,api_key', $isUpdate ? 'nullable' : 'required', 'string', 'max:10000'],
            'basic_username' => ['exclude_unless:auth_type,basic', $isUpdate ? 'nullable' : 'required', 'string', 'max:1000'],
            'basic_password' => ['exclude_unless:auth_type,basic', $isUpdate ? 'nullable' : 'required', 'string', 'max:10000'],
            'custom_header_value' => ['exclude_unless:auth_type,custom_header', $isUpdate ? 'nullable' : 'required', 'string', 'max:10000'],
            'default_headers' => ['nullable', 'array'],
            'default_query_parameters' => ['nullable', 'array'],
            'default_variables' => ['nullable', 'array'],
            'advanced_config' => ['nullable', 'array'],
            'template' => ['nullable', 'string', 'max:100'],
            'requirement' => ['nullable', 'string', 'max:100'],
            'capability' => ['nullable', 'string', 'alpha_dash', 'max:100'],
            'bot' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $authType = (string) $this->input('auth_type');
            $dataSource = $this->route('data_source');
            $protocol = (string) ($this->input('protocol')
                ?: ($dataSource instanceof DataSource && $dataSource->type === 'graphql_api' ? 'graphql' : 'rest'));
            $urlField = $protocol === 'graphql' ? 'endpoint' : 'base_url';
            $url = $this->input($urlField);

            if ($url === null || $url === '') {
                $validator->errors()->add(
                    $urlField,
                    $protocol === 'graphql' ? 'Enter a GraphQL endpoint URL.' : 'Enter a REST API base URL.',
                );
            }

            try {
                app(RestApiRequestExecutor::class)->assertSafeUrl((string) $url);
            } catch (ImportException $exception) {
                $validator->errors()->add($urlField, $exception->getMessage());
            }

            if ($authType === 'api_key' && $this->input('api_key_placement', 'header') === 'query' && ! preg_match('/^[A-Za-z_][A-Za-z0-9_.-]{0,99}$/', (string) $this->input('api_key_name'))) {
                $validator->errors()->add('api_key_name', 'Enter a valid API key query parameter name.');
            }

            if ($authType === 'api_key' && $this->input('api_key_placement', 'header') === 'header' && ! $this->validHeaderName((string) $this->input('api_key_name', 'X-API-Key'))) {
                $validator->errors()->add('api_key_name', 'Enter a valid API key header name.');
            }

            if ($authType === 'custom_header' && ! $this->validHeaderName((string) $this->input('custom_header_name'))) {
                $validator->errors()->add('custom_header_name', 'Enter a valid authentication header name.');
            }

            $this->validateKeyValueRows($validator, 'default_headers', true);
            $this->validateKeyValueRows($validator, 'default_query_parameters', false);
            $this->validateKeyValueRows($validator, 'default_variables', false);

            if ($this->containsSecretKey($this->input('default_variables', []))) {
                $validator->errors()->add('default_variables', 'Store credentials in the authentication fields, not default variables.');
            }

            if ($this->containsSecretKey($this->input('advanced_config', []))) {
                $validator->errors()->add('advanced_config', 'Store credentials in the authentication fields, not advanced configuration.');
            }
        }];
    }

    private function validHeaderName(string $name): bool
    {
        return preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $name) === 1
            && ! in_array(Str::lower($name), ['host', 'content-length', 'connection', 'transfer-encoding', 'authorization'], true);
    }

    private function validateKeyValueRows(Validator $validator, string $key, bool $headers): void
    {
        $value = $this->input($key, []);

        if (! is_array($value)) {
            return;
        }

        foreach ($value as $name => $item) {
            if (array_is_list($value)) {
                $name = $item['name'] ?? null;
                $item = $item['value'] ?? null;
            }

            if (! is_string($name) || $name === '' || (! is_scalar($item) && $item !== null)) {
                $validator->errors()->add($key, 'Entries must contain a name and scalar value.');

                return;
            }

            if ($headers && ! $this->validHeaderName($name)) {
                $validator->errors()->add($key, 'One of the header names is not allowed.');

                return;
            }
        }
    }

    private function containsSecretKey(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $key => $item) {
            if (preg_match('/(?:authorization|credential|password|secret|token|api[_-]?key)/i', (string) $key) === 1) {
                return true;
            }

            if ($this->containsSecretKey($item)) {
                return true;
            }
        }

        return false;
    }
}
