(function () {
    'use strict';

    var script = document.currentScript;
    var bot = script && script.getAttribute('data-bot');

    if (!script || !bot || !/^[0-9a-f-]{36}$/i.test(bot)) {
        return;
    }

    var existingWidgets = document.querySelectorAll('[data-mamos-widget]');

    for (var index = 0; index < existingWidgets.length; index += 1) {
        if (existingWidgets[index].getAttribute('data-mamos-widget') === bot) {
            return;
        }
    }

    if (!document.body) {
        return;
    }

    var position = script.getAttribute('data-position') === 'bottom-left'
        ? 'left'
        : 'right';
    var host = document.createElement('div');
    host.setAttribute('data-mamos-widget', bot);
    var shadow = host.attachShadow({ mode: 'open' });
    var style = document.createElement('style');
    style.textContent = [
        ':host { all: initial; }',
        '.launcher, .frame { position: fixed; z-index: 2147483647; font-family: ui-sans-serif, system-ui, sans-serif; }',
        '.launcher { display: inline-flex; align-items: center; justify-content: center; width: 56px; height: 56px; gap: 0; bottom: 20px; ' + position + ': 20px; border: 0; border-radius: 999px; background: transparent; color: white; padding: 0; cursor: pointer; }',
        '.launcher-avatar { width: 56px; height: 56px; flex: 0 0 56px; border: 0; border-radius: 50%; object-fit: cover; background: linear-gradient(135deg, #8b5cf6, #e879f9); box-shadow: 0 8px 24px rgb(0 0 0 / 20%); }',
        '.launcher-avatar-fallback { display: inline-flex; align-items: center; justify-content: center; color: white; font-size: 13px; font-weight: 700; }',
        '.launcher-copy, .launcher-name, .launcher-subtitle { display: none; }',
        '.launcher-status { position: absolute; right: 0; bottom: 0; width: 11px; height: 11px; flex: 0 0 11px; border: 2px solid white; border-radius: 50%; background: #9ca3af; }',
        '.launcher-status.online { background: #22c55e; }',
        '.frame { display: none; bottom: 76px; ' + position + ': 20px; width: min(390px, calc(100vw - 32px)); height: min(620px, calc(100vh - 96px)); border: 0; border-radius: 18px; background: white; box-shadow: 0 12px 40px rgb(0 0 0 / 24%); }',
        '.frame.expanded { width: min(560px, calc(100vw - 32px)); height: min(760px, calc(100vh - 96px)); }',
        '@media (max-width: 640px) { .frame, .frame.expanded { top: 0; right: 0; bottom: auto; left: 0; width: 100vw; height: 100vh; height: 100dvh; max-height: 100dvh; border-radius: 0; } .launcher { bottom: max(12px, env(safe-area-inset-bottom)); ' + position + ': 12px; } }',
    ].join('');
    var launcher = document.createElement('button');
    launcher.className = 'launcher';
    launcher.type = 'button';
    launcher.setAttribute('aria-label', 'Open chat');
    launcher.style.display = 'none';

    var identity = {
        name: 'Assistant',
        subtitle: '',
        avatarUrl: '',
        availability: 'offline',
        launcherText: null,
        launcherMode: 'icon-text',
    };
    var renderLauncher = function (isOpen) {
        var label = identity.launcherText || identity.name || 'Chat';
        launcher.replaceChildren();
        launcher.setAttribute('aria-label', 'Open ' + label);
        launcher.style.display = isOpen ? 'none' : 'block';

        if (isOpen) {
            return;
        }

        var avatar = document.createElement('img');
        avatar.className = 'launcher-avatar';
        avatar.alt = '';
        avatar.src = identity.avatarUrl || 'data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs=';
        avatar.onerror = function () {
            var fallback = document.createElement('span');
            fallback.className = 'launcher-avatar launcher-avatar-fallback';
            fallback.textContent = (identity.name || 'A').trim().charAt(0).toUpperCase() || 'A';
            avatar.replaceWith(fallback);
        };
        launcher.appendChild(avatar);

        var status = document.createElement('span');
        status.className = 'launcher-status ' + (identity.availability === 'online' ? 'online' : '');
        status.setAttribute('aria-label', 'Assistant status: ' + (identity.availability === 'online' ? 'Online' : 'Offline'));
        launcher.appendChild(status);
    };
    var frame = document.createElement('iframe');
    frame.className = 'frame';
    frame.title = 'Chat with assistant';
    frame.referrerPolicy = 'origin';
    frame.src = new URL('/widget/' + encodeURIComponent(bot), script.src).toString();

    var widgetOrigin = new URL(script.src).origin;
    var handleWidgetResize = function (event) {
        if (event.origin !== widgetOrigin || event.source !== frame.contentWindow) {
            return;
        }

        if (!event.data || event.data.type !== 'mamos-widget-resize') {
            return;
        }

        frame.classList.toggle('expanded', event.data.expanded === true);
    };

    var handleWidgetMinimize = function (event) {
        if (event.origin !== widgetOrigin || event.source !== frame.contentWindow) {
            return;
        }

        if (!event.data || event.data.type !== 'mamos-widget-minimize') {
            return;
        }

        frame.style.display = 'none';
        renderLauncher(false);
    };

    window.addEventListener('message', handleWidgetResize);
    window.addEventListener('message', handleWidgetMinimize);
    var handleWidgetReady = function (event) {
        if (event.origin !== widgetOrigin || event.source !== frame.contentWindow) {
            return;
        }

        if (!event.data || event.data.type !== 'mamos-widget-ready' || event.data.bot !== bot) {
            return;
        }

        identity.name = event.data.name || identity.name;
        identity.subtitle = event.data.subtitle || '';
        identity.avatarUrl = event.data.avatarUrl || '';
        identity.availability = event.data.availability === 'online' ? 'online' : 'offline';
        identity.launcherText = event.data.launcherText || null;
        identity.launcherMode = ['icon-text', 'text-only', 'icon-only'].indexOf(event.data.launcherMode) >= 0
            ? event.data.launcherMode
            : 'icon-text';
        renderLauncher(frame.style.display === 'block');
    };

    window.addEventListener('message', handleWidgetReady);
    frame.addEventListener('load', function () {
        renderLauncher(frame.style.display === 'block');
    });

    launcher.addEventListener('click', function () {
        var isOpen = frame.style.display === 'block';
        frame.style.display = isOpen ? 'none' : 'block';
        renderLauncher(!isOpen);
    });

    shadow.append(style, launcher, frame);
    document.body.appendChild(host);
}());
