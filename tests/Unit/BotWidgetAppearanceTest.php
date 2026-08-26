<?php

use App\Models\Bot;
use App\Services\Cards\BotWidgetAppearance;

test('normalizes widget appearance colors and preserves the legacy primary color', function () {
    $bot = new Bot;
    $bot->name = 'Mamos Bot';
    $bot->setAttribute('appearance', [
        'widget_title' => 'Shop assistant',
        'background_color' => '#FAFAFA',
        'text_color' => '#111111',
        'send_button_color' => '#112233',
        'user_message_color' => '#445566',
        'user_message_text_color' => '#FFFFFF',
        'accent_color' => '#EFEFEF',
    ]);

    expect(app(BotWidgetAppearance::class)->for($bot))->toMatchArray([
        'title' => 'Shop assistant',
        'background_color' => '#fafafa',
        'text_color' => '#111111',
        'primary_color' => '#112233',
        'send_button_color' => '#112233',
        'user_message_color' => '#445566',
        'user_message_text_color' => '#ffffff',
    ]);
});

test('uses the legacy primary color when a send button color is not stored', function () {
    $bot = new Bot;
    $bot->name = 'Mamos Bot';
    $bot->setAttribute('appearance', ['primary_color' => '#123456']);

    expect(app(BotWidgetAppearance::class)->for($bot)['send_button_color'])
        ->toBe('#123456');
});

test('keeps public assistant identity separate from the internal bot name', function () {
    $bot = new Bot;
    $bot->name = 'Store Assistant Production';
    $bot->setAttribute('appearance', [
        'widget_title' => 'GoParts',
        'assistant_display_name' => 'Ana',
        'assistant_subtitle' => 'AI Shopping Assistant',
        'assistant_avatar_url' => 'https://example.test/storage/ana.webp',
    ]);

    expect(app(BotWidgetAppearance::class)->for($bot))->toMatchArray([
        'title' => 'GoParts',
        'assistant_name' => 'Ana',
        'assistant_subtitle' => 'AI Shopping Assistant',
        'avatar_url' => 'https://example.test/storage/ana.webp',
    ])
        ->and(app(BotWidgetAppearance::class)->for($bot)['assistant_name'])
        ->not->toBe($bot->name);
});

test('normalizes stored uploaded avatars to a same-origin storage URL', function () {
    $bot = new Bot;
    $bot->name = 'Mamos Bot';
    $bot->setAttribute('appearance', [
        'assistant_avatar_path' => 'bot-avatars/assistant.webp',
    ]);

    expect(app(BotWidgetAppearance::class)->for($bot)['avatar_url'])
        ->toBe('/storage/bot-avatars/assistant.webp');
});

test('preserves a separate header text color', function () {
    $bot = new Bot;
    $bot->name = 'Mamos Bot';
    $bot->setAttribute('appearance', [
        'accent_color' => '#004880',
        'header_text_color' => '#ffffff',
    ]);

    expect(app(BotWidgetAppearance::class)->for($bot))->toMatchArray([
        'accent_color' => '#004880',
        'header_text_color' => '#ffffff',
    ]);
});

test('example', function () {
    expect(true)->toBeTrue();
});
