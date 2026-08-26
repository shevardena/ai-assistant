export type BotStatus = 'draft' | 'ready' | 'published' | 'disabled';

export type BotDatasetOption = {
    id: number;
    name: string;
    slug: string;
    attached: boolean;
};

export type Bot = {
    id: number;
    name: string;
    slug: string;
    status: BotStatus;
    defaultLanguage: string;
    instructions: string | null;
    welcomeMessage: string | null;
    fallbackMessage: string | null;
    createdAt: string | null;
    updatedAt: string | null;
    datasets: BotDatasetOption[];
    domains: BotDomain[];
    widget: BotWidget;
};

export type BotDomain = {
    id: number;
    domain: string;
};

export type BotWidget = {
    publicId: string;
    baseUrl: string;
    datasetCount: number;
    domainCount: number;
    snippet: string;
    ready: boolean;
};

export type BotSummary = Pick<
    Bot,
    'id' | 'name' | 'slug' | 'status' | 'createdAt' | 'updatedAt'
>;

export type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

export type Paginated<T> = {
    current_page: number;
    data: T[];
    first_page_url: string;
    from: number | null;
    last_page: number;
    last_page_url: string;
    links: PaginationLink[];
    next_page_url: string | null;
    path: string;
    per_page: number;
    prev_page_url: string | null;
    to: number | null;
    total: number;
};
