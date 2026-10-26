<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['team_id', 'finance_category_id', 'amount', 'transaction_date', 'description', 'type', 'payment_method'])]
class FinanceTransaction extends Model
{
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinanceCategory::class, 'finance_category_id');
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'transaction_date' => 'date:Y-m-d'];
    }
}
