<?php

declare(strict_types=1);

namespace LaravelAudit\Pattern;

use LaravelAudit\Analysis\Issue;
use LaravelAudit\Project\PhpFile;
use LaravelAudit\Project\ProjectIndex;

final class LlmPatternAdvisor implements PatternAdvisorInterface
{
    public function __construct(
        private readonly HeuristicPatternAdvisor $heuristicAdvisor,
        private readonly MethodSnippetExtractor $snippetExtractor,
        private readonly JsonHttpClient $httpClient,
        private readonly string $provider,
        private readonly string $endpoint,
        private readonly string $model,
        private readonly ?string $apiKey = null,
        private readonly int $timeout = 120,
        private readonly int $reviewLimit = 3,
        private readonly int $maxAttempts = 10,
        private readonly int $concurrency = 1,
    ) {}

    /**
     * @param  list<Issue>  $issues
     * @param  list<string>  $llmHypothesisKeys
     * @return list<PatternSuggestion>
     */
    public function suggest(ProjectIndex $project, array $issues, array $llmHypothesisKeys = []): array
    {
        $heuristic = $this->heuristicAdvisor->suggest($project, $issues);
        $suggestions = [];
        $selectedKeys = $llmHypothesisKeys !== [];
        $queue = $selectedKeys
            ? $this->selectedHypotheses($heuristic, $llmHypothesisKeys)
            : $this->topHypothesesByMethod($heuristic);

        $jobs = $this->prepareJobs($project, $queue);

        foreach (array_chunk($jobs, max(1, $this->concurrency)) as $batch) {
            if (! $selectedKeys && count($suggestions) >= $this->reviewLimit) {
                break;
            }

            foreach ($this->confirmBatch($batch) as $result) {
                if ($result === null) {
                    continue;
                }

                $suggestions[] = $this->reviewedSuggestion($result['hypothesis'], $result['llm']);

                if (! $selectedKeys && count($suggestions) >= $this->reviewLimit) {
                    break;
                }
            }
        }

        return $suggestions;
    }

    /**
     * @param  list<PatternSuggestion>  $queue
     * @return list<array{hypothesis: PatternSuggestion, snippet: string}>
     */
    private function prepareJobs(ProjectIndex $project, array $queue): array
    {
        $jobs = [];
        $attempts = 0;

        foreach ($queue as $hypothesis) {
            if ($attempts >= $this->maxAttempts) {
                break;
            }

            $file = $this->findFile($project, $hypothesis->file);

            if ($file === null) {
                continue;
            }

            $snippet = $this->snippetExtractor->extract($file, $hypothesis->method, $hypothesis->line);

            if ($snippet === null || trim($snippet) === '') {
                continue;
            }

            $attempts++;
            $jobs[] = [
                'hypothesis' => $hypothesis,
                'snippet' => $snippet,
            ];
        }

        return $jobs;
    }

