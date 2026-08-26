<?php

namespace App\Services\Deals;

use App\Enums\PipelineStageSemanticType;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\Team;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PipelineService
{
    /** @return array{pipeline: Pipeline, created: bool} */
    public function ensureDefault(Team $team): array
    {
        return DB::transaction(function () use ($team): array {
            $team = Team::query()->whereKey($team->getKey())->lockForUpdate()->firstOrFail();
            $default = $team->pipelines()->where('is_default', true)->where('is_active', true)->first();
            if ($default) {
                return ['pipeline' => $default, 'created' => false];
            }

            $pipeline = $team->pipelines()->where('is_active', true)->oldest('id')->first();
            if ($pipeline) {
                $team->pipelines()->update(['is_default' => false]);
                $pipeline->forceFill(['is_default' => true])->save();

                return ['pipeline' => $pipeline, 'created' => false];
            }

            $pipeline = $team->pipelines()->create(['name' => 'Sales Pipeline', 'is_default' => true, 'is_active' => true]);
            $this->createDefaultStages($pipeline);

            return ['pipeline' => $pipeline, 'created' => true];
        });
    }

    /** @param array<string, mixed> $data */
    public function create(Team $team, array $data): Pipeline
    {
        return DB::transaction(function () use ($team, $data): Pipeline {
            $team = Team::query()->whereKey($team->getKey())->lockForUpdate()->firstOrFail();
            $makeDefault = (bool) ($data['is_default'] ?? false) || ! $team->pipelines()->where('is_default', true)->where('is_active', true)->exists();
            if ($makeDefault) {
                $team->pipelines()->update(['is_default' => false]);
            }

            $pipeline = $team->pipelines()->create(['name' => trim((string) $data['name']), 'is_default' => $makeDefault, 'is_active' => true]);
            if (($data['with_default_stages'] ?? true) === true) {
                $this->createDefaultStages($pipeline);
            }

            return $pipeline->load('stages');
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Team $team, Pipeline $pipeline, array $data): Pipeline
    {
        $pipeline = $team->pipelines()->whereKey($pipeline->getKey())->firstOrFail();
        $pipeline->update(['name' => trim((string) $data['name'])]);
        if (($data['is_default'] ?? false) === true) {
            $this->setDefault($team, $pipeline);
        }

        return $pipeline->fresh(['stages']) ?? $pipeline;
    }

    public function setDefault(Team $team, Pipeline $pipeline): Pipeline
    {
        return DB::transaction(function () use ($team, $pipeline): Pipeline {
            Team::query()->whereKey($team->getKey())->lockForUpdate()->firstOrFail();
            $pipeline = $team->pipelines()->whereKey($pipeline->getKey())->where('is_active', true)->lockForUpdate()->firstOrFail();
            $team->pipelines()->where('id', '!=', $pipeline->getKey())->update(['is_default' => false]);
            $pipeline->forceFill(['is_default' => true])->save();

            return $pipeline;
        });
    }

    /** @param array<string, mixed> $data */
    public function createStage(Team $team, Pipeline $pipeline, array $data): PipelineStage
    {
        return DB::transaction(function () use ($team, $pipeline, $data): PipelineStage {
            $pipeline = $team->pipelines()->whereKey($pipeline->getKey())->firstOrFail();
            $semantic = PipelineStageSemanticType::from((string) $data['semantic_type']);
            $this->ensureSemanticAvailable($pipeline, $semantic);

            return $pipeline->stages()->create([
                'team_id' => $team->getKey(),
                'name' => trim((string) $data['name']),
                'sort_order' => ((int) $pipeline->stages()->max('sort_order')) + 1,
                'probability' => $data['probability'] ?? null,
                'semantic_type' => $semantic,
            ]);
        });
    }

    /** @param array<string, mixed> $data */
    public function updateStage(Team $team, PipelineStage $stage, array $data): PipelineStage
    {
        return DB::transaction(function () use ($team, $stage, $data): PipelineStage {
            $stage = $team->pipelineStages()->whereKey($stage->getKey())->firstOrFail();
            $semantic = PipelineStageSemanticType::from((string) ($data['semantic_type'] ?? $stage->semantic_type->value));
            if ($semantic !== $stage->semantic_type) {
                $this->ensureSemanticAvailable($stage->pipeline, $semantic, $stage);
            }
            $stage->update(['name' => trim((string) ($data['name'] ?? $stage->name)), 'probability' => $data['probability'] ?? null, 'semantic_type' => $semantic]);

            return $stage->fresh() ?? $stage;
        });
    }

    /** @param list<int> $stageIds */
    public function reorderStages(Team $team, Pipeline $pipeline, array $stageIds): void
    {
        DB::transaction(function () use ($team, $pipeline, $stageIds): void {
            $pipeline = $team->pipelines()->whereKey($pipeline->getKey())->firstOrFail();
            $existingIds = $pipeline->stages()->pluck('id')->sort()->values()->all();
            $submittedIds = collect($stageIds)->map(fn ($id): int => (int) $id)->sort()->values()->all();
            if ($existingIds !== $submittedIds) {
                throw ValidationException::withMessages(['stage_ids' => 'Stages must belong to the selected Pipeline.']);
            }

            foreach (array_values($stageIds) as $index => $stageId) {
                $pipeline->stages()->whereKey($stageId)->update(['sort_order' => ($index + 1) * 1000]);
            }
        });
    }

    public function deleteStage(Team $team, PipelineStage $stage): void
    {
        DB::transaction(function () use ($team, $stage): void {
            $stage = $team->pipelineStages()->whereKey($stage->getKey())->lockForUpdate()->firstOrFail();
            if ($stage->deals()->exists()) {
                throw ValidationException::withMessages(['stage' => 'Move all Deals out of this Stage before deleting it.']);
            }
            if ($stage->semantic_type !== PipelineStageSemanticType::Open) {
                throw ValidationException::withMessages(['stage' => 'Lifecycle stages cannot be deleted.']);
            }
            if (PipelineStage::query()->where('pipeline_id', $stage->pipeline_id)->where('semantic_type', PipelineStageSemanticType::Open->value)->lockForUpdate()->get()->count() <= 1) {
                throw ValidationException::withMessages(['stage' => 'A Pipeline must retain at least one open Stage.']);
            }
            $stage->delete();
        });
    }

    public function delete(Team $team, Pipeline $pipeline): void
    {
        DB::transaction(function () use ($team, $pipeline): void {
            $pipeline = $team->pipelines()->whereKey($pipeline->getKey())->lockForUpdate()->firstOrFail();
            if ($pipeline->deals()->exists()) {
                throw ValidationException::withMessages(['pipeline' => 'Move all Deals out of this Pipeline before deleting it.']);
            }
            if ($pipeline->is_default) {
                throw ValidationException::withMessages(['pipeline' => 'Choose another default Pipeline before deleting it.']);
            }
            $pipeline->delete();
        });
    }

    private function createDefaultStages(Pipeline $pipeline): void
    {
        foreach ([
            ['name' => 'New', 'probability' => 10, 'semantic_type' => PipelineStageSemanticType::Open],
            ['name' => 'Qualified', 'probability' => 30, 'semantic_type' => PipelineStageSemanticType::Open],
            ['name' => 'Proposal / Demo', 'probability' => 60, 'semantic_type' => PipelineStageSemanticType::Open],
            ['name' => 'Negotiation', 'probability' => 80, 'semantic_type' => PipelineStageSemanticType::Open],
            ['name' => 'Won', 'probability' => 100, 'semantic_type' => PipelineStageSemanticType::Won],
            ['name' => 'Lost', 'probability' => 0, 'semantic_type' => PipelineStageSemanticType::Lost],
        ] as $sortOrder => $stage) {
            $pipeline->stages()->create(['team_id' => $pipeline->team_id, 'name' => $stage['name'], 'sort_order' => $sortOrder + 1, 'probability' => $stage['probability'], 'semantic_type' => $stage['semantic_type']]);
        }
    }

    private function ensureSemanticAvailable(Pipeline $pipeline, PipelineStageSemanticType $semantic, ?PipelineStage $except = null): void
    {
        if ($semantic === PipelineStageSemanticType::Open) {
            return;
        }

        $exists = $pipeline->stages()->where('semantic_type', $semantic->value)->when($except, fn ($query) => $query->where('id', '!=', $except->getKey()))->exists();
        if ($exists) {
            throw ValidationException::withMessages(['semantic_type' => 'A Pipeline can only have one '.$semantic->value.' Stage.']);
        }
    }
}
