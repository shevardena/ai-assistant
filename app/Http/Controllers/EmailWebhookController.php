<?php

namespace App\Http\Controllers;

use App\Services\Channels\EmailWebhookService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class EmailWebhookController extends Controller
{
    public function __construct(private readonly EmailWebhookService $webhooks) {}

    public function receive(Request $request, string $connection): Response
    {
        $this->webhooks->handle($request, $connection);

        return response()->noContent();
    }
}
