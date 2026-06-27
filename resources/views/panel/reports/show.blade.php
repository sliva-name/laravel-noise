@extends('laravel-audit::panel.layout')

@section('title', 'Report · Laravel Audit')

@section('content')
    <div class="page-header">
        <div>
            <h1 class="page-title">Report</h1>
            <p class="page-subtitle">
                @include('laravel-audit::panel.partials.time', ['value' => $record->created_at])
                · {{ \LaravelAudit\Support\PanelTime::duration((float) $record->duration_seconds) }}
            </p>
        </div>
        <a class="btn btn-secondary" href="{{ route('laravel-audit.reports.download', $record->uuid) }}">Download JSON</a>
    </div>

    <div class="card">
        <div class="grid">
            <div class="metric"><div class="metric-label">Critical</div><div class="metric-value">{{ $record->critical_count }}</div></div>
            <div class="metric"><div class="metric-label">Error</div><div class="metric-value">{{ $record->error_count }}</div></div>
            <div class="metric"><div class="metric-label">Warning</div><div class="metric-value">{{ $record->warning_count }}</div></div>
            <div class="metric"><div class="metric-label">Info</div><div class="metric-value">{{ $record->info_count }}</div></div>
        </div>
    </div>

    @php
        $categoryLabels = [
            'security' => 'Security',
            'performance' => 'Performance',
            'reliability' => 'Reliability',
            'best-practices' => 'Best practices',
            'code-quality' => 'Code quality',
            'tooling' => 'Tooling',
        ];
        $severityTabs = [
            'all' => 'All',
            'critical' => 'Critical',
            'error' => 'Error',
            'warning' => 'Warning',
            'info' => 'Info',
        ];
        $reportShowUrl = route('laravel-audit.reports.show', $record->uuid);
    @endphp

    <div class="card">
        <div class="issues-toolbar">
            <div>
                <h2 style="margin:0 0 12px;">Issues</h2>
                <p class="muted" style="margin:0;">
                    @if ($issuesTotal === 0)
                        No issues match the current filters.
                    @else
                        Showing {{ ($issuesPage - 1) * $issuesPerPage + 1 }}–{{ min($issuesPage * $issuesPerPage, $issuesTotal) }} of {{ $issuesTotal }}
                    @endif
                </p>
            </div>

            <form class="issues-filter-form" method="get" action="{{ $reportShowUrl }}">
                @if ($severityFilter !== 'all')
                    <input type="hidden" name="severity" value="{{ $severityFilter }}">
                @endif
                <label class="issues-filter-label">
                    Category
                    <select name="category" onchange="this.form.submit()">
                        <option value="all" @selected($categoryFilter === 'all')>All categories ({{ $categoryCounts['all'] }})</option>
                        @foreach ($categoryLabels as $value => $label)
                            @if (($categoryCounts[$value] ?? 0) > 0)
                                <option value="{{ $value }}" @selected($categoryFilter === $value)>
                                    {{ $label }} ({{ $categoryCounts[$value] }})
                                </option>
                            @endif
                        @endforeach
                    </select>
                </label>
            </form>
        </div>

        <div class="filter-tabs">
            @foreach ($severityTabs as $value => $label)
                @php
                    $count = $severityCounts[$value] ?? 0;
                    $isActive = $severityFilter === $value;
                    $tabQuery = $issueQuery([
                        'severity' => $value,
                        'page' => 1,
                    ]);
                @endphp
                <a
                    href="{{ $reportShowUrl.($tabQuery !== '' ? '?'.$tabQuery : '') }}"
                    @class(['filter-tab', 'active' => $isActive, 'disabled' => $count === 0 && $value !== 'all'])
                    @if ($count === 0 && $value !== 'all') aria-disabled="true" @endif
                >
                    {{ $label }}
                    <span class="filter-tab-count">{{ $count }}</span>
                </a>
            @endforeach
        </div>

        @php $previousSeverity = null; @endphp
        @forelse ($issues as $issue)
            @if ($groupBySeverity && ($issue['severity'] ?? '') !== $previousSeverity)
                @php $previousSeverity = $issue['severity'] ?? ''; @endphp
                <div class="issue-section-header">
                    <span class="badge badge-{{ $previousSeverity }}">{{ strtoupper($previousSeverity) }}</span>
                    <span class="muted">{{ $severityCounts[$previousSeverity] ?? 0 }} issue(s)</span>
                </div>
            @endif
            <div class="issue">
                <div>
                    <span class="badge badge-{{ $issue['severity'] }}">{{ strtoupper($issue['severity']) }}</span>
                    @if (! empty($issue['category']))
                        <span class="badge badge-category badge-category-{{ $issue['category'] }}">{{ $categoryLabels[$issue['category']] ?? $issue['category'] }}</span>
                    @endif
                    <strong>{{ $issue['title'] }}</strong>
                    <span class="muted">[{{ $issue['ruleId'] }}]</span>
                </div>
                <div class="muted">{{ $issue['location']['file'] ?? '' }}:{{ $issue['location']['line'] ?? '' }}</div>
                <div>{{ $issue['message'] }}</div>
                @if (! empty($issue['recommendation']))
                    <div class="muted">Fix: {{ $issue['recommendation'] }}</div>
                @endif
            </div>
        @empty
            @if (($report['issues'] ?? []) === [])
                <p class="muted">No issues found.</p>
            @endif
        @endforelse

        @if ($issuesLastPage > 1)
            <nav class="pagination" aria-label="Issues pagination">
                <div class="pagination-nav">
                    @if ($issuesPage > 1)
                        <a class="pagination-link" href="{{ $reportShowUrl.'?'.$issueQuery(['page' => 1]) }}">First</a>
                        <a class="pagination-link" href="{{ $reportShowUrl.'?'.$issueQuery(['page' => $issuesPage - 1]) }}">Previous</a>
                    @else
                        <span class="pagination-link disabled">First</span>
                        <span class="pagination-link disabled">Previous</span>
                    @endif
                </div>

                <div class="pagination-pages">
                    @foreach ($issuePageNumbers as $pageNumber)
                        @if ($pageNumber === '...')
                            <span class="pagination-ellipsis">…</span>
                        @elseif ($pageNumber === $issuesPage)
                            <span class="pagination-page is-active">{{ $pageNumber }}</span>
                        @else
                            <a class="pagination-page" href="{{ $reportShowUrl.'?'.$issueQuery(['page' => $pageNumber]) }}">{{ $pageNumber }}</a>
                        @endif
                    @endforeach
                </div>

                <div class="pagination-nav">
                    @if ($issuesPage < $issuesLastPage)
                        <a class="pagination-link" href="{{ $reportShowUrl.'?'.$issueQuery(['page' => $issuesPage + 1]) }}">Next</a>
                        <a class="pagination-link" href="{{ $reportShowUrl.'?'.$issueQuery(['page' => $issuesLastPage]) }}">Last</a>
                    @else
                        <span class="pagination-link disabled">Next</span>
                        <span class="pagination-link disabled">Last</span>
                    @endif
                </div>
            </nav>
        @endif
    </div>

    @php
        $heuristicPatterns = collect($report['patternSuggestions'] ?? [])
            ->filter(fn (array $pattern): bool => ($pattern['source'] ?? 'heuristic') === 'heuristic')
            ->values();
    @endphp

    @if ($heuristicPatterns->isNotEmpty())
        <div class="card">
            <h2 style="margin-top:0;">Confirm hypotheses with LLM</h2>
            <p class="muted">Select heuristic hypotheses to validate against method source code. Unselected items stay heuristic-only.</p>

            <form
                method="post"
                action="{{ route('laravel-audit.reports.confirm-patterns', $record->uuid) }}"
                data-submit-loading
                data-loading-message="Confirming with LLM…"
                data-require-checked="llm_hypotheses[]"
            >
                @csrf

                <label class="pattern hypothesis-select-all" style="display:block;">
                    <input
                        type="checkbox"
                        id="llm-hypotheses-select-all"
                        style="margin-right:8px;"
                    >
                    <strong>Select all</strong>
                    <span class="muted">({{ $heuristicPatterns->count() }} hypotheses)</span>
                </label>

                @foreach ($heuristicPatterns as $pattern)
                    <label class="pattern" style="display:block;">
                        <input
                            type="checkbox"
                            name="llm_hypotheses[]"
                            class="llm-hypothesis-checkbox"
                            value="{{ $pattern['hypothesisKey'] ?? (($pattern['pattern'] ?? '').':'.($pattern['location']['file'] ?? '').'::'.($pattern['location']['method'] ?? '')) }}"
                            style="margin-right:8px;"
                        >
                        <strong>{{ $pattern['title'] ?? $pattern['pattern'] }}</strong>
                        <span class="muted">({{ number_format(($pattern['confidence'] ?? 0) * 100, 0) }}%)</span>
                        <div class="muted">{{ $pattern['location']['class'] ?? '' }}::{{ $pattern['location']['method'] ?? '' }}()</div>
                        <div>{{ $pattern['description'] ?? '' }}</div>
                        <div class="muted"><code>{{ $pattern['hypothesisKey'] ?? '' }}</code></div>
                    </label>
                @endforeach

                <button class="btn" type="submit" style="margin-top:16px;">Confirm selected with LLM</button>

                <div class="submit-progress" data-loading-progress hidden>
                    <div class="submit-progress-bar">
                        <div class="submit-progress-fill"></div>
                    </div>
                    <p class="muted">This may take a minute per selected hypothesis. Do not close the page.</p>
                </div>
            </form>
        </div>
    @endif

    @if ($heuristicPatterns->isNotEmpty())
        <script>
            (function () {
                const form = document.querySelector('form[data-require-checked="llm_hypotheses[]"]');

                if (! form) {
                    return;
                }

                const selectAll = form.querySelector('#llm-hypotheses-select-all');
                const boxes = form.querySelectorAll('.llm-hypothesis-checkbox');

                if (! selectAll || boxes.length === 0) {
                    return;
                }

                const syncSelectAll = () => {
                    const checkedCount = [...boxes].filter((box) => box.checked).length;

                    selectAll.checked = checkedCount === boxes.length;
                    selectAll.indeterminate = checkedCount > 0 && checkedCount < boxes.length;
                };

                selectAll.addEventListener('change', () => {
                    boxes.forEach((box) => {
                        box.checked = selectAll.checked;
                    });
                    selectAll.indeterminate = false;
                });

                boxes.forEach((box) => box.addEventListener('change', syncSelectAll));
            })();
        </script>
    @endif

    @if (! empty($report['patternSuggestions']))
        <div class="card">
            <h2 style="margin-top:0;">Pattern suggestions</h2>
            @foreach ($report['patternSuggestions'] as $pattern)
                <div class="pattern">
                    <div>
                        <span @class([
                            'badge',
                            'badge-confirmed' => ($pattern['source'] ?? '') === 'confirmed',
                            'badge-heuristic' => ($pattern['source'] ?? 'heuristic') === 'heuristic',
                            'badge-refuted' => ($pattern['source'] ?? '') === 'refuted',
                        ])>{{ strtoupper($pattern['source'] ?? 'heuristic') }}</span>
                        <strong>{{ $pattern['title'] ?? $pattern['pattern'] }}</strong>
                        <span class="muted">({{ number_format(($pattern['confidence'] ?? 0) * 100, 0) }}%)</span>
                    </div>
                    <div class="muted">{{ $pattern['location']['class'] ?? '' }}::{{ $pattern['location']['method'] ?? '' }}()</div>
                    <div>{{ $pattern['description'] ?? '' }}</div>
                    @if (! empty($pattern['llmRationale']))
                        <div class="muted">LLM: {{ $pattern['llmRationale'] }}</div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
@endsection
