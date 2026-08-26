<?php

namespace App\Http\Requests;

use App\Models\DataSource;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;
use JsonException;

class UpdateDataSourceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $dataSource = $this->route('data_source');

        return $dataSource instanceof DataSource && Gate::allows('update', $dataSource);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $team = $this->user()?->currentTeam;
        $dataSource = $this->route('data_source');

        abort_if(! $team || ! $dataSource instanceof DataSource, 403);

        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:file,rest_api'],
            'config' => ['nullable', 'array'],
            'config.*' => ['nullable'],
            'config.base_url' => ['required_if:type,rest_api', 'nullable', 'url:http,https'],
            'config.api_key_header' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Decode the JSON editor value before validation and persistence.
     */
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

    /**
     * Keep credential material out of public data-source configuration.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $config = $this->input('config');

            if (is_array($config) && $this->containsSecretKey($config)) {
                $validator->errors()->add('config', 'Store credentials through the data-source credential configuration.');
            }
        }];
    }

    /**
     * @param  array<mixed, mixed>  $values
     */
    private function containsSecretKey(array $values): bool
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), ['api_key', 'bearer_token', 'token', 'secret', 'password', 'authorization', 'encrypted_value'], true)) {
                return true;
            }

            if (is_array($value) && $this->containsSecretKey($value)) {
                return true;
            }
        }

        return false;
    }
}
