@extends('stripe-webhooks::debug.layout')

@section('title', 'Webhook · '.$call->stripe_event_id)

@section('head')
    @if (! $call->isTerminal() && ($autoRefresh ?? 0) > 0)
        <meta http-equiv="refresh" content="{{ $autoRefresh }}">
    @endif
@endsection

@section('content')
    @php
        $payloadJson = json_encode($call->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    @endphp

    <a href="{{ route('stripe-webhooks.debug.index') }}" class="back">&larr; all webhooks</a>
    <a href="{{ route('stripe-webhooks.debug.index', ['duplicate' => $call->id]) }}" class="back" style="float: right;">
        duplicate event &rarr;
    </a>

    <div class="grid-2">
        <div class="card">
            <h2>event</h2>
            <dl class="kv">
                <dt>id</dt>
                <dd><code>{{ $call->stripe_event_id }}</code></dd>
                <dt>type</dt>
                <dd><code>{{ $call->type }}</code></dd>
                <dt>status</dt>
                <dd>
                    @php
                        $isNoOp = $call->status === 'processed' && $call->handlerRuns->isEmpty();
                    @endphp
                    @if ($isNoOp)
                        <span class="badge no-op" title="The kit persisted this event but no handler was registered for its type — no business action was taken.">no-op</span>
                    @else
                        <span class="badge {{ $call->status }}">{{ $call->status }}</span>
                    @endif
                </dd>
                <dt>source</dt>
                <dd>{{ $call->source }}</dd>
                <dt>livemode</dt>
                <dd>{{ $call->livemode ? 'true' : 'false' }}</dd>
                <dt>api_version</dt>
                <dd>{{ $call->api_version ?? '—' }}</dd>
                <dt>config_key</dt>
                <dd>{{ $call->config_key ?? '—' }}</dd>
                <dt>related_object_id</dt>
                <dd>{{ $call->related_object_id ?? '—' }}</dd>
            </dl>
        </div>

        <div class="card">
            <h2>timing</h2>
            <dl class="kv">
                <dt>received_at</dt>
                <dd>{{ $call->received_at }}</dd>
                <dt>processed_at</dt>
                <dd>{{ $call->processed_at ?? '—' }}</dd>
                <dt>created_at</dt>
                <dd>{{ $call->created_at }}</dd>
                <dt>updated_at</dt>
                <dd>{{ $call->updated_at }}</dd>
                @if ($call->received_at && $call->processed_at)
                    <dt>e2e duration</dt>
                    <dd>{{ $call->received_at->diffInMilliseconds($call->processed_at) }} ms</dd>
                @endif
            </dl>
        </div>
    </div>

    <div class="card" style="margin-bottom: 16px;">
        <h2>handler runs ({{ $call->handlerRuns->count() }})</h2>
        @if ($call->handlerRuns->isEmpty())
            <div style="background: rgba(100, 116, 139, 0.08); border: 1px dashed var(--border); border-radius: 6px; padding: 12px 14px; font-size: 13px; color: var(--muted);">
                <strong style="color: var(--text);">No handler ran.</strong>
                Nothing in <code>config/stripe-webhooks.php → handlers</code> nor any
                <code>#[StripeEvent('{{ $call->type }}')]</code>-tagged class matched this type.
                The kit verified the signature, persisted the call for audit, and stopped.
                <strong style="color: var(--text);">No business state changed.</strong>
                Register a handler if you want this event to do something.
            </div>
        @else
            <table>
                <thead>
                <tr>
                    <th>handler</th>
                    <th>status</th>
                    <th>attempts</th>
                    <th>started</th>
                    <th>finished</th>
                    <th>error</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($call->handlerRuns as $run)
                    <tr>
                        <td><code>{{ $run->handler_class }}</code></td>
                        <td><span class="badge {{ $run->status }}">{{ $run->status }}</span></td>
                        <td>{{ $run->attempts }}</td>
                        <td>{{ $run->started_at?->diffForHumans() ?? '—' }}</td>
                        <td>{{ $run->finished_at?->diffForHumans() ?? '—' }}</td>
                        <td>
                            @if ($run->last_error)
                                <details>
                                    <summary style="color: var(--bad)">{{ $run->last_error_category ?? 'error' }}</summary>
                                    <pre>{{ $run->last_error }}</pre>
                                </details>
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <details open>
        <summary>raw payload</summary>
        <pre>{{ $payloadJson }}</pre>
    </details>
@endsection
