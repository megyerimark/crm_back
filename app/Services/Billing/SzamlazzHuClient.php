<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SimpleXMLElement;

/**
 * Számlázz.hu Számla Agent client: builds the xmlszamla document (element order follows
 * the official XSD) and returns the invoice number and PDF of the created e-invoice.
 */
class SzamlazzHuClient
{
    /**
     * Buyer tax subject codes ("adoalany").
     */
    public const TAXPAYER_HUNGARIAN = 1;

    public const TAXPAYER_NONE = -1;

    public const TAXPAYER_EU_COMPANY = 6;

    public const TAXPAYER_NON_EU_COMPANY = 7;

    public const TAXPAYER_UNKNOWN = 0;

    /**
     * @param  array{name: string, quantity: float, unit: string, net_unit_price: float, net: float, vat: float, gross: float, comment?: string|null}  $item
     * @return array{number: string, pdf: string|null}
     */
    public function createInvoice(Invoice $invoice, array $item, string $language = 'hu'): array
    {
        $response = Http::timeout((int) config('billing.szamlazz.timeout'))
            ->attach('action-xmlagentxmlfile', $this->buildXml($invoice, $item, $language), 'invoice.xml', ['Content-Type' => 'text/xml'])
            ->post(config('billing.szamlazz.endpoint'));

        if (filled($response->header('szlahu_error_code')) || $response->failed()) {
            $message = urldecode((string) $response->header('szlahu_error')) ?: 'HTTP '.$response->status();

            throw new RuntimeException('Számlázz.hu error '.$response->header('szlahu_error_code').': '.$message);
        }

        $number = urldecode((string) $response->header('szlahu_szamlaszam'));
        $pdf = null;

        if (str_contains((string) $response->header('Content-Type'), 'xml')) {
            $xml = @simplexml_load_string($response->body());

            if ($xml instanceof SimpleXMLElement) {
                $number = $number ?: (string) $xml->szamlaszam;
                $pdf = filled((string) $xml->pdf) ? base64_decode((string) $xml->pdf) : null;

                if ((string) $xml->sikeres === 'false') {
                    throw new RuntimeException('Számlázz.hu error '.$xml->hibakod.': '.$xml->hibauzenet);
                }
            }
        } elseif (str_contains((string) $response->header('Content-Type'), 'pdf')) {
            $pdf = $response->body();
        }

        if ($number === '') {
            throw new RuntimeException('Számlázz.hu did not return an invoice number.');
        }

        return ['number' => $number, 'pdf' => $pdf];
    }

    /**
     * @param  array{name: string, quantity: float, unit: string, net_unit_price: float, net: float, vat: float, gross: float, comment?: string|null}  $item
     */
    public function buildXml(Invoice $invoice, array $item, string $language = 'hu'): string
    {
        $config = config('billing.szamlazz');
        $buyer = $invoice->buyer;
        $date = ($invoice->paid_at ?? now())->timezone('Europe/Budapest')->toDateString();

        $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><xmlszamla xmlns="http://www.szamlazz.hu/xmlszamla" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://www.szamlazz.hu/xmlszamla https://www.szamlazz.hu/szamla/docs/xsds/agent/xmlszamla.xsd"></xmlszamla>');

        $settings = $xml->addChild('beallitasok');
        $this->add($settings, 'szamlaagentkulcs', $config['agent_key']);
        $this->add($settings, 'eszamla', $this->bool($config['e_invoice']));
        $this->add($settings, 'szamlaLetoltes', 'true');
        $this->add($settings, 'valaszVerzio', '2');
        $this->add($settings, 'szamlaKulsoAzon', $invoice->stripe_invoice_id);

        $header = $xml->addChild('fejlec');
        $this->add($header, 'keltDatum', $date);
        $this->add($header, 'teljesitesDatum', $date);
        $this->add($header, 'fizetesiHataridoDatum', $date);
        $this->add($header, 'fizmod', $config['payment_method']);
        $this->add($header, 'penznem', strtoupper($invoice->currency));
        $this->add($header, 'szamlaNyelve', in_array($language, ['hu', 'en', 'de'], true) ? $language : 'hu');
        $this->add($header, 'megjegyzes', 'Stripe: '.($invoice->stripe_number ?? $invoice->stripe_invoice_id));
        $this->add($header, 'rendelesSzam', $invoice->stripe_number ?? $invoice->stripe_invoice_id);
        if (filled($config['prefix'])) {
            $this->add($header, 'szamlaszamElotag', $config['prefix']);
        }
        $this->add($header, 'fizetve', 'true');

        $seller = $xml->addChild('elado');
        if (filled($config['reply_to'])) {
            $this->add($seller, 'emailReplyto', $config['reply_to']);
        }

        $customer = $xml->addChild('vevo');
        $this->add($customer, 'nev', $buyer['name']);
        $this->add($customer, 'orszag', $buyer['country'] ?? 'HU');
        $this->add($customer, 'irsz', $buyer['postcode']);
        $this->add($customer, 'telepules', $buyer['city']);
        $this->add($customer, 'cim', $buyer['address']);
        if (filled($buyer['email'] ?? null)) {
            $this->add($customer, 'email', $buyer['email']);
            $this->add($customer, 'sendEmail', $this->bool($config['send_email']));
        }
        $this->add($customer, 'adoalany', (string) $this->taxSubject($buyer));
        if (filled($buyer['tax_number'] ?? null)) {
            $this->add($customer, 'adoszam', $buyer['tax_number']);
        }
        if (filled($buyer['eu_vat_number'] ?? null)) {
            $this->add($customer, 'adoszamEU', $buyer['eu_vat_number']);
        }

        $line = $xml->addChild('tetelek')->addChild('tetel');
        $this->add($line, 'megnevezes', $item['name']);
        $this->add($line, 'mennyiseg', $this->number($item['quantity']));
        $this->add($line, 'mennyisegiEgyseg', $item['unit']);
        $this->add($line, 'nettoEgysegar', $this->number($item['net_unit_price']));
        $this->add($line, 'afakulcs', $invoice->vat_key);
        $this->add($line, 'nettoErtek', $this->number($item['net']));
        $this->add($line, 'afaErtek', $this->number($item['vat']));
        $this->add($line, 'bruttoErtek', $this->number($item['gross']));
        if (filled($item['comment'] ?? null)) {
            $this->add($line, 'megjegyzes', $item['comment']);
        }

        return $xml->asXML();
    }

    /**
     * @param  array<string, mixed>  $buyer
     */
    public function taxSubject(array $buyer): int
    {
        $country = strtoupper($buyer['country'] ?? 'HU');

        return match (true) {
            $country === 'HU' && filled($buyer['tax_number'] ?? null) => self::TAXPAYER_HUNGARIAN,
            $country === 'HU' => self::TAXPAYER_NONE,
            filled($buyer['eu_vat_number'] ?? null) => self::TAXPAYER_EU_COMPANY,
            filled($buyer['tax_number'] ?? null) => self::TAXPAYER_NON_EU_COMPANY,
            default => self::TAXPAYER_UNKNOWN,
        };
    }

    protected function add(SimpleXMLElement $parent, string $name, ?string $value): void
    {
        $parent->addChild($name, htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8'));
    }

    protected function bool(bool $value): string
    {
        return $value ? 'true' : 'false';
    }

    protected function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
