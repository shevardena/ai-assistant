export type ConversationChannel =
    | 'website'
    | 'whatsapp'
    | 'instagram'
    | 'facebook_messenger'
    | 'telegram'
    | 'sms'
    | 'email';

export type ChannelConnectionStatus = 'draft' | 'active' | 'error' | 'disabled';

export type ChannelCapability =
    | 'text'
    | 'buttons'
    | 'images'
    | 'product_cards'
    | 'forms'
    | 'file_attachments'
    | 'typing_indicator'
    | 'comparison'
    | 'order_status'
    | 'tracking'
    | 'locations'
    | 'confirmation'
    | 'appointment_slots';

export type ChannelDefinition = {
    key: ConversationChannel;
    name: string;
    description: string;
    implemented: boolean;
    capabilities: ChannelCapability[];
};

export type ChannelConnection = {
    name: string | null;
    status: ChannelConnectionStatus;
    allowedDomains: number;
    widgetReady: boolean;
    links: {
        design: string;
        domains: string;
        embed: string;
    };
    phoneNumberId?: string | null;
    businessAccountId?: string | null;
    displayPhoneNumber?: string | null;
    verifiedName?: string | null;
    tokenConfigured?: boolean;
    tokenLastFour?: string | null;
    instagramAccountId?: string | null;
    facebookPageId?: string | null;
    displayName?: string | null;
    username?: string | null;
    pageName?: string | null;
    botId?: number | null;
    botUsername?: string | null;
    webhookConfigured?: boolean;
    phoneNumber?: string | null;
    inboundAddress?: string | null;
    fromAddress?: string | null;
    fromName?: string | null;
    replyToAddress?: string | null;
    inboundStatus?: string | null;
    webhookUrl?: string | null;
};

export type BotChannel = ChannelDefinition & {
    connection: ChannelConnection | null;
};

export type BotChannelsPageProps = {
    bot: {
        id: number;
        name: string;
        slug: string;
    };
    channels: BotChannel[];
    permissions: {
        canManage: boolean;
    };
};
