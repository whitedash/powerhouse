@extends('emails.layout')

@section('content')
<h1 style="font-size:22px;font-weight:700;color:#0f172a;margin:0 0 8px;">Account suspended</h1>
<p style="font-size:14px;color:#64748b;margin:0 0 24px;">Your {{ $productName }} account</p>

<div style="background:#fee2e2;border-left:4px solid #ef4444;padding:16px;border-radius:4px;margin:0 0 24px;font-size:14px;color:#991b1b;">
    Your account has been suspended due to an outstanding balance.
</div>

<p style="font-size:15px;line-height:1.6;color:#334155;margin:0 0 16px;">Hi {{ $customerName }}, to restore access please settle your outstanding balance.</p>

<a href="{{ $payPortalUrl }}" style="display:inline-block;background:#F59E0B;color:#fff;padding:12px 28px;border-radius:6px;text-decoration:none;font-weight:600;font-size:15px;margin:8px 0 24px;">Pay now to restore access &rarr;</a>

<hr style="border:none;border-top:1px solid #f1f5f9;margin:24px 0;">
<p style="font-size:13px;line-height:1.6;color:#94a3b8;margin:0;">Need help? Contact us at <a href="mailto:{{ $supportEmail }}" style="color:#F59E0B;">{{ $supportEmail }}</a>.</p>
@endsection
