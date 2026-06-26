<?php

declare(strict_types=1);

namespace LaravelAudit\Repositories;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LaravelAudit\Audit\AuditOptions;
use LaravelAudit\Models\AuditReportSnapshot;
use LaravelAudit\Pattern\PatternReportMerger;
use LaravelAudit\Pattern\PatternSuggestion;
use LaravelAudit\Reporting\AuditReport;
use LaravelAudit\Repositories\Contracts\AuditReportStore;

final class FileAuditReportStore implements AuditReportStore
{
    public function __construct(
        private readonly string $directory,
    ) {}

    public function store(AuditReport $report, AuditOptions $options): AuditReportSnapshot
    {
        $this->ensureDirectory();

        $summary = $report->summary();
        $snapshot = new AuditReportSnapshot(
            uuid: (string) Str::uuid(),
            critical_count: $summary['critical'] ?? 0,
            error_count: $summary['error'] ?? 0,
            warning_count: $summary['warning'] ?? 0,
            info_count: $summary['info'] ?? 0,
            issues_count: count($report->issues),
            pattern_count: count($report->patternSuggestions),
            duration_seconds: $report->durationSeconds,
            payload: $report->toArray(),
            options: $options->toArray(),
            created_at: Carbon::now(),
        );

        $path = $this->pathFor($snapshot->uuid);
        $written = file_put_contents($path, json_encode($snapshot->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        if ($written === false) {
            throw new \RuntimeException("Unable to write audit report to [{$path}].");
        }

        return $snapshot;
    }

    /**
     * @return list<AuditReportSnapshot>
     */
    public function latest(int $limit = 50): array
    {
        $this->ensureDirectory();

        $reports = [];

        foreach (glob($this->directory.'/*.json') ?: [] as $path) {
            $data = $this->readFile($path);

            if ($data !== null) {
                $reports[] = AuditReportSnapshot::fromArray($data);
            }
        }

        usort(
            $reports,
            static fn (AuditReportSnapshot $a, AuditReportSnapshot $b): int => ($b->created_at?->getTimestamp() ?? 0) <=> ($a->created_at?->getTimestamp() ?? 0),
        );

        return array_slice($reports, 0, $limit);
    }

    public function findByUuid(string $uuid): ?AuditReportSnapshot
    {
        $path = $this->pathFor($uuid);

        if (! is_file($path)) {
            return null;
        }

        $data = $this->readFile($path);

        return $data === null ? null : AuditReportSnapshot::fromArray($data);
    }

    /**
     * @param  list<PatternSuggestion>  $confirmed
     * @param  list<string>  $confirmedKeys
     */
    public function mergePatternSuggestions(string $uuid, array $confirmed, array $confirmedKeys): AuditReportSnapshot
    {
        $snapshot = $this->findByUuid($uuid);

        if ($snapshot === null) {
            throw new \RuntimeException("Audit report [{$uuid}] was not found.");
        }

        $payload = $snapshot->payload;
        $payload['patternSuggestions'] = PatternReportMerger::merge(
            is_array($payload['patternSuggestions'] ?? null) ? $payload['patternSuggestions'] : [],
            $confirmed,
            $confirmedKeys,
        );

        $updated = new AuditReportSnapshot(
            uuid: $snapshot->uuid,
            critical_count: $snapshot->critical_count,
            error_count: $snapshot->error_count,
            warning_count: $snapshot->warning_count,
            info_count: $snapshot->info_count,
            issues_count: $snapshot->issues_count,
            pattern_count: count($payload['patternSuggestions']),
            duration_seconds: $snapshot->duration_seconds,
            payload: $payload,
            options: $snapshot->options,
            created_at: $snapshot->created_at,
        );

        $path = $this->pathFor($uuid);
        $written = file_put_contents($path, json_encode($updated->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        if ($written === false) {
            throw new \RuntimeException("Unable to write audit report to [{$path}].");
        }

        return $updated;
    }

    private function pathFor(string $uuid): string
    {
        return $this->directory.'/'.str_replace(['/', '\\', "\0"], '', $uuid).'.json';
    }

    private function ensureDirectory(): void
    {
        if (is_dir($this->directory)) {
            return;
        }

        if (! mkdir($this->directory, 0755, true) && ! is_dir($this->directory)) {
            throw new \RuntimeException("Unable to create audit report directory [{$this->directory}].");
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readFile(string $path): ?array
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        /** @var array<string, mixed>|null $data */
        $data = json_decode($contents, true);

        return is_array($data) ? $data : null;
    }
}
