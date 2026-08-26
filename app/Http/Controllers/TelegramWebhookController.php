<?php

namespace App\Http\Controllers;

use App\Services\Channels\TelegramWebhookService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class TelegramWebhookController extends Controller
{
    public function __construct(private readonly TelegramWebhookService $webhooks) {}

    public function receive(Request $request, string $connection): Response
    {
        $this->webhooks->handle($request, $connection);

        return response()->noContent();
    }
}
