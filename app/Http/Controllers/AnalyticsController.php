<?php

namespace App\Http\Controllers;

use App\Http\Requests\AnalyticsIndexRequest;
use App\Models\Team;
use App\Services\Analytics\TeamAnalyticsService;
use Inertia\Inertia;
use Inertia\Response;

class AnalyticsController extends Controller
{
    public function __construct(private readonly TeamAnalyticsService $analytics) {}

    public function index(AnalyticsIndexRequest $request, Team $currentTeam): Response
    {
        $filters = $request->validated();

        return Inertia::render('analytics/index', $this->analytics->dashboard(
            $currentTeam,
            (string) ($filters['range'] ?? '30d'),
            isset($filters['bot']) ? (string) $filters['bot'] : null,
        ));
    }
}
