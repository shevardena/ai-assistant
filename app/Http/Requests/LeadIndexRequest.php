<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class LeadIndexRequest extends FormRequest
{
    /**
     * @return array<string, list<ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'bot' => ['nullable', 'string', 'max:120'],
            'range' => ['nullable', 'string', 'in:today,7d,30d,90d,all'],
            'status' => ['nullable', 'string', 'in:new,contacted,qualified,won,lost,all'],
            'search' => ['nullable', 'string', 'max:120'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'bot' => $this->input('bot') === '' ? null : $this->input('bot'),
            'search' => $this->input('search') === '' ? null : $this->input('search'),
        ]);
    }
}
