@extends('emails.layout')

@section('content')
@php
    $outstanding = (float) $invoice->total - (float) ($invoice->amount_paid ?? 0);
    $payUrl = $payLink ?: $payPortalUrl;
@endphp

@if($final)
<h1 style="font-size:22px;font-weight:700;color:#0f172a;margin:0 0 8px;">We couldn't collect your payment</h1>
<p style="font-size:14px;color:#64748b;margin:0 0 24px;">Invoice {{ $invoice->number }} · due {{ $dueDate ?? '—' }}</p>

<div style="font-size:15px;line-height:1.6;color:#334155;margin:0 0 16px;">
    Hi {{ $contactName ?? 'there' }},<br><br>
    We've tried {{ $maxAttempts }} times to take payment for invoice {{ $invoice->number }} and each attempt was declined.
    This was the final automatic attempt. Please settle the balance to avoid your services being suspended.
</div>
@else
<h1 style="font-size:22px;font-weight:700;color:#0f172a;margin:0 0 8px;">Your payment didn't go through</h1>
<p style="font-size:14px;color:#64748b;margin:0 0 24px;">Invoice {{ $invoice->number }} · due {{ $dueDate ?? '—' }}</p>

<div style="font-size:15px;line-height:1.6;color:#334155;margin:0 0 16px;">
    Hi {{ $contactName ?? 'there' }},<br><br>
    We tried to charge your saved card for invoice {{ $invoice->number }} (attempt {{ $attempt }} of {{ $maxAttempts }}) but it was declined.
    We'll automatically try again over the next few days — or you can pay now using the button below.
</div>
@endif

<div style="font-size:28px;font-weight:700;color:#F59E0B;margin:0 0 8px;">£{{ number_format($outstanding, 2) }}</div>
<p style="font-size:13px;color:#94a3b8;margin:0 0 16px;">Outstanding balance</p>

@if(!empty($payUrl))
<a href="{{ $payUrl }}" style="display:inline-block;background:#F59E0B;color:#fff;padding:12px 28px;border-radius:6px;text-decoration:none;font-weight:600;font-size:15px;margin:8px 0 24px;">Pay now &rarr;</a>
@endif

<hr style="border:none;border-top:1px solid #f1f5f9;margin:24px 0;">
<p style="font-size:13px;line-height:1.6;color:#94a3b8;margin:0;">If your card details have changed, please update them in your portal. If you've already paid, you can ignore this email.</p>
@endsection
