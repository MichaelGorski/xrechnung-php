<?php

declare(strict_types=1);

namespace Normbill\Xrechnung\Tests;

use Normbill\Xrechnung\NormbillClient;

/**
 * Test double: replaces the curl transport with a canned response and records
 * what would have been sent. No network, no framework dependencies.
 */
final class StubClient extends NormbillClient
{
    public ?string $sentPath = null;
    public ?string $sentBody = null;
    /** @var list<string> */
    public array $sentHeaders = [];

    /**
     * @param array<string, string> $responseHeaders
     */
    public function __construct(
        private readonly int $responseStatus,
        private readonly string $responseBody,
        private readonly array $responseHeaders = [],
        string $apiKey = 'sk_test_0123456789abcdef',
    ) {
        parent::__construct($apiKey);
    }

    protected function transport(string $path, string $jsonBody, array $headers): array
    {
        $this->sentPath = $path;
        $this->sentBody = $jsonBody;
        $this->sentHeaders = $headers;

        return [$this->responseStatus, $this->responseHeaders, $this->responseBody];
    }
}
