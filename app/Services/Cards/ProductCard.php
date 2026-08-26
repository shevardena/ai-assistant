<?php

namespace App\Services\Cards;

final readonly class ProductCard
{
    public function __construct(
        public string $id,
        public ?string $image,
        public string $title,
        public ?string $subtitle,
        public ?string $description,
        public int|float|string|null $price,
        public int|float|string|null $oldPrice,
        public int|float|string|null $discount,
        public ?string $url,
        public ?string $buttonLabel,
        /** @var array{background_color: string, text_color: string, muted_text_color: string, price_color: string, old_price_color: string, discount_color: string, button_color: string, button_text_color: string} */
        public array $styles,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'image' => $this->image,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'description' => $this->description,
            'price' => $this->price,
            'old_price' => $this->oldPrice,
            'discount' => $this->discount,
            'url' => $this->url,
            'button_label' => $this->buttonLabel,
            'styles' => $this->styles,
        ];
    }
}
