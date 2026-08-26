<?php

namespace App\Services\Channels;

use Illuminate\Support\Str;

final class WhatsAppTextFormatter
{
    /**
     * @param  list<array<string, mixed>>  $blocks
     * @param  list<array<string, mixed>>  $cards
     */
    public function format(string $text, array $blocks = [], array $cards = []): string
    {
        $parts = [trim($text)];

        foreach ($blocks as $block) {
            $formatted = $this->block($block);

            if ($formatted !== null) {
                $parts[] = $formatted;
            }
        }

        if ($blocks === [] && $cards !== []) {
            $formattedCards = $this->productCards($cards);

            if ($formattedCards !== null) {
                $parts[] = $formattedCards;
            }
        }

        return trim(implode("\n\n", array_filter($parts, static fn (string $part): bool => $part !== '')));
    }

    /** @param array<string, mixed> $block */
    private function block(array $block): ?string
    {
        $type = $block['type'] ?? null;
        $data = $block['data'] ?? null;

        if (! is_string($type) || ! is_array($data)) {
            return null;
        }

        return match ($type) {
            'product_cards' => $this->productCards($this->cardList($data['cards'] ?? null)),
            'order_status' => $this->orderStatus($data),
            'locations' => $this->locations($data),
            'comparison' => $this->comparison($data),
            'confirmation' => $this->confirmation($data),
            'appointment_slots' => $this->appointments($data),
            default => null,
        };
    }

    /** @param list<array<string, mixed>> $cards */
    private function productCards(array $cards): ?string
    {
        $lines = [];

        foreach (array_slice($cards, 0, 10) as $card) {
            $title = $this->value($card['title'] ?? $card['name'] ?? null, 255);

            if ($title === null) {
                continue;
            }

            $line = $title;
            $price = $this->value($card['price'] ?? null, 80);

            if ($price !== null) {
                $line .= ' — '.$price;
            }

            $url = $this->url($card['url'] ?? null);

            if ($url !== null) {
                $line .= "\n".$url;
            }

            $lines[] = $line;
        }

        return $lines === [] ? null : "Products:\n".implode("\n", $lines);
    }

    /** @param array<string, mixed> $data */
    private function orderStatus(array $data): ?string
    {
        $lines = [];
        $status = $this->value($data['status'] ?? null, 120);

        if ($status !== null) {
            $lines[] = 'Order status: '.$status;
        }

        foreach ((array) ($data['fields'] ?? []) as $field) {
            if (! is_array($field)) {
                continue;
            }

            $label = $this->value($field['label'] ?? null, 255);
            $value = $this->value($field['value'] ?? null, 500);

            if ($label !== null && $value !== null) {
                $lines[] = $label.': '.$value;
            }
        }

        return $lines === [] ? null : implode("\n", $lines);
    }

    /** @param array<string, mixed> $data */
    private function locations(array $data): ?string
    {
        $lines = [];

        foreach (array_slice((array) ($data['locations'] ?? []), 0, 10) as $location) {
            if (! is_array($location)) {
                continue;
            }

            $name = $this->value($location['name'] ?? null, 255);
            $address = $this->value($location['address'] ?? null, 500);
            $city = $this->value($location['city'] ?? null, 255);
            $line = $name ?? $address ?? $city;

            if ($line === null) {
                continue;
            }

            $details = implode(', ', array_filter([$address, $city]));
            $lines[] = $details !== '' && $line !== $details ? $line."\n".$details : $line;
        }

        return $lines === [] ? null : "Locations:\n".implode("\n", $lines);
    }

    /** @param array<string, mixed> $data */
    private function comparison(array $data): ?string
    {
        $items = [];

        foreach ((array) ($data['items'] ?? []) as $item) {
            if (is_array($item) && ($label = $this->value($item['label'] ?? null, 255)) !== null) {
                $items[] = $label;
            }
        }

        $lines = $items === [] ? [] : ['Comparison: '.implode(' vs ', $items)];

        foreach (array_slice((array) ($data['fields'] ?? []), 0, 12) as $field) {
            if (! is_array($field)) {
                continue;
            }

            $label = $this->value($field['label'] ?? null, 255);
            $values = array_map(fn (mixed $value): string => $this->value($value, 300) ?? '—', (array) ($field['values'] ?? []));

            if ($label !== null) {
                $lines[] = $label.': '.implode(' | ', $values);
            }
        }

        return $lines === [] ? null : implode("\n", $lines);
    }

    /** @param array<string, mixed> $data */
    private function confirmation(array $data): ?string
    {
        $summary = $this->value($data['summary'] ?? null, 500);
        $status = $this->value($data['status'] ?? null, 80);

        if ($summary === null) {
            return null;
        }

        return match ($status) {
            'pending' => "Please confirm: {$summary}\nReply YES to confirm or NO to cancel.",
            'completed' => 'Confirmed: '.$summary,
            'cancelled' => 'Cancelled: '.$summary,
            default => $summary,
        };
    }

    /** @param array<string, mixed> $data */
    private function appointments(array $data): ?string
    {
        $lines = [];

        foreach (array_slice((array) ($data['slots'] ?? []), 0, 10) as $index => $slot) {
            if (! is_array($slot)) {
                continue;
            }

            $label = $this->value($slot['label'] ?? $slot['starts_at'] ?? null, 200);

            if ($label !== null) {
                $lines[] = ($index + 1).'. '.$label;
            }
        }

        return $lines === [] ? null : "Available times:\n".implode("\n", $lines);
    }

    private function value(mixed $value, int $limit): ?string
    {
        if (! is_scalar($value) || is_bool($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : Str::limit($value, $limit, '');
    }

    private function url(mixed $value): ?string
    {
        $value = $this->value($value, 2000);
        $scheme = is_string($value) ? strtolower((string) parse_url($value, PHP_URL_SCHEME)) : null;

        return $value !== null && in_array($scheme, ['http', 'https'], true) ? $value : null;
    }

    /** @return list<array<string, mixed>> */
    private function cardList(mixed $cards): array
    {
        if (! is_array($cards)) {
            return [];
        }

        $normalized = [];

        foreach ($cards as $card) {
            if (is_array($card)) {
                $normalized[] = $card;
            }
        }

        return $normalized;
    }
}
