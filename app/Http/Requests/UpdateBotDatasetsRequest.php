<?php

namespace App\Http\Requests;

use App\Models\Bot;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class UpdateBotDatasetsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $bot = $this->route('bot');

        return $bot instanceof Bot && Gate::allows('updateContent', $bot);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'datasets' => ['sometimes', 'array', 'max:100'],
            'datasets.*' => ['integer', 'min:1'],
        ];
    }

    /**
     * Ensure every submitted Dataset belongs to the Bot's Team.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $bot = $this->route('bot');

                if (! $bot instanceof Bot || ! $bot->team) {
                    $validator->errors()->add('datasets', 'The Bot team could not be resolved.');

                    return;
                }

                $submittedIds = $this->datasetIds();

                $allowedIds = $bot->team->datasets()
                    ->whereIn('id', $submittedIds)
                    ->pluck('id')
                    ->map(fn (mixed $datasetId): int => (int) $datasetId)
                    ->all();

                if (count($allowedIds) !== count($submittedIds)) {
                    $validator->errors()->add(
                        'datasets',
                        'All selected datasets must belong to this Bot’s team.',
                    );
                }
            },
        ];
    }

    /**
     * Get the validated, unique Dataset IDs submitted for this Bot.
     *
     * @return list<int>
     */
    public function datasetIds(): array
    {
        $datasetIds = $this->validated('datasets', []);

        if (! is_array($datasetIds)) {
            return [];
        }

        return array_values(array_unique(array_map(
            fn (mixed $datasetId): int => (int) $datasetId,
            $datasetIds,
        )));
    }
}
