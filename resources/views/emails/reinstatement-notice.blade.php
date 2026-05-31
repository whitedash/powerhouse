@extends('emails.layout')

@section('content')
<h1 style="font-size:22px;font-weight:700;color:#0f172a;margin:0 0 8px;">Account reinstated</h1>
<p style="font-size:14px;color:#64748b;margin:0 0 24px;">Your {{ $productName }} account</p>

<div style="background:#dcfce7;border-left:4px solid #22c55e;padding:16px;border-radius:4px;margin:0 0 24px;font-size:14px;color:#166534;">
    Great news — your account has been reinstated and is now active.
</div>

<p style="font-size:15px;line-height:1.6;color:#334155;margin:0 0 16px;">Hi {{ $customerName }}, thank you. Your access has been fully restored.</p>

<a href="{{ $accessUrl }}" style="display:inline-block;background:#F59E0B;color:#fff;padding:12px 28px;border-radius:6px;text-decoration:none;font-weight:600;font-size:15px;margin:8px 0 8px;">Access your account &rarr;</a>
@endsection
