<?php

declare(strict_types=1);

namespace LaravelAudit\Tests\Unit;

use LaravelAudit\Analysis\AnalysisContext;
use LaravelAudit\Analysis\Issue;
use LaravelAudit\Analyzers\Security\MassAssignmentAnalyzer;
use LaravelAudit\Analyzers\Security\RawSqlAnalyzer;
use LaravelAudit\Project\PhpFile;
use LaravelAudit\Project\ProjectIndex;
use LaravelAudit\Tests\TestCase;
use PhpParser\ParserFactory;

final class SecurityAnalyzersTest extends TestCase
{
    public function test_raw_sql_analyzer_skips_migration_files(): void
    {
        $issues = (new RawSqlAnalyzer)->analyze($this->context(<<<'PHP'
            <?php

            use Illuminate\Support\Facades\DB;

            DB::statement('ALTER TABLE scan_logs RENAME COLUMN old TO new');
            PHP, 'database/migrations/2025_12_03_012130_rename_field_in_scan_logs_table.php'));

        self::assertSame([], $issues);
    }

    public function test_raw_sql_analyzer_flags_raw_sql_outside_migrations(): void
    {
        $issues = (new RawSqlAnalyzer)->analyze($this->context(<<<'PHP'
            <?php

            use Illuminate\Support\Facades\DB;

            DB::statement('SELECT 1');
            PHP, 'app/Repositories/ReportRepository.php'));

        self::assertRuleFound('security.raw-sql', $issues);
    }

    public function test_mass_assignment_analyzer_flags_missing_policy(): void
    {
        $issues = (new MassAssignmentAnalyzer)->analyze($this->modelContext(<<<'PHP'
            <?php

            namespace App\Models;

            use Illuminate\Database\Eloquent\Model;

            final class User extends Model
            {
            }
            PHP));

        self::assertCount(1, $issues);
        self::assertSame('Model has no mass assignment policy', $issues[0]->title);
    }

    public function test_mass_assignment_analyzer_flags_empty_guarded(): void
    {
        $issues = (new MassAssignmentAnalyzer)->analyze($this->modelContext(<<<'PHP'
            <?php

            namespace App\Models;

            use Illuminate\Database\Eloquent\Model;

            final class User extends Model
            {
                protected $guarded = [];
            }
            PHP));

        self::assertCount(1, $issues);
        self::assertSame('Model allows unrestricted mass assignment', $issues[0]->title);
    }

    public function test_mass_assignment_analyzer_recommends_fillable_when_only_guarded_is_defined(): void
    {
        $issues = (new MassAssignmentAnalyzer)->analyze($this->modelContext(<<<'PHP'
            <?php

            namespace App\Models;

            use Illuminate\Database\Eloquent\Model;

            final class User extends Model
            {
                protected $guarded = ['id'];
            }
            PHP));

        self::assertCount(1, $issues);
        self::assertSame('Model defines $guarded without $fillable', $issues[0]->title);
    }

    public function test_mass_assignment_analyzer_accepts_explicit_fillable(): void
    {
        $issues = (new MassAssignmentAnalyzer)->analyze($this->modelContext(<<<'PHP'
            <?php

            namespace App\Models;

            use Illuminate\Database\Eloquent\Model;

            final class User extends Model
            {
                protected $fillable = ['name', 'email'];
            }
            PHP));

        self::assertSame([], $issues);
    }

    private function modelContext(string $contents): AnalysisContext
    {
        return $this->context($contents, 'app/Models/User.php');
    }

    private function context(string $contents, string $relativePath): AnalysisContext
    {
        return new AnalysisContext(
            basePath: __DIR__,
            project: new ProjectIndex([
                $this->phpFile($contents, $relativePath),
            ], []),
            config: [],
        );
    }

    private function phpFile(string $contents, string $relativePath): PhpFile
    {
        $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($contents) ?? [];

        return new PhpFile(
            path: __DIR__.'/Fixture.php',
            relativePath: $relativePath,
            contents: $contents,
            ast: $ast,
            classes: [],
            methods: [],
            lines: substr_count($contents, PHP_EOL) + 1,
        );
    }

    /**
     * @param  list<Issue>  $issues
     */
    private static function assertRuleFound(string $ruleId, array $issues): void
    {
        self::assertContains($ruleId, array_map(
            fn ($issue): string => $issue->ruleId,
            $issues,
        ));
    }
}
