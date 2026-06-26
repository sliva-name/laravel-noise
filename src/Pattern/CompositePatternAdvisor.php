<?php

declare(strict_types=1);

namespace LaravelAudit\Pattern;

use LaravelAudit\Analysis\Issue;
use LaravelAudit\Project\ProjectIndex;

final class CompositePatternAdvisor implements PatternAdvisorInterface
{
    public function __construct(
        private readonly HeuristicPatternAdvisor $heuristic,
        private readonly LlmPatternAdvisor $llm,
        private readonly bool $includeHeuristic,
        private readonly bool $includeLlm,
    ) {}

    /**
     * @param  list<Issue>  $issues
     * @param  list<string>  $llmHypothesisKeys
     * @return list<PatternSuggestion>
     */
    public function suggest(ProjectIndex $project, array $issues, array $llmHypothesisKeys = []): array
    {
        $suggestions = [];

        if ($this->includeHeuristic) {
            array_push($suggestions, ...$this->heuristic->suggest($project, $issues));
        }

        if ($this->includeLlm) {
            array_push($suggestions, ...$this->llm->suggest($project, $issues, $llmHypothesisKeys));
        }

        return $suggestions;
    }
}
