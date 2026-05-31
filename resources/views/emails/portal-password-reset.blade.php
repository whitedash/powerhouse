@extends('emails.layout')

@section('content')
<h1 style="font-size:22px;font-weight:700;color:#0f172a;margin:0 0 8px;">Reset your password</h1>
<p style="font-size:14px;color:#64748b;margin:0 0 24px;">Use the secure link below</p>

<p style="font-size:15px;line-height:1.6;color:#334155;margin:0 0 16px;">Hi {{ $name ?? 'there' }}, we received a request to reset your password. Click the button below to choose a new one.</p>

<a href="{{ $resetUrl }}" style="display:inline-block;background:#F59E0B;color:#fff;padding:12px 28px;border-radius:6px;text-decoration:none;font-weight:600;font-size:15px;margin:8px 0 24px;">Reset password &rarr;</a>

<div style="background:#fef3c7;border-left:4px solid #F59E0B;padding:16px;border-radius:4px;margin:0;font-size:14px;color:#92400e;">
    This link expires shortly. If you didn't request this, you can safely ignore this email — your password won't change.
</div>
@endsection
