<?php

namespace App\Models;

use App\Actions\Sales\SalesMoney;
use Database\Factories\SalesReturnFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $sales_order_id
 * @property int $sales_order_item_id
 * @property int $sales_fulfillment_id
 * @property int $quantity
 * @property string $condition
 * @property bool $accepted
 * @property string $reason
 * @property string $refundable_amount
 * @property string $payload_hash
 * @property-read SalesOrderItem $salesOrderItem
 */
#[Fillable(['sales_order_id', 'sales_order_item_id', 'sales_fulfillment_id', 'stock_movement_id', 'quantity', 'condition', 'accepted', 'reason', 'refundable_amount', 'idempotency_key', 'payload_hash'])]
class SalesReturn extends Model
{
    /** @use HasFactory<SalesReturnFactory> */
    use HasFactory;

    /** @return BelongsTo<SalesOrder, $this> */
    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    /** @return BelongsTo<SalesOrderItem, $this> */
    public function salesOrderItem(): BelongsTo
    {
        return $this->belongsTo(SalesOrderItem::class);
    }

    /** @return BelongsTo<SalesFulfillment, $this> */
    public function fulfillment(): BelongsTo
    {
        return $this->belongsTo(SalesFulfillment::class, 'sales_fulfillment_id');
    }

    /** @return HasMany<SalesRefund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(SalesRefund::class);
    }

    public function remainingRefundableMinor(): int
    {
        $used = $this->refunds()->where('status', '!=', 'cancelled')->get()
            ->sum(fn (SalesRefund $refund): int => SalesMoney::minor($refund->amount));

        return SalesMoney::minor($this->refundable_amount) - (int) $used;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['quantity' => 'integer', 'accepted' => 'boolean', 'refundable_amount' => 'decimal:2'];
    }
}
