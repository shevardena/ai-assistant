<?php

namespace App\Http\Controllers;

use App\Services\Channels\WhatsAppWebhookService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class WhatsAppWebhookController extends Controller
{
    public function __construct(private readonly WhatsAppWebhookService $webhooks) {}

    public function verify(Request $request): Response
    {
        $challenge = $request->string('hub_challenge')->toString();
        $verifyToken = $request->string('hub_verify_token')->toString();

        abort_unless(
            $request->string('hub_mode')->toString() === 'subscribe'
                && $challenge !== ''
                && $verifyToken !== '',
            403,
        );

        $verified = $this->webhooks->verify($verifyToken, $challenge);

        abort_unless($verified !== null, 403);

        return response($verified);
    }

    public function receive(Request $request): Response
    {
        $this->webhooks->handle($request);

        return response()->noContent();
    }
}
