<?php

namespace App\Services\Cards;

use App\Models\Bot;

class BotWidgetAppearance
{
    /**
     * Return only the safe, supported widget appearance values.
     *
     * @return array{title: string, input_placeholder: string, assistant_name: string, assistant_subtitle: string, avatar_url: string|null, launcher_text: string|null, launcher_mode: 'icon-text'|'text-only'|'icon-only', primary_color: string, accent_color: string, header_text_color: string, background_color: string, text_color: string, send_button_color: string, send_button_text_color: string, send_button_label: string, send_button_mode: 'icon-text'|'text-only'|'icon-only', send_button_icon: 'send'|'arrow-right'|'message', user_message_color: string, user_message_text_color: string, launcher_position: 'bottom-right'|'bottom-left'}
     */
    public function for(Bot $bot): array
    {
        $rawAppearance = $bot->getAttribute('appearance');
        $appearance = is_array($rawAppearance) ? $rawAppearance : [];
        $sendButtonColor = $this->color(
            $appearance['send_button_color'] ?? $appearance['primary_color'] ?? null,
            '#171717',
        );
        $sendButtonTextColor = $this->color($appearance['send_button_text_color'] ?? null, '#ffffff');

        return [
            'title' => $this->text($appearance['widget_title'] ?? null, $bot->name),
            'input_placeholder' => $this->text($appearance['input_placeholder'] ?? null, 'Type a message...'),
            'assistant_name' => $this->text(
                $appearance['assistant_display_name'] ?? null,
                $this->text($appearance['widget_title'] ?? null, $bot->name),
            ),
            'assistant_subtitle' => $this->text($appearance['assistant_subtitle'] ?? null, 'AI Assistant'),
            'avatar_url' => $this->url(
                $appearance['assistant_avatar_url'] ?? null,
                $appearance['assistant_avatar_path'] ?? null,
            ),
            'launcher_text' => $this->optionalText($appearance['launcher_text'] ?? null),
            'launcher_mode' => in_array($appearance['launcher_mode'] ?? null, ['icon-text', 'text-only', 'icon-only'], true)
                ? $appearance['launcher_mode']
                : 'icon-text',
            'primary_color' => $sendButtonColor,
            'accent_color' => $this->color($appearance['accent_color'] ?? null, '#f5f5f5'),
            'header_text_color' => $this->color($appearance['header_text_color'] ?? null, '#171717'),
            'background_color' => $this->color($appearance['background_color'] ?? null, '#ffffff'),
            'text_color' => $this->color($appearance['text_color'] ?? null, '#171717'),
            'send_button_color' => $sendButtonColor,
            'send_button_text_color' => $sendButtonTextColor,
            'send_button_label' => $this->text($appearance['send_button_label'] ?? null, 'Send'),
            'send_button_mode' => in_array($appearance['send_button_mode'] ?? null, ['icon-text', 'text-only', 'icon-only'], true)
                ? $appearance['send_button_mode']
                : 'icon-text',
            'send_button_icon' => in_array($appearance['send_button_icon'] ?? null, ['send', 'arrow-right', 'message'], true)
                ? $appearance['send_button_icon']
                : 'send',
            'user_message_color' => $this->color($appearance['user_message_color'] ?? null, '#171717'),
            'user_message_text_color' => $this->color($appearance['user_message_text_color'] ?? null, '#ffffff'),
            'launcher_position' => ($appearance['launcher_position'] ?? 'bottom-right') === 'bottom-left'
                ? 'bottom-left'
                : 'bottom-right',
        ];
    }

    private function text(mixed $value, string $fallback): string
    {
        if (! is_string($value) || trim($value) === '') {
            return $fallback;
        }

        return trim(mb_substr($value, 0, 120));
    }

    private function color(mixed $value, string $fallback): string
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1
            ? strtolower($value)
            : $fallback;
    }

    private function optionalText(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== ''
            ? trim(mb_substr($value, 0, 80))
            : null;
    }

    private function url(mixed $value, mixed $path = null): ?string
    {
        if (is_string($value) && trim($value) !== '') {
            $value = trim($value);

            if (str_starts_with($value, '/storage/bot-avatars/')) {
                return $value;
            }

            $scheme = parse_url($value, PHP_URL_SCHEME);

            if (in_array(strtolower((string) $scheme), ['http', 'https'], true)) {
                return $value;
            }
        }

        if (is_string($path) && preg_match('#^bot-avatars/[^/]+$#', $path) === 1) {
            return '/storage/'.$path;
        }

        return null;
    }
}
