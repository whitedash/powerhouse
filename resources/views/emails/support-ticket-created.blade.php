@extends('emails.layout')

@section('content')
<h1 style="font-size:22px;font-weight:700;color:#0f172a;margin:0 0 8px;">Support ticket #{{ $ticket->id }}</h1>
<p style="font-size:14px;color:#64748b;margin:0 0 24px;">{{ $ticket->subject }}</p>

<p style="font-size:15px;line-height:1.6;color:#334155;margin:0 0 16px;">We've received your support request and will respond within our SLA window.</p>

<table style="width:100%;border-collapse:collapse;">
    <tr><td style="padding:8px 0;font-size:14px;color:#94a3b8;width:40%;border-bottom:1px solid #f8fafc;">Ticket reference</td><td style="padding:8px 0;font-size:14px;color:#334155;border-bottom:1px solid #f8fafc;"><strong>#{{ $ticket->id }}</strong></td></tr>
    <tr><td style="padding:8px 0;font-size:14px;color:#94a3b8;border-bottom:1px solid #f8fafc;">Subject</td><td style="padding:8px 0;font-size:14px;color:#334155;border-bottom:1px solid #f8fafc;">{{ $ticket->subject }}</td></tr>
    <tr><td style="padding:8px 0;font-size:14px;color:#94a3b8;border-bottom:1px solid #f8fafc;">Priority</td><td style="padding:8px 0;font-size:14px;color:#334155;border-bottom:1px solid #f8fafc;">{{ ucfirst($ticket->priority) }}</td></tr>
</table>

<a href="{{ $portalUrl }}" style="display:inline-block;background:#F59E0B;color:#fff;padding:12px 28px;border-radius:6px;text-decoration:none;font-weight:600;font-size:15px;margin:16px 0 8px;">View ticket status &rarr;</a>
@endsection
