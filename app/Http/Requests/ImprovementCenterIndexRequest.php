<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ImprovementCenterIndexRequest extends FormRequest
{
    /**
     * @return array<string, list<ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'bot' => ['nullable', 'string', 'max:120'],
            'range' => ['nullable', 'string', 'in:today,7d,30d,90d'],
            'type' => ['nullable', 'string', 'in:all,customer_questions,search,data,integrations,actions,configuration'],
            'priority' => ['nullable', 'string', 'in:all,high,medium,low'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'bot' => $this->input('bot') === '' ? null : $this->input('bot'),
            'type' => $this->input('type') === '' ? null : $this->input('type'),
            'priority' => $this->input('priority') === '' ? null : $this->input('priority'),
        ]);
    }
}
