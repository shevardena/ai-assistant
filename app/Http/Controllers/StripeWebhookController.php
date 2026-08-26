<?php

namespace App\Http\Controllers;

use App\Exceptions\BillingProviderException;
use App\Services\Billing\StripeSubscriptionSyncService;
use App\Services\Billing\StripeWebhookVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class StripeWebhookController extends Controller
{
    public function __construct(
        private readonly StripeWebhookVerifier $verifier,
        private readonly StripeSubscriptionSyncService $sync,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        try {
            $event = $this->verifier->verify($request->getContent(), $request->header('Stripe-Signature'));
            $this->sync->handle($event);
        } catch (BillingProviderException $exception) {
            Log::warning('Stripe webhook rejected.', ['error_code' => $exception->errorCode]);

            return response()->json(['message' => 'The webhook could not be accepted.'], 400);
        }

        return response()->json(['received' => true]);
    }
}
