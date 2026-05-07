@extends('stripe-webhooks::debug.layout')

@section('title', 'Stripe Webhooks · Debug')

@section('content')
    @php $statuses = ['received', 'processing', 'processed', 'failed', 'dead_letter']; @endphp

    @php
        $iframeSrc = $duplicateFromId
            ? route('stripe-webhooks.debug.form', ['from' => $duplicateFromId])
            : route('stripe-webhooks.debug.form');
        $triggerScenarios = [
            ['scenario' => 'success',            'label' => 'Successful payment',  'class' => 'outcome-success', 'icon' => '✓'],
            ['scenario' => 'declined',           'label' => 'Declined card',       'class' => 'outcome-failure', 'icon' => '✗'],
            ['scenario' => 'insufficient_funds', 'label' => 'Insufficient funds',  'class' => 'outcome-failure', 'icon' => '✗'],
            ['scenario' => 'requires_action',    'label' => 'Requires 3DS',        'class' => 'outcome-pending', 'icon' => '⏳'],
            ['scenario' => 'refund_last',        'label' => 'Refund last paid',    'class' => 'outcome-failure', 'icon' => '↻'],
            ['scenario' => 'customer_created',   'label' => 'Create customer',     'class' => 'outcome-neutral', 'icon' => '○'],
        ];
    @endphp

    @if (session('trigger_message') || session('trigger_error'))
        <div class="card" style="margin-bottom: 12px; padding: 12px 16px; font-size: 13px; border-left: 4px solid {{ session('trigger_error') ? 'var(--bad)' : 'var(--good)' }}; background: {{ session('trigger_error') ? 'rgba(239, 68, 68, 0.06)' : 'rgba(34, 197, 94, 0.06)' }};">
            <strong style="color: {{ session('trigger_error') ? 'var(--bad)' : 'var(--good)' }};">
                {{ session('trigger_error') ? '✗ Trigger failed' : '✓ Triggered' }}:
            </strong>
            {{ session('trigger_error') ?? session('trigger_message') }}
            @if (session('trigger_message'))
                <span class="muted"> · webhook(s) should land within seconds.</span>
            @endif
        </div>
    @endif

    <div class="card" style="margin-bottom: 16px; padding: 14px 18px;">
        <h2 style="margin-bottom: 10px;">Trigger a real Stripe event</h2>
        <p class="muted" style="font-size: 12px; margin: 0 0 12px;">
            Each button hits the Stripe API to create the resource that produces the desired webhook(s).
            Stripe then delivers them through your `stripe listen` (or configured endpoint) — full pipeline.
            Distinct from the iframe form below, which only signs payloads locally.
        </p>
        <form method="POST" action="{{ route('stripe-webhooks.debug.trigger') }}" style="display: flex; flex-wrap: wrap; gap: 8px; margin: 0;">
            @csrf
            @foreach ($triggerScenarios as $s)
                <button type="submit" name="scenario" value="{{ $s['scenario'] }}"
                        class="trigger-btn {{ $s['class'] }}"
                        title="Scenario: {{ $s['scenario'] }}">
                    {{ $s['icon'] }} {{ $s['label'] }}
                </button>
            @endforeach
        </form>
    </div>

    <div class="card" style="margin-bottom: 16px; padding: 0; overflow: hidden;">
        <iframe
            id="webhookFormFrame"
            name="webhookFormFrame"
            src="{{ $iframeSrc }}"
            title="Send test webhook"
            style="width: 100%; min-height: 240px; border: 0; display: block; background: var(--panel);"
        ></iframe>
    </div>

    <div id="liveContent">
    <div class="stat-row">
        @foreach ($statuses as $s)
            <div class="stat">
                <div class="label">{{ str_replace('_', ' ', $s) }}</div>
                <div class="value">{{ (int) ($totals[$s] ?? 0) }}</div>
            </div>
        @endforeach
    </div>

    <form method="GET" class="filters">
        <select name="status">
            <option value="">all statuses</option>
            @foreach ($statuses as $s)
                <option value="{{ $s }}" @selected($filters['status'] === $s)>{{ $s }}</option>
            @endforeach
        </select>
        <input type="text" name="type" value="{{ $filters['type'] }}" placeholder="event type contains…">
        <input type="text" name="config_key" value="{{ $filters['config_key'] }}" placeholder="config key">
        <button type="submit">apply</button>
        @if (array_filter($filters))
            <a class="badge" style="background: var(--grey); color: white;" href="{{ route('stripe-webhooks.debug.index') }}">clear</a>
        @endif
    </form>

    @if ($calls->isEmpty())
        <div class="empty">
            no webhook calls yet · {{ now()->toDateTimeString() }}
        </div>
    @else
        <table>
            <thead>
            <tr>
                <th>#</th>
                <th>event id</th>
                <th>type</th>
                <th>status</th>
                <th>source</th>
                <th>config</th>
                <th>handlers</th>
                <th>received</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @foreach ($calls as $call)
                @php
                    $rowCounts = $runCounts[$call->id] ?? collect();
                    $url = route('stripe-webhooks.debug.show', $call->id);
                    $dup = route('stripe-webhooks.debug.index', ['duplicate' => $call->id]);
                    $isNoOp = $call->status === 'processed' && $rowCounts->isEmpty();
                    $payment = \TransistorizedCmd\StripeToolkit\Webhooks\Support\PaymentOutcome::fromPayload($call->payload->toArray());
                @endphp
                <tr>
                    <td><a href="{{ $url }}">{{ $call->id }}</a></td>
                    <td><a href="{{ $url }}"><code>{{ $call->stripe_event_id }}</code></a></td>
                    <td>
                        <code>{{ $call->type }}</code>
                        @if ($payment->isSuccess())
                            <span class="outcome-success" title="Payment succeeded according to the payload">paid</span>
                        @elseif ($payment->isFailure())
                            <span class="outcome-failure" title="{{ $payment->message ?? 'Payment failure — see detail page' }}">{{ $payment->code ?? 'not paid' }}</span>
                        @elseif ($payment->isInFlight())
                            <span class="outcome-pending" title="{{ $payment->message ?? 'Still processing — see detail page' }}">in flight</span>
                        @else
                            <span class="outcome-neutral" title="Not a payment-bearing event (informational, configuration, customer lifecycle, etc.)">n/a</span>
                        @endif
                    </td>
                    <td>
                        @if ($isNoOp)
                            <span class="badge no-op" title="No handler matched — kit persisted for audit, no business action">no-op</span>
                        @else
                            <span class="badge {{ $call->status }}">{{ $call->status }}</span>
                        @endif
                    </td>
                    <td>{{ $call->source }}</td>
                    <td>{{ $call->config_key ?? '—' }}</td>
                    <td>
                        @forelse ($rowCounts as $r)
                            <span class="badge {{ $r->status }}" title="{{ $r->status }}">{{ $r->total }}</span>
                        @empty
                            <span style="color: var(--muted)">—</span>
                        @endforelse
                    </td>
                    <td>{{ $call->received_at?->diffForHumans() ?? '—' }}</td>
                    <td>
                        <a href="{{ $dup }}"
                           title="Prefill the form above with this payload (new event_id will be generated)">duplicate</a>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>

        <div class="pagination">
            {{ $calls->links() }}
        </div>
    @endif
    </div>{{-- /#liveContent --}}

    <script>
        (function () {
            const frame = document.getElementById('webhookFormFrame');
            const interval = {{ (int) ($autoRefresh ?? 5) * 1000 }};

            // Iframe self-resize via postMessage from the form.
            window.addEventListener('message', e => {
                if (! e.data || e.data.type !== 'stripe-webhooks-debug-form:height') return;
                const h = Math.max(220, Math.min(1200, e.data.height + 20));
                frame.style.height = h + 'px';
            });

            // Live refresh: replace #liveContent only, leave the iframe alone.
            async function refresh() {
                try {
                    const r = await fetch(window.location.href, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                    if (! r.ok) return;
                    const html = await r.text();
                    const doc = new DOMParser().parseFromString(html, 'text/html');
                    const next = doc.getElementById('liveContent');
                    const here = document.getElementById('liveContent');
                    if (next && here) here.innerHTML = next.innerHTML;
                } catch (e) {
                    // network blip; try again next tick
                }
            }

            if (interval > 0) setInterval(refresh, interval);
        })();
    </script>
@endsection
