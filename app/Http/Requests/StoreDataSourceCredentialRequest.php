<?php

namespace App\Http\Requests;

use App\Models\DataSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreDataSourceCredentialRequest extends FormRequest
{
    public function authorize(): bool
    {
        $dataSource = $this->route('data_source');

        return $dataSource instanceof DataSource && Gate::allows('update', $dataSource);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'in:bearer_token,api_key,basic_username,basic_password,custom_header_value'],
            'secret' => ['required', 'string', 'max:10000'],
        ];
    }
}
