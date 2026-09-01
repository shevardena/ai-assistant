<?php

namespace App\Providers;

use App\Services\Ai\Contracts\AiClient;
use App\Services\Ai\OpenAiResponsesClient;
use App\Services\Billing\Contracts\SubscriptionPaymentService;
use App\Services\Billing\StripeSubscriptionPaymentService;
use App\Services\Channels\Contracts\EmailProviderClient;
use App\Services\Channels\Contracts\SmsProviderClient;
use App\Services\Channels\PostmarkEmailClient;
use App\Services\Channels\TwilioSmsClient;
use App\Services\Conversations\ConversationCycleLogger;
use App\Services\Search\Contracts\SearchEngine;
use App\Services\Search\Engines\PostgresSearchEngine;
use App\Services\Search\Engines\TypesenseSearchEngine;
use App\Services\Speech\AssemblyAiSpeechToTextProvider;
use App\Services\Speech\Contracts\SpeechToTextProvider;
use App\Services\Speech\SelfHostedWhisperSpeechToTextProvider;
use App\Services\Typesense\TypesenseClient;
use App\Services\Typesense\TypesenseClientFactory;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(ConversationCycleLogger::class);
        $this->app->bind(AiClient::class, OpenAiResponsesClient::class);
        $this->app->bind(SmsProviderClient::class, TwilioSmsClient::class);
        $this->app->bind(EmailProviderClient::class, PostmarkEmailClient::class);
        $this->app->bind(SubscriptionPaymentService::class, StripeSubscriptionPaymentService::class);
        $this->app->bind(SpeechToTextProvider::class, function (): SpeechToTextProvider {
            return match (config('speech_to_text.driver', 'self_hosted_whisper')) {
                'self_hosted_whisper' => app(SelfHostedWhisperSpeechToTextProvider::class),
                'assemblyai' => app(AssemblyAiSpeechToTextProvider::class),
                default => throw new InvalidArgumentException('Unsupported speech-to-text driver configured.'),
            };
        });

        $this->app->singleton(TypesenseClient::class, function (): TypesenseClient {
            return app(TypesenseClientFactory::class)->make();
        });

        $this->app->bind(SearchEngine::class, function (): SearchEngine {
            return match (config('search.engine', 'postgres')) {
                'postgres' => app(PostgresSearchEngine::class),
                'typesense' => app(TypesenseSearchEngine::class),
                default => throw new InvalidArgumentException('Unsupported search engine configured.'),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        RateLimiter::for('widget', function (Request $request): Limit {
            $visitor = (string) $request->input('visitor_id', 'anonymous');
            $bot = (string) $request->route('botPublicId', 'unknown');

            return Limit::perMinute((int) config('widget.rate_limit_per_minute', 30))
                ->by($bot.'|'.$visitor.'|'.$request->ip());
        });

        RateLimiter::for('widget-speech', function (Request $request): Limit {
            $bot = (string) $request->route('botPublicId', 'unknown');

            return Limit::perMinute((int) config('speech_to_text.rate_limit_per_minute', 10))
                ->by($bot.'|'.$request->ip());
        });

        RateLimiter::for('whatsapp-webhook', function (Request $request): Limit {
            return Limit::perMinute(120)->by('whatsapp|'.$request->ip());
        });

        RateLimiter::for('meta-webhook', function (Request $request): Limit {
            return Limit::perMinute(120)->by('meta|'.$request->ip());
        });

        RateLimiter::for('telegram-webhook', function (Request $request): Limit {
            return Limit::perMinute(120)->by('telegram|'.$request->route('connection').'|'.$request->ip());
        });

        RateLimiter::for('sms-webhook', function (Request $request): Limit {
            return Limit::perMinute(120)->by('sms|'.$request->route('connection').'|'.$request->ip());
        });

        RateLimiter::for('email-webhook', function (Request $request): Limit {
            return Limit::perMinute(240)->by('email|'.$request->route('connection').'|'.$request->ip());
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
