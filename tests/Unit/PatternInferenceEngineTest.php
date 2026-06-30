<?php

declare(strict_types=1);

namespace LaravelAudit\Tests\Unit;

use LaravelAudit\Analysis\Category;
use LaravelAudit\Analysis\Issue;
use LaravelAudit\Analysis\Location;
use LaravelAudit\Analysis\Severity;
use LaravelAudit\Pattern\MethodFeatureExtractor;
use LaravelAudit\Pattern\PatternInferenceEngine;
use LaravelAudit\Pattern\PatternModel;
use LaravelAudit\Project\PhpFile;
use LaravelAudit\Project\ProjectIndex;
use LaravelAudit\Tests\TestCase;
use PhpParser\ParserFactory;

final class PatternInferenceEngineTest extends TestCase
{
    public function test_suggests_strategy_for_type_dispatch(): void
    {
        $project = new ProjectIndex([
            $this->phpFile(<<<'PHP'
                <?php

                final class PaymentProcessor
                {
                    public function handle(object $payment): void
                    {
                        if ($payment instanceof CardPayment) {
                            $this->chargeCard($payment);
                        } elseif ($payment instanceof PaypalPayment) {
                            $this->chargePaypal($payment);
                        } elseif ($payment instanceof BankPayment) {
                            $this->chargeBank($payment);
                        } elseif ($payment instanceof WalletPayment) {
                            $this->chargeWallet($payment);
                        } elseif ($payment instanceof CryptoPayment) {
                            $this->chargeCrypto($payment);
                        }
                    }
                }
                PHP),
        ], []);

        $engine = new PatternInferenceEngine(
            new MethodFeatureExtractor,
            PatternModel::fromPath(__DIR__.'/../../resources/pattern-model.json'),
        );

        $suggestions = $engine->infer($project, [], 0.55, 5);

        self::assertNotSame([], $suggestions);
        self::assertSame('strategy', $suggestions[0]->pattern);
    }

    public function test_suggests_enum_for_switch_with_magic_strings(): void
    {
        $project = new ProjectIndex([
            $this->phpFile(<<<'PHP'
                <?php

                final class OrderStatusHandler
                {
                    public function handle(string $status): void
                    {
                        switch ($status) {
                            case 'pending':
                                $this->markPending();
                                break;
                            case 'paid':
                                $this->markPaid();
                                break;
                            case 'shipped':
                                $this->markShipped();
                                break;
                            case 'delivered':
                                $this->markDelivered();
                                break;
                            case 'cancelled':
                                $this->markCancelled();
                                break;
                        }
                    }
                }
                PHP),
        ], []);

        $engine = new PatternInferenceEngine(
            new MethodFeatureExtractor,
            PatternModel::fromPath(__DIR__.'/../../resources/pattern-model.json'),
        );

        $suggestions = $engine->infer($project, [], 0.55, 5);

        self::assertNotSame([], $suggestions);
        self::assertSame('enum', $suggestions[0]->pattern);
    }

    public function test_boosts_action_when_fat_controller_finding_exists(): void
    {
        $project = new ProjectIndex([
            $this->phpFile(<<<'PHP'
                <?php

                namespace App\Http\Controllers;

                final class OrderController
                {
                    public function store(): void
                    {
                        DB::table('orders')->insert(['total' => 100]);
                        DB::table('order_items')->insert(['sku' => 'abc']);
                        DB::table('inventory')->where('sku', 'abc')->decrement('qty');
                        DB::table('audit_logs')->insert(['event' => 'order.created']);
                        DB::table('customers')->where('id', 1)->update(['last_order_at' => now()]);
                        return;
                    }
                }
                PHP, 'app/Http/Controllers/OrderController.php'),
        ], []);

        $issues = [
            new Issue(
                ruleId: 'best-practices.fat-controller',
                category: Category::BestPractices,
                severity: Severity::Warning,
                title: 'Controller is large',
                message: 'Large controller',
                location: new Location('app/Http/Controllers/OrderController.php', 1),
            ),
        ];

        $engine = new PatternInferenceEngine(
            new MethodFeatureExtractor,
            PatternModel::fromPath(__DIR__.'/../../resources/pattern-model.json'),
        );

        $suggestions = $engine->infer($project, $issues, 0.50, 5);
        $patterns = array_map(fn ($suggestion) => $suggestion->pattern, $suggestions);

        self::assertContains('action', $patterns);
        self::assertContains('best-practices.fat-controller', $suggestions[array_search('action', $patterns, true)]->signals);
    }

    public function test_suggests_dependency_injection_for_service_locator_usage(): void
    {
        $project = new ProjectIndex([
            $this->phpFile(<<<'PHP'
                <?php

                final class Example
                {
                    public function handle(): void
                    {
                        $billing = app(BillingService::class);
                        $reports = resolve(ReportService::class);
                        $sync = new SyncService();
                        $billing->charge();
                        $reports->render();
                        $sync->run();
                    }
                }
                PHP),
        ], []);

        $engine = new PatternInferenceEngine(
            new MethodFeatureExtractor,
            PatternModel::fromPath(__DIR__.'/../../resources/pattern-model.json'),
        );

        $suggestions = $engine->infer($project, [], 0.55, 5);

        self::assertContains('dependency_injection', array_map(
            fn ($suggestion) => $suggestion->pattern,
            $suggestions,
        ));
    }

