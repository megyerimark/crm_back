<?php

namespace App\Services\Billing;

/**
 * Splits a gross (or net) amount into net + VAT according to the Számlázz.hu VAT key.
 */
class InvoiceCalculator
{
    public function rate(string $vatKey): float
    {
        return is_numeric($vatKey) ? (float) $vatKey : 0.0;
    }

    /**
     * @return array{net: float, vat: float, gross: float}
     */
    public function split(float $amount, string $vatKey, bool $amountIncludesVat, string $currency): array
    {
        $rate = $this->rate($vatKey) / 100;
        $decimals = strtoupper($currency) === 'HUF' ? 0 : 2;

        if ($amountIncludesVat) {
            $gross = round($amount, $decimals);
            $net = round($gross / (1 + $rate), $decimals);

            return ['net' => $net, 'vat' => round($gross - $net, $decimals), 'gross' => $gross];
        }

        $net = round($amount, $decimals);
        $vat = round($net * $rate, $decimals);

        return ['net' => $net, 'vat' => $vat, 'gross' => round($net + $vat, $decimals)];
    }
}
