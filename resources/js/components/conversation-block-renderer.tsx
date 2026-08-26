import { AppointmentSlotsBlock } from '@/components/appointment-slots-block';
import type { AppointmentSlotsAction } from '@/components/appointment-slots-block';
import { ComparisonBlock } from '@/components/comparison-block';
import { ConfirmationBlock } from '@/components/confirmation-block';
import type {
    ConfirmationBlockAction,
    ConfirmationBlockAppearance,
} from '@/components/confirmation-block';
import { FormBlock } from '@/components/form-block';
import type { FormBlockAction } from '@/components/form-block';
import { LocationsBlock } from '@/components/locations-block';
import { OrderStatusBlock } from '@/components/order-status-block';
import { ProductCardList } from '@/components/product-card';
import { TrackingBlock } from '@/components/tracking-block';
import type { ConversationBlock } from '@/types';

export function ConversationBlockRenderer({
    blocks,
    onAction,
    onFormSubmit,
    onAppointmentSelect,
    appearance,
    interactive = true,
}: {
    blocks: ConversationBlock[];
    onAction?: ConfirmationBlockAction;
    onFormSubmit?: FormBlockAction;
    onAppointmentSelect?: AppointmentSlotsAction;
    appearance?: ConfirmationBlockAppearance;
    interactive?: boolean;
}) {
    return (
        <>
            {blocks.map((block, index) => (
                <ConversationBlockItem
                    key={`${block.type}-${index}-${block.type === 'confirmation' ? `${block.data.action_reference}-${block.data.status}` : ''}`}
                    block={block}
                    onAction={interactive ? onAction : undefined}
                    onFormSubmit={interactive ? onFormSubmit : undefined}
                    onAppointmentSelect={
                        interactive ? onAppointmentSelect : undefined
                    }
                    appearance={appearance}
                />
            ))}
        </>
    );
}

function ConversationBlockItem({
    block,
    onAction,
    onFormSubmit,
    onAppointmentSelect,
    appearance,
}: {
    block: ConversationBlock;
    onAction?: ConfirmationBlockAction;
    onFormSubmit?: FormBlockAction;
    onAppointmentSelect?: AppointmentSlotsAction;
    appearance?: ConfirmationBlockAppearance;
}) {
    switch (block.type) {
        case 'product_cards':
            return <ProductCardList cards={block.data.cards} />;
        case 'confirmation':
            return (
                <ConfirmationBlock
                    block={block}
                    onAction={onAction}
                    appearance={appearance}
                />
            );
        case 'comparison':
            return <ComparisonBlock block={block} appearance={appearance} />;
        case 'order_status':
            return <OrderStatusBlock block={block} appearance={appearance} />;
        case 'tracking':
            return <TrackingBlock block={block} appearance={appearance} />;
        case 'locations':
            return <LocationsBlock block={block} appearance={appearance} />;
        case 'form':
            return (
                <FormBlock
                    block={block}
                    onSubmit={onFormSubmit}
                    appearance={appearance}
                />
            );
        case 'appointment_slots':
            return (
                <AppointmentSlotsBlock
                    block={block}
                    onSelect={onAppointmentSelect}
                    appearance={appearance}
                />
            );
        default:
            return null;
    }
}
