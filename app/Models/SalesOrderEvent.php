<?php

namespace App\Models;

use Database\Factories\SalesOrderEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $note
 * @property-read User|null $user
 */
#[Fillable(['sales_order_id', 'user_id', 'from_status', 'to_status', 'note'])]
class SalesOrderEvent extends Model
{
    /** @use HasFactory<SalesOrderEventFactory> */
    use HasFactory;

    /** @return BelongsTo<SalesOrder, $this> */
    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
