<?php

declare(strict_types=1);

namespace LaravelAudit\Tests\Unit;

use LaravelAudit\Pattern\PatternHypothesisKey;
use LaravelAudit\Pattern\PatternReportMerger;
use LaravelAudit\Pattern\PatternSuggestion;
use PHPUnit\Framework\TestCase;

final class PatternHypothesisSelectionTest extends TestCase
{
    public function test_hypothesis_key_uses_pattern_file_and_method(): void
    {
        $suggestion = new PatternSuggestion(
            pattern: 'action',
            title: 'Action / Use Case',
            description: 'Move orchestration into an action.',
            recommendation: 'Extract an invokable action.',
            confidence: 0.72,
            file: 'app/Http/Controllers/UserController.php',
            line: 12,
            method: 'store',
            class: 'App\\Http\\Controllers\\UserController',
            features: [],
        );

        $this->assertSame(
            'action:app/Http/Controllers/UserController.php::store',
            PatternHypothesisKey::for($suggestion),
        );
    }

    public function test_report_merger_replaces_selected_heuristics_with_confirmed_results(): void
    {
        $key = 'action:app/Http/Controllers/UserController.php::store';

        $merged = PatternReportMerger::merge(
            [
                [
                    'hypothesisKey' => $key,
                    'pattern' => 'action',
                    'source' => 'heuristic',
                    'title' => 'Action / Use Case',
                ],
                [
                    'hypothesisKey' => 'repository:app/Services/UserService.php::create',
                    'pattern' => 'repository',
                    'source' => 'heuristic',
                    'title' => 'Repository',
                ],
            ],
            [
                new PatternSuggestion(
                    pattern: 'action',
                    title: 'Action / Use Case',
                    description: 'Confirmed by LLM.',
                    recommendation: 'Extract action class.',
                    confidence: 0.91,
                    file: 'app/Http/Controllers/UserController.php',
                    line: 12,
                    method: 'store',
                    class: 'App\\Http\\Controllers\\UserController',
                    features: [],
                    llmRationale: 'Controller orchestrates validation and persistence.',
                    source: 'confirmed',
                ),
            ],
            [$key],
        );

        $this->assertCount(2, $merged);
        $this->assertSame('confirmed', $merged[1]['source'] ?? null);
        $this->assertSame('heuristic', $merged[0]['source'] ?? null);
        $this->assertSame('repository', $merged[0]['pattern'] ?? null);
    }

    public function test_report_merger_replaces_selected_heuristics_with_refuted_results(): void
    {
        $key = 'repository:database/migrations/2024_01_01_000000_update_tags.php::up';

        $merged = PatternReportMerger::merge(
            [
                [
                    'hypothesisKey' => $key,
                    'pattern' => 'repository',
                    'source' => 'heuristic',
                    'title' => 'Repository',
                    'location' => [
                        'file' => 'database/migrations/2024_01_01_000000_update_tags.php',
                        'method' => 'up',
                    ],
                ],
            ],
            [
                new PatternSuggestion(
                    pattern: 'repository',
                    title: 'Repository',
                    description: 'This is migration logic, not a repository.',
                    recommendation: 'Keep the logic in the migration.',
                    confidence: 0.68,
                    file: 'database/migrations/2024_01_01_000000_update_tags.php',
                    line: 10,
                    method: 'up',
                    class: 'anonymous',
                    features: [],
                    llmRationale: 'Uses Schema::table and DB::table.',
                    source: 'refuted',
                    hypothesisKey: $key,
                ),
            ],
            [$key],
        );

        $this->assertCount(1, $merged);
        $this->assertSame('refuted', $merged[0]['source'] ?? null);
        $this->assertSame('repository', $merged[0]['pattern'] ?? null);
    }
}
