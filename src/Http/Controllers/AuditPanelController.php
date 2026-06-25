<?php

declare(strict_types=1);

namespace LaravelAudit\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use LaravelAudit\Audit\AuditEngine;
use LaravelAudit\Audit\AuditOptions;
use LaravelAudit\Repositories\AuditReportRepository;

final class AuditPanelController extends Controller
{
    public function __construct(
        private readonly AuditReportRepository $reports,
        private readonly AuditEngine $engine,
    ) {}

    public function dashboard(): View
    {
        $reports = $this->reports->latest(10);

        return view('laravel-audit::panel.dashboard', [
            'reports' => $reports,
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

    public function show(string $uuid): View
    {
        $record = $this->reports->findByUuid($uuid);

        abort_if($record === null, 404);

        return view('laravel-audit::panel.reports.show', [
            'record' => $record,
            'report' => $record->payload,
            'menu' => $this->menu('reports'),
        ]);
    }

    public function create(): View
    {
        return view('laravel-audit::panel.reports.create', [
            'menu' => $this->menu('run'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $options = new AuditOptions(
            categories: $this->categories($request),
            noTools: $request->boolean('no_tools'),
            patterns: $request->boolean('patterns'),
            llm: $request->boolean('llm'),
        );

        $record = $this->reports->store($this->engine->run($options), $options);

        return redirect()
            ->route('laravel-audit.reports.show', $record->uuid)
            ->with('status', 'Audit completed and saved.');
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
                'label' => 'Run Analysis',
                'route' => route('laravel-audit.reports.create'),
                'active' => $active === 'run',
            ],
        ];
    }
}
