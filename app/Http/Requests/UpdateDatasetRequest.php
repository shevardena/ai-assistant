<?php

namespace App\Http\Requests;

use App\Models\Dataset;
use App\Models\DataSource;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use JsonException;

class UpdateDatasetRequest extends FormRequest
{
    public function authorize(): bool
    {
        $dataset = $this->route('dataset');

        return $dataset instanceof Dataset && Gate::allows('update', $dataset);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $team = $this->user()?->currentTeam;
        $dataset = $this->route('dataset');

        abort_if(! $team || ! $dataset instanceof Dataset, 403);

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'alpha_dash',
                'max:255',
                Rule::unique((new Dataset)->getTable(), 'slug')
                    ->where(fn ($query) => $query->where('team_id', $team->id))
                    ->ignore($dataset),
            ],
            'data_source_id' => [
                'required',
                'integer',
                Rule::exists((new DataSource)->getTable(), 'id')
                    ->where(fn ($query) => $query->where('team_id', $team->id)),
            ],
            'entity_type' => ['required', 'string', 'max:255'],
            'retrieval_mode' => ['required', 'string', 'in:indexed,live,hybrid'],
            'primary_key_path' => ['nullable', 'string', 'max:255'],
            'settings' => ['nullable', 'array'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $settings = $this->input('settings');

        if (! is_string($settings)) {
            return;
        }

        if (trim($settings) === '') {
            $this->merge(['settings' => null]);

            return;
        }

        try {
            $this->merge([
                'settings' => json_decode($settings, true, 512, JSON_THROW_ON_ERROR),
            ]);
        } catch (JsonException) {
            // Leave invalid input as a string so the array rule rejects it.
        }
    }
}
