<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $purchase_order_id
 * @property int|null $user_id
 * @property string|null $from_status
 * @property string $to_status
 * @property string|null $note
 * @property string|null $idempotency_key
 * @property string|null $payload_hash
 */
#[Fillable(['purchase_order_id', 'user_id', 'from_status', 'to_status', 'note', 'idempotency_key', 'payload_hash'])]
class PurchaseOrderEvent extends Model
{
    use HasFactory;

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
