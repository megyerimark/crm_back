<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hungarian invoice issued (via Számlázz.hu) for a paid Stripe invoice.
 */
#[Fillable(['workspace_id', 'stripe_invoice_id', 'stripe_number', 'number', 'status', 'plan', 'interval', 'currency', 'net_amount', 'vat_amount', 'gross_amount', 'vat_key', 'period_start', 'period_end', 'buyer', 'paid_at'])]
#[Hidden(['pdf_path'])]
class Invoice extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['status' => 'pending', 'attempts' => 0];

    protected function casts(): array
    {
        return [
            'buyer' => 'array',
            'net_amount' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'gross_amount' => 'decimal:2',
            'period_start' => 'date',
            'period_end' => 'date',
            'paid_at' => 'datetime',
            'issued_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
