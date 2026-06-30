<?php

declare(strict_types=1);

namespace LaravelAudit\Tests\Unit;

use LaravelAudit\Pattern\MethodFeatureExtractor;
use LaravelAudit\Project\PhpFile;
use LaravelAudit\Tests\TestCase;
use PhpParser\ParserFactory;

final class MethodFeatureExtractorTest extends TestCase
{
    public function test_ignores_back_and_inertia_returns_for_direct_model_returns(): void
    {
        $features = $this->extractFeatures(<<<'PHP'
            <?php

            namespace App\Http\Controllers;

            use Inertia\Inertia;

            final class CartController
            {
                public function store(): mixed
                {
                    if ($invalid) {
                        return back()->with('error', 'nope');
                    }

                    return back()->with('message', 'ok');
                }

                public function index(): mixed
                {
                    return Inertia::render('Cart/Index', ['items' => []]);
                }
            }
            PHP, 'app/Http/Controllers/CartController.php', 'store');

        self::assertSame(0.0, $features['direct_model_returns']);
        self::assertSame(0.0, $features['inertia_renders']);

        $indexFeatures = $this->extractFeatures(<<<'PHP'
            <?php

            namespace App\Http\Controllers;

            use Inertia\Inertia;

            final class CartController
            {
                public function index(): mixed
                {
                    return Inertia::render('Cart/Index', ['items' => []]);
                }
            }
            PHP, 'app/Http/Controllers/CartController.php', 'index');

        self::assertSame(1.0, $indexFeatures['inertia_renders']);
    }

    public function test_counts_json_model_returns_as_direct_model_returns(): void
    {
        $features = $this->extractFeatures(<<<'PHP'
            <?php

            namespace App\Http\Controllers;

            final class UserController
            {
                public function show(int $id): mixed
                {
                    $user = User::findOrFail($id);

                    return response()->json($user);
                }
            }
            PHP, 'app/Http/Controllers/UserController.php', 'show');

        self::assertSame(1.0, $features['direct_model_returns']);
        self::assertSame(0.0, $features['inertia_renders']);
    }

    public function test_counts_mutating_db_calls_separately_from_reads(): void
    {
        $features = $this->extractFeatures(<<<'PHP'
            <?php

            namespace App\Http\Controllers;

            use Illuminate\Support\Facades\DB;

            final class OrderController
            {
                public function store(): void
                {
                    DB::table('orders')->insert(['total' => 100]);
                    DB::table('orders')->where('id', 1)->update(['paid' => true]);
                    DB::table('orders')->where('id', 1)->first();
                    DB::table('orders')->where('status', 'paid')->get();
                }
            }
            PHP, 'app/Http/Controllers/OrderController.php');

        self::assertGreaterThan(0.0, $features['db_calls']);
        self::assertSame(2.0, $features['mutating_db_calls']);
    }

    public function test_ignores_this_delegation_and_scalar_tuple_returns(): void
    {
        $features = $this->extractFeatures(<<<'PHP'
            <?php

            namespace App\Http\Controllers;

            final class CheckoutController
            {
                public function store(): mixed
                {
                    return $this->finalizar($request, $items);
                }

                protected function cuponDeSesion(float $subtotal): array
                {
                    if (! session('cupon')) {
                        return [null, 0.0];
                    }

                    return ['SAVE10', 5.0];
                }
            }
            PHP, 'app/Http/Controllers/CheckoutController.php', 'store');

        self::assertSame(0.0, $features['direct_model_returns']);

        $helperFeatures = $this->extractFeatures(<<<'PHP'
            <?php

            namespace App\Http\Controllers;

            final class CheckoutController
            {
                protected function cuponDeSesion(float $subtotal): array
                {
                    if (! session('cupon')) {
                        return [null, 0.0];
                    }

                    return ['SAVE10', 5.0];
                }
            }
            PHP, 'app/Http/Controllers/CheckoutController.php', 'cuponDeSesion');

        self::assertSame(0.0, $helperFeatures['direct_model_returns']);
    }

    /**
     * @return array<string, float>
     */
    private function extractFeatures(string $contents, string $relativePath, string $method = 'store'): array
    {
        $file = $this->phpFile($contents, $relativePath);
        $extracted = (new MethodFeatureExtractor)->extract($file);

        foreach ($extracted as $methodFeatures) {
            if ($methodFeatures->method === $method) {
                return $methodFeatures->values;
            }
        }

        self::fail("Method {$method} was not extracted.");
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
}
