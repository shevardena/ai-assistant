<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConversationInboxRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'bot' => ['nullable', 'string', 'max:120'],
            'range' => ['nullable', 'string', 'in:all,today,7d,30d'],
            'source' => ['nullable', 'string', 'in:customer,preview,all'],
            'channel' => ['nullable', 'string', 'in:all,website,whatsapp,instagram,facebook_messenger,telegram,sms,email'],
            'handoff' => ['nullable', 'string', 'in:all,needs_attention,human'],
            'status' => ['nullable', 'string', 'in:all,open,pending,resolved,closed'],
            'assignee' => ['nullable', 'string', 'max:120'],
            'tag' => ['nullable', 'string', 'max:120'],
            'search' => ['nullable', 'string', 'max:120'],
        ];
    }
}
