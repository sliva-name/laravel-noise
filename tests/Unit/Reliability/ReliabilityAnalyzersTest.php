<?php

declare(strict_types=1);

namespace LaravelAudit\Tests\Unit\Reliability;

use LaravelAudit\Analyzers\Reliability\EnvAccessOutsideConfigAnalyzer;
use LaravelAudit\Analyzers\Reliability\MissingTransactionAnalyzer;
use LaravelAudit\Tests\Support\AnalyzesPhpFixtures;
use LaravelAudit\Tests\TestCase;

final class ReliabilityAnalyzersTest extends TestCase
{
    use AnalyzesPhpFixtures;

    public function test_env_access_analyzer_ignores_tests_directory(): void
    {
        $issues = (new EnvAccessOutsideConfigAnalyzer)->analyze($this->analysisContext(<<<'PHP'
            <?php

            final class Example
            {
                public function handle(): string
                {
                    return env('APP_KEY');
                }
            }
            PHP, 'tests/Unit/ExampleTest.php'));

        self::assertNoIssues($issues);
    }

    public function test_env_access_analyzer_flags_application_code(): void
    {
        $issues = (new EnvAccessOutsideConfigAnalyzer)->analyze($this->analysisContext(<<<'PHP'
            <?php

            final class Example
            {
                public function handle(): string
                {
                    return env('APP_KEY');
                }
            }
            PHP, 'app/Services/Example.php'));

        self::assertIssueRule('reliability.env-access-outside-config', $issues);
    }

    public function test_env_access_analyzer_ignores_env_mentions_in_comments(): void
    {
        $issues = (new EnvAccessOutsideConfigAnalyzer)->analyze($this->analysisContext(<<<'PHP'
            <?php

            final class Example
            {
                public function handle(): string
                {
                    // Switched from env() to config()
                    return config('services.example.key');
                }
            }
            PHP, 'app/Services/Example.php'));

        self::assertNoIssues($issues);
    }

    public function test_missing_transaction_analyzer_flags_method_with_multiple_writes(): void
    {
        $issues = (new MissingTransactionAnalyzer)->analyze($this->analysisContext(<<<'PHP'
            <?php

            namespace App\Http\Controllers;

            use App\Models\Order;
            use App\Models\Inventory;

            final class OrderController
            {
                public function store(): void
                {
                    Order::create(['total' => 100]);
                    Inventory::create(['sku' => 'abc']);
                }
            }
            PHP, 'app/Http/Controllers/OrderController.php'));

        self::assertCount(1, $issues);
        self::assertSame('reliability.missing-transaction', $issues[0]->ruleId);
    }

    public function test_missing_transaction_analyzer_ignores_method_wrapped_in_transaction(): void
    {
        $issues = (new MissingTransactionAnalyzer)->analyze($this->analysisContext(<<<'PHP'
            <?php

            namespace App\Http\Controllers;

            use App\Models\Order;
            use App\Models\Inventory;
            use Illuminate\Support\Facades\DB;

            final class OrderController
            {
                public function store(): void
                {
                    DB::transaction(function (): void {
                        Order::create(['total' => 100]);
                        Inventory::create(['sku' => 'abc']);
                    });
                }
            }
            PHP, 'app/Http/Controllers/OrderController.php'));

        self::assertNoIssues($issues);
    }

    public function test_missing_transaction_analyzer_ignores_storage_delete_with_model_delete(): void
    {
        $issues = (new MissingTransactionAnalyzer)->analyze($this->analysisContext(<<<'PHP'
            <?php

            namespace App\Http\Controllers;

            use App\Models\Producto;
            use Illuminate\Support\Facades\Storage;

            final class ProductoController
            {
                public function destroy(Producto $producto): void
                {
                    if ($producto->imagen) {
                        Storage::disk('public')->delete($producto->imagen);
                    }

                    $producto->delete();
                }
            }
            PHP, 'app/Http/Controllers/ProductoController.php'));

        self::assertNoIssues($issues);
    }
}
