<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['quote_id', 'description', 'quantity', 'unit', 'unit_price', 'vat_rate', 'position'])]
class QuoteItem extends Model
{
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'vat_rate' => 'decimal:2',
        ];
    }

    public function netAmount(): float
    {
        return round((float) $this->quantity * (float) $this->unit_price, 2);
    }

    public function vatAmount(): float
    {
        return round($this->netAmount() * (float) $this->vat_rate / 100, 2);
    }

    /**
     * @return BelongsTo<Quote, $this>
     */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }
}
