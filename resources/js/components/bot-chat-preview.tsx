import { useState } from 'react';
import type { FormEvent } from 'react';
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
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { reset, test } from '@/routes/bots/ai';
import { cancel, confirm } from '@/routes/bots/ai/actions';
import { select as selectAppointment } from '@/routes/bots/ai/appointments';
import { submit as submitForm } from '@/routes/bots/ai/forms';
import type { Bot, ConversationBlock } from '@/types';

type ChatMessage = {
    role: 'user' | 'assistant';
    content: string;
    blocks?: ConversationBlock[];
};

export default function BotChatPreview({
    bot,
    currentTeamSlug,
}: {
    bot: Bot;
    currentTeamSlug: string;
}) {
    const [conversationId, setConversationId] = useState<string | null>(null);
    const [messages, setMessages] = useState<ChatMessage[]>([]);
    const [message, setMessage] = useState('');
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | null>(null);

    async function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const value = message.trim();

        if (!value || processing) {
            return;
        }

        setProcessing(true);
        setError(null);
        setMessages((current) => [
            ...current,
            { role: 'user', content: value },
        ]);
        setMessage('');

        try {
            const response = await fetch(test.url([currentTeamSlug, bot.id]), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': xsrfToken(),
                },
                body: JSON.stringify({
                    message: value,
                    conversation_id: conversationId,
                }),
            });
            const payload = await response.json();

            if (!response.ok) {
                throw new Error(payload.message ?? 'The preview failed.');
            }

            setConversationId(payload.conversation_id);
            setMessages((current) => [
                ...current,
                {
                    role: 'assistant',
                    content: payload.answer,
                    blocks: payload.blocks ?? [],
                },
            ]);
        } catch (exception) {
            setError(
                exception instanceof Error
                    ? exception.message
                    : 'The preview failed.',
            );
        } finally {
            setProcessing(false);
        }
    }

    async function newConversation() {
        const response = await fetch(reset.url([currentTeamSlug, bot.id]), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-XSRF-TOKEN': xsrfToken(),
            },
        });

        if (response.ok) {
            const payload = await response.json();
            setConversationId(payload.conversation_id);
            setMessages([]);
            setError(null);
        }
    }

    async function handleBlockAction(
        actionReference: string,
        action: 'confirm' | 'cancel',
    ): Promise<Extract<ConversationBlock, { type: 'confirmation' }>> {
        if (conversationId === null) {
            throw new Error('The preview conversation is not available.');
        }

        const actionRoute = action === 'confirm' ? confirm : cancel;
        const response = await fetch(
            actionRoute.url([currentTeamSlug, bot.id, actionReference]),
            {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': xsrfToken(),
                },
                body: JSON.stringify({ conversation_id: conversationId }),
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
        if (conversationId === null) {
            throw new Error('The preview conversation is not available.');
        }

        const response = await fetch(
            submitForm.url([currentTeamSlug, bot.id, formReference]),
            {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': xsrfToken(),
                },
                body: JSON.stringify({
                    conversation_id: conversationId,
                    values,
                }),
            },
        );
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
            setMessages((current) => [
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
        if (conversationId === null) {
            throw new Error('The preview conversation is not available.');
        }

        const response = await fetch(
            selectAppointment.url([
                currentTeamSlug,
                bot.id,
                appointmentReference,
            ]),
            {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': xsrfToken(),
                },
                body: JSON.stringify({
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
            setMessages((current) => [
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

    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between gap-3">
                <CardTitle>Chat preview</CardTitle>
                <Button type="button" variant="ghost" onClick={newConversation}>
                    New conversation
                </Button>
            </CardHeader>
            <CardContent className="grid gap-4">
                <div className="grid min-h-52 gap-3 rounded-lg border bg-muted/20 p-3">
                    {messages.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {bot.welcomeMessage ||
                                'Hello! What are you looking for?'}
                        </p>
                    ) : (
                        messages.map((item, index) => (
                            <div
                                key={item.role + '-' + index}
                                className={
                                    item.role === 'user'
                                        ? 'ml-auto max-w-[85%] rounded-lg bg-primary px-3 py-2 text-sm text-primary-foreground'
                                        : 'max-w-[85%] rounded-lg border bg-background px-3 py-2 text-sm'
                                }
                            >
                                <div>{item.content}</div>
                                {item.blocks ? (
                                    <ConversationBlockRenderer
                                        blocks={item.blocks}
                                        onAction={handleBlockAction}
                                        onFormSubmit={handleFormSubmit}
                                        onAppointmentSelect={
                                            handleAppointmentSelect
                                        }
                                    />
                                ) : null}
                            </div>
                        ))
                    )}
                    {processing ? (
                        <p className="text-sm text-muted-foreground">
                            Thinking…
                        </p>
                    ) : null}
                </div>
                {error ? (
                    <p className="text-sm text-destructive">{error}</p>
                ) : null}
                <form className="flex gap-2" onSubmit={submit}>
                    <input
                        value={message}
                        onChange={(event) => setMessage(event.target.value)}
                        className="min-w-0 flex-1 rounded-md border bg-transparent px-3 py-2 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        placeholder="Ask about the catalog..."
                        aria-label="Preview message"
                        maxLength={4000}
                    />
                    <Button
                        type="submit"
                        disabled={processing || !message.trim()}
                    >
                        Send
                    </Button>
                </form>
            </CardContent>
        </Card>
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

function xsrfToken(): string {
    const token = document.cookie
        .split('; ')
        .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
        ?.split('=')[1];

    return token ? decodeURIComponent(token) : '';
}
