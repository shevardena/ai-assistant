<?php

namespace App\Http\Requests;

use App\Models\Bot;
use App\Services\Onboarding\BusinessTemplateRegistry;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreBotFromTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', Bot::class);
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'template_key' => [
                'required',
                'string',
                Rule::in(app(BusinessTemplateRegistry::class)->keys()),
            ],
            'bot_name' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function botName(): ?string
    {
        $name = trim((string) $this->validated('bot_name', ''));

        return $name !== '' ? $name : null;
    }

    public function templateKey(): string
    {
        return (string) $this->validated('template_key');
    }
}
