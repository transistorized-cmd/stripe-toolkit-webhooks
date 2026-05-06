<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>@yield('title', 'Stripe Webhooks · Debug')</title>
    @yield('head')
    <style>
        :root {
            --bg: #0f172a;
            --panel: #1e293b;
            --border: #334155;
            --text: #e2e8f0;
            --muted: #94a3b8;
            --accent: #38bdf8;
            --good: #22c55e;
            --warn: #f59e0b;
            --bad: #ef4444;
            --grey: #64748b;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, sans-serif;
            background: var(--bg);
            color: var(--text);
            font-size: 14px;
            line-height: 1.4;
        }
        a { color: var(--accent); text-decoration: none; }
        a:hover { text-decoration: underline; }
        header {
            padding: 16px 24px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: baseline;
            gap: 16px;
        }
        header h1 { margin: 0; font-size: 18px; font-weight: 600; }
        header .subtitle { color: var(--muted); font-size: 12px; }
        header .grow { flex: 1; }
        main { padding: 16px 24px; }
        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            background: var(--grey);
            color: white;
        }
        .badge.received { background: var(--grey); }
        .badge.processing { background: var(--warn); }
        .badge.processed { background: var(--good); }
        .badge.no-op { background: var(--grey); color: var(--text); border: 1px solid var(--border); }
        .outcome-success, .outcome-failure {
            display: inline-block;
            margin-left: 6px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            vertical-align: middle;
        }
        .outcome-success { color: var(--good); }
        .outcome-success::before { content: "✓ "; }
        .outcome-failure { color: var(--bad); }
        .outcome-failure::before { content: "✗ "; }
        .outcome-card {
            margin: 16px 0;
            padding: 16px 20px;
            border-radius: 8px;
            border: 1px solid var(--border);
        }
        .outcome-card.success {
            background: rgba(34, 197, 94, 0.06);
            border-color: var(--good);
            border-left-width: 4px;
        }
        .outcome-card.failure {
            background: rgba(239, 68, 68, 0.06);
            border-color: var(--bad);
            border-left-width: 4px;
        }
        .outcome-card .headline {
            font-size: 16px;
            font-weight: 700;
            margin: 0 0 6px;
        }
        .outcome-card.success .headline { color: var(--good); }
        .outcome-card.failure .headline { color: var(--bad); }
        .outcome-card .reason {
            font-size: 13px;
            color: var(--text);
            margin: 0;
        }
        .outcome-card .reason code { color: var(--muted); margin-left: 6px; }
        .badge.failed { background: var(--warn); }
        .badge.dead_letter { background: var(--bad); }
        .badge.pending { background: var(--grey); }
        .badge.running { background: var(--warn); }
        .stat-row {
            display: flex;
            gap: 12px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }
        .stat {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 10px 14px;
            min-width: 120px;
        }
        .stat .label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--muted);
        }
        .stat .value {
            font-size: 20px;
            font-weight: 600;
            font-variant-numeric: tabular-nums;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 6px;
            overflow: hidden;
            font-variant-numeric: tabular-nums;
        }
        th, td {
            padding: 10px 12px;
            text-align: left;
            border-bottom: 1px solid var(--border);
            vertical-align: top;
        }
        th {
            background: rgba(255, 255, 255, 0.03);
            font-weight: 600;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--muted);
        }
        tr:last-child td { border-bottom: 0; }
        tr.row-link { cursor: pointer; }
        tr.row-link:hover { background: rgba(56, 189, 248, 0.08); }
        code, pre {
            font-family: ui-monospace, "SF Mono", Menlo, monospace;
            font-size: 12px;
        }
        code { color: var(--accent); }
        pre {
            background: #020617;
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 12px;
            overflow: auto;
            margin: 0;
            color: #cbd5e1;
        }
        .filters {
            display: flex;
            gap: 8px;
            margin-bottom: 16px;
            flex-wrap: wrap;
            align-items: center;
        }
        .filters input, .filters select {
            background: var(--panel);
            color: var(--text);
            border: 1px solid var(--border);
            border-radius: 4px;
            padding: 6px 10px;
            font-size: 13px;
        }
        .filters button {
            background: var(--accent);
            color: var(--bg);
            border: 0;
            border-radius: 4px;
            padding: 6px 14px;
            font-weight: 600;
            cursor: pointer;
            font-size: 13px;
        }
        .filters button:hover { filter: brightness(1.1); }
        .filters .clear { background: var(--grey); }
        .empty {
            background: var(--panel);
            border: 1px dashed var(--border);
            border-radius: 6px;
            padding: 32px;
            text-align: center;
            color: var(--muted);
        }
        .pagination {
            margin-top: 16px;
            display: flex;
            gap: 8px;
            color: var(--muted);
            align-items: center;
        }
        .pagination a, .pagination span {
            padding: 4px 10px;
            border: 1px solid var(--border);
            border-radius: 4px;
        }
        .pagination .current {
            background: var(--accent);
            color: var(--bg);
            border-color: var(--accent);
        }
        .grid-2 {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 16px;
            margin-bottom: 16px;
        }
        .card {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 12px 16px;
        }
        .card h2 {
            margin: 0 0 10px;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--muted);
            font-weight: 600;
        }
        .kv { display: grid; grid-template-columns: 130px 1fr; gap: 4px 12px; }
        .kv dt { color: var(--muted); font-size: 12px; }
        .kv dd { margin: 0; font-family: ui-monospace, "SF Mono", Menlo, monospace; font-size: 12px; word-break: break-all; }
        details { margin-top: 16px; }
        details summary {
            cursor: pointer;
            padding: 8px 0;
            color: var(--muted);
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 600;
        }
        .back {
            color: var(--muted);
            font-size: 12px;
            margin-bottom: 8px;
            display: inline-block;
        }
    </style>
</head>
<body>
<header>
    <h1>
        <a href="{{ route('stripe-webhooks.debug.index') }}" style="color: var(--text);">
            Stripe Webhooks · Debug
        </a>
    </h1>
    <span class="subtitle">read-only · {{ app()->environment() }}</span>
    <span class="grow"></span>
    @if (($autoRefresh ?? 0) > 0)
        <span class="subtitle">auto-refresh every {{ $autoRefresh }}s</span>
    @endif
</header>
<main>
    @yield('content')
</main>
</body>
</html>
