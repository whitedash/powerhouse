@extends('emails.layout')

@section('content')
<h1 style="font-size:22px;font-weight:700;color:#0f172a;margin:0 0 8px;">Proposal accepted</h1>
<p style="font-size:14px;color:#64748b;margin:0 0 24px;">{{ $proposal->reference }}</p>

<div style="background:#dcfce7;border-left:4px solid #22c55e;padding:16px;border-radius:4px;margin:0 0 24px;font-size:14px;color:#166534;">
    {{ $customerName ?? 'A customer' }} accepted your proposal.
</div>

<table style="width:100%;border-collapse:collapse;">
    <tr><td style="padding:8px 0;font-size:14px;color:#94a3b8;width:40%;border-bottom:1px solid #f8fafc;">Reference</td><td style="padding:8px 0;font-size:14px;color:#334155;border-bottom:1px solid #f8fafc;">{{ $proposal->reference }}</td></tr>
    <tr><td style="padding:8px 0;font-size:14px;color:#94a3b8;border-bottom:1px solid #f8fafc;">Total</td><td style="padding:8px 0;font-size:14px;color:#334155;border-bottom:1px solid #f8fafc;">£{{ number_format((float) $proposal->total, 2) }}</td></tr>
    <tr><td style="padding:8px 0;font-size:14px;color:#94a3b8;border-bottom:1px solid #f8fafc;">Accepted at</td><td style="padding:8px 0;font-size:14px;color:#334155;border-bottom:1px solid #f8fafc;">{{ $acceptedAt }}</td></tr>
    <tr><td style="padding:8px 0;font-size:14px;color:#94a3b8;border-bottom:1px solid #f8fafc;">Accepted by</td><td style="padding:8px 0;font-size:14px;color:#334155;border-bottom:1px solid #f8fafc;">{{ $acceptedByName }}</td></tr>
    <tr><td style="padding:8px 0;font-size:14px;color:#94a3b8;border-bottom:1px solid #f8fafc;">IP</td><td style="padding:8px 0;font-size:14px;color:#334155;border-bottom:1px solid #f8fafc;">{{ $acceptedIp }}</td></tr>
</table>

<a href="{{ $internalUrl }}" style="display:inline-block;background:#F59E0B;color:#fff;padding:12px 28px;border-radius:6px;text-decoration:none;font-weight:600;font-size:15px;margin:16px 0 8px;">View proposal &rarr;</a>
@endsection
