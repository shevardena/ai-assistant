<?php

namespace App\Http\Controllers;

use App\Models\Dataset;
use App\Models\Team;
use App\Services\Knowledge\CompanyKnowledgeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class KnowledgeController extends Controller
{
    public function __construct(private readonly CompanyKnowledgeService $knowledge) {}

    public function index(Request $request, Team $currentTeam): Response
    {
        Gate::authorize('viewAny', Dataset::class);

        $dataset = $this->knowledge->ensureDataset($currentTeam);
        $records = $dataset->records()
            ->where('is_active', true)
            ->latest('id')
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('knowledge/index', [
            'dataset' => [
                'id' => $dataset->id,
                'name' => $dataset->name,
                'status' => $dataset->status,
                'recordCount' => $dataset->records()->where('is_active', true)->count(),
            ],
            'records' => $records->through(function ($record): array {
                $payload = (array) $record->payload;

                return [
                    'id' => $record->id,
                    'title' => (string) ($payload['title'] ?? ''),
                    'content' => (string) ($payload['content'] ?? ''),
                    'category' => $payload['category'] ?? null,
                    'sourceUrl' => $payload['source_url'] ?? null,
                    'language' => $payload['language'] ?? null,
                ];
            }),
        ]);
    }
}
