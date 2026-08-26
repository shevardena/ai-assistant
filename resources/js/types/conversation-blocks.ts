import type { ProductCard } from './cards';

export type ProductCardsBlock = {
    type: 'product_cards';
    data: {
        cards: ProductCard[];
    };
};

export type ComparisonValue = string | number | boolean | null;

export type ComparisonItem = {
    product_reference: string;
    label: string;
};

export type ComparisonField = {
    key: string;
    label: string;
    values: ComparisonValue[];
};

export type ComparisonBlock = {
    type: 'comparison';
    data: {
        items: ComparisonItem[];
        fields: ComparisonField[];
    };
};

export type OrderStatusValue = string | number | boolean | null;

export type OrderStatusField = {
    key: string;
    label: string;
    value: OrderStatusValue;
};

export type OrderStatusBlock = {
    type: 'order_status';
    data: {
        status?: string;
        fields: OrderStatusField[];
    };
};

export type TrackingValue = string | number | boolean | null;

export type TrackingField = {
    key: string;
    label: string;
    value: TrackingValue;
};

export type TrackingBlock = {
    type: 'tracking';
    data: {
        status?: string;
        carrier?: string;
        tracking_reference?: string;
        estimated_delivery?: string;
        latest_event?: string;
        tracking_url?: string;
        fields: TrackingField[];
    };
};

export type LocationValue = string | number | boolean;

export type LocationField = {
    key: string;
    label: string;
    value: LocationValue;
};

export type LocationItem = {
    name?: string;
    address?: string;
    city?: string;
    region?: string;
    postal_code?: string;
    country?: string;
    latitude?: number;
    longitude?: number;
    distance?: number | string;
    distance_unit?: string;
    phone?: string;
    hours?: string;
    url?: string;
    fields?: LocationField[];
};

export type LocationsBlock = {
    type: 'locations';
    data: {
        locations: LocationItem[];
    };
};

export type ConfirmationStatus =
    'pending' | 'confirmed' | 'completed' | 'cancelled' | 'failed';

export type ConfirmationBlock = {
    type: 'confirmation';
    data: {
        action_reference: string;
        summary: string;
        status: ConfirmationStatus;
        result?: Record<string, unknown>;
    };
};

export type FormFieldType =
    'text' | 'email' | 'tel' | 'textarea' | 'number' | 'select';

export type FormFieldOption = {
    value: string;
    label: string;
};

export type FormField = {
    name: string;
    label: string;
    type: FormFieldType;
    required: boolean;
    placeholder?: string;
    help_text?: string;
    options?: FormFieldOption[];
};

export type FormStatus = 'pending' | 'submitted' | 'cancelled';

export type FormBlock = {
    type: 'form';
    data: {
        form_reference: string;
        title?: string;
        description?: string;
        fields: FormField[];
        submit_label: string;
        status: FormStatus;
    };
};

export type AppointmentSlotsBlock = {
    type: 'appointment_slots';
    data: {
        appointment_reference: string;
        title?: string;
        timezone: string;
        slots: AppointmentSlot[];
        status: AppointmentSlotsStatus;
        selected_slot_reference?: string;
    };
};

export type AppointmentSlotsStatus =
    'pending' | 'selected' | 'expired' | 'cancelled';

export type AppointmentSlot = {
    slot_reference: string;
    starts_at: string;
    ends_at?: string | null;
    label?: string;
};

export type ConversationBlock =
    | ProductCardsBlock
    | ComparisonBlock
    | OrderStatusBlock
    | TrackingBlock
    | LocationsBlock
    | ConfirmationBlock
    | FormBlock
    | AppointmentSlotsBlock;
