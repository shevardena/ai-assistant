<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImprovementCenterIndexRequest;
use App\Models\Team;
use App\Services\Improvements\ImprovementCenterService;
use Inertia\Inertia;
use Inertia\Response;

class ImprovementCenterController extends Controller
{
    public function __construct(private readonly ImprovementCenterService $improvements) {}

    public function index(ImprovementCenterIndexRequest $request, Team $currentTeam): Response
    {
        return Inertia::render('improvements/index', $this->improvements->index(
            $currentTeam,
            $request->validated(),
        ));
    }
}
