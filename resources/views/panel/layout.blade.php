<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Laravel Audit')</title>
    <style>
        :root {
            color-scheme: dark;
            --bg: #0f1419;
            --panel: #151b23;
            --panel-hover: #1c2430;
            --border: #2a3441;
            --text: #e7ecf3;
            --muted: #93a1b3;
            --accent: #5b8cff;
            --accent-soft: rgba(91, 140, 255, 0.12);
            --critical: #ff6b6b;
            --error: #ff8787;
            --warning: #feca57;
            --info: #74c0fc;
            --success: #51cf66;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: Inter, ui-sans-serif, system-ui, sans-serif;
            background: var(--bg);
            color: var(--text);
        }

        .layout {
            display: grid;
            grid-template-columns: 240px 1fr;
            min-height: 100vh;
        }

        .sidebar {
            background: var(--panel);
            border-right: 1px solid var(--border);
            padding: 24px 16px;
        }

        .brand {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .brand-sub {
            color: var(--muted);
            font-size: 12px;
            margin-bottom: 28px;
        }

        .menu a {
            display: block;
            padding: 10px 12px;
            border-radius: 8px;
            color: var(--text);
            text-decoration: none;
            margin-bottom: 4px;
        }

        .menu a.active,
        .menu a:hover {
            background: var(--panel-hover);
        }

        .menu a.active {
            background: var(--accent-soft);
            color: #dbeafe;
        }

        .content {
            padding: 28px 32px;
        }

        .page-title {
            font-size: 28px;
            font-weight: 700;
            margin: 0 0 8px;
        }

        .page-subtitle {
            color: var(--muted);
            margin: 0 0 24px;
        }

        .card {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 16px;
        }

        .metric {
            background: var(--panel-hover);
            border-radius: 10px;
            padding: 16px;
        }

        .metric-label {
            color: var(--muted);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .metric-value {
            font-size: 28px;
            font-weight: 700;
            margin-top: 8px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            text-align: left;
            padding: 12px 10px;
            border-bottom: 1px solid var(--border);
            vertical-align: top;
        }

        th {
            color: var(--muted);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        a.link {
            color: var(--accent);
            text-decoration: none;
        }

        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
        }

        .badge-critical { background: rgba(255, 107, 107, 0.15); color: var(--critical); }
        .badge-error { background: rgba(255, 135, 135, 0.15); color: var(--error); }
        .badge-warning { background: rgba(254, 202, 87, 0.15); color: var(--warning); }
        .badge-info { background: rgba(116, 192, 252, 0.15); color: var(--info); }
        .badge-heuristic { background: rgba(91, 140, 255, 0.15); color: #9ec5ff; }
        .badge-confirmed { background: rgba(81, 207, 102, 0.15); color: var(--success); }
        .badge-refuted { background: rgba(255, 107, 107, 0.15); color: var(--critical); }
        .badge-queued { background: rgba(116, 192, 252, 0.15); color: var(--info); }
        .badge-running { background: rgba(91, 140, 255, 0.15); color: #9ec5ff; }
        .badge-completed { background: rgba(81, 207, 102, 0.15); color: var(--success); }
        .badge-failed { background: rgba(255, 107, 107, 0.15); color: var(--critical); }

        .btn {
            display: inline-flex;
            align-items: center;
            background: var(--accent);
            color: white;
            border: 0;
            border-radius: 8px;
            padding: 10px 16px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
        }

        .btn-secondary {
            background: var(--panel-hover);
            color: var(--text);
            border: 1px solid var(--border);
        }

        .page-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 24px;
        }

        .page-header .page-subtitle {
            margin-bottom: 0;
        }

        .issues-toolbar {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 16px;
        }

        .issues-filter-form {
            margin: 0;
        }

        .issues-filter-label {
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin: 0;
            color: var(--muted);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .issues-filter-label select {
            min-width: 220px;
            background: var(--panel-hover);
            color: var(--text);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 8px 10px;
            font-size: 14px;
        }

        .filter-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 16px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--border);
        }

        .filter-tab {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            background: var(--panel-hover);
            color: var(--text);
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            border: 1px solid transparent;
        }

        .filter-tab:hover {
            border-color: var(--border);
        }

        .filter-tab.active {
            background: var(--accent-soft);
            color: #dbeafe;
            border-color: rgba(91, 140, 255, 0.35);
        }

        .filter-tab.disabled {
            opacity: 0.45;
            pointer-events: none;
        }

        .filter-tab-count {
            color: var(--muted);
            font-size: 12px;
            font-weight: 500;
        }

        .filter-tab.active .filter-tab-count {
            color: #dbeafe;
        }

        .issue-section-header {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 18px 0 8px;
            margin-top: 4px;
            border-top: 1px solid var(--border);
        }

        .issue-section-header:first-of-type {
            border-top: 0;
            margin-top: 0;
            padding-top: 0;
        }

        .badge-category {
            background: rgba(147, 161, 179, 0.15);
            color: var(--muted);
        }

        .badge-category-security { background: rgba(255, 107, 107, 0.12); color: var(--critical); }
        .badge-category-performance { background: rgba(254, 202, 87, 0.12); color: var(--warning); }
        .badge-category-reliability { background: rgba(255, 135, 135, 0.12); color: var(--error); }
        .badge-category-best-practices { background: rgba(116, 192, 252, 0.12); color: var(--info); }
        .badge-category-code-quality { background: rgba(91, 140, 255, 0.12); color: #9ec5ff; }
        .badge-category-tooling { background: rgba(147, 161, 179, 0.18); color: var(--muted); }

        .pagination {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid var(--border);
        }

        .pagination-nav {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .pagination-pages {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        .pagination-page {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 34px;
            height: 34px;
            padding: 0 8px;
            border-radius: 8px;
            color: var(--text);
            text-decoration: none;
            font-weight: 600;
            border: 1px solid var(--border);
            background: var(--panel-hover);
        }

        .pagination-page:hover {
            border-color: rgba(91, 140, 255, 0.35);
        }

        .pagination-page.is-active {
            background: var(--accent-soft);
            color: #dbeafe;
            border-color: rgba(91, 140, 255, 0.35);
        }

        .pagination-ellipsis {
            color: var(--muted);
            padding: 0 4px;
        }

        .pagination-link {
            color: var(--accent);
            text-decoration: none;
            font-weight: 600;
        }

        .pagination-link.disabled {
            color: var(--muted);
            pointer-events: none;
        }

        .btn:disabled {
            opacity: 0.75;
            cursor: wait;
        }

        .btn.is-loading::before {
            content: '';
            width: 14px;
            height: 14px;
            margin-right: 8px;
            border: 2px solid rgba(255, 255, 255, 0.35);
            border-top-color: white;
            border-radius: 50%;
            animation: btn-spin 0.7s linear infinite;
            flex-shrink: 0;
        }

        @keyframes btn-spin {
            to { transform: rotate(360deg); }
        }

        .submit-progress {
            margin-top: 16px;
        }

        .submit-progress[hidden] {
            display: none !important;
        }

        .submit-progress-bar {
            height: 8px;
            background: var(--panel-hover);
            border-radius: 999px;
            overflow: hidden;
            margin-bottom: 8px;
        }

        .submit-progress-fill {
            height: 100%;
            width: 40%;
            background: var(--accent);
            border-radius: 999px;
            animation: submit-progress-indeterminate 1.2s ease-in-out infinite;
        }

        @keyframes submit-progress-indeterminate {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(350%); }
        }

        form.is-pending label {
            opacity: 0.65;
        }

        form.is-pending label,
        form.is-pending input:not([type="hidden"]),
        form.is-pending select,
        form.is-pending textarea {
            pointer-events: none;
        }

        .hypothesis-select-all {
            margin-bottom: 4px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--border);
        }

        .status {
            background: rgba(81, 207, 102, 0.12);
            border: 1px solid rgba(81, 207, 102, 0.35);
            color: var(--success);
            padding: 12px 14px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .issue, .pattern {
            padding: 14px 0;
            border-bottom: 1px solid var(--border);
        }

        .issue:last-child, .pattern:last-child { border-bottom: 0; }

        .muted { color: var(--muted); }

        label { display: block; margin-bottom: 12px; }

        input[type="checkbox"] { margin-right: 8px; }

        .form-row { margin-bottom: 18px; }
    </style>
</head>
<body>
<div class="layout">
    <aside class="sidebar">
        <div class="brand">Laravel Audit</div>
        <div class="brand-sub">Code analysis panel</div>
        <nav class="menu">
            @foreach ($menu as $item)
                <a href="{{ $item['route'] }}" @class(['active' => $item['active']])>{{ $item['label'] }}</a>
            @endforeach
        </nav>
    </aside>
    <main class="content">
        @if (session('status'))
            <div class="status">{{ session('status') }}</div>
        @endif
        @yield('content')
    </main>
</div>
<script>
    document.querySelectorAll('form[data-submit-loading]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (form.dataset.submitLoadingActive === '1') {
                event.preventDefault();

                return;
            }

            const requiredGroup = form.dataset.requireChecked;

            if (requiredGroup) {
                const checked = form.querySelectorAll(`input[name="${requiredGroup}"]:checked`);

                if (checked.length === 0) {
                    return;
                }
            }

            const button = form.querySelector('[type="submit"]');

            if (! button) {
                return;
            }

            form.dataset.submitLoadingActive = '1';
            form.classList.add('is-pending');
            button.disabled = true;
            button.classList.add('is-loading');
            button.dataset.originalText = button.textContent;
            button.textContent = form.dataset.loadingMessage || 'Please wait…';

            const progress = form.querySelector('[data-loading-progress]');

            if (progress) {
                progress.hidden = false;
            }
        });
    });
</script>
</body>
</html>
