<?php

namespace App\Http\Requests;

use App\Enums\KnowledgeGapStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateKnowledgeGapStatusRequest extends FormRequest
{
    /**
     * @return array<string, list<ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(KnowledgeGapStatus::class)],
        ];
    }
}
