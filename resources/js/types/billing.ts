export type PlanFeature =
    | 'analytics'
    | 'human_handoff'
    | 'workflows'
    | 'bot_testing'
    | 'advanced_health'
    | 'business_templates'
    | 'notifications'
    | 'voice_input';

export type PlanLimit =
    'bots' | 'team_members' | 'monthly_conversations' | 'monthly_actions';

export type SubscriptionStatus =
    'active' | 'trialing' | 'past_due' | 'incomplete' | 'cancelled';

export type PlanLimitDefinition = {
    value: number | null;
    warning_threshold: number;
    enforcement: 'soft' | 'hard';
};

export type PlanDefinition = {
    key: string;
    name: string;
    description: string;
    public: boolean;
    features: Partial<Record<PlanFeature, string>>;
    limits: Record<PlanLimit, PlanLimitDefinition>;
    stripe_configured?: boolean;
    display_price?: string | null;
    currency?: string;
};

export type BillingSubscriptionSummary = {
    provider: 'stripe' | null;
    status: SubscriptionStatus;
    cancel_at_period_end: boolean;
    current_period_end: string | null;
    has_billing_customer: boolean;
};

export type BillingUsageMetric = {
    key: PlanLimit;
    label: string;
    used: number;
    limit: number | null;
    unlimited: boolean;
    percentage: number | null;
    warning: boolean;
    reached: boolean;
    enforcement: 'soft' | 'hard';
};

export type TeamBillingSummary = {
    plan: PlanDefinition;
    status: SubscriptionStatus;
    period: {
        start: string;
        end: string;
        label: string;
    };
    usage: Record<PlanLimit, BillingUsageMetric>;
    features: Record<PlanFeature, boolean>;
};

export type BillingPageProps = {
    summary: TeamBillingSummary;
    plans: PlanDefinition[];
    subscription: BillingSubscriptionSummary;
};
