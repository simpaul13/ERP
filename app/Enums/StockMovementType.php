<?php

namespace App\Enums;

enum StockMovementType: string
{
    case Opening = 'opening';
    case Receipt = 'receipt';
    case Issue = 'issue';
    case Adjustment = 'adjustment';

    /**
     * Get the display label for the movement type.
     */
    public function label(): string
    {
        return match ($this) {
            self::Opening => 'Opening balance',
            self::Receipt => 'Received',
            self::Issue => 'Issued',
            self::Adjustment => 'Adjusted',
        };
    }

    /**
     * Determine whether the movement type needs an explanation.
     */
    public function requiresReason(): bool
    {
        return $this === self::Adjustment;
    }

    /**
     * Determine whether the movement increases stock by default.
     */
    public function isInbound(): bool
    {
        return in_array($this, [self::Opening, self::Receipt], true);
    }

    /**
     * Get the values allowed in validation rules.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
