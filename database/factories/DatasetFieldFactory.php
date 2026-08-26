<?php

namespace Database\Factories;

use App\Models\Dataset;
use App\Models\DatasetField;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DatasetField>
 */
class DatasetFieldFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterMaking(function (DatasetField $field): void {
            $sourceKey = Str::after((string) $field->source_path, '$.');
            $generatedLabel = Str::headline($sourceKey);

            if ($sourceKey !== (string) $field->key
                && (string) $field->canonical_name === $sourceKey
                && (string) $field->label === $generatedLabel) {
                $field->source_path = '$.'.(string) $field->key;
                $field->canonical_name = (string) $field->key;
                $field->label = Str::headline((string) $field->key);
            }
        });
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $key = fake()->unique()->slug(2);

        return [
            'dataset_id' => Dataset::factory(),
            'source_path' => '$.'.$key,
            'key' => $key,
            'canonical_name' => $key,
            'label' => str($key)->replace('-', ' ')->title(),
            'data_type' => 'string',
            'semantic_type' => null,
            'description' => fake()->sentence(),
            'aliases' => [],
            'is_searchable' => true,
            'is_filterable' => false,
            'is_sortable' => false,
            'is_semantic' => false,
            'is_displayable' => true,
            'allowed_operators' => [],
            'normalizer' => null,
            'config' => [],
            'position' => fake()->numberBetween(0, 100),
        ];
    }
}
