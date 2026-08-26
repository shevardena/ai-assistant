<?php

namespace App\Services\Knowledge;

use Normalizer;

final class KnowledgeQueryNormalizer
{
    /**
     * @var list<string>
     */
    private const GENERIC_STOP_WORDS = [
        'a', 'an', 'and', 'are', 'can', 'do', 'does', 'for', 'how', 'i', 'is',
        'me', 'of', 'on', 'or', 'the', 'to', 'what', 'when', 'where', 'which',
        'who', 'why', 'with', 'you', 'your', 'condition', 'conditions', 'policy',
    ];

    /**
     * @var list<string>
     */
    private const GEORGIAN_STOP_WORDS = [
        'და', 'თუ', 'არის', 'რა', 'როგორ', 'რომელ', 'რომელი', 'შეიძლება',
        'თქვენი', 'თქვენს', 'მე', 'მაქვს', 'გაქვთ', 'შემიძლია', 'რამდენ',
        'დღეში', 'პირობა', 'პირობები', 'პირობის', 'პოლიტიკა',
    ];

    /**
     * @var list<string>
     */
    private const GEORGIAN_SUFFIXES = [
        'ების', 'ებს', 'იდან', 'ამდე', 'თან', 'თვის', 'ზე', 'ში', 'ით', 'ის',
        'მა', 'ს', 'ად', 'გან', 'ები', 'ება', 'ო', 'ი', 'ა',
    ];

    /**
     * @var list<string>
     */
    private const LATIN_SUFFIXES = ['ies', 'ing', 'ers', 'ed', 'es', 's'];

    public function normalize(string $text): string
    {
        $normalized = class_exists(Normalizer::class)
            ? Normalizer::normalize($text, Normalizer::FORM_KC)
            : $text;

        $normalized ??= $text;
        $normalized = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/u', ' ', trim($normalized)) ?? trim($normalized);

        return mb_strtolower($normalized, 'UTF-8');
    }

    /**
     * @return list<string>
     */
    public function tokens(string $text): array
    {
        $normalized = $this->normalize($text);

        preg_match_all('/[\p{L}\p{N}]+/u', $normalized, $matches);

        return array_values(array_unique(array_filter(
            $matches[0] ?? [],
            fn (string $token): bool => ! $this->isStopWord($token) && $this->isMeaningful($token),
        )));
    }

    /**
     * @return array<string, list<string>>
     */
    public function variants(string $text): array
    {
        $variants = [];

        foreach ($this->tokens($text) as $token) {
            $tokenVariants = [$token];

            if ($this->isGeorgian($token)) {
                $tokenVariants = [...$tokenVariants, ...$this->georgianVariants($token)];
            } elseif ($this->isLatin($token)) {
                $tokenVariants = [...$tokenVariants, ...$this->latinVariants($token)];
            }

            $variants[$token] = array_values(array_unique(array_filter(
                $tokenVariants,
                fn (string $variant): bool => $this->isMeaningful($variant),
            )));
        }

        return $variants;
    }

    public function isGeorgian(string $text): bool
    {
        return preg_match('/[\x{10A0}-\x{10FF}]/u', $text) === 1;
    }

    /**
     * @return list<string>
     */
    private function georgianVariants(string $token): array
    {
        $variants = [];

        foreach (self::GEORGIAN_SUFFIXES as $suffix) {
            if (! str_ends_with($token, $suffix)) {
                continue;
            }

            $stem = mb_substr($token, 0, mb_strlen($token, 'UTF-8') - mb_strlen($suffix, 'UTF-8'), 'UTF-8');

            if ($this->isMeaningfulGeorgian($stem)) {
                $variants[] = $stem;
            }
        }

        if (str_starts_with($token, 'დავ') && mb_strlen($token, 'UTF-8') > 6) {
            $verbStem = 'დ'.mb_substr($token, 3, null, 'UTF-8');

            if ($this->isMeaningfulGeorgian($verbStem)) {
                $variants[] = $verbStem;
            }
        }

        return $variants;
    }

    /**
     * @return list<string>
     */
    private function latinVariants(string $token): array
    {
        foreach (self::LATIN_SUFFIXES as $suffix) {
            if (! str_ends_with($token, $suffix) || mb_strlen($token, 'UTF-8') - mb_strlen($suffix, 'UTF-8') < 3) {
                continue;
            }

            $stem = mb_substr($token, 0, mb_strlen($token, 'UTF-8') - mb_strlen($suffix, 'UTF-8'), 'UTF-8');

            if ($suffix === 'ies') {
                $stem .= 'y';
            }

            return [$stem];
        }

        return [];
    }

    private function isStopWord(string $token): bool
    {
        return in_array($token, self::GENERIC_STOP_WORDS, true)
            || ($this->isGeorgian($token) && in_array($token, self::GEORGIAN_STOP_WORDS, true));
    }

    private function isMeaningful(string $token): bool
    {
        return $this->isGeorgian($token)
            ? $this->isMeaningfulGeorgian($token)
            : mb_strlen($token, 'UTF-8') >= 2;
    }

    private function isMeaningfulGeorgian(string $token): bool
    {
        return mb_strlen($token, 'UTF-8') >= 4;
    }

    private function isLatin(string $token): bool
    {
        return preg_match('/^[a-z0-9]+$/u', $token) === 1;
    }
}
