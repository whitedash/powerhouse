@extends('emails.layout')

@section('content')
<h1 style="font-size:22px;font-weight:700;color:#0f172a;margin:0 0 8px;">Proposal {{ $proposal->reference }}</h1>
<p style="font-size:14px;color:#64748b;margin:0 0 24px;">{{ $proposal->title }}</p>

<p style="font-size:15px;line-height:1.6;color:#334155;margin:0 0 16px;">Hi {{ $contactName ?? 'there' }},</p>
<p style="font-size:15px;line-height:1.6;color:#334155;margin:0 0 16px;">Please find your proposal attached. You can review and accept it online using the link below.</p>

<div style="font-size:28px;font-weight:700;color:#F59E0B;margin:0 0 8px;">£{{ number_format((float) $proposal->total, 2) }}</div>
@if($validUntil)
<p style="font-size:13px;color:#94a3b8;margin:0 0 16px;">Valid until {{ $validUntil }}</p>
@endif

@if($acceptUrl)
<a href="{{ $acceptUrl }}" style="display:inline-block;background:#F59E0B;color:#fff;padding:12px 28px;border-radius:6px;text-decoration:none;font-weight:600;font-size:15px;margin:8px 0 24px;">View and accept online &rarr;</a>
@endif

<hr style="border:none;border-top:1px solid #f1f5f9;margin:24px 0;">
<p style="font-size:13px;line-height:1.6;color:#94a3b8;margin:0;">The proposal PDF is attached. Questions? Just reply to this email.</p>
@endsection
