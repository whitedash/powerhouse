@extends('emails.layout')

@section('content')
<h1 style="font-size:22px;font-weight:700;color:#0f172a;margin:0 0 8px;">Abandoned plan checkout</h1>
<p style="font-size:14px;color:#64748b;margin:0 0 24px;">A visitor started a Plans-widget purchase but never completed payment.</p>

<table style="width:100%;border-collapse:collapse;">
    <tr><td style="padding:8px 0;font-size:14px;color:#94a3b8;width:35%;border-bottom:1px solid #f8fafc;">Visitor</td><td style="padding:8px 0;font-size:14px;color:#334155;border-bottom:1px solid #f8fafc;">{{ $attempt->purchaser_name }} &lt;{{ $attempt->purchaser_email }}&gt;</td></tr>
    <tr><td style="padding:8px 0;font-size:14px;color:#94a3b8;border-bottom:1px solid #f8fafc;">Plan</td><td style="padding:8px 0;font-size:14px;color:#334155;border-bottom:1px solid #f8fafc;">{{ $productName ?? '—' }} / {{ $planName ?? '—' }}</td></tr>
    <tr><td style="padding:8px 0;font-size:14px;color:#94a3b8;border-bottom:1px solid #f8fafc;">Started</td><td style="padding:8px 0;font-size:14px;color:#334155;border-bottom:1px solid #f8fafc;">{{ $attempt->started_at->format('d M Y H:i') }}</td></tr>
</table>

<hr style="border:none;border-top:1px solid #f1f5f9;margin:24px 0;">
<p style="font-size:13px;line-height:1.6;color:#94a3b8;margin:0;">No account or invoice was created — the checkout never settled. Whether to follow up with the visitor is your call.</p>
@endsection
