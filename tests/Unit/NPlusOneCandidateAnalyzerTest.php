<?php

declare(strict_types=1);

namespace LaravelAudit\Tests\Unit;

use LaravelAudit\Analysis\AnalysisContext;
use LaravelAudit\Analyzers\Performance\NPlusOneCandidateAnalyzer;
use LaravelAudit\Project\PhpFile;
use LaravelAudit\Project\ProjectIndex;
use LaravelAudit\Tests\TestCase;
use PhpParser\ParserFactory;

final class NPlusOneCandidateAnalyzerTest extends TestCase
{
    public function test_ignores_query_builder_macro_foreach(): void
    {
        $issues = (new NPlusOneCandidateAnalyzer)->analyze($this->context(<<<'PHP'
            <?php

            use Illuminate\Database\Eloquent\Builder;

            final class AppServiceProvider
            {
                public function boot(): void
                {
                    Builder::macro('search', function ($field, $string) {
                        if (is_array($field)) {
                            foreach ($field as $item) {
                                $this->orWhere($item, 'like', '%'.$string.'%');
                            }

                            return $this;
                        }

                        return $string ? $this->where($field, 'like', '%'.$string.'%') : $this;
                    });
                }
            }
            PHP));

        self::assertSame([], $issues);
    }

    public function test_detects_relationship_property_access_in_loop(): void
    {
        $issues = (new NPlusOneCandidateAnalyzer)->analyze($this->context(<<<'PHP'
            <?php

            final class OrderController
            {
                public function index($orders): void
                {
                    foreach ($orders as $order) {
                        echo $order->customer->name;
                    }
                }
            }
            PHP));

        self::assertCount(1, $issues);
        self::assertSame('performance.n-plus-one-candidate', $issues[0]->ruleId);
    }

    public function test_does_not_flag_aggregate_row_property_access_in_loop(): void
    {
        $issues = (new NPlusOneCandidateAnalyzer)->analyze($this->context(<<<'PHP'
            <?php

            final class ReportExporter
            {
                public function export(array $rows): void
                {
                    foreach ($rows as $row) {
                        echo $row->nombre_producto;
                    }
                }
            }
            PHP));

        self::assertSame([], $issues);
    }

    public function test_does_not_flag_property_access_on_query_result_inside_loop(): void
    {
        $issues = (new NPlusOneCandidateAnalyzer)->analyze($this->context(<<<'PHP'
            <?php

            use App\Models\ProductoTalla;

            final class CheckoutController
            {
                public function handle(array $items): void
                {
                    foreach ($items as $item) {
                        $talla = ProductoTalla::where('producto_id', $item['producto_id'])->first();

                        if ($talla) {
                            $talla->decrement('stock', min($item['cantidad'], $talla->stock));
                        }
                    }
                }
            }
            PHP));

        self::assertSame([], $issues);
    }

    private function context(string $contents): AnalysisContext
    {
        return new AnalysisContext(
            basePath: __DIR__,
            project: new ProjectIndex([
                $this->phpFile($contents),
            ], []),
            config: [],
        );
    }

    private function phpFile(string $contents): PhpFile
    {
        $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($contents) ?? [];

        return new PhpFile(
            path: __DIR__.'/Fixture.php',
            relativePath: 'app/Fixture.php',
            contents: $contents,
            ast: $ast,
            classes: [],
            methods: [],
            lines: substr_count($contents, PHP_EOL) + 1,
        );
    }
}
