<?php

declare(strict_types=1);

namespace Normbill\Xrechnung\Tests;

use Normbill\Xrechnung\Exception\ApiException;
use Normbill\Xrechnung\Exception\AuthenticationException;
use Normbill\Xrechnung\Exception\NormbillException;
use Normbill\Xrechnung\Exception\RateLimitException;
use Normbill\Xrechnung\Exception\ValidationFailedException;
use Normbill\Xrechnung\NormbillClient;
use PHPUnit\Framework\TestCase;

final class NormbillClientTest extends TestCase
{
    /** @var array<string, mixed> */
    private const GENERATE_REQUEST = [
        'format' => 'xrechnung-3.0',
        'invoice' => [
            'number' => 'INV-2026-0001',
            'issue_date' => '2026-06-15',
            'due_date' => '2026-07-15',
            'buyer_reference' => '04011000-1234512345-06',
            'seller' => [
                'name' => 'Muster Lieferant GmbH',
                'vat_id' => 'DE123456789',
                'address' => ['country' => 'DE', 'city' => 'Berlin', 'postal_code' => '10115'],
                'contact' => ['name' => 'Erika Muster', 'phone' => '+49 30 1234567', 'email' => 'billing@lieferant.example'],
            ],
            'buyer' => [
                'name' => 'Beispiel Kunde AG',
                'address' => ['country' => 'DE', 'city' => 'Hamburg', 'postal_code' => '20095'],
                'contact' => ['email' => 'ap@kunde.example'],
            ],
            'payment' => ['iban' => 'DE89370400440532013000'],
            'lines' => [['name' => 'Beratungsleistung', 'qty' => 10, 'unit_price' => 100, 'vat' => 19]],
        ],
    ];

    /** @var array<string, mixed> */
    private const INVALID_REPORT = [
        'valid' => false,
        'format' => 'xrechnung-3.0',
        'profile' => 'XRechnung 3.0.2 (UBL 2.1)',
        'tier' => 'schematron',
        'counts' => ['errors' => 1, 'warnings' => 0],
        'issues' => [
            [
                'severity' => 'error',
                'rule' => 'BR-DE-15',
                'path' => 'invoice.buyer_reference',
                'message' => 'The buyer reference (Leitweg-ID) is missing.',
                'fix' => 'Set invoice.buyer_reference, e.g. "04011000-1234512345-06".',
                'docs_url' => 'https://normbill.com/docs/rules/br-de-15',
                'location' => '/ubl:Invoice/cbc:BuyerReference',
            ],
        ],
    ];

    public function testRejectsEmptyApiKey(): void
    {
        $this->expectException(NormbillException::class);
        new NormbillClient('');
    }

    public function testGeneratePostsRequestAndReturnsDecodedResponse(): void
    {
        $response = [
            'format' => 'xrechnung-3.0',
            'profile' => 'XRechnung 3.0.2 (UBL 2.1)',
            'xml' => '<?xml version="1.0"?><Invoice/>',
            'validation' => ['valid' => true, 'counts' => ['errors' => 0, 'warnings' => 0], 'issues' => []],
        ];
        $client = new StubClient(200, json_encode($response, JSON_THROW_ON_ERROR));

        $result = $client->generate(self::GENERATE_REQUEST);

        self::assertSame('/v1/invoices/generate', $client->sentPath);
        self::assertSame($response, $result);
        self::assertContains('Authorization: Bearer sk_test_0123456789abcdef', $client->sentHeaders);
        self::assertContains('Content-Type: application/json', $client->sentHeaders);
        self::assertSame(self::GENERATE_REQUEST, json_decode((string) $client->sentBody, true));
    }

    public function testGeneratePassesIdempotencyKeyHeader(): void
    {
        $client = new StubClient(200, '{"xml": "<Invoice/>"}');

        $client->generate(self::GENERATE_REQUEST, 'order-42-attempt');

        self::assertContains('Idempotency-Key: order-42-attempt', $client->sentHeaders);
    }

    public function testGenerateThrowsValidationFailedWithIssuesIntact(): void
    {
        $problem = [
            'type' => 'https://normbill.com/docs/errors/validation-failed',
            'title' => 'Invoice validation failed',
            'status' => 422,
            'report' => self::INVALID_REPORT,
        ];
        $client = new StubClient(422, json_encode($problem, JSON_THROW_ON_ERROR));

        try {
            $client->generate(self::GENERATE_REQUEST);
            self::fail('Expected ValidationFailedException');
        } catch (ValidationFailedException $exception) {
            self::assertSame(422, $exception->getStatus());
            self::assertSame(self::INVALID_REPORT, $exception->getReport());
            $issues = $exception->getIssues();
            self::assertCount(1, $issues);
            self::assertSame('invoice.buyer_reference', $issues[0]['path']);
            self::assertStringContainsString('04011000', (string) $issues[0]['fix']);
            self::assertStringContainsString('BR-DE-15', $exception->getMessage());
        }
    }

