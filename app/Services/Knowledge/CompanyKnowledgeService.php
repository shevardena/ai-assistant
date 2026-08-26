<?php

namespace App\Services\Knowledge;

use App\Enums\DatasetStatus;
use App\Models\Dataset;
use App\Models\Team;
use Illuminate\Support\Facades\DB;

final class CompanyKnowledgeService
{
    public function ensureDataset(Team $team): Dataset
    {
        return DB::transaction(function () use ($team): Dataset {
            $dataset = $team->datasets()->firstOrCreate(
                ['slug' => 'company-knowledge'],
                [
                    'name' => 'Company knowledge',
                    'entity_type' => 'knowledge',
                    'retrieval_mode' => 'indexed',
                    'primary_key_path' => null,
                    'status' => DatasetStatus::Ready->value,
                    'settings' => [
                        'managed_by' => 'company_knowledge',
                        'privacy' => 'bot_attachment_only',
                    ],
                    'schema_version' => 1,
                    'last_indexed_at' => null,
                ],
            );

            $this->createMissingFields($dataset);

            return $dataset->fresh(['fields']);
        });
    }

    private function createMissingFields(Dataset $dataset): void
    {
        $fields = [
            [
                'source_path' => 'title',
                'key' => 'title',
                'canonical_name' => 'title',
                'label' => 'Title',
                'data_type' => 'string',
                'semantic_type' => 'title',
                'description' => 'A short title for this company knowledge article.',
                'is_searchable' => true,
                'is_filterable' => false,
                'is_sortable' => false,
                'is_semantic' => true,
                'is_displayable' => true,
                'allowed_operators' => ['contains'],
                'config' => ['required' => true],
                'position' => 1,
            ],
            [
                'source_path' => 'content',
                'key' => 'content',
                'canonical_name' => 'content',
                'label' => 'Content',
                'data_type' => 'string',
                'semantic_type' => 'content',
                'description' => 'The factual answer the assistant may use.',
                'is_searchable' => true,
                'is_filterable' => false,
                'is_sortable' => false,
                'is_semantic' => true,
                'is_displayable' => true,
                'allowed_operators' => ['contains'],
                'config' => ['required' => true],
                'position' => 2,
            ],
            [
                'source_path' => 'category',
                'key' => 'category',
                'canonical_name' => 'category',
                'label' => 'Category',
                'data_type' => 'string',
                'semantic_type' => 'category',
                'description' => 'Optional category such as shipping, returns, or company.',
                'is_searchable' => true,
                'is_filterable' => true,
                'is_sortable' => false,
                'is_semantic' => false,
                'is_displayable' => true,
                'allowed_operators' => ['equal', 'contains'],
                'config' => [],
                'position' => 3,
            ],
            [
                'source_path' => 'source_url',
                'key' => 'source_url',
                'canonical_name' => 'url',
                'label' => 'Source URL',
                'data_type' => 'url',
                'semantic_type' => 'url',
                'description' => 'Optional public source for this information.',
                'is_searchable' => false,
                'is_filterable' => false,
                'is_sortable' => false,
                'is_semantic' => false,
                'is_displayable' => true,
                'allowed_operators' => [],
                'config' => [],
                'position' => 4,
            ],
            [
                'source_path' => 'language',
                'key' => 'language',
                'canonical_name' => 'language',
                'label' => 'Language',
                'data_type' => 'string',
                'semantic_type' => 'language',
                'description' => 'Optional language code such as en or ka.',
                'is_searchable' => true,
                'is_filterable' => true,
                'is_sortable' => false,
                'is_semantic' => false,
                'is_displayable' => true,
                'allowed_operators' => ['equal'],
                'config' => [],
                'position' => 5,
            ],
        ];

        $existingKeys = $dataset->fields()->pluck('key')->all();

        foreach ($fields as $field) {
            if (! in_array($field['key'], $existingKeys, true)) {
                $dataset->fields()->create($field);
            }
        }
    }
}
