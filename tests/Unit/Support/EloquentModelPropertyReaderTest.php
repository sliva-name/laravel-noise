<?php

declare(strict_types=1);

namespace LaravelAudit\Tests\Unit\Support;

use LaravelAudit\Analyzers\Support\EloquentModelPropertyReader;
use LaravelAudit\Tests\Support\AnalyzesPhpFixtures;
use LaravelAudit\Tests\TestCase;

final class EloquentModelPropertyReaderTest extends TestCase
{
    use AnalyzesPhpFixtures;

    public function test_reads_fillable_attribute_with_variadic_columns(): void
    {
        $properties = $this->read(<<<'PHP'
            <?php

            namespace App\Models;

            use Illuminate\Database\Eloquent\Attributes\Fillable;
            use Illuminate\Database\Eloquent\Model;

            #[Fillable('name', 'email')]
            final class User extends Model
            {
            }
            PHP);

        self::assertTrue($properties['hasFillable']);
        self::assertFalse($properties['hasGuarded']);
        self::assertFalse($properties['hasUnguarded']);
    }

    public function test_reads_guarded_attribute_without_treating_unguarded_as_guarded(): void
    {
        $properties = $this->read(<<<'PHP'
            <?php

            namespace App\Models;

            use Illuminate\Database\Eloquent\Attributes\Guarded;
            use Illuminate\Database\Eloquent\Model;

            #[Guarded(['id'])]
            final class User extends Model
            {
            }
            PHP);

        self::assertFalse($properties['hasFillable']);
        self::assertTrue($properties['hasGuarded']);
        self::assertFalse($properties['hasEmptyGuarded']);
        self::assertFalse($properties['hasUnguarded']);
    }

    public function test_reads_unguarded_attribute_separately_from_guarded(): void
    {
        $properties = $this->read(<<<'PHP'
            <?php

            namespace App\Models;

            use Illuminate\Database\Eloquent\Attributes\Unguarded;
            use Illuminate\Database\Eloquent\Model;

            #[Unguarded]
            final class User extends Model
            {
            }
            PHP);

        self::assertFalse($properties['hasFillable']);
        self::assertFalse($properties['hasGuarded']);
        self::assertFalse($properties['hasEmptyGuarded']);
        self::assertTrue($properties['hasUnguarded']);
    }

    public function test_reads_empty_guarded_attribute(): void
    {
        $properties = $this->read(<<<'PHP'
            <?php

            namespace App\Models;

            use Illuminate\Database\Eloquent\Attributes\Guarded;
            use Illuminate\Database\Eloquent\Model;

            #[Guarded([])]
            final class User extends Model
            {
            }
            PHP);

        self::assertTrue($properties['hasGuarded']);
        self::assertTrue($properties['hasEmptyGuarded']);
        self::assertFalse($properties['hasUnguarded']);
    }

    /**
     * @return array{
     *     hasFillable: bool,
     *     hasGuarded: bool,
     *     hasEmptyGuarded: bool,
     *     hasUnguarded: bool,
     *     guardedLine: int|null
     * }
     */
    private function read(string $contents): array
    {
        return (new EloquentModelPropertyReader)->read(
            $this->phpFixture($contents, 'app/Models/User.php'),
        );
    }
}
