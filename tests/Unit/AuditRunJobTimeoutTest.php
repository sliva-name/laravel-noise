<?php

declare(strict_types=1);

namespace LaravelAudit\Tests\Unit;

use LaravelAudit\Audit\AuditOptions;
use LaravelAudit\Audit\AuditProgressTracker;
use LaravelAudit\Audit\AuditRunJobTimeout;
use LaravelAudit\Jobs\RunStoredAuditJob;
use LaravelAudit\Tests\TestCase;

final class AuditRunJobTimeoutTest extends TestCase
{
    public function test_default_job_timeout_is_three_minutes(): void
    {
        $this->app['config']->set('laravel-audit.dashboard.job_timeout', 180);

        $this->assertSame(180, AuditRunJobTimeout::forOptions(new AuditOptions));
    }

    public function test_llm_job_timeout_accounts_for_multiple_http_calls(): void
    {
        $this->app['config']->set('laravel-audit.dashboard.job_timeout', 180);
        $this->app['config']->set('laravel-audit.patterns.llm.timeout', 120);
        $this->app['config']->set('laravel-audit.patterns.llm.review_limit', 3);
        $this->app['config']->set('laravel-audit.patterns.limit', 20);

        $timeout = AuditRunJobTimeout::forOptions(new AuditOptions(llm: true));

        $this->assertSame(180 + (120 * 20), $timeout);
    }

    public function test_llm_job_timeout_accounts_for_parallel_requests(): void
    {
        $this->app['config']->set('laravel-audit.dashboard.job_timeout', 180);
        $this->app['config']->set('laravel-audit.patterns.llm.timeout', 120);
        $this->app['config']->set('laravel-audit.patterns.llm.review_limit', 3);
        $this->app['config']->set('laravel-audit.patterns.llm.concurrency', 4);
        $this->app['config']->set('laravel-audit.patterns.limit', 20);

        $timeout = AuditRunJobTimeout::forOptions(new AuditOptions(llm: true));

        $this->assertSame(180 + (120 * 5), $timeout);
    }

    public function test_llm_job_timeout_can_be_overridden(): void
    {
        $this->app['config']->set('laravel-audit.dashboard.llm_job_timeout', 3600);

        $this->assertSame(3600, AuditRunJobTimeout::forOptions(new AuditOptions(llm: true)));
    }

    public function test_job_reads_timeout_from_run_options(): void
    {
        $directory = sys_get_temp_dir().'/laravel-audit-job-timeout-'.uniqid('', true);
        $this->app['config']->set('laravel-audit.dashboard.runs_path', $directory);
        $this->app['config']->set('laravel-audit.dashboard.job_timeout', 180);
        $this->app['config']->set('laravel-audit.patterns.llm.timeout', 120);
        $this->app['config']->set('laravel-audit.patterns.llm.review_limit', 3);
        $this->app['config']->set('laravel-audit.patterns.limit', 20);

        $tracker = new AuditProgressTracker($directory);
        $uuid = $tracker->create(new AuditOptions(llm: true));

        $job = new RunStoredAuditJob($uuid);

        $this->assertSame(180 + (120 * 20), $job->timeout);

        foreach (glob($directory.'/*.json') ?: [] as $file) {
            unlink($file);
        }

        rmdir($directory);
    }
}
