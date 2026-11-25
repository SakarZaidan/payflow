@php
use App\PayFlow\Gateway;
$labels = ['payments' => 'Payments', 'detail' => 'Payment detail', 'settlement' => 'Settlement', 'login' => 'Sign in'];
$label = fn ($text) => ucfirst(str_replace('_', ' ', $text));
$time = fn ($stamp) => \Illuminate\Support\Carbon::parse($stamp, 'UTC')->setTimezone('Asia/Kuwait')->format('d M, H:i');
$money = fn ($amount) => $amount === null ? '—' : Gateway::decimal((int) $amount);
$short = fn ($id) => '…'.substr($id, -12);
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ $labels[$page] }} · PayFlow</title>
    <link rel="icon" href="/brand/payflow-mark.png">
    <link rel="stylesheet" href="/console.css">
</head>
<body>
<a class="skip" href="#content">Skip to content</a>
@if ($page === 'login')
    <main id="content" class="login">
        <div class="login-card">
            <a class="brand login-brand" href="/login"><img src="/brand/payflow-mark.png" alt=""> <span>PayFlow</span></a>
            <h1>Operator sign in</h1>
            <form method="post" action="/login">
                @csrf
                <label for="password">Console password</label>
                <input id="password" name="password" type="password" required autocomplete="current-password" @error('password') aria-describedby="password-error" @enderror>
                @error('password')<p id="password-error" class="error">{{ $message }}</p>@enderror
                <button class="button" type="submit">Sign in</button>
            </form>
        </div>
    </main>
