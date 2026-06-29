<?php

declare(strict_types=1);

namespace LaravelAudit\Tests\Unit\Security;

use LaravelAudit\Analysis\AnalysisContext;
use LaravelAudit\Analyzers\Security\SensitiveFieldExposureAnalyzer;
use LaravelAudit\Analyzers\Support\EloquentModelSerializationReader;
use LaravelAudit\Project\PhpFile;
use LaravelAudit\Project\ProjectIndex;
use LaravelAudit\Tests\Support\AnalyzesPhpFixtures;
use LaravelAudit\Tests\TestCase;

final class SensitiveFieldExposureAnalyzerTest extends TestCase
{
    use AnalyzesPhpFixtures;

    public function test_detects_unhidden_password_when_user_is_returned_directly(): void
    {
        $issues = (new SensitiveFieldExposureAnalyzer)->analyze($this->analysisContext([
            $this->phpFixture(<<<'PHP'
                <?php

                namespace App\Models;

                use Illuminate\Database\Eloquent\Attributes\Fillable;
                use Illuminate\Database\Eloquent\Model;

                #[Fillable(['name', 'email', 'password'])]
                final class User extends Model
                {
                }
                PHP, 'app/Models/User.php'),
            $this->phpFixture(<<<'PHP'
                <?php

                namespace App\Http\Controllers;

                use App\Models\User;

                final class ProfileController
                {
                    public function show(User $user): User
                    {
                        return $user;
                    }
                }
                PHP, 'app/Http/Controllers/ProfileController.php'),
        ]));

        self::assertCount(1, $issues);
        self::assertSame('security.sensitive-field-exposure', $issues[0]->ruleId);
    }

    public function test_detects_inertia_response_with_raw_model(): void
    {
        $issues = (new SensitiveFieldExposureAnalyzer)->analyze($this->analysisContext([
            $this->phpFixture(<<<'PHP'
                <?php

                namespace App\Models;

                use Illuminate\Database\Eloquent\Model;

                final class User extends Model
                {
                    protected $fillable = ['name', 'email', 'password'];
                }
                PHP, 'app/Models/User.php'),
            $this->phpFixture(<<<'PHP'
                <?php

                namespace App\Http\Controllers;

                use App\Models\User;
                use Inertia\Inertia;

                final class ProfileController
                {
                    public function edit(User $user)
                    {
                        return Inertia::render('Profile/Edit', [
                            'user' => $user,
                        ]);
                    }
                }
                PHP, 'app/Http/Controllers/ProfileController.php'),
        ]));

        self::assertIssueRule('security.sensitive-field-exposure', $issues);
    }

    public function test_does_not_flag_model_with_hidden_attribute(): void
    {
        $issues = (new SensitiveFieldExposureAnalyzer)->analyze($this->analysisContext([
            $this->phpFixture(<<<'PHP'
                <?php

                namespace App\Models;

                use Illuminate\Database\Eloquent\Attributes\Fillable;
                use Illuminate\Database\Eloquent\Attributes\Hidden;
                use Illuminate\Database\Eloquent\Model;

                #[Fillable(['name', 'email', 'password'])]
                #[Hidden(['password', 'remember_token'])]
                final class User extends Model
                {
                }
                PHP, 'app/Models/User.php'),
            $this->phpFixture(<<<'PHP'
                <?php

                namespace App\Http\Controllers;

                use App\Models\User;
                use Inertia\Inertia;

                final class ProfileController
                {
                    public function edit(User $user)
                    {
                        return Inertia::render('Profile/Edit', [
                            'user' => $user,
                        ]);
                    }
                }
                PHP, 'app/Http/Controllers/ProfileController.php'),
        ]));

        self::assertNoIssues($issues);
    }

    public function test_does_not_flag_resource_wrapped_inertia_response(): void
    {
        $issues = (new SensitiveFieldExposureAnalyzer)->analyze($this->analysisContext([
            $this->phpFixture(<<<'PHP'
                <?php

                namespace App\Models;

                use Illuminate\Database\Eloquent\Model;

                final class User extends Model
                {
                    protected $fillable = ['name', 'email', 'password'];
                }
                PHP, 'app/Models/User.php'),
            $this->phpFixture(<<<'PHP'
                <?php

                namespace App\Http\Controllers;

                use App\Http\Resources\UserResource;
                use App\Models\User;
                use Inertia\Inertia;

                final class ProfileController
                {
                    public function edit(User $user)
                    {
                        return Inertia::render('Profile/Edit', [
                            'user' => new UserResource($user),
                        ]);
                    }
                }
                PHP, 'app/Http/Controllers/ProfileController.php'),
        ]));

        self::assertNoIssues($issues);
    }

    public function test_serialization_reader_treats_password_cast_as_sensitive(): void
    {
        $columns = (new EloquentModelSerializationReader)->unhiddenSensitiveColumns($this->phpFixture(<<<'PHP'
            <?php

            namespace App\Models;

            use Illuminate\Database\Eloquent\Model;

            final class User extends Model
            {
                protected function casts(): array
                {
                    return [
                        'password' => 'hashed',
                    ];
                }
            }
            PHP, 'app/Models/User.php'));

        self::assertSame(['password'], $columns);
    }

    /**
     * @param  list<PhpFile>  $files
     */
    private function analysisContext(array $files): AnalysisContext
    {
        return new AnalysisContext(
            basePath: __DIR__,
            project: new ProjectIndex($files, []),
            config: [],
        );
    }
}
