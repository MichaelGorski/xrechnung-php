# normbill/xrechnung

**XRechnung mit PHP erzeugen — in einem API-Aufruf, KoSIT-validiert.** Rechnungsdaten als JSON-Array rein, konforme XRechnung (UBL oder CII), ZUGFeRD/Factur-X oder Peppol BIS 3.0 raus. Schlägt die Validierung fehl, bekommen Sie keinen kryptischen Schematron-Code, sondern das exakte Feld in *Ihrem* Request samt Klartext-Erklärung und konkretem Fix — z. B. `invoice.buyer_reference` statt `[BR-DE-15] cbc:BuyerReference`. Validierung ist im Rahmen der Fair-Use-Grenzen kostenlos; ein großzügiger Gratis-Tarif reicht für Integration, Tests und den Start. API-Key auf [normbill.com](https://normbill.com).

PHP client for the [normbill](https://normbill.com) e-invoicing API. Plain PHP ≥ 8.1 with `ext-curl` — no framework, no XML libraries, no Java toolchain.

## normbill vs. horstoeko/zugferd (honest comparison)

[horstoeko/zugferd](https://github.com/horstoeko/zugferd) is an excellent open-source library — if you want to own XML generation, use it. The difference:

**horstoeko generates the XML. normbill generates it, validates it against the official KoSIT validator (the legal reference implementation), tells you which field in your request to fix when a rule fails, and tracks spec version bumps (XRechnung 4.0 is expected late 2026) for you.**

With a library, Schematron validation, error interpretation and every future XRechnung/ZUGFeRD version bump stay on your roadmap. With normbill they are the product.

## Install

```sh
composer require normbill/xrechnung
```

## Quickstart

```php
use Normbill\Xrechnung\NormbillClient;

$normbill = new NormbillClient(getenv('NORMBILL_API_KEY'));

$result = $normbill->generate([
    'format'  => 'xrechnung-3.0',
    'invoice' => [
        'number'          => 'INV-2026-0001',
        'issue_date'      => '2026-06-15',
        'due_date'        => '2026-07-15',
        'buyer_reference' => '04011000-1234512345-06', // Leitweg-ID
        'seller' => [
            'name'    => 'Muster Lieferant GmbH',
            'vat_id'  => 'DE123456789',
            'address' => ['country' => 'DE', 'city' => 'Berlin', 'postal_code' => '10115'],
            'contact' => ['name' => 'Erika Muster', 'phone' => '+49 30 1234567', 'email' => 'billing@lieferant.example'],
        ],
        'buyer' => [
            'name'    => 'Beispiel Kunde AG',
            'address' => ['country' => 'DE', 'city' => 'Hamburg', 'postal_code' => '20095'],
            'contact' => ['email' => 'ap@kunde.example'],
        ],
        'payment' => ['iban' => 'DE89370400440532013000'],
        'lines'   => [
            ['name' => 'Beratungsleistung', 'qty' => 10, 'unit_price' => 100, 'vat' => 19],
        ],
    ],
]);

$xml    = $result['xml'];        // KoSIT-validated XRechnung 3.0 (UBL 2.1)
$report = $result['validation']; // full validation report
```

## When validation fails, you know what to fix

```php
use Normbill\Xrechnung\Exception\ValidationFailedException;

try {
    $normbill->generate($request);
} catch (ValidationFailedException $e) {
    foreach ($e->getIssues() as $issue) {
        // $issue['rule']    => 'BR-DE-15'
        // $issue['path']    => 'invoice.buyer_reference'   ← a field in YOUR request
        // $issue['message'] => 'The buyer reference (Leitweg-ID) is missing.'
        // $issue['fix']     => 'Set invoice.buyer_reference, e.g. "04011000-1234512345-06".'
        error_log(sprintf('%s @ %s: %s', $issue['rule'], $issue['path'], $issue['message']));
    }
}
```

## API

| Method | Endpoint | What it does |
| --- | --- | --- |
| `generate(array $request, ?string $idempotencyKey = null)` | `POST /v1/invoices/generate` | Invoice JSON → validated e-invoice (XML; base64 PDF for hybrid formats) |
| `validate(string $content, ?string $format = null)` | `POST /v1/invoices/validate` | Existing XML → validation report with reverse-mapped fixes. **Free within fair use.** |
| `parse(string $content)` | `POST /v1/invoices/parse` | Inbound XRechnung/ZUGFeRD XML → invoice array (the receive side of the mandate) |

`validate()` always returns the report — an invalid document is a result, not an exception. Check `$report['valid']`.

### Formats

| `format` | Syntax | Validation |
| --- | --- | --- |
| `xrechnung-3.0` | UBL 2.1 | official KoSIT validator |
| `xrechnung-cii-3.0` | CII (UN/CEFACT D16B) | official KoSIT validator |
| `peppol-bis-3.0` | UBL 2.1 (BIS Billing 3.0) | structural tier |
| `zugferd-2.x` / `facturx-1.0` | CII in hybrid PDF/A-3 | structural tier, paid plans |

### Retry-safe generation (idempotency)

Pass an idempotency key and retries can never double-generate or double-bill — same key + same body replays the original result (honoured for 24 hours):

```php
$normbill->generate($request, 'order-' . $orderId);
```

### Quota warnings

From 80% usage every response carries an `X-Quota-Warning` header:

```php
$quota = $normbill->getLastQuota();
// ['limit' => 50, 'remaining' => 9, 'warning' => '82% of monthly documents used']
```

### Exceptions

All exceptions extend `Normbill\Xrechnung\Exception\NormbillException`:

| Class | When |
| --- | --- |
| `ValidationFailedException` | 422 — `getReport()` / `getIssues()` (rule, path, message, fix) |
| `AuthenticationException` | 401 — missing/invalid API key |
| `RateLimitException` | 429 — quota or burst limit; `getRetryAfter()` |
| `ApiException` | any other non-2xx; `getStatus()` / `getProblem()` (RFC 9457) |
| `ConnectionException` | network failure / timeout |

## Privacy

normbill never persists invoice content — payloads are request-scoped, processed in the EU, and gone when the response is sent.

## Tests

```sh
composer install
composer test
```

The test suite stubs the HTTP transport (no network, no API key needed). It is not executed in this repository's CI yet — run it locally or wire it into your pipeline.

## Links

- **[normbill.com](https://normbill.com)** — create a free API key
- **[API reference](https://normbill.com/docs)** — full docs, rule catalog, playground

MIT © VIBOA UG (haftungsbeschränkt)
