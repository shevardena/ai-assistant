import { Form, Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, CheckCircle2, RadioTower, Unplug } from 'lucide-react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { show as botShow } from '@/routes/bots';
import email from '@/routes/bots/channels/email';
import instagram from '@/routes/bots/channels/instagram';
import messenger from '@/routes/bots/channels/messenger';
import sms from '@/routes/bots/channels/sms';
import telegram from '@/routes/bots/channels/telegram';
import whatsapp from '@/routes/bots/channels/whatsapp';
import type {
    BotChannel,
    BotChannelsPageProps,
    ChannelCapability,
} from '@/types';

const capabilityLabels: Record<ChannelCapability, string> = {
    text: 'Text',
    buttons: 'Buttons',
    images: 'Images',
    product_cards: 'Product cards',
    forms: 'Forms',
    file_attachments: 'File attachments',
    typing_indicator: 'Typing indicator',
    comparison: 'Comparison',
    order_status: 'Order status',
    tracking: 'Tracking',
    locations: 'Locations',
    confirmation: 'Confirmation',
    appointment_slots: 'Appointment slots',
};

function connectionLabel(channel: BotChannel): string {
    if (!channel.connection) {
        return channel.implemented ? 'Not configured' : 'Coming soon';
    }

    return {
        active: 'Active',
        error: 'Error',
        disabled: 'Disabled',
        draft: 'Draft',
    }[channel.connection.status];
}

export default function BotChannels({
    bot,
    channels,
    permissions,
}: BotChannelsPageProps) {
    const { currentTeam } = usePage().props;

    if (!currentTeam) {
        return null;
    }

    return (
        <>
            <Head title={`${bot.name} channels`} />

            <div className="flex flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div className="flex items-start gap-3">
                        <Button variant="ghost" size="icon" asChild>
                            <Link
                                href={botShow([currentTeam.slug, bot.id]).url}
                                aria-label="Back to Bot"
                            >
                                <ArrowLeft />
                            </Link>
                        </Button>
                        <Heading
                            variant="small"
                            title="Channels"
                            description={`Choose where ${bot.name} can serve customers.`}
                        />
                    </div>
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    {channels.map((channel) => (
                        <ChannelCard
                            key={channel.key}
                            channel={channel}
                            bot={bot}
                            teamSlug={currentTeam.slug}
                            canManage={permissions.canManage}
                        />
                    ))}
                </div>
            </div>
        </>
    );
}

