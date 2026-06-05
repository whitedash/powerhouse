@extends('emails.layout')

@section('content')
<h1 style="font-size:22px;font-weight:700;color:#0f172a;margin:0 0 8px;">New support ticket #{{ $ticket->id }}</h1>
<p style="font-size:14px;color:#64748b;margin:0 0 24px;">{{ $ticket->subject }}</p>

<table style="width:100%;border-collapse:collapse;">
    <tr><td style="padding:8px 0;font-size:14px;color:#94a3b8;width:35%;border-bottom:1px solid #f8fafc;">From</td><td style="padding:8px 0;font-size:14px;color:#334155;border-bottom:1px solid #f8fafc;">{{ $fromName }}@if($fromEmail) &lt;{{ $fromEmail }}&gt;@endif</td></tr>
    <tr><td style="padding:8px 0;font-size:14px;color:#94a3b8;border-bottom:1px solid #f8fafc;">Priority</td><td style="padding:8px 0;font-size:14px;color:#334155;border-bottom:1px solid #f8fafc;">{{ ucfirst($ticket->priority) }}</td></tr>
    <tr><td style="padding:8px 0;font-size:14px;color:#94a3b8;border-bottom:1px solid #f8fafc;">Type</td><td style="padding:8px 0;font-size:14px;color:#334155;border-bottom:1px solid #f8fafc;">{{ $ticket->customer_id ? 'Customer' : 'Guest (public form)' }}</td></tr>
</table>

@if($firstMessage)
<p style="font-size:13px;color:#94a3b8;margin:20px 0 6px;">Message</p>
<div style="font-size:14px;line-height:1.6;color:#334155;background:#f8fafc;border-radius:6px;padding:12px 14px;white-space:pre-wrap;">{{ \Illuminate\Support\Str::limit($firstMessage, 800) }}</div>
@endif

<a href="{{ $ticketUrl }}" style="display:inline-block;background:#F59E0B;color:#fff;padding:12px 28px;border-radius:6px;text-decoration:none;font-weight:600;font-size:15px;margin:20px 0 8px;">View ticket &rarr;</a>
@endsection
