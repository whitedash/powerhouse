@extends('emails.layout')

@section('content')
<h1 style="font-size:22px;font-weight:700;color:#0f172a;margin:0 0 8px;">Action required</h1>
<p style="font-size:14px;color:#64748b;margin:0 0 24px;">Your {{ $productName }} account</p>

<div style="background:#fee2e2;border-left:4px solid #ef4444;padding:16px;border-radius:4px;margin:0 0 24px;font-size:14px;color:#991b1b;">
    Your account will be suspended in 24 hours unless payment is received.
</div>

<div style="font-size:28px;font-weight:700;color:#F59E0B;margin:0 0 4px;">£{{ number_format((float) $amount, 2) }}</div>
<p style="font-size:13px;color:#94a3b8;margin:0 0 16px;">Outstanding · Invoice {{ $invoiceNumber }}@if($dueDate) · due {{ $dueDate }}@endif</p>

<a href="{{ $payPortalUrl }}" style="display:inline-block;background:#F59E0B;color:#fff;padding:12px 28px;border-radius:6px;text-decoration:none;font-weight:600;font-size:15px;margin:8px 0 24px;">Pay now to avoid suspension &rarr;</a>

<hr style="border:none;border-top:1px solid #f1f5f9;margin:24px 0;">
<p style="font-size:13px;line-height:1.6;color:#94a3b8;margin:0;">If you have already paid, please ignore this email.</p>
@endsection
