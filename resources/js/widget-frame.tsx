import {
    ArrowDown,
    ArrowRight,
    Maximize2,
    Mic,
    MessageCircle,
    Minimize2,
    Minus,
    Square,
    SendHorizontal,
    X,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { AppointmentSelectionError } from '@/components/appointment-slots-block';
import type {
    AppointmentSelectionResult,
    AppointmentSlotsAction,
} from '@/components/appointment-slots-block';
import { ConversationBlockRenderer } from '@/components/conversation-block-renderer';
import { FormSubmissionError } from '@/components/form-block';
import type {
    FormBlockAction,
    FormSubmissionResult,
} from '@/components/form-block';
import { useVoiceRecorder } from '@/hooks/use-voice-recorder';
import {
    messages as messagesRoute,
    session,
    status as statusRoute,
} from '@/routes/widget';
import { transcribe as transcribeVoice } from '@/routes/widget';
import { cancel, confirm } from '@/routes/widget/actions';
import { select as selectAppointment } from '@/routes/widget/appointments';
import { submit as submitForm } from '@/routes/widget/forms';
import { poll as messagesPoll } from '@/routes/widget/messages';
import type {
    BotWidgetAppearance,
    ConversationBlock,
    ProductCard,
    WidgetAvailability,
} from '@/types';
import { visualMessageMeta } from './widget-grouping';

type ChatMessage = {
    role: 'user' | 'assistant' | 'system';
    content: string | null;
    created_at?: string | null;
    blocks?: ConversationBlock[];
    source?: 'human' | 'system' | null;
    sender?: string | null;
    cards?: ProductCard[];
};

type SessionPayload = {
    conversation_id: string;
    visitor_id: string;
    bot: {
        name: string;
        welcome_message: string | null;
        fallback_message: string | null;
        appearance: BotWidgetAppearance;
        capabilities: {
            voice_input: boolean;
        };
        availability: WidgetAvailability;
        platform_name: string;
        platform_url: string;
    };
    messages: ChatMessage[];
    handoff_status: 'ai' | 'requested' | 'human';
    next_after_message_id: number | null;
    availability: WidgetAvailability;
};

type PollPayload = {
    messages: ChatMessage[];
    handoff_status: 'ai' | 'requested' | 'human';
    next_after_message_id: number | null;
};

const rootElement = document.getElementById('widget-root');

if (rootElement) {
    createRoot(rootElement).render(
        <WidgetFrame
            botId={rootElement.dataset.bot ?? ''}
            initialName={rootElement.dataset.name ?? 'Assistant'}
            initialWelcome={rootElement.dataset.welcomeMessage ?? ''}
            initialFallback={rootElement.dataset.fallbackMessage ?? ''}
            initialAssistantSubtitle={
                rootElement.dataset.assistantSubtitle ?? 'AI Assistant'
            }
            initialAvatarUrl={rootElement.dataset.avatarUrl || null}
            initialAvailability={
                rootElement.dataset.availability === 'offline'
                    ? 'offline'
                    : 'online'
            }
            initialPlatformName={
                rootElement.dataset.platformName ?? 'AI Assistant'
            }
            initialPlatformUrl={
                rootElement.dataset.platformUrl ?? window.location.origin
            }
            initialLauncherText={rootElement.dataset.launcherText || null}
            initialLauncherMode={
                rootElement.dataset.launcherMode === 'text-only' ||
                rootElement.dataset.launcherMode === 'icon-only'
                    ? rootElement.dataset.launcherMode
                    : 'icon-text'
            }
            initialVoiceInputAvailable={
                rootElement.dataset.voiceInput !== 'false'
            }
        />,
    );
}

function WidgetFrame({
    botId,
    initialName,
    initialWelcome,
    initialFallback,
    initialAssistantSubtitle,
    initialAvatarUrl,
    initialAvailability,
    initialPlatformName,
    initialPlatformUrl,
    initialLauncherText,
    initialLauncherMode,
    initialVoiceInputAvailable,
}: {
    botId: string;
    initialName: string;
    initialWelcome: string;
    initialFallback: string;
    initialAssistantSubtitle: string;
    initialAvatarUrl: string | null;
    initialAvailability: WidgetAvailability;
    initialPlatformName: string;
    initialPlatformUrl: string;
    initialLauncherText: string | null;
    initialLauncherMode: 'icon-text' | 'text-only' | 'icon-only';
    initialVoiceInputAvailable: boolean;
}) {
    const [welcome, setWelcome] = useState(
        initialWelcome || 'Hello! How can I help?',
    );
    const [fallback, setFallback] = useState(
        initialFallback || 'Something went wrong. Please try again.',
    );
    const [appearance, setAppearance] = useState<BotWidgetAppearance>({
        title: initialName || 'Assistant',
        input_placeholder: 'Type a message...',
        primary_color: '#171717',
        accent_color: '#f5f5f5',
        header_text_color: '#171717',
        background_color: '#ffffff',
        text_color: '#171717',
        send_button_color: '#171717',
        send_button_text_color: '#ffffff',
        send_button_label: 'Send',
        send_button_mode: 'icon-text',
        send_button_icon: 'send',
        user_message_color: '#171717',
        user_message_text_color: '#ffffff',
        launcher_position: 'bottom-right',
        assistant_name: initialName || 'AI Assistant',
        assistant_subtitle: initialAssistantSubtitle,
        avatar_url: initialAvatarUrl,
        launcher_text: initialLauncherText,
        launcher_mode: initialLauncherMode,
    });
    const [visitorId, setVisitorId] = useState<string | null>(null);
    const [conversationId, setConversationId] = useState<string | null>(null);
    const [handoffStatus, setHandoffStatus] = useState<
        'ai' | 'requested' | 'human'
    >('ai');
    const [afterMessageId, setAfterMessageId] = useState<number | null>(null);
    const [chatMessages, setChatMessages] = useState<ChatMessage[]>([]);
    const [showWelcome, setShowWelcome] = useState(false);
    const [value, setValue] = useState('');
    const [composerRevision, setComposerRevision] = useState(0);
    const [loading, setLoading] = useState(true);
    const [sending, setSending] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [availability, setAvailability] =
        useState<WidgetAvailability>(initialAvailability);
    const [platformName, setPlatformName] = useState(initialPlatformName);
    const [platformUrl, setPlatformUrl] = useState(initialPlatformUrl);
    const [voiceInputAvailable, setVoiceInputAvailable] = useState(
        initialVoiceInputAvailable,
    );
    const [expanded, setExpanded] = useState(false);
    const [showLatest, setShowLatest] = useState(false);
    const messagesRef = useRef<HTMLDivElement>(null);

    const transcribeRecording = useCallback(
        async (blob: Blob, signal: AbortSignal): Promise<string> => {
            const formData = new FormData();
            const extension = blob.type.includes('ogg')
                ? 'ogg'
                : blob.type.includes('mp4')
                  ? 'mp4'
                  : 'webm';
            formData.append('audio', blob, `recording.${extension}`);

            const response = await fetch(transcribeVoice.url(botId), {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-Widget-Origin': parentOrigin(),
                },
                body: formData,
                signal,
            });
            const payload = (await response.json()) as {
                text?: string;
                message?: string;
            };

            if (!response.ok || !payload.text?.trim()) {
                throw new Error(
                    payload.message ||
                        'We could not transcribe that recording. Please try again or type your message.',
                );
            }

            return payload.text.trim();
        },
        [botId],
    );

    const voiceRecorder = useVoiceRecorder({
        transcribe: transcribeRecording,
        composerRevision,
        onTranscript: (transcript, revision) => {
            setValue((current) =>
                composerRevision === revision
                    ? transcript
                    : current.trim()
                      ? `${current.trim()}\n${transcript}`
                      : transcript,
            );
            setComposerRevision((current) => current + 1);
        },
    });

    const openSession = useCallback(
        async (newConversation = false) => {
            setLoading(true);
            setError(null);

            try {
                const storedVisitor = localStorage.getItem(
                    visitorStorageKey(botId),
                );
                const storedConversation = newConversation
                    ? null
                    : localStorage.getItem(conversationStorageKey(botId));
                const response = await fetch(session.url(botId), {
                    method: 'POST',
                    headers: widgetHeaders(),
                    body: JSON.stringify({
                        visitor_id: storedVisitor,
                        conversation_id: storedConversation,
                        new_conversation: newConversation,
                    }),
                });
                const payload = (await response.json()) as SessionPayload;

                if (!response.ok) {
                    setAvailability('offline');

                    throw new Error(
                        'This assistant is temporarily unavailable.',
                    );
                }

                setWelcome(
                    payload.bot.welcome_message || 'Hello! How can I help?',
                );
                setFallback(
                    payload.bot.fallback_message ||
                        'Something went wrong. Please try again.',
                );
                setAppearance(payload.bot.appearance);
                setAvailability(payload.bot.availability);
                setPlatformName(payload.bot.platform_name);
                setPlatformUrl(payload.bot.platform_url);
                setVoiceInputAvailable(
                    payload.bot.capabilities?.voice_input ?? false,
                );

                if (window.parent !== window) {
                    window.parent.postMessage(
                        {
                            type: 'mamos-widget-ready',
                            bot: botId,
                            name: payload.bot.name,
                            subtitle: payload.bot.appearance.assistant_subtitle,
                            avatarUrl: payload.bot.appearance.avatar_url,
                            availability: payload.bot.availability,
                            launcherText: payload.bot.appearance.launcher_text,
                            launcherMode: payload.bot.appearance.launcher_mode,
                        },
                        parentOrigin() || '*',
                    );
                }

                setVisitorId(payload.visitor_id);
                setConversationId(payload.conversation_id);
                setHandoffStatus(payload.handoff_status);
                setAfterMessageId(payload.next_after_message_id);
                setChatMessages(payload.messages ?? []);
                setShowWelcome((payload.messages ?? []).length === 0);
                localStorage.setItem(
                    visitorStorageKey(botId),
                    payload.visitor_id,
                );
                localStorage.setItem(
                    conversationStorageKey(botId),
                    payload.conversation_id,
                );
            } catch (exception) {
                setError(
                    exception instanceof Error
                        ? exception.message
                        : 'This widget is not available on this website.',
                );
            } finally {
                setLoading(false);
            }
        },
        [botId],
    );

    const refreshAvailability = useCallback(async () => {
        try {
            const response = await fetch(statusRoute.url(botId), {
                headers: widgetHeaders(),
            });

            if (!response.ok) {
                setAvailability('offline');

                return;
            }

            const payload = (await response.json()) as {
                availability?: WidgetAvailability;
            };
            const nextAvailability =
                payload.availability === 'online' ? 'online' : 'offline';
            setAvailability(nextAvailability);

            if (nextAvailability === 'online') {
                setError(null);

                if (visitorId === null || conversationId === null) {
                    void openSession();
                }
            }
        } catch {
            setAvailability('offline');
        }
    }, [botId, conversationId, openSession, visitorId]);

    useEffect(() => {
        if (window.parent !== window) {
            const targetOrigin = parentOrigin() || '*';

            window.parent.postMessage(
                {
                    type: 'mamos-widget-ready',
                    bot: botId,
                    name: initialName,
                    subtitle: initialAssistantSubtitle,
                    avatarUrl: initialAvatarUrl,
                    availability: initialAvailability,
                    launcherText: initialLauncherText,
                    launcherMode: initialLauncherMode,
                },
                targetOrigin,
            );
        }

        const timer = window.setTimeout(() => void openSession(), 0);

        return () => window.clearTimeout(timer);
    }, [
        botId,
        initialAssistantSubtitle,
        initialAvailability,
        initialAvatarUrl,
        initialLauncherMode,
        initialLauncherText,
        initialName,
        openSession,
    ]);

    useEffect(() => {
        const refresh = () => {
            if (document.visibilityState === 'visible') {
                void refreshAvailability();
            }
        };
        const timer = window.setInterval(refresh, 45_000);
        document.addEventListener('visibilitychange', refresh);

        return () => {
            window.clearInterval(timer);
            document.removeEventListener('visibilitychange', refresh);
        };
    }, [refreshAvailability]);

    useEffect(() => {
        if (
            handoffStatus === 'ai' ||
            visitorId === null ||
            conversationId === null
        ) {
            return;
        }

        let cancelled = false;

        const poll = async () => {
            try {
                const response = await fetch(
                    messagesPoll.url(botId, {
                        query: {
                            visitor_id: visitorId,
                            conversation_id: conversationId,
                            after_message_id: afterMessageId,
                        },
                    }),
                    { headers: widgetHeaders() },
                );
                const payload = (await response.json()) as PollPayload;

                if (!response.ok || cancelled) {
                    return;
                }

                setHandoffStatus(payload.handoff_status);
                setAfterMessageId(payload.next_after_message_id);

                if (payload.messages.length > 0) {
                    setChatMessages((current) => [
                        ...current,
                        ...payload.messages,
                    ]);
                }
            } catch {
                // Polling is best-effort; the existing conversation remains usable.
            }
        };

        void poll();
        const timer = window.setInterval(() => void poll(), 3000);

        return () => {
            cancelled = true;
            window.clearInterval(timer);
        };
    }, [afterMessageId, conversationId, handoffStatus, visitorId, botId]);

    async function send() {
        const message = value.trim();

        if (
            !message ||
            sending ||
            visitorId === null ||
            conversationId === null ||
            availability === 'offline'
        ) {
            return;
        }

        setSending(true);
        setError(null);
        setValue('');
        setComposerRevision((current) => current + 1);
        const pendingWelcome = showWelcome
            ? {
                  role: 'assistant' as const,
                  content: welcome,
                  created_at: new Date().toISOString(),
                  blocks: [],
                  sender: `${appearance.assistant_name} · ${appearance.assistant_subtitle}`,
              }
            : null;
        setShowWelcome(false);

        try {
            const response = await fetch(messagesRoute.url(botId), {
                method: 'POST',
                headers: widgetHeaders(),
                body: JSON.stringify({
                    visitor_id: visitorId,
                    conversation_id: conversationId,
                    message,
                }),
            });
            const payload = await response.json();

            if (!response.ok) {
                setAvailability('offline');
                setChatMessages((current) => [
                    ...(pendingWelcome ? [pendingWelcome] : []),
                    ...current,
                    {
                        role: 'user',
                        content: message,
                        created_at: new Date().toISOString(),
                    },
                    {
                        role: 'assistant',
                        content: payload.message || fallback,
                        blocks: [],
                        created_at: new Date().toISOString(),
                    },
                ]);

                return;
            }

            setHandoffStatus(payload.handoff_status ?? 'ai');
            setAfterMessageId(payload.next_after_message_id ?? afterMessageId);
            setAvailability('online');
            setChatMessages((current) => [
                ...(pendingWelcome ? [pendingWelcome] : []),
                ...current,
                payload.user_message,
                payload.message,
            ]);
        } catch {
            setAvailability('offline');
            setChatMessages((current) => [
                ...(pendingWelcome ? [pendingWelcome] : []),
                ...current,
                {
                    role: 'user',
                    content: message,
                    created_at: new Date().toISOString(),
                },
                { role: 'assistant', content: fallback, blocks: [] },
            ]);
        } finally {
            setSending(false);
        }
    }

    async function handleBlockAction(
        actionReference: string,
        action: 'confirm' | 'cancel',
    ): Promise<Extract<ConversationBlock, { type: 'confirmation' }>> {
        if (visitorId === null || conversationId === null) {
            throw new Error('The widget conversation is not available.');
        }

        const actionRoute = action === 'confirm' ? confirm : cancel;
        const response = await fetch(
            actionRoute.url([botId, actionReference]),
            {
                method: 'POST',
                headers: widgetHeaders(),
                body: JSON.stringify({
                    visitor_id: visitorId,
                    conversation_id: conversationId,
                }),
            },
        );
        const payload = (await response.json()) as {
            block?: Extract<ConversationBlock, { type: 'confirmation' }>;
            message?: string;
        };

        if (payload.block) {
            return payload.block;
        }

        throw new Error(payload.message ?? 'The action could not be updated.');
    }

    const handleFormSubmit: FormBlockAction = async (
        formReference,
        values,
    ): Promise<FormSubmissionResult> => {
        if (visitorId === null || conversationId === null) {
            throw new Error('The widget conversation is not available.');
        }

        const response = await fetch(submitForm.url([botId, formReference]), {
            method: 'POST',
            headers: widgetHeaders(),
            body: JSON.stringify({
                visitor_id: visitorId,
                conversation_id: conversationId,
                values,
            }),
        });
        const payload = (await response.json()) as FormResponsePayload;

        if (!response.ok) {
            throw new FormSubmissionError(
                typeof payload.message === 'string'
                    ? payload.message
                    : 'Could not submit these details.',
                normalizeErrors(payload.errors),
            );
        }

        if (
            payload.user_message &&
            typeof payload.message !== 'string' &&
            payload.message
        ) {
            setChatMessages((current) => [
                ...current,
                payload.user_message as ChatMessage,
                payload.message as ChatMessage,
            ]);
        }

        if (!payload.form_block) {
            throw new Error('The submitted form could not be updated.');
        }

        return { block: payload.form_block };
    };

    const handleAppointmentSelect: AppointmentSlotsAction = async (
        appointmentReference,
        slotReference,
    ): Promise<AppointmentSelectionResult> => {
        if (visitorId === null || conversationId === null) {
            throw new Error('The widget conversation is not available.');
        }

        const response = await fetch(
            selectAppointment.url([botId, appointmentReference]),
            {
                method: 'POST',
                headers: widgetHeaders(),
                body: JSON.stringify({
                    visitor_id: visitorId,
                    conversation_id: conversationId,
                    slot_reference: slotReference,
                }),
            },
        );
        const payload = (await response.json()) as AppointmentResponsePayload;

        if (!response.ok) {
            throw new AppointmentSelectionError(
                typeof payload.message === 'string'
                    ? payload.message
                    : 'Could not select this appointment time.',
                payload.appointment_block,
            );
        }

        if (
            payload.user_message &&
            typeof payload.message !== 'string' &&
            payload.message
        ) {
            setChatMessages((current) => [
                ...current,
                payload.user_message as ChatMessage,
                payload.message as ChatMessage,
            ]);
        }

        if (!payload.appointment_block) {
            throw new Error('The selected appointment could not be updated.');
        }

        return { block: payload.appointment_block };
    };

    function toggleExpanded() {
        const nextExpanded = !expanded;
        setExpanded(nextExpanded);

        if (window.parent !== window) {
            window.parent.postMessage(
                {
                    type: 'mamos-widget-resize',
                    expanded: nextExpanded,
                },
                parentOrigin() || '*',
            );
        }
    }

    function minimize() {
        if (window.parent !== window) {
            window.parent.postMessage(
                { type: 'mamos-widget-minimize' },
                parentOrigin() || '*',
            );
        }
    }

    function scrollToLatest() {
        messagesRef.current?.scrollTo({
            top: messagesRef.current.scrollHeight,
            behavior: 'smooth',
        });
        setShowLatest(false);
    }

    useEffect(() => {
        const element = messagesRef.current;

        if (!element || showLatest) {
            return;
        }

        element.scrollTo({ top: element.scrollHeight, behavior: 'smooth' });
    }, [chatMessages.length, sending, showLatest]);

    if (loading) {
        return <p className="p-5 text-sm text-neutral-500">Loading…</p>;
    }

    return (
        <main
            className="flex h-screen max-h-screen min-h-0 flex-col overflow-hidden bg-white"
            style={{
                backgroundColor: appearance.background_color,
                color: appearance.text_color,
            }}
        >
            <header
                className="relative z-20 flex flex-none flex-col gap-3 border-b border-neutral-200/70 px-4 py-3.5 shadow-[0_1px_10px_rgb(15_23_42/4%)] backdrop-blur"
                style={{
                    backgroundColor: appearance.accent_color,
                    color: appearance.header_text_color,
                }}
            >
                <div className="flex items-center justify-between gap-3">
                    <div className="flex min-w-0 items-center gap-2">
                        <div className="flex size-9 shrink-0 items-center justify-center rounded-full bg-neutral-950 text-xs font-semibold text-white">
                            {platformName.trim().charAt(0).toUpperCase() || 'A'}
                        </div>
                        <div className="min-w-0">
                            <a
                                href={platformUrl}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="truncate text-xs text-inherit hover:underline"
                            >
                                Built with{' '}
                                <span className="font-semibold">
                                    {platformName}
                                </span>
                            </a>
                        </div>
                    </div>
                    <div className="flex items-center gap-1">
                        <button
                            type="button"
                            className="rounded-full p-2 text-inherit transition hover:bg-black/5"
                            onClick={minimize}
                            aria-label="Minimize chat"
                            title="Minimize chat"
                        >
                            <Minus className="size-4" />
                        </button>
                        <button
                            type="button"
                            className="rounded-full p-2 text-inherit transition hover:bg-black/5"
                            onClick={toggleExpanded}
                            aria-label={
                                expanded ? 'Restore chat size' : 'Expand chat'
                            }
                            aria-pressed={expanded}
                            title={
                                expanded ? 'Restore chat size' : 'Expand chat'
                            }
                        >
                            {expanded ? (
                                <Minimize2 className="size-4" />
                            ) : (
                                <Maximize2 className="size-4" />
                            )}
                        </button>
                        <button
                            type="button"
                            className="rounded-md px-2 py-1 text-xs text-inherit transition hover:bg-black/5"
                            onClick={() => void openSession(true)}
                            aria-label="Start a new chat"
                        >
                            New chat
                        </button>
                    </div>
                </div>
            </header>
            <div
                ref={messagesRef}
                className="relative flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto px-4 py-5"
                onScroll={(event) => {
                    const element = event.currentTarget;
                    setShowLatest(
                        element.scrollHeight -
                            element.scrollTop -
                            element.clientHeight >
                            80,
                    );
                }}
            >
                {handoffStatus !== 'ai' ? (
                    <p className="rounded-2xl bg-amber-50 p-3 text-sm text-amber-900">
                        {handoffStatus === 'human'
                            ? "You're chatting with the team."
                            : 'Waiting for a team member to join…'}
                    </p>
                ) : null}
                {error ? (
                    <p className="rounded-2xl bg-red-50 p-3 text-sm text-red-700">
                        {error}
                    </p>
                ) : null}
                {availability === 'offline' && !error ? (
                    <p className="rounded-2xl bg-neutral-100 p-3 text-sm text-neutral-600">
                        This assistant is temporarily unavailable. Please try
                        again later.
                    </p>
                ) : null}
                {showWelcome ? (
                    <AssistantMessage
                        content={welcome}
                        appearance={appearance}
                        online={availability === 'online'}
                        sender={`${appearance.assistant_name} · ${appearance.assistant_subtitle}`}
                    />
                ) : null}
                {chatMessages.map((item, index) => {
                    const meta = visualMessageMeta(chatMessages, index);
                    const previousMeta =
                        index > 0
                            ? visualMessageMeta(chatMessages, index - 1)
                            : null;
                    const showDate =
                        meta.dateLabel !== null &&
                        meta.dateLabel !== previousMeta?.dateLabel;

                    return (
                        <div
                            key={item.role + '-' + index}
                            className={meta.groupStart ? 'mt-2' : '-mt-2'}
                        >
                            {showDate ? (
                                <p className="mb-2 text-center text-[11px] font-medium text-neutral-400">
                                    {meta.dateLabel}
                                </p>
                            ) : null}
                            <MessageRow
                                item={item}
                                appearance={appearance}
                                availability={availability}
                                meta={meta}
                                onAction={handleBlockAction}
                                onFormSubmit={handleFormSubmit}
                                onAppointmentSelect={handleAppointmentSelect}
                            />
                        </div>
                    );
                })}
                {sending ? (
                    <div className="flex items-center gap-2 text-sm text-neutral-500">
                        <AssistantAvatar
                            name={appearance.assistant_name}
                            src={appearance.avatar_url}
                            online={availability === 'online'}
                            className="size-7"
                        />
                        <span className="rounded-2xl bg-neutral-100 px-3 py-2">
                            {handoffStatus === 'ai' ? 'Thinking…' : 'Sending…'}
                        </span>
                    </div>
                ) : null}
                {showLatest ? (
                    <button
                        type="button"
                        onClick={scrollToLatest}
                        className="sticky bottom-2 self-end rounded-full border border-neutral-200/80 bg-white/95 p-2 text-neutral-600 shadow-[0_4px_14px_rgb(15_23_42/12%)]"
                        aria-label="Scroll to latest messages"
                    >
                        <ArrowDown className="size-4" />
                    </button>
                ) : null}
            </div>
            <form
                className="flex shrink-0 items-center gap-2 border-t border-neutral-200/70 bg-white/80 p-3 backdrop-blur"
                style={{ backgroundColor: appearance.background_color }}
                onSubmit={(event) => {
                    event.preventDefault();
                    void send();
                }}
            >
                <div className="flex shrink-0 items-center gap-1">
                    {voiceInputAvailable && voiceRecorder.state === 'recording' ? (
                        <>
                            <button
                                type="button"
                                onClick={voiceRecorder.stop}
                                className="flex size-11 items-center justify-center rounded-full border border-red-200 bg-red-50 text-red-600 transition hover:bg-red-100"
                                aria-label="Stop recording"
                                title={`Stop recording (${formatRecordingTime(voiceRecorder.elapsedSeconds)})`}
                            >
                                <Square className="size-4 fill-current" />
                            </button>
                            <span
                                className="min-w-10 text-center text-xs text-red-600 tabular-nums"
                                aria-live="polite"
                            >
                                {formatRecordingTime(
                                    voiceRecorder.elapsedSeconds,
                                )}
                            </span>
                            <button
                                type="button"
                                onClick={voiceRecorder.cancel}
                                className="flex size-11 items-center justify-center rounded-full border border-neutral-200/90 text-neutral-500 transition hover:border-red-200 hover:text-red-600"
                                aria-label="Cancel recording"
                                title="Cancel recording"
                            >
                                <X className="size-4" />
                            </button>
                        </>
                    ) : voiceInputAvailable ? (
                        <button
                            type="button"
                            onClick={() => void voiceRecorder.start()}
                            disabled={
                                voiceRecorder.state ===
                                    'requesting_permission' ||
                                voiceRecorder.state === 'uploading' ||
                                voiceRecorder.state === 'transcribing' ||
                                sending ||
                                error !== null ||
                                availability === 'offline'
                            }
                            className="flex size-11 shrink-0 items-center justify-center rounded-full border border-neutral-200/90 text-neutral-600 transition hover:border-violet-300 hover:text-violet-600 disabled:cursor-not-allowed disabled:opacity-50"
                            aria-label={
                                voiceRecorder.state === 'transcribing'
                                    ? 'Transcribing recording'
                                    : 'Record voice message'
                            }
                            title="Record voice message"
                        >
                            {voiceRecorder.state === 'requesting_permission' ||
                            voiceRecorder.state === 'uploading' ||
                            voiceRecorder.state === 'transcribing' ? (
                                <span
                                    className="size-4 animate-spin rounded-full border-2 border-current border-t-transparent"
                                    aria-hidden="true"
                                />
                            ) : (
                                <Mic className="size-4" />
                            )}
                        </button>
                    ) : null}
                </div>
                <div className="flex min-w-0 flex-1 items-center gap-2 rounded-full border border-neutral-200/90 bg-white/95 px-3 py-1 transition focus-within:border-violet-300 focus-within:ring-1 focus-within:ring-violet-200">
                    <input
                        value={value}
                        onChange={(event) => {
                            setValue(event.target.value);
                            setComposerRevision((current) => current + 1);
                        }}
                        className="min-w-0 flex-1 border-0 bg-transparent px-1 py-2.5 text-sm outline-none placeholder:text-neutral-400 focus:ring-0"
                        style={{ color: appearance.text_color }}
                        placeholder={
                            appearance.input_placeholder || 'Ask me anything'
                        }
                        aria-label="Message"
                        maxLength={4000}
                        disabled={
                            sending ||
                            error !== null ||
                            availability === 'offline'
                        }
                    />
                </div>
                <button
                    type="submit"
                    className={`flex min-h-11 shrink-0 items-center justify-center gap-1.5 rounded-full border border-white/70 shadow-[0_5px_14px_rgb(15_23_42/16%)] transition hover:-translate-y-0.5 hover:opacity-90 disabled:translate-y-0 disabled:opacity-50 ${appearance.send_button_mode === 'icon-only' ? 'w-11' : 'px-4'}`}
                    style={{
                        backgroundColor: appearance.send_button_color,
                        color: appearance.send_button_text_color,
                    }}
                    disabled={
                        sending ||
                        value.trim() === '' ||
                        error !== null ||
                        availability === 'offline'
                    }
                    aria-label="Send message"
                >
                    {appearance.send_button_mode !== 'text-only' ? (
                        <SendButtonIcon icon={appearance.send_button_icon} />
                    ) : null}
                    {appearance.send_button_mode !== 'icon-only' ? (
                        <span>{appearance.send_button_label}</span>
                    ) : null}
                </button>
            </form>
            {voiceRecorder.state === 'error' && voiceRecorder.error ? (
                <p
                    className="shrink-0 px-4 pb-2 text-xs text-red-600"
                    role="alert"
                >
                    {voiceRecorder.error}
                </p>
            ) : null}
        </main>
    );
}

