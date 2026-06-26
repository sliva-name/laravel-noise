@extends('laravel-audit::panel.layout')

@section('title', 'Run Analysis · Laravel Audit')

@section('content')
    <h1 class="page-title">Run Analysis</h1>
    <p class="page-subtitle">Execute a new audit and store the report in the panel.</p>

    <div class="card">
        <form method="post" action="{{ route('laravel-audit.reports.store') }}">
            @csrf

            <div class="form-row">
                <label>
                    <input type="checkbox" name="no_tools" value="1">
                    Skip Pint and PHPStan
                </label>
                <label>
                    <input type="checkbox" name="patterns" value="1" id="patterns-option">
                    Include heuristic pattern scoring
                </label>
                <label>
                    <input type="checkbox" name="llm" value="1">
                    Confirm top hypotheses with LLM automatically
                </label>
            </div>

            <p class="muted" style="margin-top:-8px;">
                To pick specific hypotheses manually, run with patterns only and confirm them on the report page after the audit finishes.
            </p>

            <div class="form-row">
                <label>
                    Only categories (comma-separated, optional)
                    <input type="text" name="only" placeholder="security,performance" style="width:100%;margin-top:8px;padding:10px;border-radius:8px;border:1px solid var(--border);background:var(--panel-hover);color:var(--text);">
                </label>
            </div>

            <button class="btn" type="submit">Run and save report</button>
        </form>
    </div>
@endsection