function ChannelCard({
    channel,
    bot,
    teamSlug,
    canManage,
}: {
    channel: BotChannel;
    bot: BotChannelsPageProps['bot'];
    teamSlug: string;
    canManage: boolean;
}) {
    const connection = channel.connection;

    return (
        <section className="flex flex-col gap-5 rounded-xl border p-5">
            <div className="flex items-start justify-between gap-4">
                <div className="flex items-start gap-3">
                    <div className="flex size-10 items-center justify-center rounded-lg bg-muted">
                        <RadioTower className="size-5" />
                    </div>
                    <div className="grid gap-1">
                        <h2 className="font-medium">{channel.name}</h2>
                        <p className="text-sm text-muted-foreground">
                            {channel.description}
                        </p>
                    </div>
                </div>
                <Badge variant={connection ? 'secondary' : 'outline'}>
                    {connectionLabel(channel)}
                </Badge>
            </div>

            {channel.key === 'whatsapp' ? (
                <WhatsAppConnectionForm
                    connection={connection}
                    bot={bot}
                    teamSlug={teamSlug}
                    canManage={canManage}
                />
            ) : channel.key === 'instagram' ? (
                <MetaConnectionForm
                    channel="instagram"
                    connection={connection}
                    bot={bot}
                    teamSlug={teamSlug}
                    canManage={canManage}
                />
            ) : channel.key === 'facebook_messenger' ? (
                <MetaConnectionForm
                    channel="facebook_messenger"
                    connection={connection}
                    bot={bot}
                    teamSlug={teamSlug}
                    canManage={canManage}
                />
            ) : channel.key === 'telegram' ? (
                <TelegramConnectionForm
                    connection={connection}
                    bot={bot}
                    teamSlug={teamSlug}
                    canManage={canManage}
                />
            ) : channel.key === 'sms' ? (
                <SmsConnectionForm
                    connection={connection}
                    bot={bot}
                    teamSlug={teamSlug}
                    canManage={canManage}
                />
            ) : channel.key === 'email' ? (
                <EmailConnectionForm
                    connection={connection}
                    bot={bot}
                    teamSlug={teamSlug}
                    canManage={canManage}
                />
            ) : connection ? (
                <div className="grid gap-3 text-sm">
                    <div className="flex items-center gap-2 text-muted-foreground">
                        <CheckCircle2 className="size-4 text-emerald-600" />
                        Connected through your existing widget and domain
                        settings.
                    </div>
                    <div className="grid gap-2 sm:grid-cols-2">
                        <span>
                            Allowed domains: {connection.allowedDomains}
                        </span>
                        <span>
                            Widget: {connection.widgetReady ? 'Ready' : 'Draft'}
                        </span>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button variant="outline" size="sm" asChild>
                            <Link href={connection.links.design}>Design</Link>
                        </Button>
                        <Button variant="outline" size="sm" asChild>
                            <Link href={connection.links.domains}>Domains</Link>
                        </Button>
                        <Button variant="outline" size="sm" asChild>
                            <Link href={connection.links.embed}>Embed</Link>
                        </Button>
                    </div>
                </div>
            ) : (
                <div className="grid gap-3 text-sm text-muted-foreground">
                    <p>Provider setup will be available in a future release.</p>
                    <p>No connection or credentials are required yet.</p>
                </div>
            )}

            <div className="flex flex-wrap gap-2 border-t pt-4">
                {channel.capabilities.map((capability) => (
                    <span
                        key={capability}
                        className="rounded-full bg-muted px-2.5 py-1 text-xs text-muted-foreground"
                    >
                        {capabilityLabels[capability]}
                    </span>
                ))}
            </div>
        </section>
    );
}

function SmsConnectionForm({
    connection,
    bot,
    teamSlug,
    canManage,
}: {
    connection: BotChannel['connection'];
    bot: BotChannelsPageProps['bot'];
    teamSlug: string;
    canManage: boolean;
}) {
    if (!canManage) {
        return connection ? (
            <div className="grid gap-2 text-sm text-muted-foreground">
                <p>{connection.phoneNumber ?? 'SMS connection configured.'}</p>
                <p>
                    Connection settings are managed by a Team developer or
                    administrator.
                </p>
            </div>
        ) : (
            <p className="text-sm text-muted-foreground">
                Ask a Team developer to configure SMS.
            </p>
        );
    }

    return (
        <div className="grid gap-4">
            {connection ? (
                <div className="grid gap-2 text-sm text-muted-foreground">
                    <p>
                        {connection.phoneNumber ?? 'Phone number configured'}
                        {connection.displayName
                            ? ` · ${connection.displayName}`
                            : ''}
                    </p>
                    <p>
                        Auth token:{' '}
                        {connection.tokenConfigured
                            ? connection.tokenLastFour
                                ? `••••${connection.tokenLastFour}`
                                : 'Configured'
                            : 'Not configured'}
                    </p>
                </div>
            ) : null}
            <Form
                {...sms.store.form([teamSlug, bot.id])}
                className="grid gap-3"
                options={{ preserveScroll: true }}
            >
                {({ processing, errors }) => (
                    <>
                        <input
                            name="phone_number"
                            defaultValue={connection?.phoneNumber ?? ''}
                            placeholder="Twilio phone number (+15551234567)"
                            className="rounded-md border bg-transparent px-3 py-2 text-sm"
                            required={!connection}
                        />
                        <input
                            name="account_sid"
                            type="password"
                            placeholder={
                                connection
                                    ? 'Account SID (leave blank to keep)'
                                    : 'Twilio Account SID'
                            }
                            className="rounded-md border bg-transparent px-3 py-2 text-sm"
                            required={!connection}
                        />
                        <input
                            name="auth_token"
                            type="password"
                            placeholder={
                                connection
                                    ? 'Auth Token (leave blank to keep)'
                                    : 'Twilio Auth Token'
                            }
                            className="rounded-md border bg-transparent px-3 py-2 text-sm"
                            required={!connection}
                        />
                        <input
                            name="display_name"
                            defaultValue={connection?.displayName ?? ''}
                            placeholder="Display name (optional)"
                            className="rounded-md border bg-transparent px-3 py-2 text-sm"
                        />
                        <p className="text-xs text-muted-foreground">
                            Credentials are validated with Twilio, encrypted,
                            and never returned in full.
                        </p>
                        {Object.values(errors).map((error) => (
                            <p key={error} className="text-sm text-destructive">
                                {error}
                            </p>
                        ))}
                        <div className="flex flex-wrap gap-2">
                            <Button
                                type="submit"
                                size="sm"
                                disabled={processing}
                            >
                                {processing
                                    ? 'Saving…'
                                    : connection
                                      ? 'Save configuration'
                                      : 'Connect SMS'}
                            </Button>
                            {connection && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => {
                                        if (
                                            window.confirm(
                                                'Disable this SMS connection?',
                                            )
                                        ) {
                                            const form =
                                                document.createElement('form');
                                            form.method = 'post';
                                            form.action = sms.destroy.url([
                                                teamSlug,
                                                bot.id,
                                            ]);
                                            const method =
                                                document.createElement('input');
                                            method.name = '_method';
                                            method.value = 'DELETE';
                                            form.appendChild(method);
                                            document.body.appendChild(form);
                                            form.submit();
                                        }
                                    }}
                                >
                                    <Unplug className="mr-1 size-4" />
                                    Disable
                                </Button>
                            )}
                        </div>
                    </>
                )}
            </Form>
        </div>
    );
}

