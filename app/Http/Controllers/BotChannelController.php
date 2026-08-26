<?php

namespace App\Http\Controllers;

use App\Data\ChannelDefinition;
use App\Enums\ConversationChannel;
use App\Enums\TeamPermission;
use App\Http\Requests\ConfigureEmailConnectionRequest;
use App\Http\Requests\ConfigureMetaMessagingConnectionRequest;
use App\Http\Requests\ConfigureSmsConnectionRequest;
use App\Http\Requests\ConfigureTelegramConnectionRequest;
use App\Http\Requests\ConfigureWhatsAppConnectionRequest;
use App\Models\Bot;
use App\Models\Team;
use App\Models\User;
use App\Services\Channels\ChannelConnectionService;
use App\Services\Channels\ChannelRegistry;
use App\Services\Teams\TeamAuthorizationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BotChannelController extends Controller
{
    public function __construct(
        private readonly ChannelRegistry $registry,
        private readonly ChannelConnectionService $connections,
        private readonly TeamAuthorizationService $authorization,
    ) {}

    public function index(Request $request, Team $currentTeam, Bot $bot): Response
    {
        abort_unless((int) $bot->team_id === (int) $currentTeam->id, 404);
        abort_unless($request->user() instanceof User, 401);

        $website = $this->connections->ensureWebsite($bot);
        $botRoute = [
            'current_team' => $currentTeam->slug,
            'bot' => $bot,
        ];

        return Inertia::render('bots/channels', [
            'bot' => [
                'id' => $bot->id,
                'name' => $bot->name,
                'slug' => $bot->slug,
            ],
            'channels' => array_map(
                fn (ChannelDefinition $definition): array => [
                    ...$definition->toArray(),
                    'connection' => $definition->key->value === 'website'
                        ? [
                            'name' => $website->name,
                            'status' => $website->status->value,
                            'allowedDomains' => $bot->domains()->active()->count(),
                            'widgetReady' => in_array($bot->status, ['ready', 'published'], true)
                                && $bot->domains()->active()->exists(),
                            'links' => [
                                'design' => route('bots.design.edit', $botRoute),
                                'domains' => route('bots.show', $botRoute).'#domains',
                                'embed' => route('bots.show', $botRoute).'#embed',
                            ],
                        ]
                        : $this->channelConnection($definition, $bot),
                ],
                $this->registry->all(),
            ),
            'permissions' => [
                'canManage' => $this->authorization->can($request->user(), $currentTeam, TeamPermission::ChannelsManage),
            ],
        ]);
    }

    public function configureWhatsApp(
        ConfigureWhatsAppConnectionRequest $request,
        Team $currentTeam,
        Bot $bot,
    ): RedirectResponse {
        $this->ensureBotTeam($currentTeam, $bot);
        abort_unless($request->user() instanceof User, 401);
        $this->connections->configureWhatsApp($bot, $request->validated(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('WhatsApp connection saved.')]);

        return to_route('bots.channels.index', [$currentTeam->slug, $bot]);
    }

    public function disconnectWhatsApp(Request $request, Team $currentTeam, Bot $bot): RedirectResponse
    {
        abort_unless($request->user() !== null, 401);
        $this->ensureBotTeam($currentTeam, $bot);
        $this->connections->disconnectWhatsApp($bot);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('WhatsApp connection disabled.')]);

        return to_route('bots.channels.index', [$currentTeam->slug, $bot]);
    }

    public function configureInstagram(
        ConfigureMetaMessagingConnectionRequest $request,
        Team $currentTeam,
        Bot $bot,
    ): RedirectResponse {
        $this->ensureBotTeam($currentTeam, $bot);
        abort_unless($request->user() instanceof User, 401);
        $this->connections->configureMeta($bot, ConversationChannel::Instagram, $request->validated(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Instagram connection saved.')]);

        return to_route('bots.channels.index', [$currentTeam->slug, $bot]);
    }

    public function disconnectInstagram(Request $request, Team $currentTeam, Bot $bot): RedirectResponse
    {
        abort_unless($request->user() !== null, 401);
        $this->ensureBotTeam($currentTeam, $bot);
        $this->connections->disconnectMeta($bot, ConversationChannel::Instagram);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Instagram connection disabled.')]);

        return to_route('bots.channels.index', [$currentTeam->slug, $bot]);
    }

    public function configureMessenger(
        ConfigureMetaMessagingConnectionRequest $request,
        Team $currentTeam,
        Bot $bot,
    ): RedirectResponse {
        $this->ensureBotTeam($currentTeam, $bot);
        abort_unless($request->user() instanceof User, 401);
        $this->connections->configureMeta($bot, ConversationChannel::FacebookMessenger, $request->validated(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Facebook Messenger connection saved.')]);

        return to_route('bots.channels.index', [$currentTeam->slug, $bot]);
    }

    public function disconnectMessenger(Request $request, Team $currentTeam, Bot $bot): RedirectResponse
    {
        abort_unless($request->user() !== null, 401);
        $this->ensureBotTeam($currentTeam, $bot);
        $this->connections->disconnectMeta($bot, ConversationChannel::FacebookMessenger);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Facebook Messenger connection disabled.')]);

        return to_route('bots.channels.index', [$currentTeam->slug, $bot]);
    }

    public function configureTelegram(
        ConfigureTelegramConnectionRequest $request,
        Team $currentTeam,
        Bot $bot,
    ): RedirectResponse {
        $this->ensureBotTeam($currentTeam, $bot);
        abort_unless($request->user() instanceof User, 401);
        $this->connections->configureTelegram($bot, $request->validated(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Telegram connection saved.')]);

        return to_route('bots.channels.index', [$currentTeam->slug, $bot]);
    }

    public function disconnectTelegram(Request $request, Team $currentTeam, Bot $bot): RedirectResponse
    {
        abort_unless($request->user() !== null, 401);
        $this->ensureBotTeam($currentTeam, $bot);
        $this->connections->disconnectTelegram($bot);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Telegram connection disabled.')]);

        return to_route('bots.channels.index', [$currentTeam->slug, $bot]);
    }

    public function configureSms(
        ConfigureSmsConnectionRequest $request,
        Team $currentTeam,
        Bot $bot,
    ): RedirectResponse {
        $this->ensureBotTeam($currentTeam, $bot);
        abort_unless($request->user() instanceof User, 401);
        $this->connections->configureSms($bot, $request->validated(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('SMS connection saved.')]);

        return to_route('bots.channels.index', [$currentTeam->slug, $bot]);
    }

    public function disconnectSms(Request $request, Team $currentTeam, Bot $bot): RedirectResponse
    {
        abort_unless($request->user() !== null, 401);
        $this->ensureBotTeam($currentTeam, $bot);
        $this->connections->disconnectSms($bot);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('SMS connection disabled.')]);

        return to_route('bots.channels.index', [$currentTeam->slug, $bot]);
    }

    public function configureEmail(
        ConfigureEmailConnectionRequest $request,
        Team $currentTeam,
        Bot $bot,
    ): RedirectResponse {
        $this->ensureBotTeam($currentTeam, $bot);
        abort_unless($request->user() instanceof User, 401);
        $this->connections->configureEmail($bot, $request->validated(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Email connection saved.')]);

        return to_route('bots.channels.index', [$currentTeam->slug, $bot]);
    }

    public function disconnectEmail(Request $request, Team $currentTeam, Bot $bot): RedirectResponse
    {
        abort_unless($request->user() !== null, 401);
        $this->ensureBotTeam($currentTeam, $bot);
        $this->connections->disconnectEmail($bot);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Email connection disabled.')]);

        return to_route('bots.channels.index', [$currentTeam->slug, $bot]);
    }

    /** @return array<string, mixed>|null */
    private function channelConnection(ChannelDefinition $definition, Bot $bot): ?array
    {
        if (! in_array($definition->key, [
            ConversationChannel::WhatsApp,
            ConversationChannel::Instagram,
            ConversationChannel::FacebookMessenger,
            ConversationChannel::Telegram,
            ConversationChannel::Sms,
            ConversationChannel::Email,
        ], true)) {
            return null;
        }

        $connection = $bot->channelConnections()
            ->where('channel', $definition->key->value)
            ->with('credential')
            ->first();

        if ($connection === null) {
            return null;
        }

        $base = [
            'name' => $connection->name,
            'status' => $connection->status->value,
            'tokenConfigured' => $connection->credential !== null,
            'tokenLastFour' => $connection->credential?->access_token_last_four,
        ];

        if ($definition->key === ConversationChannel::WhatsApp) {
            return [
                ...$base,
                'phoneNumberId' => $connection->provider_channel_reference,
                'businessAccountId' => $connection->provider_account_reference,
                'displayPhoneNumber' => data_get($connection->configuration, 'display_phone_number'),
                'verifiedName' => data_get($connection->configuration, 'verified_name'),
            ];
        }

        if ($definition->key === ConversationChannel::Instagram) {
            return [
                ...$base,
                'instagramAccountId' => $connection->provider_channel_reference,
                'facebookPageId' => $connection->provider_account_reference,
                'displayName' => data_get($connection->configuration, 'display_name'),
                'username' => data_get($connection->configuration, 'username'),
            ];
        }

        if ($definition->key === ConversationChannel::Telegram) {
            return [
                ...$base,
                'botId' => data_get($connection->configuration, 'bot_id'),
                'botUsername' => data_get($connection->configuration, 'bot_username'),
                'displayName' => data_get($connection->configuration, 'display_name'),
                'webhookConfigured' => (bool) data_get($connection->configuration, 'webhook_configured', false),
            ];
        }

        if ($definition->key === ConversationChannel::Sms) {
            return [
                ...$base,
                'phoneNumber' => data_get($connection->configuration, 'phone_number'),
                'displayName' => data_get($connection->configuration, 'display_name'),
            ];
        }

        if ($definition->key === ConversationChannel::Email) {
            return [
                ...$base,
                'inboundAddress' => data_get($connection->configuration, 'inbound_address'),
                'fromAddress' => data_get($connection->configuration, 'from_address'),
                'fromName' => data_get($connection->configuration, 'from_name'),
                'replyToAddress' => data_get($connection->configuration, 'reply_to_address'),
                'displayName' => data_get($connection->configuration, 'display_name'),
                'inboundStatus' => data_get($connection->configuration, 'inbound_status'),
                'webhookUrl' => data_get($connection->configuration, 'webhook_url'),
            ];
        }

        return [
            ...$base,
            'facebookPageId' => $connection->provider_channel_reference,
            'pageName' => data_get($connection->configuration, 'page_name'),
        ];
    }

    private function ensureBotTeam(Team $currentTeam, Bot $bot): void
    {
        abort_unless((int) $bot->team_id === (int) $currentTeam->id, 404);
    }
}