function SendButtonIcon({
    icon,
}: {
    icon: BotWidgetAppearance['send_button_icon'];
}) {
    if (icon === 'arrow-right') {
        return <ArrowRight className="size-4" />;
    }

    if (icon === 'message') {
        return <MessageCircle className="size-4" />;
    }

    return <SendHorizontal className="size-4" />;
}

function MessageRow({
    item,
    appearance,
    availability,
    meta,
    onAction,
    onFormSubmit,
    onAppointmentSelect,
}: {
    item: ChatMessage;
    appearance: BotWidgetAppearance;
    availability: WidgetAvailability;
    meta: ReturnType<typeof visualMessageMeta>;
    onAction: (
        actionReference: string,
        action: 'confirm' | 'cancel',
    ) => Promise<Extract<ConversationBlock, { type: 'confirmation' }>>;
    onFormSubmit: FormBlockAction;
    onAppointmentSelect: AppointmentSlotsAction;
}) {
    if (item.role === 'user') {
        return (
            <div className="ml-auto flex w-fit max-w-[88%] flex-col items-end">
                <div
                    className="flex w-fit max-w-full items-center rounded-2xl rounded-br-md border border-neutral-200/70 px-4 py-2.5 text-sm shadow-none"
                    style={{
                        backgroundColor: appearance.user_message_color,
                        color: appearance.user_message_text_color,
                    }}
                >
                    <p className="break-words whitespace-pre-wrap">
                        {item.content}
                    </p>
                </div>
                {meta.groupEnd ? <MessageTimestamp meta={meta} /> : null}
            </div>
        );
    }

    if (item.role === 'system') {
        return (
            <p className="mx-auto max-w-[90%] rounded-2xl bg-neutral-100 px-3 py-2 text-center text-xs text-neutral-500">
                {item.content}
                {meta.groupEnd ? <MessageTimestamp meta={meta} /> : null}
            </p>
        );
    }

    const productBlocks = (item.blocks ?? []).filter(
        (block) => block.type === 'product_cards',
    );
    const nonProductBlocks = (item.blocks ?? []).filter(
        (block) => block.type !== 'product_cards',
    );
    const content =
        productBlocks.length > 0 && (item.content?.length ?? 0) > 240
            ? 'I found these options for you.'
            : item.content;

    return (
        <div className="flex items-start gap-2">
            {meta.groupStart ? (
                <AssistantAvatar
                    name={appearance.assistant_name}
                    src={appearance.avatar_url}
                    online={availability === 'online'}
                    className="mt-1 size-8 shrink-0"
                />
            ) : (
                <div className="size-8 shrink-0" aria-hidden="true" />
            )}
            <div className="max-w-[calc(100%-2.5rem)] min-w-0 space-y-2">
                {content ? (
                    <div className="rounded-2xl rounded-tl-md bg-neutral-100 px-4 py-3 text-sm leading-6 text-neutral-800">
                        {item.sender ? (
                            <p className="mb-1 text-xs font-semibold text-neutral-500">
                                {item.sender}
                            </p>
                        ) : null}
                        <p className="break-words whitespace-pre-wrap">
                            {content}
                        </p>
                    </div>
                ) : null}
                {nonProductBlocks.length > 0 ? (
                    <ConversationBlockRenderer
                        blocks={nonProductBlocks}
                        onAction={onAction}
                        onFormSubmit={onFormSubmit}
                        onAppointmentSelect={onAppointmentSelect}
                        appearance={{
                            backgroundColor: appearance.background_color,
                            textColor: appearance.text_color,
                            buttonColor: appearance.send_button_color,
                            buttonTextColor: '#ffffff',
                        }}
                    />
                ) : null}
                {productBlocks.length > 0 ? (
                    <ConversationBlockRenderer
                        blocks={productBlocks}
                        onAction={onAction}
                        onFormSubmit={onFormSubmit}
                        onAppointmentSelect={onAppointmentSelect}
                    />
                ) : null}
                {meta.groupEnd ? <MessageTimestamp meta={meta} /> : null}
            </div>
        </div>
    );
}

