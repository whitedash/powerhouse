@extends('emails.layout')

@section('content')
<h1 style="font-size:22px;font-weight:700;color:#0f172a;margin:0 0 8px;">Thanks — we've got it</h1>
<p style="font-size:14px;color:#64748b;margin:0 0 24px;">Ticket #{{ $ticket->id }} · {{ $ticket->subject }}</p>

<p style="font-size:15px;line-height:1.6;color:#334155;margin:0 0 16px;">We've received your support request and our team will get back to you by email. There's nothing more you need to do — just keep your ticket reference handy.</p>

<table style="width:100%;border-collapse:collapse;">
    <tr><td style="padding:8px 0;font-size:14px;color:#94a3b8;width:40%;border-bottom:1px solid #f8fafc;">Ticket reference</td><td style="padding:8px 0;font-size:14px;color:#334155;border-bottom:1px solid #f8fafc;"><strong>#{{ $ticket->id }}</strong></td></tr>
    <tr><td style="padding:8px 0;font-size:14px;color:#94a3b8;border-bottom:1px solid #f8fafc;">Subject</td><td style="padding:8px 0;font-size:14px;color:#334155;border-bottom:1px solid #f8fafc;">{{ $ticket->subject }}</td></tr>
</table>

<p style="font-size:14px;line-height:1.6;color:#64748b;margin:24px 0 0;">If you need to add anything, simply reply to this email and it will be attached to your ticket.</p>
@endsection
