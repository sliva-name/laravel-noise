<?php

declare(strict_types=1);

namespace LaravelAudit\Tests\Feature;

use LaravelAudit\Audit\AuditOptions;
use LaravelAudit\Audit\AuditRunDispatcher;
use LaravelAudit\Audit\Contracts\AuditRunProcessLauncher;
use LaravelAudit\Reporting\AuditReport;
use LaravelAudit\Repositories\FileAuditReportStore;
use LaravelAudit\Tests\TestCase;

final class AuditPanelTest extends TestCase
{
    private string $reportsDirectory;

    private string $runsDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reportsDirectory = sys_get_temp_dir().'/laravel-audit-panel-'.uniqid('', true);
        $this->runsDirectory = sys_get_temp_dir().'/laravel-audit-runs-'.uniqid('', true);
        $this->app['config']->set('laravel-audit.dashboard.storage', 'file');
        $this->app['config']->set('laravel-audit.dashboard.storage_path', $this->reportsDirectory);
        $this->app['config']->set('laravel-audit.dashboard.runs_path', $this->runsDirectory);
        $this->app['config']->set('laravel-audit.dashboard.runner', 'queue');
        $this->app['config']->set('queue.default', 'sync');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->reportsDirectory.'/*.json') ?: [] as $file) {
            unlink($file);
        }

        foreach (glob($this->runsDirectory.'/*.json') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->reportsDirectory)) {
            rmdir($this->reportsDirectory);
        }

        if (is_dir($this->runsDirectory)) {
            rmdir($this->runsDirectory);
        }

        parent::tearDown();
    }

    public function test_dashboard_is_accessible(): void
    {
        $this->get('/audit')
            ->assertOk()
            ->assertSee('Overview')
            ->assertSee('All Reports')
            ->assertSee('Jobs');
    }

    public function test_runs_index_lists_background_jobs(): void
    {
        $this->post('/audit/reports', ['no_tools' => '1']);

        $this->get('/audit/runs')
            ->assertOk()
            ->assertSee('Jobs')
            ->assertSee('COMPLETED')
            ->assertSee(' · ')
            ->assertDontSee('T14:30:00');
    }

    public function test_reports_index_lists_saved_reports(): void
    {
        $store = new FileAuditReportStore($this->reportsDirectory);
        $snapshot = $store->store(new AuditReport(issues: [], toolResults: [], durationSeconds: 1.25), new AuditOptions(noTools: true));

        $this->get('/audit/reports')
            ->assertOk()
            ->assertSee($snapshot->uuid);
    }

    public function test_report_show_displays_details(): void
    {
        $store = new FileAuditReportStore($this->reportsDirectory);
        $snapshot = $store->store(new AuditReport(
            issues: [],
            toolResults: [],
            durationSeconds: 0.5,
        ), new AuditOptions(noTools: true));

        file_put_contents(
            $this->reportsDirectory.'/'.$snapshot->uuid.'.json',
            json_encode([
                ...$snapshot->toArray(),
                'payload' => [
                    'issues' => [[
                        'ruleId' => 'security.raw-sql',
                        'category' => 'security',
                        'severity' => 'error',
                        'title' => 'Raw SQL usage',
                        'message' => 'Avoid raw SQL in controllers.',
                        'location' => ['file' => 'app/Http/Controllers/Foo.php', 'line' => 10],
                        'recommendation' => 'Use Eloquent.',
                    ]],
                    'patternSuggestions' => [],
                ],
            ], JSON_THROW_ON_ERROR),
        );

        $this->get('/audit/reports/'.$snapshot->uuid)
            ->assertOk()
            ->assertSee('Raw SQL usage')
            ->assertSee('app/Http/Controllers/Foo.php')
            ->assertSee('Download JSON')
            ->assertSee('Security');
    }

    public function test_report_can_be_downloaded_as_json(): void
    {
        $store = new FileAuditReportStore($this->reportsDirectory);
        $snapshot = $store->store(new AuditReport(
            issues: [],
            toolResults: [],
            durationSeconds: 0.5,
        ), new AuditOptions(noTools: true));

        $this->get('/audit/reports/'.$snapshot->uuid.'/download')
            ->assertOk()
            ->assertHeader('content-disposition')
            ->assertJsonPath('uuid', $snapshot->uuid);
    }

    public function test_report_issues_support_pagination_and_filters(): void
    {
        $store = new FileAuditReportStore($this->reportsDirectory);
        $snapshot = $store->store(new AuditReport(
            issues: [],
            toolResults: [],
            durationSeconds: 0.5,
        ), new AuditOptions(noTools: true));

        $issues = [];

        for ($index = 1; $index <= 30; $index++) {
            $issues[] = [
                'ruleId' => 'code-quality.example-'.$index,
                'category' => $index % 2 === 0 ? 'security' : 'code-quality',
                'severity' => $index <= 5 ? 'critical' : 'warning',
                'title' => 'Issue '.$index,
                'message' => 'Message '.$index,
                'location' => ['file' => 'app/Example'.$index.'.php', 'line' => $index],
                'recommendation' => null,
            ];
        }

        file_put_contents(
            $this->reportsDirectory.'/'.$snapshot->uuid.'.json',
            json_encode([
                ...$snapshot->toArray(),
                'payload' => [
                    'issues' => $issues,
                    'patternSuggestions' => [],
                ],
            ], JSON_THROW_ON_ERROR),
        );

        $this->get('/audit/reports/'.$snapshot->uuid)
            ->assertOk()
            ->assertSee('Showing 1–25 of 30')
            ->assertSee('Issue 1')
            ->assertDontSee('Issue 30');

        $this->get('/audit/reports/'.$snapshot->uuid.'?page=2')
            ->assertOk()
            ->assertSee('Showing 26–30 of 30')
            ->assertSee('Issue 30');

        $this->get('/audit/reports/'.$snapshot->uuid.'?severity=critical')
            ->assertOk()
            ->assertSee('Showing 1–5 of 5')
            ->assertSee('Issue 1')
            ->assertDontSee('Issue 6')
            ->assertSee('href="'.route('laravel-audit.reports.show', $snapshot->uuid).'"', false);

        $this->get('/audit/reports/'.$snapshot->uuid)
            ->assertOk()
            ->assertSee('Showing 1–25 of 30');

        $this->get('/audit/reports/'.$snapshot->uuid.'?page=2')
            ->assertOk()
            ->assertSee('First')
            ->assertSee('Last')
            ->assertSee('pagination-page is-active">2</', false);

        $this->get('/audit/reports/'.$snapshot->uuid.'?category=security')
            ->assertOk()
            ->assertSee('Showing 1–15 of 15');
    }

    public function test_run_analysis_redirects_to_progress_page(): void
    {
        $this->post('/audit/reports', [
            'no_tools' => '1',
        ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Audit started in background.');
    }

    public function test_run_analysis_stores_report_via_queue_runner(): void
    {
        $this->post('/audit/reports', [
            'no_tools' => '1',
        ]);

        $this->assertCount(1, glob($this->reportsDirectory.'/*.json') ?: []);
    }

    public function test_run_status_returns_progress_json(): void
    {
        $response = $this->post('/audit/reports', ['no_tools' => '1']);
        $runUuid = basename((string) $response->headers->get('Location'));

        $this->get('/audit/runs/'.$runUuid.'/status')
            ->assertOk()
            ->assertJsonStructure(['status', 'progress', 'message', 'log', 'report_url']);
    }

    public function test_process_runner_can_be_selected(): void
    {
        $this->app->instance(AuditRunProcessLauncher::class, new class implements AuditRunProcessLauncher
        {
            public function dispatch(string $runUuid): bool
            {
                return true;
            }
        });
        $this->app['config']->set('laravel-audit.dashboard.runner', 'process');

        $this->assertTrue($this->app->make(AuditRunDispatcher::class)->dispatch('run-uuid'));
    }

    public function test_run_execute_still_works_as_foreground_fallback(): void
    {
        $response = $this->post('/audit/reports', ['no_tools' => '1']);
        $runUuid = basename((string) $response->headers->get('Location'));

        $this->post('/audit/runs/'.$runUuid.'/execute')
            ->assertOk()
            ->assertJsonPath('status', 'completed');
    }

    public function test_report_show_displays_llm_hypothesis_picker_for_heuristic_patterns(): void
    {
        $store = new FileAuditReportStore($this->reportsDirectory);
        $snapshot = $store->store(new AuditReport(
            issues: [],
            toolResults: [],
            durationSeconds: 0.5,
        ), new AuditOptions(noTools: true, patterns: true));

        file_put_contents(
            $this->reportsDirectory.'/'.$snapshot->uuid.'.json',
            json_encode([
                ...$snapshot->toArray(),
                'payload' => [
                    'issues' => [],
                    'patternSuggestions' => [[
                        'hypothesisKey' => 'action:app/Http/Controllers/UserController.php::store',
                        'pattern' => 'action',
                        'title' => 'Action / Use Case',
                        'description' => 'Move orchestration into an action.',
                        'recommendation' => 'Extract action class.',
                        'confidence' => 0.72,
                        'location' => [
                            'file' => 'app/Http/Controllers/UserController.php',
                            'line' => 12,
                            'class' => 'App\\Http\\Controllers\\UserController',
                            'method' => 'store',
                        ],
                        'source' => 'heuristic',
                    ]],
                ],
            ], JSON_THROW_ON_ERROR),
        );

        $this->get('/audit/reports/'.$snapshot->uuid)
            ->assertOk()
            ->assertSee('Confirm hypotheses with LLM')
            ->assertSee('Select all')
            ->assertSee('action:app/Http/Controllers/UserController.php::store');
    }

    public function test_confirm_patterns_requires_at_least_one_hypothesis(): void
    {
        $store = new FileAuditReportStore($this->reportsDirectory);
        $snapshot = $store->store(new AuditReport(
            issues: [],
            toolResults: [],
            durationSeconds: 0.5,
        ), new AuditOptions(noTools: true));

        $this->post('/audit/reports/'.$snapshot->uuid.'/confirm-patterns', [])
            ->assertStatus(422);
    }
}
