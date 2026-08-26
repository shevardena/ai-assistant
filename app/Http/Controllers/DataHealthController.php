<?php

namespace App\Http\Controllers;

use App\Http\Requests\DataHealthIndexRequest;
use App\Models\Dataset;
use App\Models\Team;
use App\Services\DataHealth\DataHealthService;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class DataHealthController extends Controller
{
    public function __construct(private readonly DataHealthService $health) {}

    public function index(DataHealthIndexRequest $request, Team $currentTeam): Response
    {
        return Inertia::render('data-health/index', $this->health->index(
            $currentTeam,
            $request->validated(),
        ));
    }

    public function show(Team $currentTeam, Dataset $dataset): Response
    {
        Gate::authorize('viewHealth', $dataset);

        return Inertia::render('data-health/show', $this->health->detail($currentTeam, $dataset));
    }
}
