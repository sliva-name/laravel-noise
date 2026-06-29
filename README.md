# Laravel Audit

Extensible Laravel audit package inspired by Laravel-focused tools such as ShieldCI. It combines Laravel-specific static analyzers with optional Pint and PHPStan/Larastan runners, pattern inference, optional LLM confirmation, and a web dashboard for browsing saved reports.

## Requirements

- PHP 8.2+
- Laravel 10–13
- Optional: `laravel/pint`, `phpstan/phpstan`, `larastan/larastan` in the host application
- Optional: OpenAI-compatible LLM endpoint (LM Studio, Ollama, etc.) for pattern confirmation
- Web dashboard: no database required by default (reports are stored as JSON files)

## Installation

```bash
composer require laravel-audit/package --dev
```

Publish configuration:

```bash
php artisan vendor:publish --tag=laravel-audit-config
```

Publish dashboard migrations only when using database report storage:

```bash
php artisan vendor:publish --tag=laravel-audit-migrations
php artisan migrate
```

## Quick start

### CLI

```bash
# Full audit (analyzers + Pint + PHPStan when available)
php artisan audit:analyze

# Fast run without external tools
php artisan audit:analyze --no-tools

# Save report for the web panel
php artisan audit:analyze --no-tools --store
```

### Web panel

After installation the panel is available at `/audit` by default.

1. Open `http://your-app.test/audit`
2. Use **Run Analysis** to start an audit
3. Watch progress on the run page
4. Open the finished report from **All Reports**

