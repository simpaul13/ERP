<?php

namespace App\Models;

use Database\Factories\SalesRefundFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $sales_order_id
 * @property int $sales_return_id
 * @property string $amount
 * @property string $status
 * @property string|null $reference
 * @property string $payload_hash
 */
#[Fillable(['sales_order_id', 'sales_return_id', 'amount', 'status', 'reference', 'idempotency_key', 'payload_hash'])]
class SalesRefund extends Model
{
    /** @use HasFactory<SalesRefundFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (SalesRefund $refund): void {
            if (array_diff(array_keys($refund->getDirty()), ['status', 'reference', 'updated_at']) !== []
                || $refund->getOriginal('status') !== 'pending'
                || ! in_array($refund->status, ['refunded', 'cancelled'], true)) {
                throw new \LogicException('Refunds only allow pending-to-refunded or pending-to-cancelled transitions; amounts are immutable.');
            }
        });
        static::deleting(function (SalesRefund $refund): void {
            throw new \LogicException('Sales refunds cannot be deleted.');
        });
    }

    /** @return BelongsTo<SalesOrder, $this> */
    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    /** @return BelongsTo<SalesReturn, $this> */
    public function salesReturn(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }
}