function EmailConnectionForm({
    connection,
    bot,
    teamSlug,
    canManage,
}: {
    connection: BotChannel['connection'];
    bot: BotChannelsPageProps['bot'];
    teamSlug: string;
    canManage: boolean;
}) {
    if (!canManage) {
        return connection ? (
            <div className="grid gap-2 text-sm text-muted-foreground">
                <p>
                    {connection.fromAddress ?? 'Email connection configured.'}
                </p>
                <p>
                    Inbound setup:{' '}
                    {connection.inboundStatus === 'setup_pending'
                        ? 'Pending provider webhook setup'
                        : 'Configured'}
                </p>
                <p>
                    Connection settings are managed by a Team developer or
                    administrator.
                </p>
            </div>
        ) : (
            <p className="text-sm text-muted-foreground">
                Ask a Team developer to configure Email.
            </p>
        );
    }

    return (
        <div className="grid gap-4">
            {connection ? (
                <div className="grid gap-2 text-sm text-muted-foreground">
                    <p>
                        {connection.fromName ? `${connection.fromName} · ` : ''}
                        {connection.fromAddress ?? 'From address configured'}
                    </p>
                    <p>
                        Inbound address:{' '}
                        {connection.inboundAddress ?? 'Not configured'}
                    </p>
                    <p>
                        Server token:{' '}
                        {connection.tokenConfigured
                            ? connection.tokenLastFour
                                ? `••••${connection.tokenLastFour}`
                                : 'Configured'
                            : 'Not configured'}
                    </p>
                    <p>
                        Inbound setup: provider webhook configuration is still
                        pending.
                    </p>
                    {connection.webhookUrl ? (
                        <p className="text-xs break-all">
                            Webhook endpoint: {connection.webhookUrl}
                        </p>
                    ) : null}
                </div>
            ) : null}
            <Form
                {...email.store.form([teamSlug, bot.id])}
                className="grid gap-3"
                options={{ preserveScroll: true }}
            >
                {({ processing, errors }) => (
                    <>
                        <input
                            name="inbound_address"
                            defaultValue={connection?.inboundAddress ?? ''}
                            placeholder="Postmark inbound address"
                            className="rounded-md border bg-transparent px-3 py-2 text-sm"
                            required
                        />
                        <input
                            name="from_address"
                            type="email"
                            defaultValue={connection?.fromAddress ?? ''}
                            placeholder="support@example.com"
                            className="rounded-md border bg-transparent px-3 py-2 text-sm"
                            required
                        />
                        <input
                            name="from_name"
                            defaultValue={connection?.fromName ?? ''}
                            placeholder="From name"
                            className="rounded-md border bg-transparent px-3 py-2 text-sm"
                        />
                        <input
                            name="reply_to_address"
                            type="email"
                            defaultValue={connection?.replyToAddress ?? ''}
                            placeholder="Reply-to address (optional)"
                            className="rounded-md border bg-transparent px-3 py-2 text-sm"
                        />
                        <input
                            name="server_api_token"
                            type="password"
                            placeholder={
                                connection
                                    ? 'Postmark server token (leave blank to keep)'
                                    : 'Postmark server token'
                            }
                            className="rounded-md border bg-transparent px-3 py-2 text-sm"
                            required={!connection}
                        />
                        <input
                            name="webhook_secret"
                            type="password"
                            placeholder={
                                connection
                                    ? 'Webhook Basic Auth secret (leave blank to keep)'
                                    : 'Webhook Basic Auth secret'
                            }
                            className="rounded-md border bg-transparent px-3 py-2 text-sm"
                            required={!connection}
                        />
                        <p className="text-xs text-muted-foreground">
                            Postmark validates outbound access. Configure its
                            inbound webhook with the endpoint above and HTTP
                            Basic Auth; inbound status remains pending until
                            that setup is completed.
                        </p>
                        {Object.values(errors).map((error) => (
                            <p key={error} className="text-sm text-destructive">
                                {error}
                            </p>
                        ))}
                        <div className="flex flex-wrap gap-2">
                            <Button
                                type="submit"
                                size="sm"
                                disabled={processing}
                            >
                                {processing
                                    ? 'Saving…'
                                    : connection
                                      ? 'Save configuration'
                                      : 'Connect Email'}
                            </Button>
                            {connection ? (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => {
                                        if (
                                            window.confirm(
                                                'Disable this Email connection?',
                                            )
                                        ) {
                                            const form =
                                                document.createElement('form');
                                            form.method = 'post';
                                            form.action = email.destroy.url([
                                                teamSlug,
                                                bot.id,
                                            ]);
                                            const method =
                                                document.createElement('input');
                                            method.name = '_method';
                                            method.value = 'DELETE';
                                            form.appendChild(method);
                                            document.body.appendChild(form);
                                            form.submit();
                                        }
                                    }}
                                >
                                    <Unplug className="mr-1 size-4" />
                                    Disable
                                </Button>
                            ) : null}
                        </div>
                    </>
                )}
            </Form>
        </div>
    );
}

