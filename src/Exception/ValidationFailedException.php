<?php

declare(strict_types=1);

namespace Normbill\Xrechnung\Exception;

/**
 * 422 — the invoice did not validate. getIssues() lists every finding with
 * the rule id, the JSON path into YOUR request, a plain-language message and
 * a concrete fix:
 *
 *     [
 *         'severity' => 'error',
 *         'rule'     => 'BR-DE-15',
 *         'path'     => 'invoice.buyer_reference',
 *         'message'  => 'The buyer reference (Leitweg-ID) is missing.',
 *         'fix'      => 'Set invoice.buyer_reference, e.g. "04011000-1234512345-06".',
 *         'docs_url' => 'https://normbill.com/docs/rules/br-de-15',
 *         'location' => '/ubl:Invoice/cbc:BuyerReference',
 *     ]
 */
class ValidationFailedException extends ApiException
{
    /**
     * @param array<string, mixed> $problem the full 422 problem-details body (with 'report')
     * @param array<string, mixed> $report  the validation report
     */
    public function __construct(
        array $problem,
        private readonly array $report,
    ) {
        parent::__construct(422, $problem, self::buildMessage($report));
    }

    /**
     * The full validation report: valid, format, profile, tier, counts, issues.
     *
     * @return array<string, mixed>
     */
    public function getReport(): array
    {
        return $this->report;
    }

    /**
     * Shortcut for the report's issues array.
     *
     * @return list<array<string, mixed>>
     */
    public function getIssues(): array
    {
        $issues = $this->report['issues'] ?? [];

        return \is_array($issues) ? array_values($issues) : [];
    }

    /**
     * @param array<string, mixed> $report
     */
    private static function buildMessage(array $report): string
    {
        $counts = \is_array($report['counts'] ?? null) ? $report['counts'] : [];
        $errors = (int) ($counts['errors'] ?? 0);
        $warnings = (int) ($counts['warnings'] ?? 0);
        $head = sprintf('Invoice validation failed: %d error(s), %d warning(s).', $errors, $warnings);

        $issues = \is_array($report['issues'] ?? null) ? $report['issues'] : [];
        $first = null;
        foreach ($issues as $issue) {
            if (\is_array($issue) && ($issue['severity'] ?? null) === 'error') {
                $first = $issue;
                break;
            }
        }
        if ($first === null && $issues !== [] && \is_array($issues[array_key_first($issues)])) {
            $first = $issues[array_key_first($issues)];
        }
        if ($first === null) {
            return $head;
        }

        $where = $first['path'] ?? $first['location'] ?? 'document';
        $message = sprintf(
            '%s First: [%s] %s — %s',
            $head,
            (string) ($first['rule'] ?? '?'),
            \is_string($where) ? $where : 'document',
            (string) ($first['message'] ?? ''),
        );
        if (\is_string($first['fix'] ?? null) && $first['fix'] !== '') {
            $message .= ' Fix: ' . $first['fix'];
        }

        return $message;
    }
}