function AssistantMessage({
    content,
    appearance,
    online,
    sender,
}: {
    content: string;
    appearance: BotWidgetAppearance;
    online: boolean;
    sender?: string;
}) {
    return (
        <div className="flex items-start gap-2">
            <AssistantAvatar
                name={appearance.assistant_name}
                src={appearance.avatar_url}
                online={online}
                className="mt-1 size-8 shrink-0"
            />
            <div className="max-w-[calc(100%-2.5rem)] rounded-2xl rounded-tl-md bg-neutral-100 px-4 py-3 text-sm leading-6 text-neutral-800">
                {sender ? (
                    <p className="mb-1 text-xs font-semibold text-neutral-500">
                        {sender}
                    </p>
                ) : null}
                {content}
            </div>
        </div>
    );
}

function AssistantAvatar({
    name,
    src,
    online,
    className,
}: {
    name: string;
    src: string | null;
    online: boolean;
    className: string;
}) {
    return (
        <div className={`relative ${className}`}>
            {src ? (
                <img
                    src={src}
                    alt={`${name} avatar`}
                    className="relative z-10 size-full rounded-full object-cover"
                    onError={(event) => {
                        event.currentTarget.style.display = 'none';
                    }}
                />
            ) : null}
            <div className="absolute inset-0 z-0 flex items-center justify-center rounded-full bg-gradient-to-br from-violet-500 to-fuchsia-400 text-sm font-semibold text-white">
                {name.trim().charAt(0).toUpperCase() || 'A'}
            </div>
            <span
                className={`absolute right-0 bottom-0 z-20 size-2.5 rounded-full border-2 border-white ${online ? 'bg-emerald-500' : 'bg-neutral-400'}`}
                aria-label={`Assistant status: ${online ? 'Online' : 'Offline'}`}
            />
        </div>
    );
}