function TelegramConnectionForm({
    connection,
    bot,
    teamSlug,
    canManage,
}: {
    connection: BotChannel['connection'];
    bot: BotChannelsPageProps['bot'];
    teamSlug: string;
    canManage: boolean;
}) {
    if (!canManage) {
        return connection ? (
            <div className="grid gap-2 text-sm text-muted-foreground">
                <p>
                    {connection.botUsername
                        ? `@${connection.botUsername}`
                        : (connection.displayName ?? 'Telegram Bot configured')}
                </p>
                <p>
                    Connection settings are managed by a Team developer or
                    administrator.
                </p>
            </div>
        ) : (
            <p className="text-sm text-muted-foreground">
                Ask a Team developer to configure Telegram.
            </p>
        );
    }

    return (
        <div className="grid gap-4">
            {connection ? (
                <div className="grid gap-2 text-sm text-muted-foreground">
                    <p>
                        {connection.botUsername
                            ? `@${connection.botUsername}`
                            : (connection.displayName ?? 'Telegram Bot')}
                    </p>
                    <p>
                        Bot token:{' '}
                        {connection.tokenConfigured
                            ? connection.tokenLastFour
                                ? `••••${connection.tokenLastFour}`
                                : 'Configured'
                            : 'Not configured'}
                    </p>
                    <p>
                        Webhook:{' '}
                        {connection.webhookConfigured
                            ? 'Configured'
                            : 'Needs attention'}
                    </p>
                </div>
            ) : null}
            <Form
                {...telegram.store.form([teamSlug, bot.id])}
                className="grid gap-3"
                options={{ preserveScroll: true }}
            >
                {({ processing, errors }) => (
                    <>
                        <input
                            name="bot_token"
                            type="password"
                            placeholder={
                                connection
                                    ? 'Bot token (leave blank to keep)'
                                    : 'Bot token from BotFather'
                            }
                            className="rounded-md border bg-transparent px-3 py-2 text-sm"
                            required={!connection}
                        />
                        <p className="text-xs text-muted-foreground">
                            The token is validated with Telegram and never
                            displayed or stored in channel configuration.
                        </p>
                        {Object.values(errors).map((error) => (
                            <p key={error} className="text-sm text-destructive">
                                {error}
                            </p>
                        ))}
                        <div className="flex flex-wrap gap-2">
                            <Button
                                type="submit"
                                size="sm"
                                disabled={processing}
                            >
                                {processing
                                    ? 'Saving…'
                                    : connection
                                      ? 'Save configuration'
                                      : 'Connect Telegram'}
                            </Button>
                            {connection && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => {
                                        if (
                                            window.confirm(
                                                'Disable this Telegram connection?',
                                            )
                                        ) {
                                            const form =
                                                document.createElement('form');
                                            form.method = 'post';
                                            form.action = telegram.destroy.url([
                                                teamSlug,
                                                bot.id,
                                            ]);
                                            const method =
                                                document.createElement('input');
                                            method.name = '_method';
                                            method.value = 'DELETE';
                                            form.appendChild(method);
                                            document.body.appendChild(form);
                                            form.submit();
                                        }
                                    }}
                                >
                                    <Unplug className="mr-1 size-4" />
                                    Disable
                                </Button>
                            )}
                        </div>
                    </>
                )}
            </Form>
        </div>
    );
}

