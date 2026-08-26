import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, BarChart3, Search, UserRound } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { ConversationBlockRenderer } from '@/components/conversation-block-renderer';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { show as actionShow } from '@/routes/actions';
import { index as analyticsIndex } from '@/routes/analytics';
import { store as storeTag } from '@/routes/conversation-tags';
import { index as conversationsIndex } from '@/routes/conversations';
import { reply as replyRoute } from '@/routes/conversations';
import { update as updateAssignment } from '@/routes/conversations/assignment';
import {
    returnToAi as returnToAiRoute,
    takeOver as takeOverRoute,
} from '@/routes/conversations/handoff';
import { store as storeNote } from '@/routes/conversations/notes';
import { update as updateStatus } from '@/routes/conversations/status';
import { attach as attachTag } from '@/routes/conversations/tags';
import type {
    ConversationDetailPageProps,
    ConversationInboxStatus,
} from '@/types';

function formatDate(value: string | null): string {
    return value
        ? new Intl.DateTimeFormat(undefined, {
              dateStyle: 'medium',
              timeStyle: 'short',
          }).format(new Date(value))
        : '—';
}

export default function ConversationShow({
    conversation,
    assignableUsers,
    tagOptions,
    permissions,
}: ConversationDetailPageProps) {
    const { currentTeam } = usePage().props;
    const replyForm = useForm({ message: '' });
    const statusForm = useForm<{ status: ConversationInboxStatus }>({
        status: conversation.conversationStatus,
    });
    const assignmentForm = useForm({
        assigned_to_user_id: conversation.assignee?.reference ?? '',
    });
    const noteForm = useForm({ body: '' });
    const tagForm = useForm({ name: '' });
    const [selectedTag, setSelectedTag] = useState('');

    if (!currentTeam) {
        return null;
    }

    const currentTeamSlug = currentTeam.slug;

    const conversationRoute = [currentTeam.slug, conversation.reference] as [
        string,
        string,
    ];

    function submitReply(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        replyForm.post(replyRoute(conversationRoute).url, {
            preserveScroll: true,
            onSuccess: () => replyForm.reset(),
        });
    }

    function submitStatus(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        statusForm.patch(updateStatus(conversationRoute).url, {
            preserveScroll: true,
        });
    }

    function submitAssignment(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        assignmentForm.patch(updateAssignment(conversationRoute).url, {
            preserveScroll: true,
        });
    }

    function submitNote(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        noteForm.post(storeNote(conversationRoute).url, {
            preserveScroll: true,
            onSuccess: () => noteForm.reset(),
        });
    }

    function submitTag(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        tagForm.post(storeTag(currentTeamSlug).url, {
            preserveScroll: true,
            onSuccess: () => tagForm.reset(),
        });
    }

    return (
        <>
            <Head title={`Conversation · ${conversation.bot.name}`} />

            <div className="flex flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-4">
                    <Link
                        href={conversationsIndex(currentTeam.slug).url}
                        className="flex w-fit items-center gap-2 text-sm text-muted-foreground hover:text-foreground"
                    >
                        <ArrowLeft className="size-4" />
                        Back to conversations
                    </Link>
                    <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
                        <Heading
                            variant="small"
                            title={conversation.bot.name}
                            description={
                                conversation.subject
                                    ? `${conversation.subject} · ${conversation.reference}`
                                    : `Conversation ${conversation.reference}`
                            }
                        />
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="rounded-full bg-primary/10 px-3 py-1 text-xs font-medium text-primary">
                                {conversation.channelName}
                            </span>
                            <span className="rounded-full bg-amber-100 px-3 py-1 text-xs font-medium text-amber-900">
                                {conversation.handoff.label}
                            </span>
                            {conversation.handoff.takenOverBy ? (
                                <span className="text-sm text-muted-foreground">
                                    Handled by{' '}
                                    {conversation.handoff.takenOverBy}
                                </span>
                            ) : null}
                            {conversation.sender ? (
                                <span className="text-sm text-muted-foreground">
                                    {conversation.sender}
                                </span>
                            ) : null}
                            {conversation.handoff.canTakeOver ? (
                                <Button
                                    type="button"
                                    onClick={() =>
                                        router.post(
                                            takeOverRoute(conversationRoute)
                                                .url,
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Take over conversation
                                </Button>
                            ) : null}
                            {conversation.handoff.canReturnToAi ? (
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() =>
                                        router.post(
                                            returnToAiRoute(conversationRoute)
                                                .url,
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Return conversation to AI
                                </Button>
                            ) : null}
                        </div>
                        <div className="flex flex-wrap gap-3 text-sm">
                            <Link
                                href={
                                    analyticsIndex(currentTeam.slug, {
                                        query: { bot: conversation.bot.slug },
                                    }).url
                                }
                                className="flex items-center gap-2 text-muted-foreground hover:text-foreground hover:underline"
                            >
                                <BarChart3 className="size-4" />
                                Bot analytics
                            </Link>
                        </div>
                    </div>
                </div>

                <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_20rem]">
                    <div className="grid min-w-0 gap-6">
                        <Card>
                            <CardHeader className="border-b">
                                <CardTitle className="flex items-center justify-between gap-3 text-base">
                                    <span>Message history</span>
                                    <span className="text-sm font-normal text-muted-foreground">
                                        {conversation.messages.length}{' '}
                                        {conversation.messages.length === 1
                                            ? 'message'
                                            : 'messages'}
                                    </span>
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-5 p-4 md:p-6">
                                {conversation.messages.length > 0 ? (
                                    conversation.messages.map(
                                        (message, index) => (
                                            <div
                                                key={`${message.createdAt ?? 'message'}-${index}`}
                                                className={`flex ${
                                                    message.role === 'user'
                                                        ? 'justify-start'
                                                        : 'justify-end'
                                                }`}
                                            >
                                                <div
                                                    className={`grid max-w-3xl gap-2 ${
                                                        message.role === 'user'
                                                            ? 'justify-items-start'
                                                            : message.role ===
                                                                'system'
                                                              ? 'justify-items-center'
                                                              : 'justify-items-end'
                                                    }`}
                                                >
                                                    <div className="flex items-center gap-2 text-xs text-muted-foreground">
                                                        <span className="font-medium text-foreground">
                                                            {message.source ===
                                                            'human'
                                                                ? message.sender
                                                                : message.role ===
                                                                    'system'
                                                                  ? 'System'
                                                                  : message.role ===
                                                                      'user'
                                                                    ? 'Visitor'
                                                                    : 'Assistant'}
                                                        </span>
                                                        <span>
                                                            {formatDate(
                                                                message.createdAt,
                                                            )}
                                                        </span>
                                                    </div>
                                                    <div
                                                        className={`rounded-xl border px-4 py-3 text-sm whitespace-pre-wrap ${
                                                            message.role ===
                                                            'user'
                                                                ? 'bg-muted/50'
                                                                : message.role ===
                                                                    'system'
                                                                  ? 'bg-amber-50 text-amber-900'
                                                                  : 'bg-primary/5'
                                                        }`}
                                                    >
                                                        {message.content}
                                                    </div>
                                                    {message.blocks.length >
                                                    0 ? (
                                                        <div className="w-full max-w-3xl">
                                                            <ConversationBlockRenderer
                                                                blocks={
                                                                    message.blocks
                                                                }
                                                                interactive={
                                                                    false
                                                                }
                                                            />
                                                        </div>
                                                    ) : null}
                                                </div>
                                            </div>
                                        ),
                                    )
                                ) : (
                                    <p className="py-8 text-center text-sm text-muted-foreground">
                                        This conversation has no customer-facing
                                        messages yet.
                                    </p>
                                )}
                            </CardContent>
                        </Card>

                        {conversation.handoff.canReply ? (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">
                                        Reply to customer
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <form
                                        className="grid gap-3"
                                        onSubmit={submitReply}
                                    >
                                        <textarea
                                            value={replyForm.data.message}
                                            onChange={(event) =>
                                                replyForm.setData(
                                                    'message',
                                                    event.target.value,
                                                )
                                            }
                                            rows={4}
                                            maxLength={4000}
                                            placeholder="Type a reply…"
                                            className="min-h-28 w-full rounded-lg border bg-transparent px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-ring"
                                        />
                                        {replyForm.errors.message ? (
                                            <p className="text-sm text-destructive">
                                                {replyForm.errors.message}
                                            </p>
                                        ) : null}
                                        <Button
                                            type="submit"
                                            disabled={
                                                replyForm.processing ||
                                                replyForm.data.message.trim() ===
                                                    ''
                                            }
                                        >
                                            {replyForm.processing
                                                ? 'Sending…'
                                                : 'Send reply'}
                                        </Button>
                                    </form>
                                </CardContent>
                            </Card>
                        ) : null}
                    </div>

                    <aside className="grid content-start gap-4">
                        {permissions.canManage ? (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">
                                        Conversation operations
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="grid gap-4 text-sm">
                                    <form
                                        className="grid gap-2"
                                        onSubmit={submitStatus}
                                    >
                                        <label htmlFor="conversation-status">
                                            Status
                                        </label>
                                        <select
                                            id="conversation-status"
                                            value={statusForm.data.status}
                                            onChange={(event) =>
                                                statusForm.setData(
                                                    'status',
                                                    event.target
                                                        .value as ConversationInboxStatus,
                                                )
                                            }
                                            className="rounded-lg border bg-transparent px-3 py-2"
                                        >
                                            <option value="open">Open</option>
                                            <option value="pending">
                                                Pending
                                            </option>
                                            <option value="resolved">
                                                Resolved
                                            </option>
                                            <option value="closed">
                                                Closed
                                            </option>
                                        </select>
                                        <Button
                                            type="submit"
                                            size="sm"
                                            disabled={statusForm.processing}
                                        >
                                            Save status
                                        </Button>
                                    </form>

                                    <form
                                        className="grid gap-2"
                                        onSubmit={submitAssignment}
                                    >
                                        <label htmlFor="conversation-assignee">
                                            Assignee
                                        </label>
                                        <select
                                            id="conversation-assignee"
                                            value={
                                                assignmentForm.data
                                                    .assigned_to_user_id
                                            }
                                            onChange={(event) =>
                                                assignmentForm.setData(
                                                    'assigned_to_user_id',
                                                    event.target.value,
                                                )
                                            }
                                            className="rounded-lg border bg-transparent px-3 py-2"
                                        >
                                            <option value="">Unassigned</option>
                                            {assignableUsers.map((member) => (
                                                <option
                                                    key={member.reference}
                                                    value={member.reference}
                                                >
                                                    {member.name}
                                                </option>
                                            ))}
                                        </select>
                                        <Button
                                            type="submit"
                                            size="sm"
                                            disabled={assignmentForm.processing}
                                        >
                                            Save assignment
                                        </Button>
                                    </form>

                                    <div className="grid gap-2 border-t pt-3">
                                        {conversation.tags.length > 0 ? (
                                            <div className="flex flex-wrap gap-1">
                                                {conversation.tags.map(
                                                    (tag) => (
                                                        <span
                                                            key={tag.reference}
                                                            className="rounded-full bg-primary/10 px-2 py-0.5 text-xs text-primary"
                                                        >
                                                            {tag.name}
                                                        </span>
                                                    ),
                                                )}
                                            </div>
                                        ) : null}
                                        <label htmlFor="conversation-tag">
                                            Add tag
                                        </label>
                                        <select
                                            id="conversation-tag"
                                            value={selectedTag}
                                            onChange={(event) =>
                                                setSelectedTag(
                                                    event.target.value,
                                                )
                                            }
                                            className="rounded-lg border bg-transparent px-3 py-2"
                                        >
                                            <option value="">
                                                Choose a tag
                                            </option>
                                            {tagOptions.map((tag) => (
                                                <option
                                                    key={tag.reference}
                                                    value={tag.reference}
                                                >
                                                    {tag.name}
                                                </option>
                                            ))}
                                        </select>
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            disabled={!selectedTag}
                                            onClick={() => {
                                                router.post(
                                                    attachTag([
                                                        currentTeam.slug,
                                                        conversation.reference,
                                                        selectedTag,
                                                    ]).url,
                                                    {},
                                                    { preserveScroll: true },
                                                );
                                                setSelectedTag('');
                                            }}
                                        >
                                            Add tag
                                        </Button>
                                    </div>

                                    <form
                                        className="grid gap-2 border-t pt-3"
                                        onSubmit={submitTag}
                                    >
                                        <label htmlFor="new-conversation-tag">
                                            Create tag
                                        </label>
                                        <input
                                            id="new-conversation-tag"
                                            value={tagForm.data.name}
                                            onChange={(event) =>
                                                tagForm.setData(
                                                    'name',
                                                    event.target.value,
                                                )
                                            }
                                            maxLength={80}
                                            placeholder="e.g. follow-up"
                                            className="rounded-lg border bg-transparent px-3 py-2"
                                        />
                                        <Button
                                            type="submit"
                                            size="sm"
                                            variant="outline"
                                            disabled={tagForm.processing}
                                        >
                                            Create tag
                                        </Button>
                                    </form>

                                    <form
                                        className="grid gap-2 border-t pt-3"
                                        onSubmit={submitNote}
                                    >
                                        <label htmlFor="conversation-note">
                                            Internal note
                                        </label>
                                        <textarea
                                            id="conversation-note"
                                            value={noteForm.data.body}
                                            onChange={(event) =>
                                                noteForm.setData(
                                                    'body',
                                                    event.target.value,
                                                )
                                            }
                                            maxLength={5000}
                                            rows={4}
                                            placeholder="Only your team can see this note."
                                            className="rounded-lg border bg-transparent px-3 py-2"
                                        />
                                        <Button
                                            type="submit"
                                            size="sm"
                                            disabled={
                                                noteForm.processing ||
                                                noteForm.data.body.trim() === ''
                                            }
                                        >
                                            Add internal note
                                        </Button>
                                    </form>

                                    {conversation.notes.length > 0 ? (
                                        <div className="grid gap-2 border-t pt-3">
                                            <p className="font-medium">
                                                Internal notes
                                            </p>
                                            {conversation.notes.map((note) => (
                                                <div
                                                    key={note.reference}
                                                    className="rounded-lg bg-muted/50 p-3"
                                                >
                                                    <p className="whitespace-pre-wrap">
                                                        {note.body}
                                                    </p>
                                                    <p className="mt-2 text-xs text-muted-foreground">
                                                        {note.author ??
                                                            'Team member'}{' '}
                                                        ·{' '}
                                                        {formatDate(
                                                            note.createdAt,
                                                        )}
                                                    </p>
                                                </div>
                                            ))}
                                        </div>
                                    ) : null}
                                </CardContent>
                            </Card>
                        ) : null}

                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <UserRound className="size-4" />
                                    Visitor
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-3 text-sm">
                                <p className="font-medium">
                                    {conversation.visitor.label}
                                </p>
                                <dl className="grid gap-2 text-muted-foreground">
                                    <div className="flex justify-between gap-3">
                                        <dt>First seen</dt>
                                        <dd className="text-right text-foreground">
                                            {formatDate(
                                                conversation.visitor
                                                    .firstSeenAt,
                                            )}
                                        </dd>
                                    </div>
                                    <div className="flex justify-between gap-3">
                                        <dt>Last seen</dt>
                                        <dd className="text-right text-foreground">
                                            {formatDate(
                                                conversation.visitor.lastSeenAt,
                                            )}
                                        </dd>
                                    </div>
                                    <div className="flex justify-between gap-3">
                                        <dt>Conversations</dt>
                                        <dd className="text-foreground">
                                            {conversation.visitor
                                                .conversationCount ?? '—'}
                                        </dd>
                                    </div>
                                </dl>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Customer profile
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-2 text-sm">
                                <p className="font-medium">
                                    {conversation.customer.label}
                                </p>
                                {conversation.customer.profileUrl ? (
                                    <Link className="text-primary hover:underline" href={conversation.customer.profileUrl}>Open customer profile</Link>
                                ) : null}
                                {conversation.customer.email ? <p className="text-muted-foreground">{conversation.customer.email}</p> : null}
                                {conversation.customer.phone ? <p className="text-muted-foreground">{conversation.customer.phone}</p> : null}
                                {conversation.customer.status ? <p className="text-muted-foreground">Status: {conversation.customer.status}</p> : null}
                                {conversation.customer.owner ? <p className="text-muted-foreground">Owner: {conversation.customer.owner}</p> : null}
                                {conversation.customer.identity ? (
                                    <p className="text-muted-foreground">
                                        {conversation.customer.identity}
                                    </p>
                                ) : null}
                                <p className="text-muted-foreground">
                                    {conversation.customer.conversationCount}{' '}
                                    conversation(s)
                                </p>
                                {Object.values(conversation.related)
                                    .flat()
                                    .slice(0, 5)
                                    .map((record) => (
                                        <Link
                                            key={record.reference}
                                            href={record.url}
                                            className="flex justify-between gap-2 border-t pt-2 text-muted-foreground hover:text-foreground"
                                        >
                                            <span>{record.label}</span>
                                            <span>{record.status}</span>
                                        </Link>
                                    ))}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Activity
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-3 text-sm">
                                <div className="flex items-center justify-between gap-3">
                                    <span className="flex items-center gap-2 text-muted-foreground">
                                        <Search className="size-4" /> Searches
                                    </span>
                                    <span className="font-medium">
                                        {conversation.searchesCount}
                                    </span>
                                </div>
                                {conversation.actions.length > 0 ? (
                                    <div className="grid gap-2 border-t pt-3">
                                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                            Actions
                                        </p>
                                        {conversation.actions.map(
                                            (action, index) => (
                                                <Link
                                                    key={`${action.name}-${index}`}
                                                    href={
                                                        actionShow([
                                                            currentTeam.slug,
                                                            action.actionReference,
                                                        ]).url
                                                    }
                                                    className="flex justify-between gap-3"
                                                >
                                                    <span>{action.name}</span>
                                                    <span className="text-muted-foreground">
                                                        {action.status}
                                                    </span>
                                                </Link>
                                            ),
                                        )}
                                    </div>
                                ) : null}
                            </CardContent>
                        </Card>
                    </aside>
                </div>
            </div>
        </>
    );
}
