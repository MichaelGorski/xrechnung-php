<?php

declare(strict_types=1);

namespace Normbill\Xrechnung\Exception;

/**
 * 429 — monthly quota exceeded or burst rate limit hit.
 */
class RateLimitException extends ApiException
{
    /**
     * @param array<string, mixed> $problem
     */
    public function __construct(
        int $status,
        array $problem,
        private readonly ?int $retryAfter = null,
    ) {
        parent::__construct($status, $problem);
    }

    /** Seconds to wait before retrying (Retry-After header), or null if not sent. */
    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }
}
