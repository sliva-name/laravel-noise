<?php

declare(strict_types=1);

namespace LaravelAudit\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $critical_count
 * @property int $error_count
 * @property int $warning_count
 * @property int $info_count
 * @property int $issues_count
 * @property int $pattern_count
 * @property float $duration_seconds
 * @property array<string, mixed> $payload
 * @property array<string, mixed>|null $options
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class AuditReportRecord extends Model
{
    protected $table = 'audit_reports';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'critical_count',
        'error_count',
        'warning_count',
        'info_count',
        'issues_count',
        'pattern_count',
        'duration_seconds',
        'payload',
        'options',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'options' => 'array',
            'duration_seconds' => 'float',
        ];
    }
}
