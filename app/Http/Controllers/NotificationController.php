<?php

namespace App\Http\Controllers;

use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;
use Inertia\Response;

final class NotificationController extends Controller
{
    public function index(Request $request, Team $currentTeam): Response
    {
        $filter = $request->string('filter')->toString();
        $filter = in_array($filter, ['all', 'unread'], true) ? $filter : 'all';
        $baseQuery = $request->user()->notifications()->where('team_id', $currentTeam->getKey());
        $totalCount = (clone $baseQuery)->count();
        $unreadCount = (clone $baseQuery)->whereNull('read_at')->count();
        $notifications = $baseQuery
            ->when($filter === 'unread', fn ($query) => $query->whereNull('read_at'))
            ->latest('created_at')
            ->latest('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (DatabaseNotification $notification): array => $this->present($notification, $currentTeam));

        return Inertia::render('notifications/index', [
            'filter' => $filter,
            'totalCount' => $totalCount,
            'unreadCount' => $unreadCount,
            'notifications' => $notifications,
        ]);
    }

    public function read(Request $request, Team $currentTeam, string $notification): RedirectResponse
    {
        $this->findForUser($request, $currentTeam, $notification)->markAsRead();

        return to_route('notifications.index', $currentTeam->slug);
    }

    public function unread(Request $request, Team $currentTeam, string $notification): RedirectResponse
    {
        $this->findForUser($request, $currentTeam, $notification)->markAsUnread();

        return to_route('notifications.index', $currentTeam->slug);
    }

    public function readAll(Request $request, Team $currentTeam): RedirectResponse
    {
        $request->user()->notifications()
            ->where('team_id', $currentTeam->getKey())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return to_route('notifications.index', $currentTeam->slug);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(DatabaseNotification $notification, Team $team): array
    {
        $data = $notification->data;

        return [
            'id' => (string) $notification->getKey(),
            'type' => (string) ($data['type'] ?? 'system'),
            'title' => (string) ($data['title'] ?? 'Notification'),
            'message' => (string) ($data['message'] ?? ''),
            'botName' => is_string($data['bot_name'] ?? null) ? $data['bot_name'] : null,
            'href' => $this->href($team, $data),
            'readAt' => $notification->read_at?->toIso8601String(),
            'createdAt' => $notification->created_at?->toIso8601String(),
        ];
    }

    private function findForUser(Request $request, Team $team, string $notification): DatabaseNotification
    {
        return $request->user()->notifications()
            ->where('team_id', $team->getKey())
            ->whereKey($notification)
            ->firstOrFail();
    }

    /**
     * Build only known internal destinations. Notification data never supplies a URL.
     *
     * @param  array<string, mixed>  $data
     */
    private function href(Team $team, array $data): ?string
    {
        $type = $data['target_type'] ?? null;
        $reference = $data['target_reference'] ?? null;

        if (! is_string($type) || (! is_string($reference) && ! is_int($reference)) || (string) $reference === '') {
            return null;
        }

        return match ($type) {
            'conversation' => route('conversations.show', [$team->slug, (string) $reference]),
            'lead' => route('leads.show', [$team->slug, (string) $reference]),
            'appointment' => route('appointments.show', [$team->slug, (string) $reference]),
            'support_ticket' => route('support-tickets.show', [$team->slug, (string) $reference]),
            'data_source' => route('integration-health.show', [$team->slug, (int) $reference]),
            'dataset' => route('data-health.show', [$team->slug, (int) $reference]),
            'action' => route('actions.show', [$team->slug, (string) $reference]),
            default => null,
        };
    }
}
