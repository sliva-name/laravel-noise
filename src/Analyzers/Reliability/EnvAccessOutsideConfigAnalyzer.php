<?php

declare(strict_types=1);

namespace LaravelAudit\Analyzers\Reliability;

use LaravelAudit\Analysis\AnalysisContext;
use LaravelAudit\Analysis\AnalyzerInterface;
use LaravelAudit\Analysis\Category;
use LaravelAudit\Analysis\Issue;
use LaravelAudit\Analysis\Severity;
use LaravelAudit\Analyzers\BaseAnalyzer;
use PhpParser\Node;
use PhpParser\NodeFinder;

final class EnvAccessOutsideConfigAnalyzer extends BaseAnalyzer implements AnalyzerInterface
{
    public function id(): string
    {
        return 'reliability.env-access-outside-config';
    }

    public function category(): Category
    {
        return Category::Reliability;
    }

    /**
     * @return list<Issue>
     */
    public function analyze(AnalysisContext $context): array
    {
        $issues = [];
        $finder = new NodeFinder;

        foreach ($context->project->phpFiles as $file) {
            if ($file->inDirectory('config') || $file->inDirectory('tests')) {
                continue;
            }

            /** @var list<Node\Expr\FuncCall> $calls */
            $calls = $finder->findInstanceOf($file->ast, Node\Expr\FuncCall::class);

            foreach ($calls as $call) {
                if (! $call->name instanceof Node\Name || strtolower($call->name->toString()) !== 'env') {
                    continue;
                }

                $issues[] = $this->issue(
                    $this->id(),
                    $this->category(),
                    Severity::Error,
                    'env() used outside config',
                    'Laravel configuration caching can make env() unavailable outside config files.',
                    $file,
                    $call->getStartLine(),
                    'Move environment reads into config files and use config() throughout application code.',
                );
            }
        }

        return $issues;
    }
}
