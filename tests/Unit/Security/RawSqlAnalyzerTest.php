<?php

declare(strict_types=1);

namespace LaravelAudit\Tests\Unit\Security;

use LaravelAudit\Analyzers\Security\RawSqlAnalyzer;
use LaravelAudit\Tests\Support\AnalyzesPhpFixtures;
use LaravelAudit\Tests\TestCase;

final class RawSqlAnalyzerTest extends TestCase
{
    use AnalyzesPhpFixtures;

    public function test_flags_raw_sql_in_application_code(): void
    {
        $issues = (new RawSqlAnalyzer)->analyze($this->analysisContext(<<<'PHP'
            <?php

            use Illuminate\Support\Facades\DB;

            final class ReportRepository
            {
                public function totals(): void
                {
                    DB::table('orders')->whereRaw('status = ?', ['paid']);
                }
            }
            PHP, 'app/Repositories/ReportRepository.php'));

        self::assertIssueRule('security.raw-sql', $issues);
    }

    public function test_reports_warning_for_static_raw_sql(): void
    {
        $issues = (new RawSqlAnalyzer)->analyze($this->analysisContext(<<<'PHP'
            <?php

            use Illuminate\Support\Facades\DB;

            final class ReportRepository
            {
                public function totals(): void
                {
                    DB::table('orders')->select(DB::raw('SUM(total) as total'));
                }
            }
            PHP, 'app/Repositories/ReportRepository.php'));

        self::assertCount(1, $issues);
        self::assertSame('security.raw-sql', $issues[0]->ruleId);
        self::assertSame('warning', $issues[0]->severity->value);
    }

    public function test_reports_one_issue_for_multiple_raw_fragments_on_same_line(): void
    {
        $issues = (new RawSqlAnalyzer)->analyze($this->analysisContext(<<<'PHP'
            <?php

            use Illuminate\Support\Facades\DB;

            final class ReportRepository
            {
                public function totals(): void
                {
                    DB::table('orders')->select(DB::raw('DATE(created_at) as dia'), DB::raw('SUM(total) as total'), DB::raw('COUNT(*) as pedidos'));
                }
            }
            PHP, 'app/Repositories/ReportRepository.php'));

        self::assertCount(1, $issues);
        self::assertSame('warning', $issues[0]->severity->value);
    }

    public function test_reports_critical_for_dynamic_raw_sql(): void
    {
        $issues = (new RawSqlAnalyzer)->analyze($this->analysisContext(<<<'PHP'
            <?php

            use Illuminate\Support\Facades\DB;

            final class ReportRepository
            {
                public function totals(string $column): void
                {
                    DB::table('orders')->whereRaw('status = '.$column);
                }
            }
            PHP, 'app/Repositories/ReportRepository.php'));

        self::assertCount(1, $issues);
        self::assertSame('critical', $issues[0]->severity->value);
    }

    public function test_ignores_raw_sql_inside_migrations(): void
    {
        $issues = (new RawSqlAnalyzer)->analyze($this->analysisContext(<<<'PHP'
            <?php

            use Illuminate\Support\Facades\DB;

            final class RenameScanLogsColumn
            {
                public function up(): void
                {
                    DB::statement('ALTER TABLE scan_logs RENAME COLUMN old TO new');
                }
            }
            PHP, 'database/migrations/2025_12_03_012130_rename_field_in_scan_logs_table.php'));

        self::assertNoIssues($issues);
    }

    public function test_ignores_query_builder_without_raw_methods(): void
    {
        $issues = (new RawSqlAnalyzer)->analyze($this->analysisContext(<<<'PHP'
            <?php

            use Illuminate\Support\Facades\DB;

            final class ReportRepository
            {
                public function totals(): int
                {
                    return (int) DB::table('orders')->count();
                }
            }
            PHP, 'app/Repositories/ReportRepository.php'));

        self::assertNoIssues($issues);
    }
}