    public function testA422WithoutReportIsAPlainApiException(): void
    {
        $problem = [
            'type' => 'https://normbill.com/docs/errors/idempotency-key-reuse',
            'title' => 'Idempotency-Key reuse',
            'status' => 422,
        ];
        $client = new StubClient(422, json_encode($problem, JSON_THROW_ON_ERROR));

        try {
            $client->generate(self::GENERATE_REQUEST);
            self::fail('Expected ApiException');
        } catch (ApiException $exception) {
            self::assertNotInstanceOf(ValidationFailedException::class, $exception);
            self::assertSame(422, $exception->getStatus());
        }
    }

    public function testMaps401ToAuthenticationException(): void
    {
        $problem = ['type' => 'about:blank', 'title' => 'Missing or invalid API key', 'status' => 401];
        $client = new StubClient(401, json_encode($problem, JSON_THROW_ON_ERROR));

        $this->expectException(AuthenticationException::class);
        $client->generate(self::GENERATE_REQUEST);
    }

    public function testMaps429ToRateLimitExceptionWithRetryAfter(): void
    {
        $problem = ['type' => 'about:blank', 'title' => 'Monthly quota exceeded', 'status' => 429];
        $client = new StubClient(429, json_encode($problem, JSON_THROW_ON_ERROR), ['retry-after' => '30']);

        try {
            $client->generate(self::GENERATE_REQUEST);
            self::fail('Expected RateLimitException');
        } catch (RateLimitException $exception) {
            self::assertSame(30, $exception->getRetryAfter());
            self::assertSame(429, $exception->getStatus());
        }
    }

    public function testSynthesizesProblemFromNonJsonErrorBody(): void
    {
        $client = new StubClient(502, '<html>Bad Gateway</html>');

        try {
            $client->generate(self::GENERATE_REQUEST);
            self::fail('Expected ApiException');
        } catch (ApiException $exception) {
            self::assertSame(502, $exception->getStatus());
            self::assertSame('about:blank', $exception->getProblem()['type']);
        }
    }

    public function testValidateReturnsReportForValidDocument(): void
    {
        $report = ['valid' => true, 'counts' => ['errors' => 0, 'warnings' => 0], 'issues' => []];
        $client = new StubClient(200, json_encode($report, JSON_THROW_ON_ERROR));

        $result = $client->validate('<Invoice/>');

        self::assertSame('/v1/invoices/validate', $client->sentPath);
        self::assertSame(['content' => '<Invoice/>'], json_decode((string) $client->sentBody, true));
        self::assertTrue($result['valid']);
    }

    public function testValidateReturnsReportInsteadOfThrowingOn422(): void
    {
        $problem = [
            'type' => 'https://normbill.com/docs/errors/validation-failed',
            'title' => 'Validation failed',
            'status' => 422,
            'report' => self::INVALID_REPORT,
        ];
        $client = new StubClient(422, json_encode($problem, JSON_THROW_ON_ERROR));

        $report = $client->validate('<Invoice/>');

        self::assertFalse($report['valid']);
        self::assertSame('BR-DE-15', $report['issues'][0]['rule']);
    }

    public function testValidateSendsFormatWhenGiven(): void
    {
        $client = new StubClient(200, '{"valid": true}');

        $client->validate('<Invoice/>', 'xrechnung-3.0');

        self::assertSame(
            ['content' => '<Invoice/>', 'format' => 'xrechnung-3.0'],
            json_decode((string) $client->sentBody, true),
        );
    }

    public function testParsePostsContent(): void
    {
        $response = ['format' => 'xrechnung-3.0', 'profile' => 'XRechnung 3.0.2 (UBL 2.1)', 'invoice' => ['number' => 'INV-1']];
        $client = new StubClient(200, json_encode($response, JSON_THROW_ON_ERROR));

        $result = $client->parse('<Invoice/>');

        self::assertSame('/v1/invoices/parse', $client->sentPath);
        self::assertSame(['content' => '<Invoice/>'], json_decode((string) $client->sentBody, true));
        self::assertSame('INV-1', $result['invoice']['number']);
    }

    public function testExposesQuotaFromResponseHeaders(): void
    {
        $client = new StubClient(200, '{"xml": "<Invoice/>"}', [
            'x-ratelimit-limit' => '50',
            'x-ratelimit-remaining' => '9',
            'x-quota-warning' => '82% of monthly documents used',
        ]);

        $client->generate(self::GENERATE_REQUEST);

        self::assertSame(
            ['limit' => 50, 'remaining' => 9, 'warning' => '82% of monthly documents used'],
            $client->getLastQuota(),
        );
    }
}