function MetaConnectionForm({
    channel,
    connection,
    bot,
    teamSlug,
    canManage,
}: {
    channel: 'instagram' | 'facebook_messenger';
    connection: BotChannel['connection'];
    bot: BotChannelsPageProps['bot'];
    teamSlug: string;
    canManage: boolean;
}) {
    const isInstagram = channel === 'instagram';
    const routes = isInstagram ? instagram : messenger;

    if (!canManage) {
        return connection ? (
            <div className="grid gap-2 text-sm text-muted-foreground">
                <p>
                    {isInstagram
                        ? (connection.username ??
                          connection.displayName ??
                          'Instagram connection configured.')
                        : (connection.pageName ??
                          'Facebook Page connection configured.')}
                </p>
                <p>
                    Connection settings are managed by a Team developer or
                    administrator.
                </p>
            </div>
        ) : (
            <p className="text-sm text-muted-foreground">
                Ask a Team developer to configure this Meta channel.
            </p>
        );
    }

    return (
        <div className="grid gap-4">
            {connection ? (
                <div className="grid gap-2 text-sm text-muted-foreground">
                    <p>
                        {isInstagram
                            ? (connection.username ??
                              connection.displayName ??
                              'Instagram account configured')
                            : (connection.pageName ??
                              'Facebook Page configured')}
                    </p>
                    <p>
                        Access token:{' '}
                        {connection.tokenConfigured
                            ? connection.tokenLastFour
                                ? `••••${connection.tokenLastFour}`
                                : 'Configured'
                            : 'Not configured'}
                    </p>
                </div>
            ) : null}
            <Form
                {...routes.store.form([teamSlug, bot.id])}
                className="grid gap-3"
                options={{ preserveScroll: true }}
            >
                {({ processing, errors }) => (
                    <>
                        {isInstagram ? (
                            <>
                                <input
                                    name="instagram_account_id"
                                    defaultValue={
                                        connection?.instagramAccountId ?? ''
                                    }
                                    placeholder="Instagram Account ID"
                                    className="rounded-md border bg-transparent px-3 py-2 text-sm"
                                    required
                                />
                                <input
                                    name="facebook_page_id"
                                    defaultValue={
                                        connection?.facebookPageId ?? ''
                                    }
                                    placeholder="Facebook Page ID"
                                    className="rounded-md border bg-transparent px-3 py-2 text-sm"
                                    required
                                />
                                <input
                                    name="display_name"
                                    defaultValue={connection?.displayName ?? ''}
                                    placeholder="Display name (optional)"
                                    className="rounded-md border bg-transparent px-3 py-2 text-sm"
                                />
                                <input
                                    name="username"
                                    defaultValue={connection?.username ?? ''}
                                    placeholder="Username (optional)"
                                    className="rounded-md border bg-transparent px-3 py-2 text-sm"
                                />
                            </>
                        ) : (
                            <>
                                <input
                                    name="facebook_page_id"
                                    defaultValue={
                                        connection?.facebookPageId ?? ''
                                    }
                                    placeholder="Facebook Page ID"
                                    className="rounded-md border bg-transparent px-3 py-2 text-sm"
                                    required
                                />
                                <input
                                    name="page_name"
                                    defaultValue={connection?.pageName ?? ''}
                                    placeholder="Page name (optional)"
                                    className="rounded-md border bg-transparent px-3 py-2 text-sm"
                                />
                            </>
                        )}
                        <input
                            name="access_token"
                            type="password"
                            placeholder={
                                connection
                                    ? 'Access Token (leave blank to keep)'
                                    : 'Access Token'
                            }
                            className="rounded-md border bg-transparent px-3 py-2 text-sm"
                        />
                        <input
                            name="webhook_verify_token"
                            type="password"
                            placeholder={
                                connection
                                    ? 'Webhook Verify Token (leave blank to keep)'
                                    : 'Webhook Verify Token'
                            }
                            className="rounded-md border bg-transparent px-3 py-2 text-sm"
                        />
                        <input
                            name="app_secret"
                            type="password"
                            placeholder={
                                connection
                                    ? 'Meta App Secret (leave blank to keep)'
                                    : 'Meta App Secret'
                            }
                            className="rounded-md border bg-transparent px-3 py-2 text-sm"
                        />
                        {Object.values(errors).map((error) => (
                            <p key={error} className="text-sm text-destructive">
                                {error}
                            </p>
                        ))}
                        <div className="flex flex-wrap gap-2">
                            <Button
                                type="submit"
                                size="sm"
                                disabled={processing}
                            >
                                {processing
                                    ? 'Saving…'
                                    : connection
                                      ? 'Save configuration'
                                      : `Connect ${isInstagram ? 'Instagram' : 'Messenger'}`}
                            </Button>
                            {connection && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => {
                                        if (
                                            window.confirm(
                                                `Disable this ${isInstagram ? 'Instagram' : 'Messenger'} connection?`,
                                            )
                                        ) {
                                            const form =
                                                document.createElement('form');
                                            form.method = 'post';
                                            form.action = routes.destroy.url([
                                                teamSlug,
                                                bot.id,
                                            ]);
                                            const method =
                                                document.createElement('input');
                                            method.name = '_method';
                                            method.value = 'DELETE';
                                            form.appendChild(method);
                                            document.body.appendChild(form);
                                            form.submit();
                                        }
                                    }}
                                >
                                    <Unplug className="mr-1 size-4" />
                                    Disable
                                </Button>
                            )}
                        </div>
                    </>
                )}
            </Form>
        </div>
    );
}

