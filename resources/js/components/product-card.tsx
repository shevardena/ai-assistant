import { ExternalLink, ImageOff, Tag } from 'lucide-react';
import { useState } from 'react';
import type { ProductCard, ProductCardStyles } from '@/types';

const defaultStyles: ProductCardStyles = {
    background_color: '#ffffff',
    text_color: '#171717',
    muted_text_color: '#737373',
    price_color: '#7c3aed',
    old_price_color: '#737373',
    discount_color: '#7c3aed',
    button_color: '#171717',
    button_text_color: '#ffffff',
};

export default function ProductCardView({
    card,
    className,
}: {
    card: ProductCard;
    className?: string;
}) {
    const image = safeUrl(card.image);
    const url = safeUrl(card.url);
    const [imageFailed, setImageFailed] = useState(false);
    const styles = card.styles ?? defaultStyles;

    return (
        <article
            className={`flex w-64 shrink-0 snap-start flex-col overflow-hidden rounded-2xl border border-black/10 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md ${className ?? ''}`}
            style={{
                backgroundColor: safeColor(
                    styles.background_color,
                    defaultStyles.background_color,
                ),
                color: safeColor(styles.text_color, defaultStyles.text_color),
            }}
        >
            <div className="relative h-40 overflow-hidden bg-neutral-50">
                {image && !imageFailed ? (
                    <img
                        src={image}
                        alt=""
                        loading="lazy"
                        className="h-full w-full object-cover"
                        onError={() => setImageFailed(true)}
                    />
                ) : image ? (
                    <div
                        className="flex h-40 w-full flex-col items-center justify-center gap-1 bg-neutral-100 text-neutral-400"
                        aria-label="Image unavailable"
                    >
                        <ImageOff className="size-6" aria-hidden="true" />
                        <span className="text-xs">Image unavailable</span>
                    </div>
                ) : null}
                {url ? (
                    <a
                        href={url}
                        target="_blank"
                        rel="noreferrer"
                        className="absolute top-2 right-2 rounded-full bg-white/90 p-2 text-neutral-700 shadow-sm hover:bg-white"
                        aria-label={`Open ${card.title}`}
                    >
                        <ExternalLink className="size-4" />
                    </a>
                ) : null}
            </div>
            <div className="flex min-h-36 flex-1 flex-col gap-2 p-3">
                <h3 className="line-clamp-2 min-h-10 text-sm font-semibold">
                    {card.title}
                </h3>
                {card.subtitle ? (
                    <p
                        className="text-xs"
                        style={{
                            color: safeColor(
                                styles.muted_text_color,
                                defaultStyles.muted_text_color,
                            ),
                        }}
                    >
                        {card.subtitle}
                    </p>
                ) : null}
                {card.description ? (
                    <p
                        className="line-clamp-3 text-sm"
                        style={{
                            color: safeColor(
                                styles.muted_text_color,
                                defaultStyles.muted_text_color,
                            ),
                        }}
                    >
                        {card.description}
                    </p>
                ) : null}
                {card.price !== null || card.old_price !== null ? (
                    <div className="mt-auto flex items-baseline gap-2 pt-2">
                        {card.price !== null ? (
                            <span
                                className="font-semibold"
                                style={{
                                    color: safeColor(
                                        styles.price_color,
                                        defaultStyles.price_color,
                                    ),
                                }}
                            >
                                {String(card.price)}
                            </span>
                        ) : null}
                        {card.old_price !== null ? (
                            <span
                                className="text-xs line-through"
                                style={{
                                    color: safeColor(
                                        styles.old_price_color,
                                        defaultStyles.old_price_color,
                                    ),
                                }}
                            >
                                {String(card.old_price)}
                            </span>
                        ) : null}
                        {card.discount !== null ? (
                            <span
                                className="inline-flex items-center gap-1 rounded-full bg-current/10 px-2 py-1 text-xs font-medium"
                                style={{
                                    color: safeColor(
                                        styles.discount_color,
                                        defaultStyles.discount_color,
                                    ),
                                }}
                            >
                                <Tag className="size-3" />
                                {String(card.discount)}
                            </span>
                        ) : null}
                    </div>
                ) : null}
                {url && card.button_label ? (
                    <a
                        href={url}
                        target="_blank"
                        rel="noreferrer"
                        className="mt-2 inline-flex min-h-10 items-center justify-center rounded-xl px-3 py-2 text-sm font-medium hover:opacity-90"
                        style={{
                            backgroundColor: safeColor(
                                styles.button_color,
                                defaultStyles.button_color,
                            ),
                            color: safeColor(
                                styles.button_text_color,
                                defaultStyles.button_text_color,
                            ),
                        }}
                    >
                        {card.button_label}
                    </a>
                ) : null}
            </div>
        </article>
    );
}

export function ProductCardList({
    cards,
    className,
    cardClassName,
}: {
    cards: ProductCard[];
    className?: string;
    cardClassName?: string;
}) {
    if (cards.length === 0) {
        return null;
    }

    return (
        <div
            className={`-mx-1 mt-3 flex max-w-full snap-x snap-mandatory items-stretch gap-3 overflow-x-auto px-1 pb-2 ${className ?? ''}`}
            aria-label="Product results"
        >
            {cards.map((card) => (
                <ProductCardView
                    key={card.id}
                    card={card}
                    className={cardClassName}
                />
            ))}
        </div>
    );
}

function safeUrl(value: string | null): string | null {
    if (!value) {
        return null;
    }

    try {
        const url = new URL(value);

        return url.protocol === 'http:' || url.protocol === 'https:'
            ? value
            : null;
    } catch {
        return null;
    }
}

function safeColor(value: string | undefined, fallback: string): string {
    return value && /^#[0-9a-f]{6}$/i.test(value) ? value : fallback;
}
