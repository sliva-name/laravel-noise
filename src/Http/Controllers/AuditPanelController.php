<?php

declare(strict_types=1);

namespace LaravelAudit\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use LaravelAudit\Analysis\Category;
use LaravelAudit\Analysis\Severity;
use LaravelAudit\Audit\AuditEngine;
use LaravelAudit\Audit\AuditOptions;
use LaravelAudit\Audit\AuditProgressTracker;
use LaravelAudit\Audit\AuditRunDispatcher;
use LaravelAudit\Audit\AuditRunExecutor;
use LaravelAudit\Repositories\AuditReportRepository;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AuditPanelController extends Controller
{
    public function __construct(
        private readonly AuditReportRepository $reports,
        private readonly AuditProgressTracker $runs,
        private readonly AuditRunExecutor $executor,
        private readonly AuditRunDispatcher $dispatcher,
        private readonly AuditEngine $engine,
    ) {}

    public function dashboard(): View
    {
        $reports = $this->reports->latest(10);
        $activeRuns = $this->runs->active();

        return view('laravel-audit::panel.dashboard', [
            'reports' => $reports,
            'activeRuns' => $activeRuns,
            'menu' => $this->menu('dashboard'),
        ]);
    }

    public function index(): View
    {
        return view('laravel-audit::panel.reports.index', [
            'reports' => $this->reports->latest(100),
            'menu' => $this->menu('reports'),
        ]);
    }

    public function show(Request $request, string $uuid): View
    {
        $record = $this->reports->findByUuid($uuid);

        abort_if($record === null, 404);

        $issuesView = $this->issuesViewData($request, $record->payload);

        return view('laravel-audit::panel.reports.show', [
            'record' => $record,
            'report' => $record->payload,
            'menu' => $this->menu('reports'),
            ...$issuesView,
            'issueQuery' => fn (array $overrides = []): string => $this->buildIssueListQuery($request, $overrides),
            'issuePageNumbers' => $this->issuePageNumbers(
                (int) $issuesView['issuesPage'],
                (int) $issuesView['issuesLastPage'],
            ),
        ]);
    }

    public function download(string $uuid): StreamedResponse
    {
        $record = $this->reports->findByUuid($uuid);

        abort_if($record === null, 404);

        $filename = sprintf('laravel-audit-report-%s.json', $record->uuid);

        return response()->streamDownload(
            static function () use ($record): void {
                echo json_encode(
                    $record->toArray(),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                );
            },
            $filename,
            ['Content-Type' => 'application/json; charset=utf-8'],
        );
    }

    public function create(): View
    {
        return view('laravel-audit::panel.reports.create', [
            'menu' => $this->menu('run'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $options = $this->optionsFromRequest($request);
        $runUuid = $this->runs->create($options);
        $this->dispatcher->dispatch($runUuid);

        return redirect()
            ->route('laravel-audit.runs.show', $runUuid)
            ->with('status', 'Audit started in background.');
    }

    public function runsIndex(): View
    {
        return view('laravel-audit::panel.runs.index', [
            'runs' => $this->runs->list(),
            'menu' => $this->menu('runs'),
        ]);
    }

    public function runShow(string $uuid): View
    {
        $run = $this->runs->get($uuid);

        abort_if($run === null, 404);

        return view('laravel-audit::panel.runs.show', [
            'run' => $run,
            'runUuid' => $uuid,
            'statusUrl' => route('laravel-audit.runs.status', $uuid),
            'kickUrl' => route('laravel-audit.runs.kick', $uuid),
            'executeUrl' => route('laravel-audit.runs.execute', $uuid),
            'runner' => (string) config('laravel-audit.dashboard.runner', 'queue'),
            'menu' => $this->menu('runs'),
        ]);
    }

    public function confirmPatterns(Request $request, string $uuid): RedirectResponse
    {
        $keys = array_values(array_filter(array_map(
            strval(...),
            $request->input('llm_hypotheses', []),
        )));

        abort_if($keys === [], 422);

        $this->reports->confirmPatternHypotheses($uuid, $keys, $this->engine);

        return redirect()
            ->route('laravel-audit.reports.show', $uuid)
            ->with('status', 'Selected pattern hypotheses were sent to the LLM.');
    }

    public function runStatus(string $uuid): JsonResponse
    {
        $run = $this->runs->get($uuid);

        abort_if($run === null, 404);

        return response()->json($this->runPayload($run));
    }

    public function runKick(string $uuid): JsonResponse
    {
        $run = $this->runs->get($uuid);

        abort_if($run === null, 404);

        if (($run['status'] ?? 'queued') === 'queued') {
            $this->dispatcher->dispatch($uuid);
        }

        $run = $this->runs->get($uuid) ?? $run;

        return response()->json($this->runPayload($run));
    }

    public function runExecute(string $uuid): JsonResponse
    {
        $run = $this->runs->get($uuid);

        abort_if($run === null, 404);

        if (($run['status'] ?? 'queued') === 'queued') {
            $this->executor->execute($uuid);
            $run = $this->runs->get($uuid) ?? $run;
        }

        return response()->json($this->runPayload($run));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function issuesViewData(Request $request, array $payload): array
    {
        /** @var list<array<string, mixed>> $rawIssues */
        $rawIssues = is_array($payload['issues'] ?? null) ? $payload['issues'] : [];
        $allIssues = collect($rawIssues);
        $severityFilter = $this->severityFilter($request);
        $categoryFilter = $this->categoryFilter($request);
        $perPage = 25;
        $page = max(1, (int) $request->query('page', '1'));

        $filtered = $allIssues
            ->when(
                $severityFilter !== 'all',
                fn (Collection $issues): Collection => $issues->filter(
                    fn (array $issue): bool => ($issue['severity'] ?? '') === $severityFilter,
                ),
            )
            ->when(
                $categoryFilter !== 'all',
                fn (Collection $issues): Collection => $issues->filter(
                    fn (array $issue): bool => ($issue['category'] ?? '') === $categoryFilter,
                ),
            )
            ->sort($this->issueSort(...))
            ->values();

        $total = $filtered->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);

        return [
            'issuesPage' => $page,
            'issuesPerPage' => $perPage,
            'issuesTotal' => $total,
            'issuesLastPage' => $lastPage,
            'issues' => $filtered->slice(($page - 1) * $perPage, $perPage)->values(),
            'severityFilter' => $severityFilter,
            'categoryFilter' => $categoryFilter,
            'severityCounts' => $this->severityCounts($allIssues),
            'categoryCounts' => $this->categoryCounts($allIssues),
            'groupBySeverity' => $severityFilter === 'all',
        ];
    }

    /**
     * @param  array{severity?: string, category?: string, page?: int}  $overrides
     */
    private function buildIssueListQuery(Request $request, array $overrides = []): string
    {
        $severity = array_key_exists('severity', $overrides)
            ? (string) $overrides['severity']
            : $this->severityFilter($request);

        $category = array_key_exists('category', $overrides)
            ? (string) $overrides['category']
            : $this->categoryFilter($request);

        if (array_key_exists('page', $overrides)) {
            $page = max(1, (int) $overrides['page']);
        } elseif (array_key_exists('severity', $overrides) || array_key_exists('category', $overrides)) {
            $page = 1;
        } else {
            $page = max(1, (int) $request->query('page', '1'));
        }

        return http_build_query(array_filter([
            'severity' => $severity !== 'all' ? $severity : null,
            'category' => $category !== 'all' ? $category : null,
            'page' => $page > 1 ? $page : null,
        ], static fn ($value) => $value !== null && $value !== ''));
    }

    /**
     * @return list<int|string>
     */
    private function issuePageNumbers(int $current, int $last): array
    {
        if ($last <= 1) {
            return [];
        }

        if ($last <= 7) {
            return range(1, $last);
        }

        $pages = [1];
        $rangeStart = max(2, $current - 1);
        $rangeEnd = min($last - 1, $current + 1);

        if ($rangeStart > 2) {
            $pages[] = '...';
        }

        for ($page = $rangeStart; $page <= $rangeEnd; $page++) {
            $pages[] = $page;
        }

        if ($rangeEnd < $last - 1) {
            $pages[] = '...';
        }

        $pages[] = $last;

        return $pages;
    }

    private function severityFilter(Request $request): string
    {
        $severity = $request->string('severity')->trim()->toString();

        if ($severity === '' || $severity === 'all') {
            return 'all';
        }

        $matched = Severity::tryFrom($severity);

        return $matched === null ? 'all' : $matched->value;
    }

    private function categoryFilter(Request $request): string
    {
        $category = $request->string('category')->trim()->toString();

        if ($category === '' || $category === 'all') {
            return 'all';
        }

        $matched = Category::tryFrom($category);

        return $matched === null ? 'all' : $matched->value;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $issues
     * @return array<string, int>
     */
    private function severityCounts(Collection $issues): array
    {
        $counts = ['all' => $issues->count()];

        foreach (Severity::cases() as $severity) {
            $counts[$severity->value] = $issues
                ->filter(fn (array $issue): bool => ($issue['severity'] ?? '') === $severity->value)
                ->count();
        }

        return $counts;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $issues
     * @return array<string, int>
     */
    private function categoryCounts(Collection $issues): array
    {
        $counts = ['all' => $issues->count()];

        foreach (Category::cases() as $category) {
            $counts[$category->value] = $issues
                ->filter(fn (array $issue): bool => ($issue['category'] ?? '') === $category->value)
                ->count();
        }

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private function issueSort(array $left, array $right): int
    {
        $leftSeverity = Severity::tryFrom((string) ($left['severity'] ?? '')) ?? Severity::Info;
        $rightSeverity = Severity::tryFrom((string) ($right['severity'] ?? '')) ?? Severity::Info;

        $comparison = $rightSeverity->rank() <=> $leftSeverity->rank();

        if ($comparison !== 0) {
            return $comparison;
        }

        $comparison = strcmp((string) ($left['category'] ?? ''), (string) ($right['category'] ?? ''));

        if ($comparison !== 0) {
            return $comparison;
        }

        $comparison = strcmp(
            (string) ($left['location']['file'] ?? ''),
            (string) ($right['location']['file'] ?? ''),
        );

        if ($comparison !== 0) {
            return $comparison;
        }

        return ((int) ($left['location']['line'] ?? 0)) <=> ((int) ($right['location']['line'] ?? 0));
    }

    /**
     * @param  array<string, mixed>  $run
     * @return array<string, mixed>
     */
    private function runPayload(array $run): array
    {
        $reportUuid = $run['report_uuid'] ?? null;

        return [
            'status' => $run['status'] ?? 'queued',
            'progress' => (int) ($run['progress'] ?? 0),
            'message' => (string) ($run['message'] ?? ''),
            'log' => is_array($run['log'] ?? null) ? $run['log'] : [],
            'error' => $run['error'] ?? null,
            'report_url' => is_string($reportUuid) && $reportUuid !== ''
                ? route('laravel-audit.reports.show', $reportUuid)
                : null,
        ];
    }

    private function optionsFromRequest(Request $request): AuditOptions
    {
        return new AuditOptions(
            categories: $this->categories($request),
            noTools: $request->boolean('no_tools'),
            patterns: $request->boolean('patterns'),
            llm: $request->boolean('llm'),
            llmHypothesisKeys: $this->llmHypothesisKeys($request),
        );
    }

    /**
     * @return list<string>
     */
    private function llmHypothesisKeys(Request $request): array
    {
        return array_values(array_filter(array_map(
            strval(...),
            $request->input('llm_hypotheses', []),
        )));
    }

    /**
     * @return list<string>
     */
    private function categories(Request $request): array
    {
        $only = $request->string('only')->trim()->toString();

        if ($only === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $only))));
    }

    /**
     * @return list<array{label: string, route: string, active: bool}>
     */
    private function menu(string $active): array
    {
        return [
            [
                'label' => 'Overview',
                'route' => route('laravel-audit.dashboard'),
                'active' => $active === 'dashboard',
            ],
            [
                'label' => 'All Reports',
                'route' => route('laravel-audit.reports.index'),
                'active' => $active === 'reports',
            ],
            [
                'label' => 'Jobs',
                'route' => route('laravel-audit.runs.index'),
                'active' => $active === 'runs',
            ],
            [
                'label' => 'Run Analysis',
                'route' => route('laravel-audit.reports.create'),
                'active' => $active === 'run',
            ],
        ];
    }
}