function WhatsAppConnectionForm({
    connection,
    bot,
    teamSlug,
    canManage,
}: {
    connection: BotChannel['connection'];
    bot: BotChannelsPageProps['bot'];
    teamSlug: string;
    canManage: boolean;
}) {
    if (!canManage) {
        return connection ? (
            <div className="grid gap-2 text-sm text-muted-foreground">
                <p>
                    {connection.displayPhoneNumber ??
                        'WhatsApp connection configured.'}
                </p>
                <p>
                    Connection settings are managed by a Team developer or
                    administrator.
                </p>
            </div>
        ) : (
            <p className="text-sm text-muted-foreground">
                Ask a Team developer to configure WhatsApp.
            </p>
        );
    }

    return (
        <div className="grid gap-4">
            {connection ? (
                <div className="grid gap-2 text-sm text-muted-foreground">
                    <p>
                        {connection.displayPhoneNumber ??
                            'Phone number configured'}
                        {connection.verifiedName
                            ? ` · ${connection.verifiedName}`
                            : ''}
                    </p>
                    <p>
                        Access token:{' '}
                        {connection.tokenConfigured
                            ? connection.tokenLastFour
                                ? `••••${connection.tokenLastFour}`
                                : 'Configured'
                            : 'Not configured'}
                    </p>
                </div>
            ) : null}
            <Form
                {...whatsapp.store.form([teamSlug, bot.id])}
                className="grid gap-3"
                options={{ preserveScroll: true }}
            >
                {({ processing, errors }) => (
                    <>
                        <input
                            name="phone_number_id"
                            defaultValue={connection?.phoneNumberId ?? ''}
                            placeholder="Phone Number ID"
                            className="rounded-md border bg-transparent px-3 py-2 text-sm"
                            required
                        />
                        <input
                            name="business_account_id"
                            defaultValue={connection?.businessAccountId ?? ''}
                            placeholder="WhatsApp Business Account ID (optional)"
                            className="rounded-md border bg-transparent px-3 py-2 text-sm"
                        />
                        <input
                            name="display_phone_number"
                            defaultValue={connection?.displayPhoneNumber ?? ''}
                            placeholder="Display phone number (optional)"
                            className="rounded-md border bg-transparent px-3 py-2 text-sm"
                        />
                        <input
                            name="access_token"
                            type="password"
                            placeholder={
                                connection
                                    ? 'Access Token (leave blank to keep)'
                                    : 'Access Token'
                            }
                            className="rounded-md border bg-transparent px-3 py-2 text-sm"
                        />
                        <input
                            name="webhook_verify_token"
                            type="password"
                            placeholder={
                                connection
                                    ? 'Webhook Verify Token (leave blank to keep)'
                                    : 'Webhook Verify Token'
                            }
                            className="rounded-md border bg-transparent px-3 py-2 text-sm"
                        />
                        <input
                            name="app_secret"
                            type="password"
                            placeholder={
                                connection
                                    ? 'Meta App Secret (leave blank to keep)'
                                    : 'Meta App Secret'
                            }
                            className="rounded-md border bg-transparent px-3 py-2 text-sm"
                        />
                        {Object.values(errors).map((error) => (
                            <p key={error} className="text-sm text-destructive">
                                {error}
                            </p>
                        ))}
                        <div className="flex flex-wrap gap-2">
                            <Button
                                type="submit"
                                size="sm"
                                disabled={processing}
                            >
                                {processing
                                    ? 'Saving…'
                                    : connection
                                      ? 'Save configuration'
                                      : 'Connect WhatsApp'}
                            </Button>
                            {connection && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => {
                                        if (
                                            window.confirm(
                                                'Disable this WhatsApp connection?',
                                            )
                                        ) {
                                            const form =
                                                document.createElement('form');
                                            form.method = 'post';
                                            form.action = whatsapp.destroy.url([
                                                teamSlug,
                                                bot.id,
                                            ]);
                                            const method =
                                                document.createElement('input');
                                            method.name = '_method';
                                            method.value = 'DELETE';
                                            form.appendChild(method);
                                            document.body.appendChild(form);
                                            form.submit();
                                        }
                                    }}
                                >
                                    <Unplug className="mr-1 size-4" />
                                    Disable
                                </Button>
                            )}
                        </div>
                    </>
                )}
            </Form>
        </div>
    );
}
