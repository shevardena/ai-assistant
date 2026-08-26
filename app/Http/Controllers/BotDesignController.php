<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateBotDesignRequest;
use App\Models\Bot;
use App\Models\Dataset;
use App\Models\DatasetRecord;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class BotDesignController extends Controller
{
    public function edit(Team $currentTeam, Bot $bot): Response
    {
        Gate::authorize('updateContent', $bot);

        $attachments = $bot->botDatasets()
            ->where('is_enabled', true)
            ->whereHas('dataset', fn ($query) => $query->where('team_id', $bot->team_id))
            ->with(['dataset.fields'])
            ->orderBy('priority')
            ->get();
        $datasets = [];

        foreach ($attachments as $attachment) {
            $dataset = $attachment->dataset;

            if (! $dataset instanceof Dataset) {
                continue;
            }

            $fields = [];

            foreach ($dataset->fields->where('is_displayable', true) as $field) {
                $fields[] = [
                    'id' => $field->id,
                    'key' => $field->key,
                    'label' => $field->label,
                    'dataType' => $field->data_type,
                    'canonicalName' => $field->canonical_name,
                    'semanticType' => $field->semantic_type,
                ];
            }

            $datasets[] = [
                'id' => $dataset->id,
                'name' => $dataset->name,
                'slug' => $dataset->slug,
                'fields' => $fields,
                'template' => $this->template($bot, $dataset),
                'sample' => $this->sample($dataset),
            ];
        }

        return Inertia::render('bots/design', [
            'bot' => [
                'id' => $bot->id,
                'name' => $bot->name,
                'welcomeMessage' => $bot->welcome_message,
                'appearance' => (array) $bot->appearance,
            ],
            'datasets' => $datasets,
            'platform' => [
                'name' => (string) config('platform.marketing_name'),
                'url' => (string) config('platform.marketing_url'),
            ],
        ]);
    }

    public function update(
        UpdateBotDesignRequest $request,
        Team $currentTeam,
        Bot $bot,
    ): RedirectResponse {
        Gate::authorize('updateContent', $bot);

        $appearance = array_replace((array) $bot->appearance, $request->appearanceData());

        if ($request->removeAvatar() || $request->avatarFile() !== null) {
            $existingAvatar = data_get($appearance, 'assistant_avatar_path');

            if (is_string($existingAvatar)) {
                Storage::disk('public')->delete($existingAvatar);
            }

            unset($appearance['assistant_avatar_path'], $appearance['assistant_avatar_url']);
        }

        if ($request->avatarFile() !== null) {
            $path = $request->avatarFile()->store('bot-avatars', 'public');
            $appearance['assistant_avatar_path'] = $path;
            // Keep uploaded assets same-origin so local ports and APP_URL mismatches
            // cannot turn a valid upload into a broken preview/widget image.
            $appearance['assistant_avatar_url'] = '/storage/'.ltrim($path, '/');
        }

        $bot->update([
            'appearance' => $appearance,
            'welcome_message' => $request->welcomeMessage(),
        ]);

        $datasetId = $request->datasetId();

        if ($datasetId !== null) {
            $template = $bot->cardTemplates()->firstOrNew(['dataset_id' => $datasetId]);
            $layout = (array) $template->layout;
            $cardStyles = $request->cardStyleData();

            if ($cardStyles !== []) {
                $layout['card_style'] = array_replace(
                    (array) data_get($layout, 'card_style'),
                    $cardStyles,
                );
            }

            $template->fill([
                'name' => 'Default',
                'is_default' => true,
                'mapping' => $request->mappingData(),
                'layout' => array_replace($layout, [
                    'button_label' => $request->buttonLabel(),
                ]),
            ]);
            $template->save();
        }

        return to_route('bots.design.edit', [
            'current_team' => $currentTeam->slug,
            'bot' => $bot,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function template(Bot $bot, Dataset $dataset): ?array
    {
        $template = $bot->cardTemplates()
            ->where('dataset_id', $dataset->id)
            ->first();

        return $template === null ? null : [
            'name' => $template->name,
            'mapping' => (array) $template->getAttribute('mapping'),
            'buttonLabel' => data_get($template->getAttribute('layout'), 'button_label', 'View product'),
            'cardStyle' => (array) data_get($template->getAttribute('layout'), 'card_style', []),
        ];
    }

    /**
     * Return one safe active record containing only displayable scalar values.
     *
     * @return array{id: string, values: array<string, int|float|string|bool|null>}|null
     */
    private function sample(Dataset $dataset): ?array
    {
        $record = $dataset->records()
            ->active()
            ->latest('id')
            ->first();

        if (! $record instanceof DatasetRecord) {
            return null;
        }

        $payload = $record->getAttribute('payload');
        $payload = is_array($payload) ? $payload : [];
        $values = [];

        foreach ($dataset->fields->where('is_displayable', true) as $field) {
            $value = $payload[$field->key] ?? null;

            if (is_scalar($value) || $value === null) {
                $values[$field->key] = $value;
            }
        }

        return [
            'id' => (string) $record->external_id,
            'values' => $values,
        ];
    }
}
