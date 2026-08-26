<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class KnowledgeGapIndexRequest extends FormRequest
{
    /**
     * @return array<string, list<ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'bot' => ['nullable', 'string', 'max:255'],
            'range' => ['nullable', 'string', 'in:today,7d,30d,90d'],
            'status' => ['nullable', 'string', 'in:open,resolved,ignored,all'],
            'reason' => ['nullable', 'string', 'in:no_knowledge_match,no_results'],
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
