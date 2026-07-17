<?php

declare(strict_types=1);

namespace Normbill\Xrechnung;

use Normbill\Xrechnung\Exception\ApiException;
use Normbill\Xrechnung\Exception\AuthenticationException;
use Normbill\Xrechnung\Exception\ConnectionException;
use Normbill\Xrechnung\Exception\NormbillException;
use Normbill\Xrechnung\Exception\RateLimitException;
use Normbill\Xrechnung\Exception\ValidationFailedException;

/**
 * Client for the normbill e-invoicing API (https://api.normbill.com).
 *
 *     $normbill = new NormbillClient('sk_live_…');
 *     $result = $normbill->generate([
 *         'format'  => 'xrechnung-3.0',
 *         'invoice' => [ ... ],
 *     ]);
 *     // $result['xml'], $result['validation'], for hybrid PDFs: $result['pdf']
 *
 * Plain PHP >= 8.1 + ext-curl, no framework dependencies.
 */
class NormbillClient
{
    public const DEFAULT_BASE_URL = 'https://api.normbill.com';

    private readonly string $apiKey;
    private readonly string $baseUrl;
    private readonly int $timeoutSeconds;
    private readonly int $connectTimeoutSeconds;

    /** @var array{limit: ?int, remaining: ?int, warning: ?string} */
    private array $lastQuota = ['limit' => null, 'remaining' => null, 'warning' => null];

    /**
     * @param string $apiKey  API key from the dashboard: sk_live_… or sk_test_…
     * @param string $baseUrl override the API origin (e.g. for testing)
     * @param int    $timeoutSeconds per-request timeout; generate with KoSIT
     *                               validation can take a few seconds
     */
    public function __construct(
        string $apiKey,
        string $baseUrl = self::DEFAULT_BASE_URL,
        int $timeoutSeconds = 60,
        int $connectTimeoutSeconds = 10,
    ) {
        if ($apiKey === '') {
            throw new NormbillException(
                'Missing API key — create one in the normbill dashboard (sk_live_… or sk_test_…).',
            );
        }
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeoutSeconds = $timeoutSeconds;
        $this->connectTimeoutSeconds = $connectTimeoutSeconds;
    }

    /**
     * POST /v1/invoices/generate — invoice JSON in, compliant e-invoice out.
     *
     * $request example:
     *
     *     [
     *         'format'  => 'xrechnung-3.0',
     *         'invoice' => [
     *             'number'          => 'INV-2026-0001',
     *             'issue_date'      => '2026-06-15',
     *             'buyer_reference' => '04011000-1234512345-06', // Leitweg-ID
     *             'seller'          => [...],
     *             'buyer'           => [...],
     *             'lines'           => [['name' => '…', 'qty' => 1, 'unit_price' => 100, 'vat' => 19]],
     *         ],
     *     ]
     *
     * Returns the decoded response: format, profile, xml, validation
     * (and pdf, base64-encoded, for hybrid-PDF formats).
     *
     * Pass $idempotencyKey to make retries safe: same key + same body replays
     * the original result without consuming quota or billing twice (24h).
     *
     * @param array<string, mixed> $request
     *
     * @return array<string, mixed>
     *
     * @throws ValidationFailedException 422 — issues map to fields in your request
     * @throws AuthenticationException   401
     * @throws RateLimitException        429
     * @throws ApiException              any other non-2xx
     * @throws ConnectionException       network failure / timeout
     */
    public function generate(array $request, ?string $idempotencyKey = null): array
    {
        return $this->post('/v1/invoices/generate', $request, $idempotencyKey);
    }

    /**
     * POST /v1/invoices/validate — validate an existing e-invoice document.
     *
     * Always returns the validation report — for an invalid document too
     * (check $report['valid']); an invalid document is a result here, not an
     * exception. Validation is free within fair use.
     *
     * @param string      $content the XML document
     * @param string|null $format  force a format instead of auto-detecting
     *
     * @return array<string, mixed> the validation report
     *
     * @throws AuthenticationException 401
     * @throws RateLimitException      429
     * @throws ApiException            any other non-2xx
     * @throws ConnectionException     network failure / timeout
     */
    public function validate(string $content, ?string $format = null): array
    {
        $body = ['content' => $content];
        if ($format !== null) {
            $body['format'] = $format;
        }

        try {
            return $this->post('/v1/invoices/validate', $body);
        } catch (ValidationFailedException $exception) {
            return $exception->getReport();
        }
    }

