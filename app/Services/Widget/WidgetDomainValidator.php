<?php

namespace App\Services\Widget;

use App\Models\Bot;
use Illuminate\Http\Request;

class WidgetDomainValidator
{
    public function __construct(private readonly WidgetDomainNormalizer $normalizer) {}

    public function isAllowed(Request $request, Bot $bot): bool
    {
        $host = $this->requestHost($request);

        if ($host !== null && $this->isLocalhost($host) && config('widget.allow_localhost', false)) {
            return true;
        }

        return $host !== null
            && $bot->domains()->active()->where('domain', $host)->exists();
    }

    public function requestHost(Request $request): ?string
    {
        $origin = $request->header('Origin');
        $widgetOrigin = $request->header('X-Widget-Origin');

        if ($widgetOrigin !== null && $this->isApplicationOrigin($origin)) {
            $origin = $widgetOrigin;
        } elseif ($origin === null && $widgetOrigin !== null) {
            $origin = $widgetOrigin;
        } elseif ($origin === null && $widgetOrigin === null) {
            $origin = $request->header('Referer');
        }

        if (! is_string($origin) || trim($origin) === '' || strtolower(trim($origin)) === 'null') {
            return null;
        }

        $parts = parse_url($origin);

        if (! is_array($parts) || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) {
            return null;
        }

        try {
            return $this->normalizer->normalizeHost((string) ($parts['host'] ?? ''));
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private function isApplicationOrigin(?string $origin): bool
    {
        if (! is_string($origin) || trim($origin) === '') {
            return false;
        }

        $application = parse_url((string) config('app.url'));
        $requestOrigin = parse_url($origin);

        return is_array($application)
            && is_array($requestOrigin)
            && strtolower((string) ($application['scheme'] ?? '')) === strtolower((string) ($requestOrigin['scheme'] ?? ''))
            && strtolower((string) ($application['host'] ?? '')) === strtolower((string) ($requestOrigin['host'] ?? ''))
            && ((int) ($application['port'] ?? 0) === (int) ($requestOrigin['port'] ?? 0));
    }

    private function isLocalhost(string $host): bool
    {
        return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }
}
