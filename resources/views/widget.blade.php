<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        @fonts
        @viteReactRefresh
        @vite(['resources/css/widget.css', 'resources/js/widget-frame.tsx'])
    </head>
    <body>
        <div
            id="widget-root"
            data-bot="{{ $bot['publicId'] }}"
            data-name="{{ $bot['name'] }}"
            data-welcome-message="{{ $bot['welcomeMessage'] }}"
            data-fallback-message="{{ $bot['fallbackMessage'] }}"
            data-assistant-subtitle="{{ $bot['appearance']['assistant_subtitle'] }}"
            data-avatar-url="{{ $bot['appearance']['avatar_url'] }}"
            data-launcher-text="{{ $bot['appearance']['launcher_text'] }}"
            data-launcher-mode="{{ $bot['appearance']['launcher_mode'] }}"
            data-availability="{{ $bot['availability'] }}"
            data-platform-name="{{ $bot['platformName'] }}"
            data-platform-url="{{ $bot['platformUrl'] }}"
            data-voice-input="{{ $bot['capabilities']['voice_input'] ? 'true' : 'false' }}"
        ></div>
    </body>
</html>
