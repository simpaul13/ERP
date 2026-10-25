<?php

namespace App\Models;

use App\Enums\SalesOrderStatus;
use Database\Factories\SalesOrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $team_id
 * @property int|null $created_by
 * @property string $customer_name
 * @property string|null $customer_email
 * @property string|null $customer_phone
 * @property string|null $customer_address
 * @property string $currency
 * @property string $total_amount
 * @property string|null $cancellation_reason
 * @property SalesOrderStatus $status
 * @property-read Team $team
 */
#[Fillable(['team_id', 'created_by', 'customer_name', 'customer_email', 'customer_phone', 'customer_address', 'currency', 'total_amount', 'status', 'cancellation_reason'])]
class SalesOrder extends Model
{
    /** @use HasFactory<SalesOrderFactory> */
    use HasFactory;

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<SalesOrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(SalesOrderItem::class);
    }

    /** @return HasMany<StockReservation, $this> */
    public function reservations(): HasMany
    {
        return $this->hasMany(StockReservation::class);
    }

    /** @return HasMany<SalesFulfillment, $this> */
    public function fulfillments(): HasMany
    {
        return $this->hasMany(SalesFulfillment::class);
    }

    /** @return HasMany<SalesReturn, $this> */
    public function returns(): HasMany
    {
        return $this->hasMany(SalesReturn::class);
    }

    /** @return HasMany<SalesRefund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(SalesRefund::class);
    }

    /** @return HasMany<SalesOrderEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(SalesOrderEvent::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => SalesOrderStatus::class, 'total_amount' => 'decimal:2'];
    }

    public function number(): string
    {
        return 'SO-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }
}
