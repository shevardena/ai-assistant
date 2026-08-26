<?php

namespace App\Http\Requests;

use App\Models\DataSource;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Validator;

class StoreSourceFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        $dataSource = $this->route('data_source');

        return $dataSource instanceof DataSource && Gate::allows('update', $dataSource);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                File::types(['csv', 'json', 'xlsx'])
                    ->extensions(['csv', 'json', 'xlsx'])
                    ->max((int) config('source-files.max_size_kb', 25600)),
            ],
        ];
    }

    /**
     * Reject uploads for REST API data sources after parent authorization.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $dataSource = $this->route('data_source');

                if ($dataSource instanceof DataSource && $dataSource->type !== 'file') {
                    $validator->errors()->add(
                        'data_source',
                        'Only file data sources can receive uploaded files.',
                    );
                }
            },
        ];
    }
}
