<?php

declare(strict_types=1);

namespace LaravelAudit\Repositories\Contracts;

use LaravelAudit\Audit\AuditOptions;
use LaravelAudit\Models\AuditReportSnapshot;
use LaravelAudit\Pattern\PatternSuggestion;
use LaravelAudit\Reporting\AuditReport;

interface AuditReportStore
{
    public function store(AuditReport $report, AuditOptions $options): AuditReportSnapshot;

    /**
     * @return list<AuditReportSnapshot>
     */
    public function latest(int $limit = 50): array;

    public function findByUuid(string $uuid): ?AuditReportSnapshot;

    /**
     * @param  list<PatternSuggestion>  $confirmed
     * @param  list<string>  $confirmedKeys
     */
    public function mergePatternSuggestions(string $uuid, array $confirmed, array $confirmedKeys): AuditReportSnapshot;
}