    /**
     * POST /v1/invoices/parse — map an inbound e-invoice (UBL/XRechnung or
     * CII/ZUGFeRD/Factur-X, auto-detected) back to invoice JSON.
     *
     * @return array<string, mixed> format, profile, invoice
     *
     * @throws AuthenticationException 401
     * @throws RateLimitException      429
     * @throws ApiException            any other non-2xx
     * @throws ConnectionException     network failure / timeout
     */
    public function parse(string $content): array
    {
        return $this->post('/v1/invoices/parse', ['content' => $content]);
    }

    /**
     * Quota state from the most recent response. 'warning' is the
     * X-Quota-Warning header, present from 80% of the monthly quota — wire it
     * to your monitoring.
     *
     * @return array{limit: ?int, remaining: ?int, warning: ?string}
     */
    public function getLastQuota(): array
    {
        return $this->lastQuota;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function post(string $path, array $body, ?string $idempotencyKey = null): array
    {
        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ];
        if ($idempotencyKey !== null) {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }

        try {
            $json = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException $exception) {
            throw new NormbillException('Request body is not JSON-encodable: ' . $exception->getMessage(), 0, $exception);
        }

        [$status, $responseHeaders, $responseBody] = $this->transport($path, $json, $headers);

        $this->lastQuota = [
            'limit' => self::intHeader($responseHeaders, 'x-ratelimit-limit'),
            'remaining' => self::intHeader($responseHeaders, 'x-ratelimit-remaining'),
            'warning' => $responseHeaders['x-quota-warning'] ?? null,
        ];

        if ($status >= 200 && $status < 300) {
            $decoded = json_decode($responseBody, true);
            if (!\is_array($decoded)) {
                throw new NormbillException(sprintf('Unexpected non-JSON %d response from %s.', $status, $path));
            }

            return $decoded;
        }

        throw $this->errorFromResponse($status, $responseHeaders, $responseBody);
    }

    /**
     * Perform the HTTP request. Protected so tests (or exotic environments)
     * can substitute the transport without any framework dependency.
     *
     * @param list<string> $headers
     *
     * @return array{0: int, 1: array<string, string>, 2: string} status, lowercased headers, body
     */
    protected function transport(string $path, string $jsonBody, array $headers): array
    {
        $handle = curl_init($this->baseUrl . $path);
        if ($handle === false) {
            throw new ConnectionException('Failed to initialise curl.');
        }

        $responseHeaders = [];
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonBody,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$responseHeaders): int {
                $parts = explode(':', $header, 2);
                if (\count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return \strlen($header);
            },
        ]);

        $responseBody = curl_exec($handle);
        if ($responseBody === false) {
            $error = curl_error($handle);
            $errno = curl_errno($handle);
            curl_close($handle);

            throw new ConnectionException(
                sprintf('POST %s failed before a response was received: %s (curl error %d)', $path, $error, $errno),
                $errno,
            );
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return [$status, $responseHeaders, (string) $responseBody];
    }

    /**
     * @param array<string, string> $responseHeaders
     */
    private function errorFromResponse(int $status, array $responseHeaders, string $responseBody): ApiException
    {
        $problem = json_decode($responseBody, true);
        if (!\is_array($problem) || !\is_string($problem['title'] ?? null)) {
            // Non-JSON body (e.g. a proxy error page) — synthesize problem details.
            $problem = ['type' => 'about:blank', 'title' => 'HTTP error', 'status' => $status];
        }

        // 422 with a report is a validation failure; 422 without one (e.g.
        // Idempotency-Key reuse with a different body) is a plain API error.
        $report = $problem['report'] ?? null;
        if ($status === 422 && \is_array($report) && \is_array($report['issues'] ?? null)) {
            return new ValidationFailedException($problem, $report);
        }
        if ($status === 401) {
            return new AuthenticationException($status, $problem);
        }
        if ($status === 429) {
            return new RateLimitException($status, $problem, self::intHeader($responseHeaders, 'retry-after'));
        }

        return new ApiException($status, $problem);
    }

    /**
     * @param array<string, string> $headers
     */
    private static function intHeader(array $headers, string $name): ?int
    {
        $value = $headers[$name] ?? null;
        if ($value === null || !is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }
}
