<?php

namespace App\Services\Imports\Discovery\Data;

final readonly class DiscoveredSourceField
{
    /**
     * @param  list<string>  $sampleValues
     */
    public function __construct(
        public string $sourcePath,
        public string $suggestedInternalKey,
        public string $suggestedLabel,
        public string $suggestedType,
        public array $sampleValues,
        public string $confidence,
        public bool $isSearchable,
        public bool $isFilterable,
        public bool $isSortable,
        public bool $isDisplayable,
        public bool $isPrimaryKey,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source_path' => $this->sourcePath,
            'suggested_internal_key' => $this->suggestedInternalKey,
            'suggested_label' => $this->suggestedLabel,
            'suggested_type' => $this->suggestedType,
            'sample_values' => $this->sampleValues,
            'confidence' => $this->confidence,
            'is_searchable' => $this->isSearchable,
            'is_filterable' => $this->isFilterable,
            'is_sortable' => $this->isSortable,
            'is_displayable' => $this->isDisplayable,
            'is_primary_key' => $this->isPrimaryKey,
        ];
    }
}
