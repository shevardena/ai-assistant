<?php

namespace App\Services\Ai;

use App\Enums\PriceSemanticRole;
use App\Models\Bot;
use App\Models\Dataset;

class BotRuntimeContextBuilder
{
    /**
     * @return array{datasets: list<array{slug: string, name: string, entityType: string, fields: list<array<string, mixed>>}>}
     */
    public function build(Bot $bot): array
    {
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

            foreach ($dataset->fields as $field) {
                if (! $field->is_displayable && ! $field->is_filterable && ! $field->is_sortable) {
                    continue;
                }

                $role = PriceSemanticRole::normalize($field->semantic_type, $field->key);
                $fields[] = [
                    'key' => $role?->value ?? (string) $field->key,
                    'type' => (string) $field->data_type,
                    'filterable' => (bool) $field->is_filterable,
                    'sortable' => (bool) $field->is_sortable,
                    'operators' => array_filter((array) $field->allowed_operators, 'is_string'),
                    'semantic_role' => $role?->value,
                ];
            }

            $datasets[] = [
                'slug' => (string) $dataset->slug,
                'name' => (string) $dataset->name,
                'entityType' => (string) $dataset->entity_type,
                'fields' => $fields,
            ];
        }

        return ['datasets' => $datasets];
    }
}
