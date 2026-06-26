<?php

declare(strict_types=1);

namespace LaravelAudit\Pattern;

use LaravelAudit\Analysis\Issue;
use LaravelAudit\Project\ProjectIndex;

interface PatternAdvisorInterface
{
    /**
     * @param  list<Issue>  $issues
     * @param  list<string>  $llmHypothesisKeys
     * @return list<PatternSuggestion>
     */
    public function suggest(ProjectIndex $project, array $issues, array $llmHypothesisKeys = []): array;
}
