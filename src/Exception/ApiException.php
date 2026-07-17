<?php

declare(strict_types=1);

namespace Normbill\Xrechnung\Exception;

/**
 * The API answered with a non-2xx status. getProblem() carries the full
 * RFC 9457 problem-details body.
 */
class ApiException extends NormbillException
{
    /**
     * @param array<string, mixed> $problem RFC 9457 problem details
     */
    public function __construct(
        private readonly int $status,
        private readonly array $problem,
        ?string $message = null,
    ) {
        $detail = $problem['detail'] ?? $problem['title'] ?? 'HTTP error';
        parent::__construct($message ?? (\is_string($detail) ? $detail : 'HTTP error'), $status);
    }

    /** HTTP status code, e.g. 400, 403, 409, 503. */
    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * The RFC 9457 problem-details response body.
     *
     * @return array<string, mixed>
     */
    public function getProblem(): array
    {
        return $this->problem;
    }
}
