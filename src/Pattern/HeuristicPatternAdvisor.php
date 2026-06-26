<?php

declare(strict_types=1);

namespace LaravelAudit\Pattern;

use LaravelAudit\Analysis\Issue;
use LaravelAudit\Project\ProjectIndex;

final class HeuristicPatternAdvisor implements PatternAdvisorInterface
{
    public function __construct(
        private readonly PatternInferenceEngine $engine,
        private readonly float $minConfidence = 0.55,
        private readonly int $limit = 20,
    ) {}

    /**
     * @param  list<Issue>  $issues
     * @param  list<string>  $llmHypothesisKeys
     * @return list<PatternSuggestion>
     */
    public function suggest(ProjectIndex $project, array $issues, array $llmHypothesisKeys = []): array
    {
        return $this->engine->infer($project, $issues, $this->minConfidence, $this->limit);
    }
}
