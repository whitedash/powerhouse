@extends('emails.layout')

@section('content')
<h1 style="font-size:22px;font-weight:700;color:#0f172a;margin:0 0 8px;">Payment received</h1>
<p style="font-size:14px;color:#64748b;margin:0 0 24px;">Thank you, {{ $contactName ?? 'there' }}!</p>

<div style="background:#dcfce7;border-left:4px solid #22c55e;padding:16px;border-radius:4px;margin:0 0 24px;font-size:14px;color:#166534;">
    We've received your payment. Thank you.
</div>

<div style="font-size:28px;font-weight:700;color:#F59E0B;margin:0 0 16px;">£{{ number_format((float) $invoice->total, 2) }}</div>

<table style="width:100%;border-collapse:collapse;">
    <tr><td style="padding:8px 0;font-size:14px;color:#94a3b8;width:40%;border-bottom:1px solid #f8fafc;">Reference</td><td style="padding:8px 0;font-size:14px;color:#334155;border-bottom:1px solid #f8fafc;"><strong>{{ $invoice->number }}</strong></td></tr>
    <tr><td style="padding:8px 0;font-size:14px;color:#94a3b8;border-bottom:1px solid #f8fafc;">Date</td><td style="padding:8px 0;font-size:14px;color:#334155;border-bottom:1px solid #f8fafc;">{{ $paidAt }}</td></tr>
    <tr><td style="padding:8px 0;font-size:14px;color:#94a3b8;border-bottom:1px solid #f8fafc;">Amount</td><td style="padding:8px 0;font-size:14px;color:#334155;border-bottom:1px solid #f8fafc;">£{{ number_format((float) $invoice->total, 2) }}</td></tr>
</table>

<hr style="border:none;border-top:1px solid #f1f5f9;margin:24px 0;">
<p style="font-size:13px;line-height:1.6;color:#94a3b8;margin:0;">Your invoice is attached for your records.</p>
@endsection
