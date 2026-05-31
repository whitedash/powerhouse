@extends('emails.layout')

@section('content')
<h1 style="font-size:22px;font-weight:700;color:#0f172a;margin:0 0 8px;">Invoice {{ $invoice->number }}</h1>
<p style="font-size:14px;color:#64748b;margin:0 0 24px;">Due {{ $dueDate ?? '—' }}</p>

<p style="font-size:15px;line-height:1.6;color:#334155;margin:0 0 16px;">Hi {{ $contactName ?? 'there' }},</p>
<p style="font-size:15px;line-height:1.6;color:#334155;margin:0 0 16px;">Please find your invoice attached.</p>

<div style="font-size:28px;font-weight:700;color:#F59E0B;margin:0 0 16px;">£{{ number_format((float) $invoice->total, 2) }}</div>

<table style="width:100%;border-collapse:collapse;">
    <tr><td style="padding:8px 0;font-size:14px;color:#94a3b8;width:40%;border-bottom:1px solid #f8fafc;">Invoice number</td><td style="padding:8px 0;font-size:14px;color:#334155;border-bottom:1px solid #f8fafc;">{{ $invoice->number }}</td></tr>
    <tr><td style="padding:8px 0;font-size:14px;color:#94a3b8;border-bottom:1px solid #f8fafc;">Issue date</td><td style="padding:8px 0;font-size:14px;color:#334155;border-bottom:1px solid #f8fafc;">{{ $invoice->issue_date?->format('d M Y') }}</td></tr>
    <tr><td style="padding:8px 0;font-size:14px;color:#94a3b8;border-bottom:1px solid #f8fafc;">Due date</td><td style="padding:8px 0;font-size:14px;color:#334155;border-bottom:1px solid #f8fafc;">{{ $dueDate ?? '—' }}</td></tr>
    <tr><td style="padding:8px 0;font-size:14px;color:#94a3b8;border-bottom:1px solid #f8fafc;">Payment reference</td><td style="padding:8px 0;font-size:14px;color:#334155;border-bottom:1px solid #f8fafc;"><strong>{{ $invoice->number }}</strong></td></tr>
</table>

@if(!empty($paymentDetails))
<hr style="border:none;border-top:1px solid #f1f5f9;margin:24px 0;">
<p style="font-size:15px;line-height:1.6;color:#334155;margin:0 0 16px;font-weight:600;">Payment details:</p>
<table style="width:100%;border-collapse:collapse;">
    @foreach($paymentDetails as $key => $val)
    <tr><td style="padding:8px 0;font-size:14px;color:#94a3b8;width:40%;border-bottom:1px solid #f8fafc;">{{ $key }}</td><td style="padding:8px 0;font-size:14px;color:#334155;border-bottom:1px solid #f8fafc;">{{ $val }}</td></tr>
    @endforeach
</table>
@endif

<hr style="border:none;border-top:1px solid #f1f5f9;margin:24px 0;">
<p style="font-size:13px;line-height:1.6;color:#94a3b8;margin:0;">The invoice PDF is attached to this email. If you have any questions, please reply to this email.</p>
@endsection