function MessageTimestamp({
    meta,
}: {
    meta: ReturnType<typeof visualMessageMeta>;
}) {
    if (!meta.timeLabel || !meta.datetime) {
        return null;
    }

    return (
        <time
            dateTime={meta.datetime}
            className="mt-1 block text-right text-[10px] text-neutral-400"
        >
            {meta.timeLabel}
        </time>
    );
}

type FormResponsePayload = {
    form_block?: Extract<ConversationBlock, { type: 'form' }>;
    user_message?: ChatMessage;
    message?: ChatMessage | string;
    errors?: Record<string, string | string[]>;
};

type AppointmentResponsePayload = {
    appointment_block?: Extract<
        ConversationBlock,
        { type: 'appointment_slots' }
    >;
    user_message?: ChatMessage;
    message?: ChatMessage | string;
    errors?: Record<string, string | string[]>;
};

function normalizeErrors(
    errors: Record<string, string | string[]> | undefined,
): Record<string, string[]> {
    return Object.fromEntries(
        Object.entries(errors ?? {}).map(([key, value]) => [
            key,
            Array.isArray(value) ? value : [value],
        ]),
    );
}

function widgetHeaders(): HeadersInit {
    return {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Widget-Origin': parentOrigin(),
    };
}

function parentOrigin(): string {
    try {
        return document.referrer ? new URL(document.referrer).origin : '';
    } catch {
        return '';
    }
}

function visitorStorageKey(botId: string): string {
    return 'ai_widget_visitor_' + botId;
}

function formatRecordingTime(seconds: number): string {
    const minutes = Math.floor(seconds / 60)
        .toString()
        .padStart(2, '0');
    const remainingSeconds = (seconds % 60).toString().padStart(2, '0');

    return `${minutes}:${remainingSeconds}`;
}

function conversationStorageKey(botId: string): string {
    return 'ai_widget_conversation_' + botId;
}
