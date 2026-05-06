<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Send test webhook</title>
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
        }
        * { box-sizing: border-box; }
        html, body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, sans-serif;
            font-size: 13px;
            line-height: 1.4;
        }
        body { padding: 12px 16px; }
        form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 8px 12px;
            align-items: end;
        }
        label {
            display: flex;
            flex-direction: column;
            gap: 3px;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--muted);
        }
        label.checkbox {
            flex-direction: row;
            align-items: center;
            gap: 6px;
            text-transform: none;
            letter-spacing: 0;
            font-size: 12px;
            color: var(--text);
        }
        input[type=text], input[type=number], select, textarea {
            background: #0b1220;
            color: var(--text);
            border: 1px solid var(--border);
            border-radius: 4px;
            padding: 6px 8px;
            font-size: 12px;
            font-family: ui-monospace, "SF Mono", Menlo, monospace;
        }
        textarea {
            width: 100%;
            min-height: 110px;
            resize: vertical;
        }
        input:focus, select:focus, textarea:focus { outline: none; border-color: var(--accent); }
        button {
            background: var(--accent);
            color: var(--bg);
            border: 0;
            border-radius: 4px;
            padding: 8px 16px;
            font-weight: 600;
            cursor: pointer;
            font-size: 13px;
            grid-column: span 2;
        }
        button.secondary {
            background: transparent;
            color: var(--muted);
            border: 1px solid var(--border);
            grid-column: auto;
        }
        button:hover { filter: brightness(1.1); }
        .row-full { grid-column: 1 / -1; }
        .row-2 { grid-column: span 2; }
        .result {
            margin-bottom: 12px;
            padding: 10px 12px;
            border-radius: 6px;
            background: var(--panel);
            border: 1px solid var(--border);
        }
        .result.ok { border-left: 3px solid var(--good); }
        .result.bad { border-left: 3px solid var(--bad); }
        .result.warn { border-left: 3px solid var(--warn); }
        .result-line {
            display: flex;
            gap: 12px;
            align-items: center;
            font-family: ui-monospace, "SF Mono", Menlo, monospace;
            font-size: 12px;
            flex-wrap: wrap;
        }
        .result-line .status {
            font-weight: 700;
            padding: 1px 8px;
            border-radius: 3px;
        }
        .result-line .status.ok { background: var(--good); color: var(--bg); }
        .result-line .status.bad { background: var(--bad); color: white; }
        .result-line .status.warn { background: var(--warn); color: var(--bg); }
        .result pre {
            margin: 8px 0 0;
            padding: 8px;
            background: #020617;
            border: 1px solid var(--border);
            border-radius: 4px;
            font-size: 11px;
            color: #cbd5e1;
            overflow: auto;
            max-height: 100px;
        }
        .meta {
            color: var(--muted);
            font-size: 11px;
        }
        .source-banner {
            background: rgba(56, 189, 248, 0.08);
            border: 1px solid rgba(56, 189, 248, 0.4);
            border-radius: 4px;
            padding: 6px 10px;
            font-size: 12px;
            margin-bottom: 8px;
            color: var(--accent);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .source-banner a {
            color: var(--muted);
            font-size: 11px;
            text-decoration: underline;
        }
        details.json-details {
            grid-column: 1 / -1;
        }
        details.json-details summary {
            cursor: pointer;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--muted);
            font-weight: 600;
            padding: 4px 0;
        }
        details.json-details[open] summary { color: var(--accent); }
        .toolbar {
            display: flex;
            gap: 6px;
            margin: 4px 0;
            font-size: 11px;
        }
        .toolbar a {
            color: var(--muted);
            cursor: pointer;
            text-decoration: underline;
        }
    </style>
</head>
<body>

@if ($prefill['source_id'] ?? null)
    <div class="source-banner">
        <span>duplicating event from call <code>#{{ $prefill['source_id'] }}</code></span>
        <a href="{{ route('stripe-webhooks.debug.form') }}" target="_self">clear</a>
    </div>
@endif

@if ($result)
    @php
        $statusClass = $result['error']
            ? 'bad'
            : (($result['http_status'] >= 200 && $result['http_status'] < 300) ? 'ok' : ($result['http_status'] >= 400 ? 'bad' : 'warn'));
    @endphp
    <div class="result {{ $statusClass }}">
        <div class="result-line">
            @if ($result['error'])
                <span class="status bad">ERR</span>
                <span>{{ $result['error'] }}</span>
            @else
                <span class="status {{ $statusClass }}">{{ $result['http_status'] }}</span>
                <span><code>POST {{ $result['request_url'] }}</code></span>
                <span class="meta">event_id <code>{{ $result['event_id'] }}</code></span>
            @endif
        </div>
        @if (! $result['error'])
            <pre>{{ $result['http_body'] }}</pre>
            <div class="meta" style="margin-top: 6px;">
                {{ $result['event_type'] }} · config_key={{ $result['config_key'] }}
                @if ($result['tampered']) · <span style="color: var(--bad)">tampered</span>@endif
                @if ($result['timestamp_skew']) · skew {{ $result['timestamp_skew'] }}s @endif
            </div>
        @endif
    </div>
