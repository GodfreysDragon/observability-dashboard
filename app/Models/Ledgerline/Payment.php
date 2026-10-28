<?php

namespace App\Models\Ledgerline;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    public $timestamps = false;

    protected $fillable = ['invoice_id', 'amount_cents', 'gateway_ref', 'paid_at', 'synced_at'];

    protected function casts(): array
    {
        return [
            'paid_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
