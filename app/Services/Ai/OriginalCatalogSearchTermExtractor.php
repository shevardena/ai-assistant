<?php

namespace App\Services\Ai;

use Illuminate\Support\Str;

final class OriginalCatalogSearchTermExtractor
{
    /** @var list<string> */
    private const STOP_WORDS = [
        'a', 'an', 'anything', 'available', 'can', 'could', 'do', 'find', 'have', 'hello', 'hi',
        'i', 'items', 'me', 'parts', 'please', 'product', 'products', 'search', 'show', 'the',
        'want', 'you', 'for', 'year', 'years',
        'არის', 'არსებობს', 'გაქვთ', 'მაჩვენე', 'მაჩვენოთ', 'ნაწილი', 'ნაწილები', 'პროდუქტი',
        'პროდუქტები', 'პროდუქცია', 'პრიდუქცია', 'რამე', 'სალამი', 'შეგიძლიათ', 'წელი', 'წლის', 'წლიან', 'წლიანი',
        'есть', 'запчасти', 'покажи', 'покажите', 'привет', 'части',
    ];

    public function extract(?string $message): ?string
    {
        if (! is_string($message) || trim($message) === '') {
            return null;
        }

        preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}_.-]*/u', $message, $matches);
        $tokens = [];

        foreach ($matches[0] as $token) {
            $normalized = mb_strtolower($token);

            if ($this->isStopWord($normalized) || $this->isYearToken($normalized)) {
                continue;
            }

            $tokens[] = $this->removeGeorgianCaseSuffix($token);
        }

        $term = trim(implode(' ', array_filter($tokens, static fn (string $token): bool => $token !== '')));

        return $term !== '' ? $term : null;
    }

    public function extractLiteral(?string $message): ?string
    {
        if (! is_string($message) || trim($message) === '') {
            return null;
        }

        $candidate = trim($message);
        $candidate = preg_replace('/^(?:(?:show\s+me|do\s+you\s+have|have\s+you\s+got|can\s+you\s+show\s+me|please|მაჩვენე|მაჩვენეთ|მაჩვენოთ)\s+)+/iu', '', $candidate) ?? $candidate;
        $candidate = preg_replace('/\s+(?:(?:do\s+you\s+have|have\s+you\s+got)|(?:გაქვს|გაქვთ|არის|არსებობს))\s*[?!.,…]*$/iu', '', $candidate) ?? $candidate;
        $candidate = trim($candidate, " \t\n\r\0\x0B?!.,…");

        if ($candidate === '' || ! $this->looksLikeLiteral($candidate)) {
            return null;
        }

        return preg_replace('/\s+/u', ' ', $candidate) ?: $candidate;
    }

    private function looksLikeLiteral(string $value): bool
    {
        return preg_match('/(?:\b\d{2,4}[-\/]\d{2,4}\b|\b[A-Za-z0-9]+[-_][A-Za-z0-9_-]+\b|[()])/u', $value) === 1;
    }

    private function isStopWord(string $token): bool
    {
        if (in_array($token, self::STOP_WORDS, true)) {
            return true;
        }

        return Str::startsWith($token, ['პროდუქ', 'პრიდუქ', 'ნაწილ']);
    }

    private function isYearToken(string $token): bool
    {
        return preg_match('/^(?:19|20)\d{2}$/', $token) === 1
            || preg_match('/^\d{2}[-\/]\d{2}$/', $token) === 1;
    }

    private function removeGeorgianCaseSuffix(string $token): string
    {
        if (! preg_match('/[\x{10A0}-\x{10FF}]/u', $token)) {
            return $token;
        }

        foreach (['თვის', 'თან', 'გან', 'ზე', 'ში'] as $suffix) {
            if (Str::endsWith($token, $suffix)) {
                return mb_substr($token, 0, mb_strlen($token) - mb_strlen($suffix));
            }
        }

        if (Str::endsWith($token, 'ის')) {
            $withoutFinalS = mb_substr($token, 0, mb_strlen($token) - 1);

            if (Str::endsWith($withoutFinalS, 'სი')) {
                return mb_substr($withoutFinalS, 0, mb_strlen($withoutFinalS) - 1);
            }

            return $withoutFinalS;
        }

        return $token;
    }
}
