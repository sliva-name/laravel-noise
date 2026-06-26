<?php

declare(strict_types=1);

namespace LaravelAudit\Analysis;

final class IssueFactory
{
    /**
     * @param  array<string, mixed>  $payload
     * @return list<Issue>
     */
    public static function fromReportPayload(array $payload): array
    {
        $issues = [];

        foreach (($payload['issues'] ?? []) as $data) {
            if (! is_array($data)) {
                continue;
            }

            $issues[] = self::fromArray($data);
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): Issue
    {
        $location = is_array($data['location'] ?? null) ? $data['location'] : [];

        return new Issue(
            ruleId: (string) ($data['ruleId'] ?? 'unknown'),
            category: Category::tryFrom((string) ($data['category'] ?? '')) ?? Category::CodeQuality,
            severity: Severity::tryFrom((string) ($data['severity'] ?? '')) ?? Severity::Info,
            title: (string) ($data['title'] ?? 'Issue'),
            message: (string) ($data['message'] ?? ''),
            location: new Location(
                file: (string) ($location['file'] ?? 'unknown'),
                line: (int) ($location['line'] ?? 1),
                column: isset($location['column']) ? (int) $location['column'] : null,
            ),
            recommendation: is_string($data['recommendation'] ?? null) ? $data['recommendation'] : null,
            metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
        );
    }
}
