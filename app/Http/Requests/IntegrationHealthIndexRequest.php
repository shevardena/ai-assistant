<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IntegrationHealthIndexRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'range' => ['nullable', 'string', 'in:today,7d,30d,90d'],
            'data_source' => ['nullable', 'integer', 'min:1'],
            'health' => ['nullable', 'string', 'in:all,healthy,warning,error,inactive'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $empty = [];

        foreach (['data_source', 'health'] as $key) {
            if ($this->input($key) === '') {
                $empty[$key] = null;
            }
        }

        if ($empty !== []) {
            $this->merge($empty);
        }
    }
}
