<?php

namespace App\Ledgerline;

use App\Models\Ledgerline\Invoice;

/**
 * Renders an invoice document. Stands in for the legacy PDF pipeline: CPU work that
 * grows with the number of lines, plus the VAT lookup that breaks for legacy regions.
 */
class InvoiceRenderer
{
    /**
     * @return array{number: string, subtotal_cents: int, vat_cents: int, total_cents: int, bytes: int}
     */
    public function render(Invoice $invoice): array
    {
        $invoice->loadMissing('customer', 'lines');

        $rates = config('ledgerline.vat_rates');
        $rate = $rates[$invoice->customer->region];

        $document = '';
        $subtotal = 0;

        foreach ($invoice->lines as $line) {
            $amount = $line->quantity * $line->unit_cents;
            $subtotal += $amount;
            $document .= str_pad($line->description, 40).str_pad((string) $line->quantity, 6, ' ', STR_PAD_LEFT).str_pad(number_format($amount / 100, 2), 14, ' ', STR_PAD_LEFT)."\n";
        }

        // Simulated layout pass: the old renderer measured every glyph.
        $document = str_repeat($document, 40);
        $checksum = 0;

        for ($i = 0, $length = strlen($document); $i < $length; $i += 3) {
            $checksum = ($checksum * 31 + ord($document[$i])) % 1000003;
        }

        $vat = (int) round($subtotal * $rate);

        return [
            'number' => $invoice->number,
            'subtotal_cents' => $subtotal,
            'vat_cents' => $vat,
            'total_cents' => $subtotal + $vat,
            'bytes' => strlen($document) + $checksum % 7,
        ];
    }
}
