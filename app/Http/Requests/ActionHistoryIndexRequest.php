<?php

namespace App\Http\Requests;

use App\Enums\ToolRunStatus;
use Illuminate\Foundation\Http\FormRequest;

class ActionHistoryIndexRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'bot' => ['nullable', 'string', 'max:120'],
            'range' => ['nullable', 'string', 'in:all,today,7d,30d,90d'],
            'action' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', 'in:'.implode(',', array_map(
                fn (ToolRunStatus $status): string => $status->value,
                ToolRunStatus::cases(),
            ))],
            'search' => ['nullable', 'string', 'max:120'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $empty = [];

        foreach (['bot', 'action', 'status', 'search'] as $key) {
            if ($this->input($key) === '') {
                $empty[$key] = null;
            }
        }

        if ($empty !== []) {
            $this->merge($empty);
        }
    }
}
