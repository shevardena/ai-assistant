<?php

namespace App\Http\Requests;

use App\Models\DataSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class TestApiConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $dataSource = $this->route('data_source');

        return $dataSource instanceof DataSource
            ? Gate::allows('update', $dataSource)
            : Gate::allows('create', DataSource::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'base_url' => ['required', 'url:http,https', 'max:2000'],
            'auth_type' => ['required', Rule::in(['none', 'bearer', 'api_key', 'basic', 'custom_header'])],
            'api_key_placement' => ['nullable', Rule::in(['header', 'query'])],
            'api_key_name' => ['nullable', 'string', 'max:255'],
            'custom_header_name' => ['nullable', 'string', 'max:255'],
            'bearer_token' => ['exclude_unless:auth_type,bearer', 'nullable', 'string', 'max:10000'],
            'api_key' => ['exclude_unless:auth_type,api_key', 'nullable', 'string', 'max:10000'],
            'basic_username' => ['exclude_unless:auth_type,basic', 'nullable', 'string', 'max:1000'],
            'basic_password' => ['exclude_unless:auth_type,basic', 'nullable', 'string', 'max:10000'],
            'custom_header_value' => ['exclude_unless:auth_type,custom_header', 'nullable', 'string', 'max:10000'],
            'default_headers' => ['nullable', 'array'],
            'default_query_parameters' => ['nullable', 'array'],
            'headers' => ['nullable', 'array'],
            'query_parameters' => ['nullable', 'array'],
            'path' => ['nullable', 'string', 'max:2000', 'regex:/^\/(?!\/)/'],
        ];
    }
}