    /**
     * @param  list<array{hypothesis: PatternSuggestion, snippet: string}>  $batch
     * @return list<array{hypothesis: PatternSuggestion, llm: array<string, mixed>}|null>
     */
    private function confirmBatch(array $batch): array
    {
        if ($batch === []) {
            return [];
        }

        $payloads = array_map(
            fn (array $job): array => $this->buildPayload($job['hypothesis'], $job['snippet']),
            $batch,
        );

        $responses = $this->httpClient->postMany($this->endpoint, $payloads, $this->apiKey, $this->timeout);
        $results = [];

        foreach ($batch as $index => $job) {
            $response = $responses[$index] ?? null;
            $decoded = $response === null ? null : $this->parseResponse($response);

            $results[] = $decoded === null
                ? null
                : [
                    'hypothesis' => $job['hypothesis'],
                    'llm' => $decoded,
                ];
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $llmResult
     */
    private function reviewedSuggestion(PatternSuggestion $hypothesis, array $llmResult): PatternSuggestion
    {
        $key = $hypothesis->hypothesisKey();
        $confirmed = (bool) ($llmResult['confirmed'] ?? false);

        if ($confirmed) {
            return new PatternSuggestion(
                pattern: (string) $llmResult['pattern'],
                title: (string) $llmResult['title'],
                description: (string) $llmResult['description'],
                recommendation: (string) $llmResult['recommendation'],
                confidence: min(1.0, max($hypothesis->confidence, (float) $llmResult['confidence'])),
                file: $hypothesis->file,
                line: $hypothesis->line,
                method: $hypothesis->method,
                class: $hypothesis->class,
                features: $hypothesis->features,
                signals: [
                    'hypothesis:'.$hypothesis->pattern,
                    ...$hypothesis->signals,
                ],
                llmRationale: (string) $llmResult['rationale'],
                source: 'confirmed',
                hypothesisKey: $key,
            );
        }

        return new PatternSuggestion(
            pattern: $hypothesis->pattern,
            title: $hypothesis->title,
            description: (string) $llmResult['description'],
            recommendation: (string) $llmResult['recommendation'],
            confidence: $hypothesis->confidence,
            file: $hypothesis->file,
            line: $hypothesis->line,
            method: $hypothesis->method,
            class: $hypothesis->class,
            features: $hypothesis->features,
            signals: [
                'hypothesis:'.$hypothesis->pattern,
                ...$hypothesis->signals,
            ],
            llmRationale: (string) $llmResult['rationale'],
            source: 'refuted',
            hypothesisKey: $key,
        );
    }

    /**
     * @param  list<PatternSuggestion>  $heuristic
     * @return list<PatternSuggestion>
     */
    private function topHypothesesByMethod(array $heuristic): array
    {
        $byMethod = [];

        foreach ($heuristic as $suggestion) {
            $key = $suggestion->file.'::'.$suggestion->method;

            if (! isset($byMethod[$key]) || $suggestion->confidence > $byMethod[$key]->confidence) {
                $byMethod[$key] = $suggestion;
            }
        }

        $hypotheses = array_values($byMethod);

        usort(
            $hypotheses,
            fn (PatternSuggestion $left, PatternSuggestion $right): int => $right->confidence <=> $left->confidence,
        );

        return $hypotheses;
    }

    /**
     * @param  list<PatternSuggestion>  $heuristic
     * @param  list<string>  $keys
     * @return list<PatternSuggestion>
     */
    private function selectedHypotheses(array $heuristic, array $keys): array
    {
        $byKey = [];

        foreach ($heuristic as $suggestion) {
            $byKey[PatternHypothesisKey::for($suggestion)] = $suggestion;
        }

        $selected = [];

        foreach ($keys as $key) {
            if (isset($byKey[$key])) {
                $selected[] = $byKey[$key];
            }
        }

        return $selected;
    }

    private function findFile(ProjectIndex $project, string $relativePath): ?PhpFile
    {
        foreach ($project->phpFiles as $file) {
            if ($file->relativePath === $relativePath) {
                return $file;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(PatternSuggestion $hypothesis, string $snippet): array
    {
        $signals = $this->structuralSignals($hypothesis->features);

        $prompt = <<<PROMPT
You validate refactoring hypotheses against real PHP/Laravel source code.

Static analysis hypothesis:
- pattern: {$hypothesis->pattern}
- title: {$hypothesis->title}

Observed structure (facts only, not conclusions):
{$signals}

Your task:
1. Read the method source code below
2. Confirm or reject the hypothesis using evidence from the code
3. Do not invent a different pattern unless the hypothesis is clearly wrong

Return one JSON object:
{
  "confirmed": true,
  "pattern": "{$hypothesis->pattern}",
  "title": "pattern title",
  "description": "why the hypothesis fits or does not fit this code",
  "recommendation": "concrete refactor steps for this method",
  "confidence": 0.0,
  "rationale": "quote concrete structures from the code"
}

If you reject the hypothesis, set "confirmed": false, provide the better pattern slug in "pattern", and explain why in "rationale".

Method: {$hypothesis->class}::{$hypothesis->method}()
File: {$hypothesis->file}

```php
{$snippet}
```
PROMPT;

        return $this->provider === 'ollama'
            ? [
                'model' => $this->model,
                'stream' => false,
                'format' => 'json',
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]
            : [
                'model' => $this->model,
                'max_tokens' => 2000,
                'messages' => [
                    ['role' => 'system', 'content' => 'Return JSON only. No markdown fences.'],
                    ['role' => 'user', 'content' => "/no_think\n{$prompt}"],
                ],
            ];
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>|null
     */
    private function parseResponse(array $response): ?array
    {
        $message = $this->provider === 'ollama'
            ? $response
            : data_get($response, 'choices.0.message');

        if (! is_array($message)) {
            return null;
        }

        $decoded = $this->decodeJsonResponse(
            is_string($message['content'] ?? null) ? $message['content'] : '',
            is_string($message['reasoning_content'] ?? null) ? $message['reasoning_content'] : '',
        );

        if ($decoded === null || ! $this->isValidLlmResult($decoded)) {
            return null;
        }

        return $decoded;
    }

    /**
     * @param  array<string, float>  $features
     */
    private function structuralSignals(array $features): string
    {
        if ($features === []) {
            return '- none';
        }

        $lines = [];

        foreach ($features as $name => $value) {
            if ($name === 'is_controller_method') {
                $lines[] = '- controller_method: '.($value >= 1.0 ? 'yes' : 'no');

                continue;
            }

            if ($value <= 0.0) {
                continue;
            }

            $lines[] = "- {$name}: {$value}";
        }

        return $lines === [] ? '- none' : implode("\n", $lines);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJsonResponse(string $content, string $reasoning): ?array
    {
        foreach ([trim($content), trim($reasoning)] as $candidate) {
            if ($candidate === '') {
                continue;
            }

            $decoded = json_decode($this->extractJson($candidate), true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    private function extractJson(string $text): string
    {
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $text, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('/(\{.*\})/s', $text, $matches) === 1) {
            return $matches[1];
        }

        return $text;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function isValidLlmResult(array $result): bool
    {
        if (! is_bool($result['confirmed'] ?? null)) {
            return false;
        }

        $pattern = $result['pattern'] ?? null;

        if (! is_string($pattern) || trim($pattern) === '') {
            return false;
        }

        foreach (['title', 'description', 'recommendation', 'rationale'] as $field) {
            $value = $result[$field] ?? null;

            if (! is_string($value) || trim($value) === '') {
                return false;
            }
        }

        if (! is_numeric($result['confidence'] ?? null)) {
            return false;
        }

        $confidence = (float) $result['confidence'];

        return $confidence > 0.0 && $confidence <= 1.0;
    }
}
