import { Form, Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    Check,
    CircleCheck,
    ImagePlus,
    MessageCircle,
    Palette,
    ShoppingBag,
    SendHorizontal,
    WandSparkles,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { ProductCardList } from '@/components/product-card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { edit, update } from '@/routes/bots/design';
import { show as datasetShow } from '@/routes/datasets';
import type {
    BotWidgetAppearance,
    ProductCard,
    ProductCardStyles,
} from '@/types';

type Primitive = boolean | number | string | null;

type Field = {
    id: number;
    key: string;
    label: string;
    dataType: string;
    canonicalName: string | null;
    semanticType: string | null;
};

type Dataset = {
    id: number;
    name: string;
    slug: string;
    fields: Field[];
    template: {
        name: string;
        mapping: Record<string, number | string>;
        buttonLabel?: string;
        cardStyle?: Partial<ProductCardStyles>;
    } | null;
    sample: { id: string; values: Record<string, Primitive> } | null;
};

type Props = {
    bot: {
        id: number;
        name: string;
        welcomeMessage: string | null;
        appearance: Record<string, string | null>;
    };
    datasets: Dataset[];
    platform: { name: string; url: string };
};

type Tab = 'product' | 'assistant' | 'appearance';

const sendButtonIconOptions: Array<{
    value: BotWidgetAppearance['send_button_icon'];
    label: string;
    icon: LucideIcon;
}> = [
    { value: 'send', label: 'Send', icon: SendHorizontal },
    { value: 'arrow-right', label: 'Arrow right', icon: ArrowRight },
    { value: 'message', label: 'Message', icon: MessageCircle },
];

type CardSlot = {
    key: string;
    label: string;
    required?: boolean;
    hints: string[];
    preferredTypes: string[];
};

const cardSlots: CardSlot[] = [
    {
        key: 'image',
        label: 'Product image',
        hints: ['image_url', 'image', 'thumbnail_url'],
        preferredTypes: ['url', 'string'],
    },
    {
        key: 'title',
        label: 'Product title',
        required: true,
        hints: ['name', 'title', 'product_name'],
        preferredTypes: ['string'],
    },
    {
        key: 'subtitle',
        label: 'Subtitle',
        hints: ['brand', 'category', 'manufacturer'],
        preferredTypes: ['string'],
    },
    {
        key: 'description',
        label: 'Description',
        hints: ['description', 'summary'],
        preferredTypes: ['string'],
    },
    {
        key: 'price',
        label: 'Price',
        hints: ['price', 'sale_price'],
        preferredTypes: ['decimal', 'integer'],
    },
    {
        key: 'old_price',
        label: 'Original price',
        hints: ['old_price', 'compare_price', 'original_price'],
        preferredTypes: ['decimal', 'integer'],
    },
    {
        key: 'discount',
        label: 'Discount',
        hints: ['discount', 'discount_percent'],
        preferredTypes: ['decimal', 'integer', 'string'],
    },
    {
        key: 'url',
        label: 'Product link',
        hints: ['url', 'product_url', 'link'],
        preferredTypes: ['url', 'string'],
    },
];

const defaultCardStyles: ProductCardStyles = {
    background_color: '#ffffff',
    text_color: '#171717',
    muted_text_color: '#737373',
    price_color: '#7c3aed',
    old_price_color: '#737373',
    discount_color: '#7c3aed',
    button_color: '#171717',
    button_text_color: '#ffffff',
};

export default function BotDesign({ bot, datasets, platform }: Props) {
    const { currentTeam } = usePage().props;
    const [activeTab, setActiveTab] = useState<Tab>('product');
    const [datasetId, setDatasetId] = useState<number | null>(
        datasets[0]?.id ?? null,
    );
    const [mapping, setMapping] = useState<Record<string, string>>(() =>
        initialMapping(datasets[0]),
    );
    const [buttonLabel, setButtonLabel] = useState(
        () => datasets[0]?.template?.buttonLabel ?? 'View product',
    );
    const [mappingDirty, setMappingDirty] = useState(
        () => datasets[0]?.template === null,
    );
    const [cardStylesDirty, setCardStylesDirty] = useState(false);
    const [appearanceDirty, setAppearanceDirty] = useState(false);
    const [autoMapFeedback, setAutoMapFeedback] = useState<string | null>(null);
    const [saveFeedback, setSaveFeedback] = useState(false);
    const [appearance, setAppearance] = useState<BotWidgetAppearance>(() => ({
        title: bot.appearance.widget_title ?? bot.name,
        input_placeholder:
            bot.appearance.input_placeholder ?? 'Type a message...',
        assistant_name:
            bot.appearance.assistant_display_name ??
            bot.appearance.widget_title ??
            bot.name,
        assistant_subtitle: bot.appearance.assistant_subtitle ?? 'AI Assistant',
        avatar_url: bot.appearance.assistant_avatar_url ?? null,
        launcher_text: bot.appearance.launcher_text ?? null,
        launcher_mode:
            bot.appearance.launcher_mode === 'text-only' ||
            bot.appearance.launcher_mode === 'icon-only'
                ? bot.appearance.launcher_mode
                : 'icon-text',
        primary_color: bot.appearance.primary_color ?? '#171717',
        accent_color: bot.appearance.accent_color ?? '#f5f5f5',
        header_text_color: bot.appearance.header_text_color ?? '#171717',
        background_color: bot.appearance.background_color ?? '#ffffff',
        text_color: bot.appearance.text_color ?? '#171717',
        send_button_color:
            bot.appearance.send_button_color ??
            bot.appearance.primary_color ??
            '#171717',
        send_button_text_color:
            bot.appearance.send_button_text_color ?? '#ffffff',
        send_button_label: bot.appearance.send_button_label ?? 'Send',
        send_button_mode:
            bot.appearance.send_button_mode === 'text-only' ||
            bot.appearance.send_button_mode === 'icon-only'
                ? bot.appearance.send_button_mode
                : 'icon-text',
        send_button_icon:
            bot.appearance.send_button_icon === 'arrow-right' ||
            bot.appearance.send_button_icon === 'message'
                ? bot.appearance.send_button_icon
                : 'send',
        user_message_color: bot.appearance.user_message_color ?? '#171717',
        user_message_text_color:
            bot.appearance.user_message_text_color ?? '#ffffff',
        launcher_position:
            bot.appearance.launcher_position === 'bottom-left'
                ? 'bottom-left'
                : 'bottom-right',
    }));
    const [welcomeMessage, setWelcomeMessage] = useState(
        bot.welcomeMessage ??
            'Hi! I can help you find products and compare options.',
    );
    const [removeAvatar, setRemoveAvatar] = useState(false);
    const selectedDataset =
        datasets.find((dataset) => dataset.id === datasetId) ?? null;
    const [cardStyles, setCardStyles] = useState<ProductCardStyles>(() =>
        initialCardStyles(datasets[0]),
    );
    const hasChanges = mappingDirty || cardStylesDirty || appearanceDirty;
    const previewCards = useMemo(() => {
        if (!selectedDataset || selectedDataset.fields.length === 0) {
            return [];
        }

        const card = previewCard(
            selectedDataset,
            mapping,
            buttonLabel,
            cardStyles,
        );

        return Array.from({ length: 3 }, (_, index) => ({
            ...card,
            id: `${card.id}-preview-${index}`,
            title:
                index === 0
                    ? card.title
                    : `${card.title} · option ${index + 1}`,
        }));
    }, [buttonLabel, cardStyles, mapping, selectedDataset]);

    if (!currentTeam) {
        return null;
    }

    function updateAppearance(changes: Partial<BotWidgetAppearance>) {
        setAppearance((current) => ({ ...current, ...changes }));
        setAppearanceDirty(true);
        setSaveFeedback(false);
    }

    function selectDataset(value: string) {
        const next = datasets.find((dataset) => String(dataset.id) === value);

        if (!next) {
            return;
        }

        if (
            (mappingDirty || cardStylesDirty) &&
            !window.confirm(
                'Discard unsaved product card changes for the current dataset?',
            )
        ) {
            return;
        }

        setDatasetId(next.id);
        setMapping(initialMapping(next));
        setButtonLabel(next.template?.buttonLabel ?? 'View product');
        setCardStyles(initialCardStyles(next));
        setMappingDirty(next.template === null);
        setCardStylesDirty(false);
        setAutoMapFeedback(null);
        setSaveFeedback(false);
    }

    function updateMapping(slot: string, value: string) {
        setMapping((current) => ({ ...current, [slot]: value }));
        setMappingDirty(true);
        setAutoMapFeedback(null);
        setSaveFeedback(false);
    }

    function updateCardStyles(changes: Partial<ProductCardStyles>) {
        setCardStyles((current) => ({ ...current, ...changes }));
        setCardStylesDirty(true);
        setSaveFeedback(false);
    }

    function autoMap() {
        if (!selectedDataset) {
            return;
        }

        const next = autoMapFields(selectedDataset.fields);
        const count = Object.values(next).filter(Boolean).length;

        setMapping(next);
        setMappingDirty(true);
        setAutoMapFeedback(
            `${count} field${count === 1 ? '' : 's'} mapped automatically.`,
        );
        setSaveFeedback(false);
    }

    return (
        <>
            <Head title={`Design ${bot.name}`} />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <div className="flex items-start gap-3">
                    <Button variant="ghost" size="icon" asChild>
                        <Link
                            href={edit([currentTeam.slug, bot.id]).url}
                            aria-label="Back to bot"
                        >
                            <ArrowLeft />
                        </Link>
                    </Button>
                    <Heading
                        variant="small"
                        title={`${bot.name} design`}
                        description="Configure the product cards and widget appearance customers will see."
                    />
                </div>

                <Form
                    {...update.form([currentTeam.slug, bot.id])}
                    onSuccess={() => {
                        setMappingDirty(false);
                        setCardStylesDirty(false);
                        setAppearanceDirty(false);
                        setSaveFeedback(true);
                    }}
                    options={{ preserveScroll: true }}
                >
                    {({ errors, processing }) => (
                        <>
                            <input
                                type="hidden"
                                name="dataset_id"
                                value={datasetId ?? ''}
                            />
                            <input
                                type="hidden"
                                name="button_label"
                                value={mapping.url ? buttonLabel : ''}
                            />
                            {cardSlots.map((slot) => (
                                <input
                                    key={slot.key}
                                    type="hidden"
                                    name={`mapping[${slot.key}]`}
                                    value={mapping[slot.key] ?? ''}
                                />
                            ))}
                            <input
                                type="hidden"
                                name="appearance[widget_title]"
                                value={appearance.title}
                            />
                            <input
                                type="hidden"
                                name="appearance[input_placeholder]"
                                value={appearance.input_placeholder}
                            />
                            <input
                                type="hidden"
                                name="appearance[assistant_display_name]"
                                value={appearance.assistant_name}
                            />
                            <input
                                type="hidden"
                                name="appearance[assistant_subtitle]"
                                value={appearance.assistant_subtitle}
                            />
                            <input
                                type="hidden"
                                name="welcome_message"
                                value={welcomeMessage}
                            />
                            <input
                                type="hidden"
                                name="remove_avatar"
                                value={removeAvatar ? '1' : '0'}
                            />
                            <input
                                type="hidden"
                                name="appearance[primary_color]"
                                value={appearance.primary_color}
                            />
                            <input
                                type="hidden"
                                name="appearance[accent_color]"
                                value={appearance.accent_color}
                            />
                            <input
                                type="hidden"
                                name="appearance[header_text_color]"
                                value={appearance.header_text_color}
                            />
                            <input
                                type="hidden"
                                name="appearance[background_color]"
                                value={appearance.background_color}
                            />
                            <input
                                type="hidden"
                                name="appearance[text_color]"
                                value={appearance.text_color}
                            />
                            <input
                                type="hidden"
                                name="appearance[send_button_color]"
                                value={appearance.send_button_color}
                            />
                            <input
                                type="hidden"
                                name="appearance[send_button_text_color]"
                                value={appearance.send_button_text_color}
                            />
                            <input
                                type="hidden"
                                name="appearance[send_button_label]"
                                value={appearance.send_button_label}
                            />
                            <input
                                type="hidden"
                                name="appearance[send_button_mode]"
                                value={appearance.send_button_mode}
                            />
                            <input
                                type="hidden"
                                name="appearance[send_button_icon]"
                                value={appearance.send_button_icon}
                            />
                            <input
                                type="hidden"
                                name="appearance[user_message_color]"
                                value={appearance.user_message_color}
                            />
                            <input
                                type="hidden"
                                name="appearance[user_message_text_color]"
                                value={appearance.user_message_text_color}
                            />
                            <input
                                type="hidden"
                                name="appearance[launcher_position]"
                                value={appearance.launcher_position}
                            />
                            {Object.entries(cardStyles).map(([key, value]) => (
                                <input
                                    key={key}
                                    type="hidden"
                                    name={'card_style[' + key + ']'}
                                    value={value}
                                />
                            ))}

                            <div className="grid gap-6 lg:grid-cols-[minmax(0,1.5fr)_minmax(20rem,0.9fr)]">
                                <div className="min-w-0">
                                    <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                                        <div className="inline-flex rounded-lg border bg-muted/30 p-1">
                                            <TabButton
                                                active={activeTab === 'product'}
                                                icon={<ShoppingBag />}
                                                onClick={() =>
                                                    setActiveTab('product')
                                                }
                                            >
                                                Product Card
                                            </TabButton>
                                            <TabButton
                                                active={
                                                    activeTab === 'assistant'
                                                }
                                                icon={<ImagePlus />}
                                                onClick={() =>
                                                    setActiveTab('assistant')
                                                }
                                            >
                                                Assistant
                                            </TabButton>
                                            <TabButton
                                                active={
                                                    activeTab === 'appearance'
                                                }
                                                icon={<Palette />}
                                                onClick={() =>
                                                    setActiveTab('appearance')
                                                }
                                            >
                                                Appearance
                                            </TabButton>
                                        </div>
                                        {hasChanges ? (
                                            <Badge variant="outline">
                                                Unsaved changes
                                            </Badge>
                                        ) : (
                                            <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
                                                <CircleCheck className="size-4" />{' '}
                                                All changes saved
                                            </span>
                                        )}
                                    </div>

                                    {activeTab === 'appearance' ? (
                                        <AppearancePanel
                                            appearance={appearance}
                                            onChange={updateAppearance}
                                        />
                                    ) : activeTab === 'assistant' ? (
                                        <AssistantPanel
                                            appearance={appearance}
                                            welcomeMessage={welcomeMessage}
                                            avatarUrl={appearance.avatar_url}
                                            removeAvatar={removeAvatar}
                                            errors={errors}
                                            onChange={updateAppearance}
                                            onWelcomeMessageChange={(value) => {
                                                setWelcomeMessage(value);
                                                setAppearanceDirty(true);
                                                setSaveFeedback(false);
                                            }}
                                            onAvatarChange={(url) => {
                                                setAppearance((current) => ({
                                                    ...current,
                                                    avatar_url: url,
                                                }));
                                                setRemoveAvatar(false);
                                                setAppearanceDirty(true);
                                                setSaveFeedback(false);
                                            }}
                                            onRemoveAvatar={() => {
                                                setRemoveAvatar(true);
                                                setAppearance((current) => ({
                                                    ...current,
                                                    avatar_url: null,
                                                }));
                                                setAppearanceDirty(true);
                                                setSaveFeedback(false);
                                            }}
                                        />
                                    ) : (
                                        <ProductCardPanel
                                            bot={bot}
                                            currentTeamSlug={currentTeam.slug}
                                            datasets={datasets}
                                            selectedDataset={selectedDataset}
                                            datasetId={datasetId}
                                            mapping={mapping}
                                            cardStyles={cardStyles}
                                            buttonLabel={buttonLabel}
                                            autoMapFeedback={autoMapFeedback}
                                            errors={errors}
                                            onDatasetChange={selectDataset}
                                            onMappingChange={updateMapping}
                                            onCardStyleChange={updateCardStyles}
                                            onButtonLabelChange={(value) => {
                                                setButtonLabel(value);
                                                setMappingDirty(true);
                                                setSaveFeedback(false);
                                            }}
                                            onAutoMap={autoMap}
                                        />
                                    )}
                                </div>

                                <div className="self-start lg:sticky lg:top-6">
                                    <WidgetPreview
                                        appearance={appearance}
                                        inputPlaceholder={
                                            appearance.input_placeholder
                                        }
                                        cards={previewCards}
                                        hasDataset={selectedDataset !== null}
                                        welcomeMessage={welcomeMessage}
                                        platform={platform}
                                    />
                                </div>
                            </div>

                            <div className="sticky bottom-0 z-20 -mx-4 flex flex-col gap-3 border-t bg-background/95 px-4 py-3 shadow-lg backdrop-blur sm:flex-row sm:items-center sm:justify-between md:-mx-6 md:px-6">
                                <p className="text-sm text-muted-foreground">
                                    {saveFeedback
                                        ? 'Design saved.'
                                        : hasChanges
                                          ? 'Your changes are ready to save.'
                                          : 'Product cards use deterministic dataset fields.'}
                                </p>
                                <Button
                                    type="submit"
                                    disabled={!hasChanges || processing}
                                >
                                    {processing ? 'Saving...' : 'Save design'}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

function AppearancePanel({
    appearance,
    onChange,
}: {
    appearance: BotWidgetAppearance;
    onChange: (changes: Partial<BotWidgetAppearance>) => void;
}) {
    const selectedSendButtonIcon =
        sendButtonIconOptions.find(
            ({ value }) => value === appearance.send_button_icon,
        ) ?? sendButtonIconOptions[0];
    const SelectedSendButtonIcon = selectedSendButtonIcon.icon;

    return (
        <Card>
            <CardHeader>
                <CardTitle>Appearance</CardTitle>
                <p className="text-sm text-muted-foreground">
                    Keep the widget aligned with your brand.
                </p>
            </CardHeader>
            <CardContent className="grid gap-4 sm:grid-cols-2">
                <TextSetting
                    label="Widget title"
                    value={appearance.title}
                    onChange={(value) => onChange({ title: value })}
                />
                <TextSetting
                    label="Input placeholder"
                    value={appearance.input_placeholder}
                    onChange={(value) => onChange({ input_placeholder: value })}
                />
                <ColorSetting
                    label="Send button background"
                    value={appearance.send_button_color}
                    onChange={(value) =>
                        onChange({
                            send_button_color: value,
                            primary_color: value,
                        })
                    }
                />
                <ColorSetting
                    label="Send icon/text color"
                    value={appearance.send_button_text_color}
                    onChange={(value) =>
                        onChange({ send_button_text_color: value })
                    }
                />
                <TextSetting
                    label="Send button text"
                    value={appearance.send_button_label}
                    onChange={(value) => onChange({ send_button_label: value })}
                />
                <div className="grid gap-2">
                    <Label htmlFor="send-button-mode">
                        Send button display
                    </Label>
                    <select
                        id="send-button-mode"
                        value={appearance.send_button_mode}
                        onChange={(event) =>
                            onChange({
                                send_button_mode: event.target
                                    .value as BotWidgetAppearance['send_button_mode'],
                            })
                        }
                        className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    >
                        <option value="icon-text">Icon and text</option>
                        <option value="text-only">Text only</option>
                        <option value="icon-only">Icon only</option>
                    </select>
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="send-button-icon">Send button icon</Label>
                    <Select
                        value={appearance.send_button_icon}
                        onValueChange={(value) =>
                            onChange({
                                send_button_icon:
                                    value as BotWidgetAppearance['send_button_icon'],
                            })
                        }
                    >
                        <SelectTrigger id="send-button-icon" className="w-full">
                            <SelectValue>
                                <span className="flex items-center gap-2">
                                    <SelectedSendButtonIcon className="size-4" />
                                    {selectedSendButtonIcon.label}
                                </span>
                            </SelectValue>
                        </SelectTrigger>
                        <SelectContent>
                            {sendButtonIconOptions.map(
                                ({ value, label, icon: Icon }) => (
                                    <SelectItem key={value} value={value}>
                                        <Icon className="size-4" />
                                        {label}
                                    </SelectItem>
                                ),
                            )}
                        </SelectContent>
                    </Select>
                </div>
                <ColorSetting
                    label="Header color"
                    value={appearance.accent_color}
                    onChange={(value) => onChange({ accent_color: value })}
                />
                <ColorSetting
                    label="Header text color"
                    value={appearance.header_text_color}
                    onChange={(value) => onChange({ header_text_color: value })}
                />
                <ColorSetting
                    label="Widget background"
                    value={appearance.background_color}
                    onChange={(value) => onChange({ background_color: value })}
                />
                <ColorSetting
                    label="Widget text"
                    value={appearance.text_color}
                    onChange={(value) => onChange({ text_color: value })}
                />
                <div className="grid gap-4 sm:col-span-2 sm:grid-cols-2">
                    <ColorSetting
                        label="Customer text background"
                        value={appearance.user_message_color}
                        onChange={(value) =>
                            onChange({ user_message_color: value })
                        }
                    />
                    <ColorSetting
                        label="Customer text color"
                        value={appearance.user_message_text_color}
                        onChange={(value) =>
                            onChange({ user_message_text_color: value })
                        }
                    />
                </div>
                <div className="grid gap-2 sm:col-span-2">
                    <Label htmlFor="launcher-position">Launcher position</Label>
                    <select
                        id="launcher-position"
                        value={appearance.launcher_position}
                        onChange={(event) =>
                            onChange({
                                launcher_position: event.target
                                    .value as BotWidgetAppearance['launcher_position'],
                            })
                        }
                        className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    >
                        <option value="bottom-right">Bottom right</option>
                        <option value="bottom-left">Bottom left</option>
                    </select>
                </div>
            </CardContent>
        </Card>
    );
}

function AssistantPanel({
    appearance,
    welcomeMessage,
    avatarUrl,
    removeAvatar,
    errors,
    onChange,
    onWelcomeMessageChange,
    onAvatarChange,
    onRemoveAvatar,
}: {
    appearance: BotWidgetAppearance;
    welcomeMessage: string;
    avatarUrl: string | null;
    removeAvatar: boolean;
    errors: Record<string, string>;
    onChange: (changes: Partial<BotWidgetAppearance>) => void;
    onWelcomeMessageChange: (value: string) => void;
    onAvatarChange: (url: string) => void;
    onRemoveAvatar: () => void;
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Assistant</CardTitle>
                <p className="text-sm text-muted-foreground">
                    Set the public identity visitors see. Private behavior
                    instructions stay in Bot settings.
                </p>
            </CardHeader>
            <CardContent className="grid gap-4">
                <div className="flex items-center gap-3 rounded-xl border p-3">
                    <PreviewAvatar
                        name={appearance.assistant_name}
                        src={avatarUrl}
                    />
                    <div className="grid gap-1">
                        <span className="text-sm font-medium">
                            Assistant avatar
                        </span>
                        <span className="text-xs text-muted-foreground">
                            JPG, PNG, or WebP up to 2 MB.
                        </span>
                    </div>
                    {avatarUrl && !removeAvatar ? (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="ml-auto"
                            onClick={onRemoveAvatar}
                        >
                            Remove
                        </Button>
                    ) : null}
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="assistant-avatar">Upload avatar</Label>
                    <Input
                        id="assistant-avatar"
                        name="assistant_avatar"
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        onChange={(event) => {
                            const file = event.target.files?.[0];

                            if (file) {
                                onAvatarChange(URL.createObjectURL(file));
                            }
                        }}
                    />
                    <InputError message={errors.assistant_avatar} />
                </div>
                <TextSetting
                    label="Assistant name"
                    value={appearance.assistant_name}
                    onChange={(value) => onChange({ assistant_name: value })}
                />
                <InputError
                    message={errors['appearance.assistant_display_name']}
                />
                <TextSetting
                    label="Subtitle / role"
                    value={appearance.assistant_subtitle}
                    onChange={(value) =>
                        onChange({ assistant_subtitle: value })
                    }
                />
                <InputError message={errors['appearance.assistant_subtitle']} />
                <div className="grid gap-2">
                    <Label htmlFor="welcome-message">Welcome message</Label>
                    <textarea
                        id="welcome-message"
                        value={welcomeMessage}
                        onChange={(event) =>
                            onWelcomeMessageChange(event.target.value)
                        }
                        maxLength={1000}
                        rows={3}
                        className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    />
                    <InputError message={errors.welcome_message} />
                </div>
            </CardContent>
        </Card>
    );
}

function ProductCardPanel({
    bot,
    currentTeamSlug,
    datasets,
    selectedDataset,
    datasetId,
    mapping,
    cardStyles,
    buttonLabel,
    autoMapFeedback,
    errors,
    onDatasetChange,
    onMappingChange,
    onCardStyleChange,
    onButtonLabelChange,
    onAutoMap,
}: {
    bot: Props['bot'];
    currentTeamSlug: string;
    datasets: Dataset[];
    selectedDataset: Dataset | null;
    datasetId: number | null;
    mapping: Record<string, string>;
    cardStyles: ProductCardStyles;
    buttonLabel: string;
    autoMapFeedback: string | null;
    errors: Record<string, string>;
    onDatasetChange: (value: string) => void;
    onMappingChange: (slot: string, value: string) => void;
    onCardStyleChange: (changes: Partial<ProductCardStyles>) => void;
    onButtonLabelChange: (value: string) => void;
    onAutoMap: () => void;
}) {
    if (datasets.length === 0) {
        return (
            <Card>
                <CardHeader>
                    <CardTitle>Product Card</CardTitle>
                </CardHeader>
                <CardContent className="grid gap-3">
                    <p className="text-sm text-muted-foreground">
                        Attach a Dataset before configuring product cards.
                    </p>
                    <Button variant="outline" asChild>
                        <Link href={edit([currentTeamSlug, bot.id]).url}>
                            Manage datasets
                        </Link>
                    </Button>
                </CardContent>
            </Card>
        );
    }

    const hasDisplayableFields = (selectedDataset?.fields.length ?? 0) > 0;

    return (
        <Card>
            <CardHeader className="gap-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <CardTitle>Product Card</CardTitle>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Use Dataset fields to decide what appears on each
                            product card.
                        </p>
                    </div>
                    <Badge
                        variant={
                            selectedDataset?.template ? 'default' : 'secondary'
                        }
                    >
                        {selectedDataset?.template
                            ? 'Product card configured'
                            : 'Not configured'}
                    </Badge>
                </div>
                <div className="grid gap-2 sm:max-w-sm">
                    <Label htmlFor="card-dataset">Dataset</Label>
                    <select
                        id="card-dataset"
                        value={datasetId === null ? '' : String(datasetId)}
                        onChange={(event) =>
                            onDatasetChange(event.target.value)
                        }
                        className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    >
                        {datasets.map((dataset) => (
                            <option key={dataset.id} value={dataset.id}>
                                {dataset.name} ·{' '}
                                {dataset.template
                                    ? 'Configured'
                                    : 'Not configured'}
                            </option>
                        ))}
                    </select>
                </div>
            </CardHeader>
            <CardContent className="grid gap-4">
                {!hasDisplayableFields ? (
                    <div className="grid gap-3 rounded-lg border border-dashed p-4">
                        <p className="text-sm text-muted-foreground">
                            This Dataset has no displayable fields. Mark fields
                            as Displayable in Dataset mappings first.
                        </p>
                        {selectedDataset ? (
                            <Button variant="outline" size="sm" asChild>
                                <Link
                                    href={
                                        datasetShow([
                                            currentTeamSlug,
                                            selectedDataset.id,
                                        ]).url
                                    }
                                >
                                    Open Dataset mappings
                                </Link>
                            </Button>
                        ) : null}
                    </div>
                ) : (
                    <>
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <p className="text-xs text-muted-foreground">
                                Title is required. Optional slots use None to
                                stay hidden.
                            </p>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={onAutoMap}
                            >
                                <WandSparkles /> Auto-map fields
                            </Button>
                        </div>
                        {autoMapFeedback ? (
                            <p className="inline-flex items-center gap-2 text-sm text-emerald-600 dark:text-emerald-400">
                                <Check className="size-4" /> {autoMapFeedback}
                            </p>
                        ) : null}
                        <div className="overflow-x-auto rounded-lg border">
                            <table className="w-full min-w-[42rem] text-left text-sm">
                                <thead className="bg-muted/40 text-xs text-muted-foreground">
                                    <tr>
                                        <th className="px-3 py-2 font-medium">
                                            Card slot
                                        </th>
                                        <th className="px-3 py-2 font-medium">
                                            Mapped Dataset field
                                        </th>
                                        <th className="px-3 py-2 font-medium">
                                            State
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {cardSlots.map((slot) => {
                                        const mappedField =
                                            selectedDataset?.fields.find(
                                                (field) =>
                                                    String(field.id) ===
                                                    mapping[slot.key],
                                            );

                                        return (
                                            <tr key={slot.key}>
                                                <td className="px-3 py-3 align-middle">
                                                    <span className="font-medium">
                                                        {slot.label}
                                                    </span>
                                                    {slot.required ? (
                                                        <span className="ml-2 text-xs text-muted-foreground">
                                                            Required
                                                        </span>
                                                    ) : null}
                                                </td>
                                                <td className="px-3 py-3 align-middle">
                                                    <select
                                                        value={
                                                            mapping[slot.key] ??
                                                            'none'
                                                        }
                                                        onChange={(event) =>
                                                            onMappingChange(
                                                                slot.key,
                                                                event.target
                                                                    .value ===
                                                                    'none'
                                                                    ? ''
                                                                    : event
                                                                          .target
                                                                          .value,
                                                            )
                                                        }
                                                        className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                                    >
                                                        {!slot.required ? (
                                                            <option value="none">
                                                                None
                                                            </option>
                                                        ) : null}
                                                        {orderedFields(
                                                            selectedDataset?.fields ??
                                                                [],
                                                            slot,
                                                        ).map((field) => (
                                                            <option
                                                                key={field.id}
                                                                value={field.id}
                                                            >
                                                                {field.label} —{' '}
                                                                {field.key} ·{' '}
                                                                {field.dataType}
                                                            </option>
                                                        ))}
                                                    </select>
                                                    {mappedField ? (
                                                        <p className="mt-1 text-xs text-muted-foreground">
                                                            {mappedField.label}{' '}
                                                            ·{' '}
                                                            {
                                                                mappedField.dataType
                                                            }
                                                        </p>
                                                    ) : null}
                                                </td>
                                                <td className="px-3 py-3 align-middle text-xs text-muted-foreground">
                                                    {slot.required ? (
                                                        <span className="font-medium text-foreground">
                                                            Required
                                                        </span>
                                                    ) : mapping[slot.key] ? (
                                                        <span className="inline-flex items-center gap-1 text-emerald-600 dark:text-emerald-400">
                                                            <Check className="size-3.5" />{' '}
                                                            Enabled
                                                        </span>
                                                    ) : (
                                                        'Off'
                                                    )}
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                        <InputError message={errors.dataset_id} />
                        <InputError message={errors.mapping} />
                        {mapping.url ? (
                            <div className="grid gap-2 sm:max-w-sm">
                                <Label htmlFor="button-label">
                                    Button text
                                </Label>
                                <Input
                                    id="button-label"
                                    value={buttonLabel}
                                    onChange={(event) =>
                                        onButtonLabelChange(event.target.value)
                                    }
                                    placeholder="View product"
                                />
                                <p className="text-xs text-muted-foreground">
                                    Shown only when Product link is configured.
                                </p>
                            </div>
                        ) : null}
                        <div className="grid gap-3 rounded-lg border p-4">
                            <div>
                                <p className="text-sm font-medium">
                                    Product card styling
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    Customize card background, text, and CTA
                                    colors.
                                </p>
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <ColorSetting
                                    label="Card background"
                                    value={cardStyles.background_color}
                                    onChange={(value) =>
                                        onCardStyleChange({
                                            background_color: value,
                                        })
                                    }
                                />
                                <ColorSetting
                                    label="Card text"
                                    value={cardStyles.text_color}
                                    onChange={(value) =>
                                        onCardStyleChange({
                                            text_color: value,
                                        })
                                    }
                                />
                                <ColorSetting
                                    label="Muted text"
                                    value={cardStyles.muted_text_color}
                                    onChange={(value) =>
                                        onCardStyleChange({
                                            muted_text_color: value,
                                        })
                                    }
                                />
                                <div className="grid gap-4 sm:col-span-2 sm:grid-cols-2">
                                    <ColorSetting
                                        label="Price color"
                                        value={cardStyles.price_color}
                                        onChange={(value) =>
                                            onCardStyleChange({
                                                price_color: value,
                                            })
                                        }
                                    />
                                    <ColorSetting
                                        label="Discount color"
                                        value={cardStyles.discount_color}
                                        onChange={(value) =>
                                            onCardStyleChange({
                                                discount_color: value,
                                            })
                                        }
                                    />
                                    <ColorSetting
                                        label="Old price color"
                                        value={cardStyles.old_price_color}
                                        onChange={(value) =>
                                            onCardStyleChange({
                                                old_price_color: value,
                                            })
                                        }
                                    />
                                    <ColorSetting
                                        label="Button color"
                                        value={cardStyles.button_color}
                                        onChange={(value) =>
                                            onCardStyleChange({
                                                button_color: value,
                                            })
                                        }
                                    />
                                    <ColorSetting
                                        label="Button text color"
                                        value={cardStyles.button_text_color}
                                        onChange={(value) =>
                                            onCardStyleChange({
                                                button_text_color: value,
                                            })
                                        }
                                    />
                                </div>
                            </div>
                        </div>
                    </>
                )}
            </CardContent>
        </Card>
    );
}

function WidgetPreview({
    appearance,
    inputPlaceholder,
    cards,
    hasDataset,
    welcomeMessage,
    platform,
}: {
    appearance: BotWidgetAppearance;
    inputPlaceholder: string;
    cards: ProductCard[];
    hasDataset: boolean;
    welcomeMessage: string;
    platform: { name: string; url: string };
}) {
    return (
        <Card className="mx-auto w-full max-w-[380px] overflow-hidden shadow-lg">
            <CardHeader className="border-b px-4 py-3">
                <CardTitle className="text-sm">Live preview</CardTitle>
            </CardHeader>
            <CardContent className="p-3">
                <div
                    className="flex h-[560px] flex-col overflow-hidden rounded-3xl border shadow-sm"
                    style={{
                        backgroundColor: appearance.background_color,
                        color: appearance.text_color,
                    }}
                >
                    <header
                        className="flex items-center justify-between border-b border-neutral-200/70 px-4 py-3 shadow-[0_1px_10px_rgb(15_23_42/4%)]"
                        style={{
                            backgroundColor: appearance.accent_color,
                            color: appearance.header_text_color,
                        }}
                    >
                        <div className="flex items-center gap-2">
                            <div className="flex size-7 items-center justify-center rounded-full bg-neutral-950 text-[10px] font-semibold text-white">
                                N
                            </div>
                            <a
                                href={platform.url}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="text-xs text-inherit hover:underline"
                            >
                                Built with <strong>{platform.name}</strong>
                            </a>
                        </div>
                        <div className="flex items-center gap-2 text-xs text-inherit">
                            <span>Preview</span>
                        </div>
                    </header>
                    <div
                        className="flex flex-1 flex-col gap-3 overflow-y-auto p-3"
                        style={{
                            backgroundColor: appearance.background_color,
                        }}
                    >
                        <div className="flex items-start gap-2">
                            <PreviewAvatar
                                name={appearance.assistant_name}
                                src={appearance.avatar_url}
                            />
                            <div
                                className="max-w-[90%] rounded-2xl rounded-tl-md px-3 py-2 text-sm shadow-sm"
                                style={{
                                    backgroundColor: '#f5f5f7',
                                    color: appearance.text_color,
                                }}
                            >
                                <p className="mb-1 text-[10px] font-semibold opacity-60">
                                    {appearance.assistant_name} ·{' '}
                                    {appearance.assistant_subtitle}
                                </p>
                                {welcomeMessage || 'Hello! How can I help?'}
                            </div>
                        </div>
                        <div
                            className="max-w-[90%] rounded-2xl rounded-tl-md bg-muted px-3 py-2 text-sm shadow-sm"
                            style={{
                                backgroundColor: appearance.background_color,
                                color: appearance.text_color,
                            }}
                        >
                            {hasDataset
                                ? 'I found these products:'
                                : 'Attach a Dataset to preview product cards.'}
                        </div>
                        <div
                            className="ml-auto w-fit max-w-[90%] rounded-2xl rounded-br-md border border-neutral-200/70 px-3 py-2 text-sm shadow-none"
                            style={{
                                backgroundColor: appearance.user_message_color,
                                color: appearance.user_message_text_color,
                            }}
                        >
                            Show me the available options
                        </div>
                        <ProductCardList
                            cards={cards}
                            className="shrink-0"
                            cardClassName="min-h-[360px]"
                        />
                    </div>
                    <div
                        className="flex items-center gap-2 border-t p-3"
                        style={{
                            backgroundColor: appearance.background_color,
                        }}
                    >
                        <div
                            className="min-w-0 flex-1 rounded-full border px-4 py-2.5 text-sm opacity-60"
                            style={{ color: appearance.text_color }}
                        >
                            {inputPlaceholder || 'Ask me anything'}
                        </div>
                        <div
                            className="inline-flex items-center justify-center gap-1.5 rounded-full px-3 py-2 text-sm"
                            style={{
                                backgroundColor: appearance.send_button_color,
                                color: appearance.send_button_text_color,
                            }}
                        >
                            {appearance.send_button_mode !== 'text-only' ? (
                                <SendButtonIcon
                                    icon={appearance.send_button_icon}
                                />
                            ) : null}
                            {appearance.send_button_mode !== 'icon-only' ? (
                                <span>{appearance.send_button_label}</span>
                            ) : null}
                        </div>
                    </div>
                </div>
            </CardContent>
        </Card>
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

function PreviewAvatar({ name, src }: { name: string; src: string | null }) {
    const [failedSrc, setFailedSrc] = useState<string | null>(null);
    const imageFailed = src !== null && failedSrc === src;

    if (src && !imageFailed) {
        return (
            <img
                src={src}
                alt={`${name} avatar`}
                className="size-10 rounded-full object-cover"
                onError={() => setFailedSrc(src)}
            />
        );
    }

    return (
        <div className="flex size-10 items-center justify-center rounded-full bg-gradient-to-br from-violet-500 to-fuchsia-400 text-sm font-semibold text-white">
            {name.trim().charAt(0).toUpperCase() || 'A'}
        </div>
    );
}

function TabButton({
    active,
    icon,
    children,
    onClick,
}: {
    active: boolean;
    icon: ReactNode;
    children: ReactNode;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            aria-pressed={active}
            onClick={onClick}
            className={`inline-flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium transition-colors ${active ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground'}`}
        >
            {icon}
            {children}
        </button>
    );
}

function TextSetting({
    label,
    value,
    onChange,
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
}) {
    return (
        <div className="grid gap-2">
            <Label>{label}</Label>
            <Input
                value={value}
                onChange={(event) => onChange(event.target.value)}
            />
        </div>
    );
}

function ColorSetting({
    label,
    value,
    onChange,
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
}) {
    const pickerValue = isHexColor(value) ? value : '#171717';

    return (
        <div className="grid gap-2">
            <Label>{label}</Label>
            <div className="flex gap-2">
                <input
                    type="color"
                    value={pickerValue}
                    onChange={(event) => onChange(event.target.value)}
                    className="h-9 w-12 cursor-pointer rounded-md border bg-background p-1"
                    aria-label={`${label} picker`}
                />
                <Input
                    value={value}
                    onChange={(event) => onChange(event.target.value)}
                    aria-label={`${label} hex value`}
                    className="font-mono"
                />
            </div>
        </div>
    );
}

function initialMapping(dataset: Dataset | undefined): Record<string, string> {
    if (!dataset) {
        return {};
    }

    return dataset.template
        ? normalizeMapping(dataset)
        : autoMapFields(dataset.fields);
}

function initialCardStyles(dataset: Dataset | undefined): ProductCardStyles {
    const styles = dataset?.template?.cardStyle ?? {};

    return {
        background_color: safeHexColor(
            styles.background_color,
            defaultCardStyles.background_color,
        ),
        text_color: safeHexColor(
            styles.text_color,
            defaultCardStyles.text_color,
        ),
        muted_text_color: safeHexColor(
            styles.muted_text_color,
            defaultCardStyles.muted_text_color,
        ),
        price_color: safeHexColor(
            styles.price_color,
            defaultCardStyles.price_color,
        ),
        old_price_color: safeHexColor(
            styles.old_price_color,
            defaultCardStyles.old_price_color,
        ),
        discount_color: safeHexColor(
            styles.discount_color,
            defaultCardStyles.discount_color,
        ),
        button_color: safeHexColor(
            styles.button_color,
            defaultCardStyles.button_color,
        ),
        button_text_color: safeHexColor(
            styles.button_text_color,
            defaultCardStyles.button_text_color,
        ),
    };
}

function normalizeMapping(dataset: Dataset): Record<string, string> {
    const normalized: Record<string, string> = {};

    for (const [slot, reference] of Object.entries(
        dataset.template?.mapping ?? {},
    )) {
        const field = dataset.fields.find(
            (item) => item.id === Number(reference) || item.key === reference,
        );

        if (field && cardSlots.some((cardSlot) => cardSlot.key === slot)) {
            normalized[slot] = String(field.id);
        }
    }

    return normalized;
}

function autoMapFields(fields: Field[]): Record<string, string> {
    const result: Record<string, string> = {};
    const used = new Set<number>();

    for (const slot of cardSlots) {
        const match = orderedFields(fields, slot).find(
            (field) => !used.has(field.id) && fieldScore(field, slot) > 0,
        );

        if (match) {
            result[slot.key] = String(match.id);
            used.add(match.id);
        }
    }

    return result;
}

function orderedFields(fields: Field[], slot: CardSlot): Field[] {
    return [...fields].sort(
        (left, right) => fieldScore(right, slot) - fieldScore(left, slot),
    );
}

function fieldScore(field: Field, slot: CardSlot): number {
    const values = [
        field.key,
        field.canonicalName,
        field.label,
        field.semanticType,
    ]
        .filter((value): value is string => Boolean(value))
        .map(normalizeHint);
    const hints = slot.hints.map(normalizeHint);
    let score = 0;

    for (const value of values) {
        for (const hint of hints) {
            if (value === hint) {
                score = Math.max(score, 100);
            } else if (value.includes(hint) || hint.includes(value)) {
                score = Math.max(score, 70);
            }
        }
    }

    return score > 0 && slot.preferredTypes.includes(field.dataType)
        ? score + 10
        : score;
}

function normalizeHint(value: string): string {
    return value.toLowerCase().replace(/[^a-z0-9]/g, '');
}

function previewCard(
    dataset: Dataset,
    mapping: Record<string, string>,
    buttonLabel: string,
    styles: ProductCardStyles,
): ProductCard {
    const values = dataset.sample?.values ?? {};
    const value = (slot: string): Primitive => {
        const field = dataset.fields.find(
            (item) => String(item.id) === mapping[slot],
        );

        return field ? (values[field.key] ?? null) : null;
    };

    return {
        id: dataset.sample?.id ?? 'preview',
        image: stringValue(value('image')),
        title: stringValue(value('title')) ?? 'Product title',
        subtitle: stringValue(value('subtitle')),
        description: stringValue(value('description')),
        price: numberOrString(value('price')),
        old_price: numberOrString(value('old_price')),
        discount: numberOrString(value('discount')),
        url: stringValue(value('url')),
        button_label: mapping.url ? buttonLabel.trim() || 'View product' : null,
        styles,
    };
}

function stringValue(value: Primitive): string | null {
    return typeof value === 'string' && value.trim() !== ''
        ? value
        : value !== null && value !== false
          ? String(value)
          : null;
}

function numberOrString(value: Primitive): number | string | null {
    return typeof value === 'number' || typeof value === 'string'
        ? value
        : null;
}

function isHexColor(value: string): boolean {
    return /^#[0-9a-f]{6}$/i.test(value);
}

function safeHexColor(value: string | undefined, fallback: string): string {
    return value && isHexColor(value) ? value : fallback;
}
