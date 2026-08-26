<?php

namespace App\Http\Controllers;

use App\Services\Channels\SmsWebhookService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class SmsWebhookController extends Controller
{
    public function __construct(private readonly SmsWebhookService $webhooks) {}

    public function receive(Request $request, string $connection): Response
    {
        $this->webhooks->handle($request, $connection);

        return response('<Response></Response>', 200)->header('Content-Type', 'text/xml');
    }
}
