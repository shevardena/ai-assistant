<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SupportTicketIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['bot' => ['nullable', 'string', 'max:120'], 'range' => ['nullable', 'string', 'in:today,7d,30d,90d,all'], 'status' => ['nullable', 'string', 'in:open,in_progress,resolved,closed,all'], 'search' => ['nullable', 'string', 'max:120']];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['bot' => $this->input('bot') === '' ? null : $this->input('bot'), 'search' => $this->input('search') === '' ? null : $this->input('search')]);
    }
}