@else
    <aside class="sidebar">
        <a class="brand" href="/" aria-label="PayFlow home"><span class="brand-icon"><img src="/brand/payflow-mark.png" alt=""></span>PayFlow</a>
        <nav aria-label="Workspace">
            <a href="/" @if(in_array($page, ['payments','detail'], true)) aria-current="page" @endif>
                <span class="nav-icon" aria-hidden="true">▤</span> Payments
            </a>
            <a href="/settlement" @if($page === 'settlement') aria-current="page" @endif>
                <span class="nav-icon" aria-hidden="true">⇄</span> Settlement
            </a>
        </nav>
        <form method="post" action="/logout" class="signout">@csrf<button type="submit">Sign out</button></form>
    </aside>
    <main class="workspace" id="content">
        <header class="topline"><span>Operations console</span><span>Local · Kuwait time</span></header>
        <div class="content">
        @if ($page === 'payments')
            <header class="page-header"><h1>Payments</h1><p>Track KNET and card payments from submission to capture.</p></header>
            <dl class="metrics">
                <div><dt>Captured volume</dt><dd>{{ $money($metrics->captured ?? 0) }} <span>KWD</span></dd></div>
                <div><dt>Payment attempts</dt><dd>{{ number_format($metrics->total) }}</dd></div>
                <div><dt>Declined</dt><dd>{{ number_format($metrics->declined ?? 0) }}</dd></div>
            </dl>
            <section class="panel" aria-labelledby="history-title">
                <div class="panel-header"><h2 id="history-title">Payment history</h2>
                    <form class="filters" method="get">
                        <label>Status <select name="state">
                            <option value="">All statuses</option>
                            @foreach (['captured','declined','pending','queued','needs_review'] as $state)
                                <option value="{{ $state }}" @selected(($filter['state'] ?? '') === $state)>{{ $label($state) }}</option>
                            @endforeach
                        </select></label>
                        <label>Method <select name="rail">
                            <option value="">All methods</option>
                            @foreach (['knet' => 'KNET', 'card' => 'Card'] as $rail => $method)
                                <option value="{{ $rail }}" @selected(($filter['rail'] ?? '') === $rail)>{{ $method }}</option>
                            @endforeach
                        </select></label>
                        <button class="button secondary" type="submit">Apply</button>
                    </form>
                </div>
                <div class="table-scroll"><table>
                    <thead><tr><th scope="col">Payment</th><th scope="col">Method</th><th scope="col">Status</th><th scope="col" class="created-column">Created</th><th scope="col" class="numeric">Amount · KWD</th></tr></thead>
                    <tbody>
                    @forelse ($payments as $row)
                        <tr><td><a class="mono" href="/payments/{{ $row->id }}">{{ $short($row->id) }}</a></td>
                            <td>{{ $row->rail === 'knet' ? 'KNET' : 'Card' }}</td>
                            <td><span class="status {{ $row->state }}">{{ $label($row->state) }}</span></td>
                            <td class="created-column">{{ $time($row->created_at) }}</td>
                            <td class="numeric">{{ $money($row->amount) }}</td></tr>
                    @empty
                        <tr><td colspan="5" class="empty">No payments match these filters.</td></tr>
                    @endforelse
                    </tbody>
                </table></div>
                @include('pagination', ['paginator' => $payments])
            </section>
        @elseif ($page === 'detail')
            <a class="back" href="/">← Payments</a>
            <header class="page-header"><h1>Payment detail</h1><p class="mono break">{{ $payment->id }}</p></header>
            <dl class="metrics">
                <div><dt>Amount</dt><dd>{{ $money($payment->amount) }} <span>KWD</span></dd></div>
                <div><dt>Method</dt><dd>{{ $payment->rail === 'knet' ? 'KNET' : 'Card' }}</dd></div>
                <div><dt>Status</dt><dd><span class="status {{ $payment->state }}">{{ $label($payment->state) }}</span></dd></div>
            </dl>
            <div class="detail-grid">
                <section class="panel"><div class="panel-header"><h2>Activity</h2></div>
                    <ol class="timeline">
                        @foreach ($events as $event)
                        <li><strong>{{ $label($event->state) }}</strong><span>{{ $time($event->created_at) }} · {{ $event->source === 'api' ? 'API' : $label($event->source) }}</span><small class="mono">Event {{ $event->id }}</small></li>
                        @endforeach
                    </ol>
                </section>
                <section class="panel"><div class="panel-header"><h2>Provider &amp; refunds</h2></div>
                    <dl class="meta"><dt>Gateway reference</dt><dd class="mono break">{{ $payment->provider_id ?? '—' }}</dd></dl>
                    @forelse ($refunds as $refund)
                    <div class="refund"><span class="mono">{{ $money($refund->amount) }} KWD</span><span class="status {{ $refund->state }}">{{ $label($refund->state) }}</span><span class="mono break">{{ $refund->id }}</span></div>
                    @empty
                    <p class="empty">No refunds recorded.</p>
                    @endforelse
                    @include('pagination', ['paginator' => $refunds])
                </section>
            </div>
        @else
            <header class="page-header"><h1>Settlement</h1><p>Compare gateway statements with PayFlow’s ledger.</p></header>
            @if ($run)
                <dl class="metrics">
                    <div><dt>Business date</dt><dd>{{ \Illuminate\Support\Carbon::parse($run->business_date)->format('d M Y') }}</dd></div>
                    <div><dt>Exceptions</dt><dd>{{ $open }}</dd></div>
                    <div><dt>Run status</dt><dd><span class="status {{ $run->state }}">{{ $label($run->state) }}</span></dd></div>
                </dl>
                <section class="panel">
                    <div class="panel-header"><div><h2>Statement review</h2><p class="caption">Run {{ $run->id }} · {{ $label($run->gateway) }}</p></div>
                        @if ($runs->count() > 1)
                        <nav class="run-list" aria-label="Recent runs">@foreach ($runs->take(5) as $recent)
                            <a href="/settlement/{{ $recent->id }}" @if($recent->id === $run->id) aria-current="page" @endif>{{ $recent->business_date }} <span class="sr-only">run {{ $recent->id }}</span></a>
                        @endforeach</nav>
                        @endif
                    </div>
                    <div class="table-scroll"><table>
                        <thead><tr><th scope="col">Provider reference</th><th scope="col">Result</th><th scope="col" class="numeric">Ledger · KWD</th><th scope="col" class="numeric">Statement · KWD</th></tr></thead>
                        <tbody>@forelse ($items as $item)
                            @php($paymentId = $item->kind === 'refund' ? $item->payment_id : $item->operation_id)
                            <tr><td class="mono">@if ($paymentId)<a href="/payments/{{ $paymentId }}">{{ $short($item->provider_id) }}</a>@else{{ $item->provider_id }}@endif</td>
                                <td><span class="status {{ $item->result }}">{{ $label($item->result) }}</span></td>
                                <td class="numeric">{{ $money($item->expected) }}</td><td class="numeric">{{ $money($item->reported) }}</td></tr>
                        @empty
                            <tr><td colspan="4" class="empty">{{ $run->state === 'waiting_for_report' ? 'Waiting for a gateway statement.' : 'This statement contains no movements.' }}</td></tr>
                        @endforelse</tbody>
                    </table></div>
                    @include('pagination', ['paginator' => $items])
                </section>
            @else
                <p class="panel empty">There are no settlement runs yet.</p>
            @endif
        @endif
        </div>
    </main>
@endif
</body>
</html>