For production, run a queue worker (see [Dashboard runners](#dashboard-runners)).

---

## Commands

### `audit:analyze`

Main analysis command. Runs built-in analyzers and, unless disabled, Pint and PHPStan/Larastan.

```bash
php artisan audit:analyze [options]
```

| Option | Description |
|--------|-------------|
| `--format=console` | Output format: `console` (default), `json`, or `sarif` |
| `--fail-on=` | Minimum severity for a non-zero exit code: `info`, `warning`, `error`, `critical` (default from config: `error`) |
| `--only=` | Comma-separated categories: `security`, `performance`, `reliability`, `best-practices`, `code-quality` |
| `--no-tools` | Skip Pint and PHPStan runners |
| `--patterns` | Enable heuristic refactoring pattern scoring |
| `--llm` | Confirm top heuristic hypotheses with an LLM using method source code |
| `--llm-pick=` | Confirm only selected hypothesis keys (repeatable; format `pattern:file::method`) |
| `--store` | Save the report for the web panel (when dashboard is enabled) |

**Examples**

```bash
php artisan audit:analyze --format=json
php artisan audit:analyze --format=sarif --fail-on=warning
php artisan audit:analyze --only=security,performance
php artisan audit:analyze --no-tools --patterns
php artisan audit:analyze --patterns --llm --format=json
php artisan audit:analyze --patterns --llm-pick=action:app/Http/Controllers/UserController.php::store --format=json
php artisan audit:analyze --no-tools --store
```

Exit code is `0` on success and `1` when at least one issue meets the `--fail-on` threshold.

### `audit:run-stored`

Internal command used by the web dashboard to execute a queued run. You can also run it manually:

```bash
php artisan audit:run-stored {uuid}
```

`{uuid}` is the run id from `/audit/runs/{uuid}` or from `storage/app/laravel-audit/runs/{uuid}.json`.

---

## Web dashboard

### Routes

| Method | Path | Description |
|--------|------|-------------|
| GET | `/audit` | Overview and recent reports |
| GET | `/audit/reports` | All saved reports |
| GET | `/audit/reports/create` | Start a new analysis |
| POST | `/audit/reports` | Queue/create a run and redirect to progress |
| GET | `/audit/reports/{uuid}` | View a saved report |
| POST | `/audit/reports/{uuid}/confirm-patterns` | Confirm selected heuristic hypotheses with LLM (sync) |
| GET | `/audit/runs/{uuid}` | Progress page with live status |
| GET | `/audit/runs/{uuid}/status` | JSON progress payload (polling) |
| POST | `/audit/runs/{uuid}/kick` | Re-dispatch background runner |
| POST | `/audit/runs/{uuid}/execute` | Run synchronously in the HTTP request (blocks UI; fallback) |

Default path prefix is `audit`. Configure with `LARAVEL_AUDIT_DASHBOARD_PATH`.

### Dashboard runners

Panel audits run **asynchronously** so the UI stays responsive.

| Runner | Config value | How it works | When to use |
|--------|--------------|--------------|-------------|
| **Queue** (default) | `queue` | Dispatches `RunStoredAuditJob` | Production, local dev with `queue:work` |
| **Process** | `process` | Detached `nohup php artisan audit:run-stored` | No queue infrastructure; requires `exec` |

**Queue runner (recommended)**

```env
LARAVEL_AUDIT_DASHBOARD_RUNNER=queue
QUEUE_CONNECTION=database   # or redis, sqs, etc.
```

```bash
# One-time setup for database driver
php artisan queue:table
php artisan migrate

# Keep a worker running. For LLM audits, set worker timeout above the job timeout.
php artisan queue:work --timeout=1800
```

With `QUEUE_CONNECTION=sync`, jobs run inline during the HTTP request (fine for tests, but blocks the create request).

**Process runner (fallback)**

```env
LARAVEL_AUDIT_DASHBOARD_RUNNER=process
```

Logs are written to `storage/logs/laravel-audit-{uuid}.log`.

### Report storage

| Driver | Config value | Location |
|--------|--------------|----------|
| **File** (default) | `file` | `storage/app/laravel-audit/reports/{uuid}.json` |
| **Database** | `database` | `audit_reports` table |

No database is required for the default file storage.

### Protecting the panel

By default the panel uses the `web` middleware group only. In production, add authentication in `config/laravel-audit.php`:

```php
'dashboard' => [
    'middleware' => ['web', 'auth'],
],
```

---

## Configuration

All options live in `config/laravel-audit.php` after publishing.

### Scan paths

```php
'paths' => ['app', 'routes', 'config', 'database/migrations', 'tests'],
'exclude' => ['vendor', 'storage', 'bootstrap/cache', 'node_modules'],
```

### Tools (Pint & PHPStan)

| Key | Env | Default | Description |
|-----|-----|---------|-------------|
| `tools.pint.enabled` | — | `true` | Run Pint in `--test` mode |
| `tools.pint.binary` | `LARAVEL_AUDIT_PINT_BINARY` | `vendor/bin/pint` | Pint executable |
| `tools.phpstan.enabled` | — | `true` | Run PHPStan |
| `tools.phpstan.binary` | `LARAVEL_AUDIT_PHPSTAN_BINARY` | `vendor/bin/phpstan` | PHPStan executable |
| `tools.phpstan.auto_larastan` | `LARAVEL_AUDIT_PHPSTAN_AUTO_LARASTAN` | `true` | Auto-generate Larastan config when no `phpstan.neon` exists |
| `tools.phpstan.level` | `LARAVEL_AUDIT_PHPSTAN_LEVEL` | `10` | PHPStan level for auto Larastan config (max by default) |

When Larastan is installed, the PHPStan runner generates `storage/framework/cache/laravel-audit-phpstan/audit-phpstan.neon` with writable cache paths and your scan paths.

Fatal bootstrap failures (missing traits, autoload errors, worker crashes) appear in the report as `tooling.phpstan.runner` issues parsed from PHPStan's top-level JSON `errors[]`.

After updating this package, restart queue workers so they load the new runner code:

```bash
php artisan queue:restart
```

The queue worker timeout must exceed the audit job timeout. For example, if `dashboard.job_timeout` is `180`, run `queue:work` with `--timeout=300` or higher.

### Reporting

| Key | Default | Description |
|-----|---------|-------------|
| `reporting.default_format` | `console` | Default CLI output format |
| `reporting.fail_on` | `error` | Default `--fail-on` threshold |

### Dashboard

| Key | Env | Default | Description |
|-----|-----|---------|-------------|
| `dashboard.enabled` | `LARAVEL_AUDIT_DASHBOARD` | `true` | Enable routes and views |
| `dashboard.path` | `LARAVEL_AUDIT_DASHBOARD_PATH` | `audit` | URL prefix |
| `dashboard.middleware` | — | `['web']` | Route middleware |
| `dashboard.storage` | `LARAVEL_AUDIT_DASHBOARD_STORAGE` | `file` | `file` or `database` |
| `dashboard.storage_path` | `LARAVEL_AUDIT_DASHBOARD_STORAGE_PATH` | — | Custom reports directory |
| `dashboard.runs_path` | `LARAVEL_AUDIT_DASHBOARD_RUNS_PATH` | — | Custom in-progress runs directory |
| `dashboard.runner` | `LARAVEL_AUDIT_DASHBOARD_RUNNER` | `queue` | `queue` or `process` |
| `dashboard.queue_connection` | `LARAVEL_AUDIT_DASHBOARD_QUEUE_CONNECTION` | — | Queue connection (default: app default) |
| `dashboard.queue` | `LARAVEL_AUDIT_DASHBOARD_QUEUE` | `default` | Queue name |
| `dashboard.job_timeout` | `LARAVEL_AUDIT_DASHBOARD_JOB_TIMEOUT` | `180` | Base queue job timeout (seconds) |
| `dashboard.llm_job_timeout` | `LARAVEL_AUDIT_DASHBOARD_LLM_JOB_TIMEOUT` | — | Optional fixed timeout for LLM audits |

Default storage paths:

- Reports: `storage/app/laravel-audit/reports/`
- Runs: `storage/app/laravel-audit/runs/`

### Thresholds

| Key | Default | Description |
|-----|---------|-------------|
| `thresholds.nesting_depth` | `4` | Max nesting depth before `code-quality.nesting-depth` fires |

### Pattern inference

| Key | Env | Default | Description |
|-----|-----|---------|-------------|
| `patterns.enabled` | `LARAVEL_AUDIT_PATTERNS` | `false` | Enable heuristic patterns from CLI/config (without `--patterns` flag) |
| `patterns.min_confidence` | `LARAVEL_AUDIT_PATTERN_MIN_CONFIDENCE` | `0.55` | Minimum score to include a suggestion |
| `patterns.limit` | `LARAVEL_AUDIT_PATTERN_LIMIT` | `20` | Max heuristic suggestions |
| `patterns.model_path` | `LARAVEL_AUDIT_PATTERN_MODEL` | package `pattern-model.json` | Custom model file |
| `patterns.llm.enabled` | `LARAVEL_AUDIT_PATTERN_LLM` | `false` | Enable LLM from config |
| `patterns.llm.provider` | `LARAVEL_AUDIT_PATTERN_LLM_PROVIDER` | `openai_compatible` | LLM provider |
| `patterns.llm.endpoint` | `LARAVEL_AUDIT_PATTERN_LLM_ENDPOINT` | `http://127.0.0.1:1234/v1/chat/completions` | Chat completions URL |
| `patterns.llm.model` | `LARAVEL_AUDIT_PATTERN_LLM_MODEL` | `local-model` | Model name |
| `patterns.llm.api_key` | `LARAVEL_AUDIT_PATTERN_LLM_API_KEY` | — | API key (optional for local servers) |
| `patterns.llm.timeout` | `LARAVEL_AUDIT_PATTERN_LLM_TIMEOUT` | `120` | HTTP timeout (seconds) |
| `patterns.llm.max_attempts` | `LARAVEL_AUDIT_PATTERN_LLM_MAX_ATTEMPTS` | auto | Max LLM HTTP calls per audit |
| `patterns.llm.review_limit` | `LARAVEL_AUDIT_PATTERN_LLM_REVIEW_LIMIT` | `3` | Max methods sent to LLM |
| `patterns.llm.refine_top` | `LARAVEL_AUDIT_PATTERN_LLM_REFINE_TOP` | `3` | Legacy alias for review limit |

### Rules

Each analyzer can be toggled in `rules`:

```php
'rules' => [
    'security.raw-sql' => true,
    // ...
],
```

Set a rule to `false` to disable it without removing the analyzer from the package.

---

## Environment examples

### Minimal (CLI + panel, no LLM)

```env
LARAVEL_AUDIT_DASHBOARD=true
LARAVEL_AUDIT_DASHBOARD_PATH=audit
LARAVEL_AUDIT_DASHBOARD_RUNNER=queue
QUEUE_CONNECTION=database

LARAVEL_AUDIT_PATTERNS=false
LARAVEL_AUDIT_PATTERN_LLM=false
```

### Full (patterns + local LLM + queue)

```env
LARAVEL_AUDIT_DASHBOARD=true
LARAVEL_AUDIT_DASHBOARD_RUNNER=queue
QUEUE_CONNECTION=database

LARAVEL_AUDIT_PATTERNS=true
LARAVEL_AUDIT_PATTERN_LLM=true
LARAVEL_AUDIT_PATTERN_LLM_ENDPOINT=http://127.0.0.1:1234/v1/chat/completions
LARAVEL_AUDIT_PATTERN_LLM_MODEL=google/gemma-4-e4b
```

### No queue, no database (process runner + file storage)

```env
LARAVEL_AUDIT_DASHBOARD=true
LARAVEL_AUDIT_DASHBOARD_RUNNER=process
LARAVEL_AUDIT_DASHBOARD_STORAGE=file
```

---

## Built-in analyzers

### Security

| Rule ID | Detects |
|---------|---------|
| `security.raw-sql` | Raw SQL / `DB::raw` usage |
| `security.mass-assignment` | Missing `$fillable`/`$guarded`, empty `$guarded`, or `$guarded`-only models |
| `security.weak-validation` | Weak inline validation |
| `security.debug-configuration` | Debug-friendly defaults in config |
| `security.command-injection` | Shell/command injection risks |
| `security.eval-usage` | `eval()` usage |
| `security.hardcoded-credentials` | Hardcoded secrets |
| `security.unguarded-model` | `Model::unguard()` usage |
| `security.unvalidated-mass-create` | `Model::create($request->all())` without validation |
| `security.sensitive-field-exposure` | Raw model/Inertia/JSON responses exposing unhidden sensitive columns |

### Performance

| Rule ID | Detects |
|---------|---------|
| `performance.n-plus-one-candidate` | Possible N+1 query patterns |
| `performance.sync-heavy-job` | Heavy work in non-queue jobs |

### Reliability

| Rule ID | Detects |
|---------|---------|
| `reliability.missing-transaction` | Multi-step writes without transactions |
| `reliability.env-access-outside-config` | `env()` outside config files |
| `reliability.global-variables` | Superglobal access |

### Best practices

| Rule ID | Detects |
|---------|---------|
| `best-practices.missing-form-request` | Inline validation instead of Form Requests |
| `best-practices.fat-controller` | Oversized controllers |
| `best-practices.logic-in-routes` | Business logic in route closures |
| `best-practices.silent-failure` | Empty catch blocks / swallowed errors |
| `best-practices.debug-statement` | `dd()`, `dump()`, and similar debug helpers in app code |

### Code quality

| Rule ID | Detects |
|---------|---------|
| `code-quality.long-method` | Methods exceeding length threshold |
| `code-quality.large-class` | Classes with too many lines |
| `code-quality.nesting-depth` | Deep nesting |
| `code-quality.redundant-boolean-return` | Redundant boolean return patterns |
| `code-quality.redundant-null-coalesce` | Useless null coalesce |
| `code-quality.redundant-empty-foreach-guard` | Empty check before foreach |
| `code-quality.redundant-catch-rethrow` | Catch/rethrow without value |
| `code-quality.redundant-else-after-exit` | Else after return/throw |
| `code-quality.redundant-type-guard` | Redundant type/is/instance checks |
| `code-quality.redundant-method-exists` | `method_exists()` on typed dependencies |
| `code-quality.redundant-class-exists` | `class_exists()` for app classes |
| `code-quality.redundant-config-fallback` | `config('key') ?? $default` instead of `config('key', $default)` |

### Tooling

When not using `--no-tools`, findings from **Pint** and **PHPStan/Larastan** are merged into the report.

---

## Pattern inference

Two independent layers:

### 1. Heuristic model (`--patterns` or `patterns.enabled`)

Extracts AST features per method and scores them against `resources/pattern-model.json` (logistic-style weighted model).

Known patterns:

- `strategy`
- `action` (Action / Use Case)
- `repository`
- `value_object`
- `guard_clauses`
- `extract_method`
- `dependency_injection`
- `form_request`
- `policy`
- `pipeline`
- `enum`
- `api_resource`

Output uses `source: heuristic`. Existing audit findings can boost pattern scores (`finding_boosts` in the model file).

### 2. LLM confirmation (`--llm` or `patterns.llm.enabled`)

Does **not** pick patterns from scratch. For each heuristic hypothesis it sends:

- pattern slug and title
- structural facts (lines, branches, etc.)
- **method source code**

The model must **confirm or reject** the hypothesis with code evidence. Confirmed items use `source: confirmed`; rejected ones use `source: refuted` with the LLM rationale.

**Automatic (top N):** with `--llm` and no explicit keys, the advisor picks the strongest hypotheses per method, up to `patterns.llm.review_limit`.

**Manual selection:**

| Flow | How |
|------|-----|
| CLI | Repeat `--llm-pick=pattern:path/to/file.php::methodName` (hypothesis key shown on each heuristic row in JSON/console) |
| Panel | Run with **patterns only** (leave LLM unchecked on create). On the report page, check hypotheses and click **Confirm selected with LLM** |

Hypothesis key format: `{pattern}:{relative-file}::{method}` — e.g. `action:app/Http/Controllers/UserController.php::store`.

```bash
php artisan audit:analyze --patterns          # heuristic only
php artisan audit:analyze --llm               # LLM only (needs config/flag for heuristics to produce hypotheses)
php artisan audit:analyze --patterns --llm    # both layers (auto top-N)
php artisan audit:analyze --patterns --llm-pick=repository:app/Repositories/UserRepository.php::findForUpdate
```

Compatible with OpenAI-compatible APIs (LM Studio, Ollama, etc.).

---

## Report formats

### Console (default)

Human-readable summary grouped by severity, suitable for local development and CI logs.

### JSON

```bash
php artisan audit:analyze --format=json
```

Structured payload with `summary`, `issues`, `tools`, `patternSuggestions`, and `durationSeconds`. Suitable for scripting and dashboards.

### SARIF

```bash
php artisan audit:analyze --format=sarif
```

SARIF 2.1.0 for GitHub Code Scanning and compatible tools.

---

## CI integration

```yaml
- name: Run Laravel Audit
  run: php artisan audit:analyze --no-tools --fail-on=error
```

With Pint and PHPStan in CI:

```yaml
- name: Run Laravel Audit
  run: php artisan audit:analyze --fail-on=error --format=sarif > audit.sarif
```

Store reports from CI into the panel:

```yaml
- name: Run and store audit
  run: php artisan audit:analyze --no-tools --store
```

Ensure the CI environment has write access to `storage/app/laravel-audit/` when using file storage.

---

## Extending the package

### Adding analyzers

1. Implement `LaravelAudit\Analysis\AnalyzerInterface`
2. Return normalized `LaravelAudit\Analysis\Issue` objects
3. Register the analyzer in `AuditServiceProvider` or a consuming app service provider

```php
use LaravelAudit\Analysis\AnalyzerInterface;
use LaravelAudit\Analysis\AnalysisContext;
use LaravelAudit\Analysis\Category;

final class CustomAnalyzer implements AnalyzerInterface
{
    public function id(): string
    {
        return 'best-practices.custom-rule';
    }

    public function category(): Category
    {
        return Category::BestPractices;
    }

    public function analyze(AnalysisContext $context): array
    {
        return [];
    }
}
```

Add a toggle under `rules` in config and register the class in `AuditServiceProvider`.

Analyzers should report evidence and recommendations. If a rule cannot prove a defect statically, phrase the issue as a candidate or risk.

### Custom pattern model

Publish or point to your own JSON model:

```env
LARAVEL_AUDIT_PATTERN_MODEL=/path/to/custom-pattern-model.json
```

---

## Troubleshooting

### Panel audit stuck on `QUEUED`

- **Queue runner:** ensure `php artisan queue:work` is running
- Check failed jobs: `php artisan queue:failed`
- Verify `QUEUE_CONNECTION` is not `sync` if you want a non-blocking UI
- Use **Retry start** on the progress page or run manually: `php artisan audit:run-stored {uuid}`

### Panel blocks the whole site during audit

- You are likely using `--execute` / foreground fallback or `QUEUE_CONNECTION=sync`
- Switch to `LARAVEL_AUDIT_DASHBOARD_RUNNER=queue` with a real queue driver and a worker

### Process runner does nothing

- Check `function_exists('exec')` is true
- Inspect `storage/logs/laravel-audit-{uuid}.log`
- Switch to queue runner for production

### LLM audit times out in queue

- `RunStoredAuditJob` now sets a longer timeout automatically when LLM is enabled
- The queue worker timeout must still be high enough: `php artisan queue:work --timeout=1800`
- Or set a fixed ceiling with `LARAVEL_AUDIT_DASHBOARD_LLM_JOB_TIMEOUT=3600`
- Reduce load with `LARAVEL_AUDIT_PATTERN_LLM_REVIEW_LIMIT` or `LARAVEL_AUDIT_PATTERN_LLM_MAX_ATTEMPTS`

### LLM returns empty or invalid JSON

- LM Studio: use an OpenAI-compatible endpoint; some models need `/no_think` or lower `review_limit`
- Increase `LARAVEL_AUDIT_PATTERN_LLM_TIMEOUT` for slow local models
- Test with `--patterns --llm --format=json` before using the panel

### `Permission denied` writing `storage/app/laravel-audit/reports/*.json`

- The web request that confirms patterns must be able to write the same directory as the queue worker / CLI that created the report
- Fix ownership and permissions (adjust user/group for your stack):

```bash
sudo mkdir -p storage/app/laravel-audit/reports storage/app/laravel-audit/runs
sudo chown -R www-data:www-data storage/app/laravel-audit
sudo chmod -R ug+rwx storage/app/laravel-audit
```

- In Docker: run `chown` inside the PHP-FPM and queue-worker containers, or mount `storage` with a shared UID
- Alternative: switch panel storage to database with `LARAVEL_AUDIT_DASHBOARD_STORAGE=database`

### LM Studio log: `'messages' field is required` with body `{}`

- Usually means the HTTP client failed to encode the request JSON (invalid UTF-8 in method source) and sent an empty payload
- Recent package versions throw instead of posting `{}`; update the package if you still see `{}` requests
- A successful log entry followed by this error can also be a second hypothesis or an unrelated probe to the same endpoint

### PHPStan/Larastan not found

- Install dev dependencies in the host app: `composer require --dev larastan/larastan phpstan/phpstan`
- Or use `--no-tools` to run built-in analyzers only

### PHPStan shows `non-JSON response` or `Could not write file: /tmp/phpstan/resultCache.php`

- The audit job is probably running on an old queue worker process. Run `php artisan queue:restart` after `composer update`
- Ensure `storage/framework/cache` is writable by the queue worker user (`www-data` in Docker)
- Increase the queue worker `--timeout` above `dashboard.job_timeout` (for example `queue:work --timeout=300`)

---

## License

MIT
