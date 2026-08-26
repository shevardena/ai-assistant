<?php

namespace App\Http\Controllers;

use App\Http\Requests\IntegrationHealthIndexRequest;
use App\Models\DataSource;
use App\Models\Team;
use App\Services\Integrations\IntegrationHealthService;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class IntegrationHealthController extends Controller
{
    public function __construct(private readonly IntegrationHealthService $health) {}

    public function index(IntegrationHealthIndexRequest $request, Team $currentTeam): Response
    {
        return Inertia::render('integrations/health/index', $this->health->index(
            $currentTeam,
            $request->validated(),
        ));
    }

    public function show(Team $currentTeam, DataSource $dataSource): Response
    {
        Gate::authorize('viewHealth', $dataSource);

        return Inertia::render('integrations/health/show', $this->health->detail(
            $currentTeam,
            $dataSource,
        ));
    }
}
