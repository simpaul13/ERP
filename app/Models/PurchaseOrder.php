<?php

namespace App\Models;

use App\Enums\PurchaseOrderStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property int $supplier_id
 * @property int|null $created_by
 * @property int|null $approved_by
 * @property PurchaseOrderStatus $status
 * @property Carbon $expected_date
 * @property string|null $reference
 * @property string|null $cancellation_reason
 * @property string $total_amount
 * @property-read Team $team
 * @property-read Supplier $supplier
 * @property-read User|null $creator
 * @property-read User|null $approver
 * @property-read Collection<int, PurchaseOrderItem> $items
 * @property-read Collection<int, PurchaseOrderEvent> $events
 */
#[Fillable(['team_id', 'supplier_id', 'created_by', 'approved_by', 'status', 'expected_date', 'reference', 'cancellation_reason', 'total_amount'])]
class PurchaseOrder extends Model
{
    use HasFactory;

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return HasMany<PurchaseOrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    /** @return HasMany<PurchaseOrderEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(PurchaseOrderEvent::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => PurchaseOrderStatus::class,
            'expected_date' => 'date',
            'total_amount' => 'decimal:2',
        ];
    }

    public function number(): string
    {
        return 'PO-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }
}
