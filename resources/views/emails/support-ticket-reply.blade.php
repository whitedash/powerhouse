@extends('emails.layout')

@section('content')
<h1 style="font-size:22px;font-weight:700;color:#0f172a;margin:0 0 8px;">Re: #{{ $ticket->id }}</h1>
<p style="font-size:14px;color:#64748b;margin:0 0 24px;">{{ $ticket->subject }}</p>

<div style="font-size:15px;line-height:1.6;color:#334155;margin:0 0 24px;white-space:pre-line;">{{ $messageBody }}</div>

<a href="{{ $portalUrl }}" style="display:inline-block;background:#F59E0B;color:#fff;padding:12px 28px;border-radius:6px;text-decoration:none;font-weight:600;font-size:15px;margin:8px 0 24px;">View full conversation &rarr;</a>

<hr style="border:none;border-top:1px solid #f1f5f9;margin:24px 0;">
<p style="font-size:13px;line-height:1.6;color:#94a3b8;margin:0;">You can reply directly to this email to respond.</p>
@endsection
