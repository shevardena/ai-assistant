export type ProductCard = {
    id: string;
    image: string | null;
    title: string;
    subtitle: string | null;
    description: string | null;
    price: number | string | null;
    old_price: number | string | null;
    discount: number | string | null;
    url: string | null;
    button_label: string | null;
    styles?: ProductCardStyles;
};

export type ProductCardStyles = {
    background_color: string;
    text_color: string;
    muted_text_color: string;
    price_color: string;
    old_price_color: string;
    discount_color: string;
    button_color: string;
    button_text_color: string;
};

export type BotWidgetAppearance = {
    title: string;
    input_placeholder: string;
    assistant_name: string;
    assistant_subtitle: string;
    avatar_url: string | null;
    launcher_text: string | null;
    launcher_mode: 'icon-text' | 'text-only' | 'icon-only';
    primary_color: string;
    accent_color: string;
    header_text_color: string;
    background_color: string;
    text_color: string;
    send_button_color: string;
    send_button_text_color: string;
    send_button_label: string;
    send_button_mode: 'icon-text' | 'text-only' | 'icon-only';
    send_button_icon: 'send' | 'arrow-right' | 'message';
    user_message_color: string;
    user_message_text_color: string;
    launcher_position: 'bottom-right' | 'bottom-left';
};

export type WidgetAvailability = 'online' | 'offline';
