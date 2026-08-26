<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ConversationReplyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && Gate::allows('reply', $this->route('conversation'));
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:4000'],
        ];
    }
}
