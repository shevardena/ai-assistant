<?php

namespace App\Http\Requests;

use App\Models\Bot;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreBotRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Gate::allows('create', Bot::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $team = $this->user()?->currentTeam;

        abort_if(! $team, 403);

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'alpha_dash',
                'max:255',
                Rule::unique('bots', 'slug')->where(fn ($query) => $query
                    ->where('team_id', $team->id)
                    ->whereNull('deleted_at')),
            ],
            'default_language' => ['required', 'string', 'max:10'],
            'instructions' => ['nullable', 'string'],
            'welcome_message' => ['nullable', 'string'],
            'fallback_message' => ['nullable', 'string'],
        ];
    }
}
