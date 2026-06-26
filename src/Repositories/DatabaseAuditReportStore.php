<?php

declare(strict_types=1);

namespace LaravelAudit\Repositories;

use Illuminate\Support\Str;
use LaravelAudit\Audit\AuditOptions;
use LaravelAudit\Models\AuditReportRecord;
use LaravelAudit\Models\AuditReportSnapshot;
use LaravelAudit\Pattern\PatternReportMerger;
use LaravelAudit\Pattern\PatternSuggestion;
use LaravelAudit\Reporting\AuditReport;
use LaravelAudit\Repositories\Contracts\AuditReportStore;

final class DatabaseAuditReportStore implements AuditReportStore
{
    public function store(AuditReport $report, AuditOptions $options): AuditReportSnapshot
    {
        $summary = $report->summary();

        $record = AuditReportRecord::query()->create([
            'uuid' => (string) Str::uuid(),
            'critical_count' => $summary['critical'] ?? 0,
            'error_count' => $summary['error'] ?? 0,
            'warning_count' => $summary['warning'] ?? 0,
            'info_count' => $summary['info'] ?? 0,
            'issues_count' => count($report->issues),
            'pattern_count' => count($report->patternSuggestions),
            'duration_seconds' => $report->durationSeconds,
            'payload' => $report->toArray(),
            'options' => $options->toArray(),
        ]);

        return $this->toSnapshot($record);
    }

    /**
     * @return list<AuditReportSnapshot>
     */
    public function latest(int $limit = 50): array
    {
        /** @var list<AuditReportSnapshot> $reports */
        $reports = [];

        /** @var list<AuditReportRecord> $records */
        $records = AuditReportRecord::query()->latest()->limit($limit)->get()->all();

        foreach ($records as $record) {
            $reports[] = $this->toSnapshot($record);
        }

        return $reports;
    }

    public function findByUuid(string $uuid): ?AuditReportSnapshot
    {
        $record = AuditReportRecord::query()
            ->where('uuid', $uuid)
            ->first();

        return $record === null ? null : $this->toSnapshot($record);
    }

    /**
     * @param  list<PatternSuggestion>  $confirmed
     * @param  list<string>  $confirmedKeys
     */
    public function mergePatternSuggestions(string $uuid, array $confirmed, array $confirmedKeys): AuditReportSnapshot
    {
        $record = AuditReportRecord::query()->where('uuid', $uuid)->first();

        if ($record === null) {
            throw new \RuntimeException("Audit report [{$uuid}] was not found.");
        }

        $payload = $record->payload;
        $payload['patternSuggestions'] = PatternReportMerger::merge(
            is_array($payload['patternSuggestions'] ?? null) ? $payload['patternSuggestions'] : [],
            $confirmed,
            $confirmedKeys,
        );

        $record->update([
            'pattern_count' => count($payload['patternSuggestions']),
            'payload' => $payload,
        ]);

        return $this->toSnapshot($record->fresh() ?? $record);
    }

    private function toSnapshot(AuditReportRecord $record): AuditReportSnapshot
    {
        return new AuditReportSnapshot(
            uuid: $record->uuid,
            critical_count: $record->critical_count,
            error_count: $record->error_count,
            warning_count: $record->warning_count,
            info_count: $record->info_count,
            issues_count: $record->issues_count,
            pattern_count: $record->pattern_count,
            duration_seconds: $record->duration_seconds,
            payload: $record->payload,
            options: $record->options,
            created_at: $record->created_at,
        );
    }
}