    public function test_does_not_suggest_patterns_for_simple_methods_below_threshold(): void
    {
        $project = new ProjectIndex([
            $this->phpFile(<<<'PHP'
                <?php

                final class HealthCheck
                {
                    public function ping(): string
                    {
                        return 'ok';
                    }
                }
                PHP),
        ], []);

        $engine = new PatternInferenceEngine(
            new MethodFeatureExtractor,
            PatternModel::fromPath(__DIR__.'/../../resources/pattern-model.json'),
        );

        $suggestions = $engine->infer($project, [], 0.95, 5);

        self::assertSame([], $suggestions);
    }

    public function test_does_not_suggest_api_resource_for_inertia_controller_with_back_returns(): void
    {
        $project = new ProjectIndex([
            $this->phpFile(<<<'PHP'
                <?php

                namespace App\Http\Controllers;

                use Inertia\Inertia;

                final class CartController
                {
                    public function store(): mixed
                    {
                        $request->validate(['sku' => 'required']);

                        if ($invalid) {
                            return back()->with('error', 'nope');
                        }

                        Producto::create(['sku' => 'abc']);

                        return back()->with('message', 'ok');
                    }
                }
                PHP, 'app/Http/Controllers/CartController.php'),
        ], []);

        $engine = new PatternInferenceEngine(
            new MethodFeatureExtractor,
            PatternModel::fromPath(__DIR__.'/../../resources/pattern-model.json'),
        );

        $suggestions = $engine->infer($project, [], 0.55, 10);
        $patterns = array_map(fn ($suggestion) => $suggestion->pattern, $suggestions);

        self::assertNotContains('api_resource', $patterns);
    }

    public function test_keeps_only_top_pattern_per_method(): void
    {
        $project = new ProjectIndex([
            $this->phpFile(<<<'PHP'
                <?php

                namespace App\Http\Controllers;

                use Illuminate\Support\Facades\DB;

                final class OrderController
                {
                    public function store(): void
                    {
                        DB::table('orders')->insert(['total' => 100]);
                        DB::table('order_items')->insert(['sku' => 'abc']);
                        DB::table('inventory')->where('sku', 'abc')->decrement('qty');
                        DB::table('audit_logs')->insert(['event' => 'order.created']);
                        DB::table('customers')->where('id', 1)->update(['last_order_at' => now()]);
                    }
                }
                PHP, 'app/Http/Controllers/OrderController.php'),
        ], []);

        $engine = new PatternInferenceEngine(
            new MethodFeatureExtractor,
            PatternModel::fromPath(__DIR__.'/../../resources/pattern-model.json'),
        );

        $suggestions = $engine->infer($project, [], 0.50, 20);
        $methods = array_map(fn ($suggestion) => $suggestion->method, $suggestions);

        self::assertSame(array_unique($methods), $methods);
    }

    public function test_prefers_form_request_for_simple_inline_validation(): void
    {
        $relativePath = 'app/Http/Controllers/Auth/PasswordController.php';
        $project = new ProjectIndex([
            $this->phpFile(<<<'PHP'
                <?php

                namespace App\Http\Controllers\Auth;

                final class PasswordController
                {
                    public function update(): void
                    {
                        $validated = request()->validate([
                            'current_password' => ['required', 'current_password'],
                            'password' => ['required', 'confirmed'],
                        ]);

                        request()->user()->update([
                            'password' => bcrypt($validated['password']),
                        ]);
                    }
                }
                PHP, $relativePath),
        ], []);

        $issues = [
            new Issue(
                ruleId: 'best-practices.missing-form-request',
                category: Category::BestPractices,
                severity: Severity::Info,
                title: 'Inline validation in controller',
                message: 'Use a Form Request.',
                location: new Location($relativePath, 18),
            ),
        ];

        $engine = new PatternInferenceEngine(
            new MethodFeatureExtractor,
            PatternModel::fromPath(__DIR__.'/../../resources/pattern-model.json'),
        );

        $suggestions = $engine->infer($project, $issues, 0.55, 5);

        self::assertNotSame([], $suggestions);
        self::assertSame('form_request', $suggestions[0]->pattern);
    }

    public function test_still_prefers_action_for_complex_controller_with_inline_validation(): void
    {
        $relativePath = 'app/Http/Controllers/CarritoController.php';
        $project = new ProjectIndex([
            $this->phpFile(<<<'PHP'
                <?php

                namespace App\Http\Controllers;

                final class CarritoController
                {
                    public function store(): mixed
                    {
                        $data = request()->validate([
                            'producto_id' => 'required',
                            'talla' => 'required',
                            'cantidad' => 'required|integer|min:1',
                        ]);

                        if ($invalid) {
                            return back()->with('error', 'nope');
                        }

                        if ($anotherInvalid) {
                            return back()->with('error', 'still nope');
                        }

                        if ($yetAnother) {
                            return back()->with('error', 'nope again');
                        }

                        Carrito::agregar($producto, $data['talla'], $data['cantidad']);

                        return back()->with('message', 'ok');
                    }
                }
                PHP, $relativePath),
        ], []);

        $issues = [
            new Issue(
                ruleId: 'best-practices.missing-form-request',
                category: Category::BestPractices,
                severity: Severity::Info,
                title: 'Inline validation in controller',
                message: 'Use a Form Request.',
                location: new Location($relativePath, 23),
            ),
        ];

        $engine = new PatternInferenceEngine(
            new MethodFeatureExtractor,
            PatternModel::fromPath(__DIR__.'/../../resources/pattern-model.json'),
        );

        $suggestions = $engine->infer($project, $issues, 0.55, 5);

        self::assertNotSame([], $suggestions);
        self::assertSame('action', $suggestions[0]->pattern);
    }

    private function phpFile(string $contents, string $relativePath = 'app/Example.php'): PhpFile
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
