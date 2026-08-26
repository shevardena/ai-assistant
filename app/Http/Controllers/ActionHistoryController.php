<?php

namespace App\Http\Controllers;

use App\Http\Requests\ActionHistoryIndexRequest;
use App\Models\Team;
use App\Services\Actions\ActionHistoryService;
use Inertia\Inertia;
use Inertia\Response;

class ActionHistoryController extends Controller
{
    public function __construct(private readonly ActionHistoryService $actions) {}

    public function index(ActionHistoryIndexRequest $request, Team $currentTeam): Response
    {
        return Inertia::render('actions/index', $this->actions->index(
            $currentTeam,
            $request->validated(),
        ));
    }

    public function show(Team $currentTeam, string $actionReference): Response
    {
        return Inertia::render('actions/show', $this->actions->detail($currentTeam, $actionReference));
    }
}
