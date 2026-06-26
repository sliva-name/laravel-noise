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
}
