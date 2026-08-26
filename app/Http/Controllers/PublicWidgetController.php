<?php

namespace App\Http\Controllers;

use App\Enums\PlanFeature;
use App\Models\Bot;
use App\Services\Billing\TeamEntitlementService;
use App\Services\Cards\BotWidgetAppearance;
use App\Services\Widget\BotPublicAvailabilityService;
use App\Services\Widget\WidgetDomainValidator;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class PublicWidgetController extends Controller
{
    public function __construct(
        private readonly BotWidgetAppearance $widgetAppearance,
        private readonly WidgetDomainValidator $domainValidator,
        private readonly BotPublicAvailabilityService $availability,
        private readonly TeamEntitlementService $entitlements,
    ) {}

    public function show(Request $request, string $botPublicId): View
    {
        $bot = $this->bot($botPublicId);
        abort_unless($this->domainValidator->isAllowed($request, $bot), 404);
        $appearance = $this->widgetAppearance->for($bot);

        return view('widget', [
            'bot' => [
                'publicId' => $bot->public_id,
                'name' => $appearance['assistant_name'],
                'welcomeMessage' => $bot->welcome_message,
                'fallbackMessage' => $bot->fallback_message,
                'appearance' => $appearance,
                'availability' => $this->availability->status($bot),
                'platformName' => (string) config('platform.marketing_name'),
                'platformUrl' => (string) config('platform.marketing_url'),
                'capabilities' => [
                    'voice_input' => $this->entitlements->hasFeature($bot->team, PlanFeature::VoiceInput),
                ],
            ],
        ]);
    }

    private function bot(string $publicId): Bot
    {
        return Bot::query()
            ->where('public_id', $publicId)
            ->firstOrFail();
    }
}