@endif

<form id="webhookForm" method="POST" action="{{ route('stripe-webhooks.debug.send') }}" target="_self">
    @csrf

    <label>
        event type
        <select name="event_type" data-storage="event_type">
            @foreach ($eventTypes as $t)
                <option value="{{ $t }}" @selected($prefill['event_type'] === $t)>{{ $t }}</option>
            @endforeach
            @if ($prefill['event_type'] && ! in_array($prefill['event_type'], $eventTypes, true))
                <option value="{{ $prefill['event_type'] }}" selected>{{ $prefill['event_type'] }}</option>
            @endif
        </select>
    </label>

    <label>
        config key
        <select name="config_key" data-storage="config_key">
            @foreach ($configKeys as $k)
                <option value="{{ $k }}" @selected($prefill['config_key'] === $k)>{{ $k }}</option>
            @endforeach
            @if ($prefill['config_key'] && ! in_array($prefill['config_key'], $configKeys, true))
                <option value="{{ $prefill['config_key'] }}" selected>{{ $prefill['config_key'] }}</option>
            @endif
        </select>
    </label>

    <label>
        force event_id <span class="meta">(idem.)</span>
        <input type="text" name="event_id" value="{{ $prefill['event_id'] }}" placeholder="evt_dbg_…">
    </label>

    <label>
        force object_id
        <input type="text" name="object_id" value="{{ $prefill['object_id'] }}" placeholder="auto">
    </label>

    <label>
        amount
        <input type="number" name="amount" value="{{ $prefill['amount'] }}" data-storage="amount">
    </label>

    <label>
        currency
        <input type="text" name="currency" value="{{ $prefill['currency'] }}" data-storage="currency">
    </label>

    <label class="row-2">
        customer
        <input type="text" name="customer" value="{{ $prefill['customer'] }}" data-storage="customer">
    </label>

    <label>
        timestamp skew (s)
        <input type="number" name="timestamp_skew" value="{{ $prefill['timestamp_skew'] }}" data-storage="timestamp_skew">
    </label>

    <label class="checkbox">
        <input type="checkbox" name="livemode" value="1" data-storage="livemode" @checked($prefill['livemode'])> livemode
    </label>

    <label class="checkbox">
        <input type="checkbox" name="tamper" value="1" @checked($prefill['tamper'])> tamper signature
    </label>

    <details class="json-details" @if ($prefill['data_json']) open @endif>
        <summary>custom data.object (JSON, overrides amount/currency/customer)</summary>
        <div class="toolbar">
            <a id="jsonClear">clear</a>
            <a id="jsonFormat">prettify</a>
        </div>
        <textarea name="data_json" placeholder='{"id":"pi_xxx","object":"payment_intent","amount":4900,…}'>{{ $prefill['data_json'] }}</textarea>
    </details>

    <button type="submit">send</button>
</form>

<script>
(function () {
    const STORAGE_PREFIX = 'stripe-webhooks-debug-form:';
    const form = document.getElementById('webhookForm');
    const sourceId = @json($prefill['source_id'] ?? null);

    // Restore from localStorage UNLESS we're in "duplicate from id" mode
    // (the explicit prefill from a source call must win).
    if (sourceId === null) {
        form.querySelectorAll('[data-storage]').forEach(el => {
            const key = STORAGE_PREFIX + el.dataset.storage;
            const stored = localStorage.getItem(key);
            if (stored === null) return;
            if (el.type === 'checkbox') {
                el.checked = stored === '1';
            } else {
                el.value = stored;
            }
        });
    }

    // Persist on change
    form.querySelectorAll('[data-storage]').forEach(el => {
        const key = STORAGE_PREFIX + el.dataset.storage;
        const persist = () => {
            if (el.type === 'checkbox') {
                localStorage.setItem(key, el.checked ? '1' : '0');
            } else {
                localStorage.setItem(key, el.value);
            }
        };
        el.addEventListener('change', persist);
        el.addEventListener('input', persist);
    });

    // JSON helpers
    const ta = form.querySelector('textarea[name="data_json"]');
    document.getElementById('jsonClear').addEventListener('click', () => { ta.value = ''; ta.focus(); reportHeight(); });
    document.getElementById('jsonFormat').addEventListener('click', () => {
        const v = ta.value.trim();
        if (!v) return;
        try {
            ta.value = JSON.stringify(JSON.parse(v), null, 2);
        } catch (e) {
            alert('invalid JSON: ' + e.message);
        }
    });

    // Tell parent how tall we are so it can size the iframe.
    function reportHeight() {
        const h = Math.max(
            document.body.scrollHeight,
            document.documentElement.scrollHeight
        );
        if (window.parent !== window) {
            window.parent.postMessage({ type: 'stripe-webhooks-debug-form:height', height: h }, '*');
        }
    }
    window.addEventListener('load', reportHeight);
    new ResizeObserver(reportHeight).observe(document.body);
    document.querySelectorAll('details.json-details').forEach(d =>
        d.addEventListener('toggle', reportHeight)
    );
})();
</script>

</body>
</html>
