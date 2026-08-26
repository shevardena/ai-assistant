<?php

namespace App\Http\Controllers;

use App\Enums\AppointmentStatus;
use App\Http\Requests\AppointmentIndexRequest;
use App\Http\Requests\UpdateAppointmentStatusRequest;
use App\Models\Appointment;
use App\Models\Team;
use App\Services\Appointments\AppointmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AppointmentController extends Controller
{
    public function __construct(private readonly AppointmentService $appointments) {}

    public function index(AppointmentIndexRequest $request, Team $currentTeam): Response
    {
        Gate::authorize('viewAny', Appointment::class);

        return Inertia::render('appointments/index', $this->appointments->index($currentTeam, $request->validated()));
    }

    public function show(Team $currentTeam, Appointment $appointment): Response
    {
        Gate::authorize('view', $appointment);

        return Inertia::render('appointments/show', $this->appointments->detail($currentTeam, $appointment));
    }

    public function update(UpdateAppointmentStatusRequest $request, Team $currentTeam, Appointment $appointment): RedirectResponse
    {
        Gate::authorize('update', $appointment);

        $this->appointments->updateStatus($currentTeam, $appointment, AppointmentStatus::from((string) $request->validated('status')));

        return back()->with('success', 'Appointment status updated.');
    }
}
