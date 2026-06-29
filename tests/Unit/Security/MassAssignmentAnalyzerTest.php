<?php

declare(strict_types=1);

namespace LaravelAudit\Tests\Unit\Security;

use LaravelAudit\Analysis\AnalysisContext;
use LaravelAudit\Analyzers\Security\MassAssignmentAnalyzer;
use LaravelAudit\Tests\Support\AnalyzesPhpFixtures;
use LaravelAudit\Tests\TestCase;

final class MassAssignmentAnalyzerTest extends TestCase
{
    use AnalyzesPhpFixtures;

    public function test_flags_models_without_fillable_or_guarded(): void
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

    public function test_flags_empty_guarded_as_critical(): void
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

    public function test_recommends_fillable_when_only_guarded_is_defined(): void
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

    public function test_accepts_explicit_fillable_list(): void
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

        self::assertNoIssues($issues);
    }

    public function test_accepts_fillable_php_attribute(): void
    {
        $issues = (new MassAssignmentAnalyzer)->analyze($this->modelContext(<<<'PHP'
            <?php

            namespace App\Models;

            use Illuminate\Database\Eloquent\Attributes\Fillable;
            use Illuminate\Database\Eloquent\Model;

            #[Fillable(['name', 'email'])]
            final class User extends Model
            {
            }
            PHP));

        self::assertNoIssues($issues);
    }

    public function test_accepts_fillable_attribute_with_variadic_columns(): void
    {
        $issues = (new MassAssignmentAnalyzer)->analyze($this->modelContext(<<<'PHP'
            <?php

            namespace App\Models;

            use Illuminate\Database\Eloquent\Attributes\Fillable;
            use Illuminate\Database\Eloquent\Model;

            #[Fillable('name', 'email')]
            final class User extends Model
            {
            }
            PHP));

        self::assertNoIssues($issues);
    }

    public function test_recommends_fillable_when_only_guarded_attribute_is_defined(): void
    {
        $issues = (new MassAssignmentAnalyzer)->analyze($this->modelContext(<<<'PHP'
            <?php

            namespace App\Models;

            use Illuminate\Database\Eloquent\Attributes\Guarded;
            use Illuminate\Database\Eloquent\Model;

            #[Guarded(['id'])]
            final class User extends Model
            {
            }
            PHP));

        self::assertCount(1, $issues);
        self::assertSame('Model defines $guarded without $fillable', $issues[0]->title);
    }

    public function test_flags_empty_guarded_attribute_as_critical(): void
    {
        $issues = (new MassAssignmentAnalyzer)->analyze($this->modelContext(<<<'PHP'
            <?php

            namespace App\Models;

            use Illuminate\Database\Eloquent\Attributes\Guarded;
            use Illuminate\Database\Eloquent\Model;

            #[Guarded([])]
            final class User extends Model
            {
            }
            PHP));

        self::assertCount(1, $issues);
        self::assertSame('Model allows unrestricted mass assignment', $issues[0]->title);
    }

    public function test_does_not_duplicate_empty_guarded_warning_for_unguarded_attribute(): void
    {
        $issues = (new MassAssignmentAnalyzer)->analyze($this->modelContext(<<<'PHP'
            <?php

            namespace App\Models;

            use Illuminate\Database\Eloquent\Attributes\Unguarded;
            use Illuminate\Database\Eloquent\Model;

            #[Unguarded]
            final class User extends Model
            {
            }
            PHP));

        self::assertNoIssues($issues);
    }

    public function test_ignores_non_model_files(): void
    {
        $issues = (new MassAssignmentAnalyzer)->analyze($this->analysisContext(<<<'PHP'
            <?php

            final class UserFactory
            {
            }
            PHP, 'database/factories/UserFactory.php'));

        self::assertNoIssues($issues);
    }

    public function test_does_not_flag_model_with_both_fillable_and_guarded(): void
    {
        $issues = (new MassAssignmentAnalyzer)->analyze($this->modelContext(<<<'PHP'
            <?php

            namespace App\Models;

            use Illuminate\Database\Eloquent\Model;

            final class User extends Model
            {
                protected $fillable = ['name'];
                protected $guarded = ['id'];
            }
            PHP));

        self::assertNoIssues($issues);
    }

    public function test_does_not_treat_fillable_mentions_in_comments_as_policy(): void
    {
        $issues = (new MassAssignmentAnalyzer)->analyze($this->modelContext(<<<'PHP'
            <?php

            namespace App\Models;

            use Illuminate\Database\Eloquent\Model;

            /**
             * Document $fillable for readers.
             */
            final class User extends Model
            {
            }
            PHP));

        self::assertCount(1, $issues);
        self::assertSame('Model has no mass assignment policy', $issues[0]->title);
    }

    private function modelContext(string $contents): AnalysisContext
    {
        return $this->analysisContext($contents, 'app/Models/User.php');
    }
}
