@extends('emails.layout')

@section('content')
<h1 style="font-size:22px;font-weight:700;color:#0f172a;margin:0 0 8px;">Proposal declined</h1>
<p style="font-size:14px;color:#64748b;margin:0 0 24px;">Proposal {{ $proposal->reference }}</p>

<p style="font-size:15px;line-height:1.6;color:#334155;margin:0 0 16px;">Hi {{ $contactName ?? 'there' }},</p>

<div style="background:#fee2e2;border-left:4px solid #ef4444;padding:16px;border-radius:4px;margin:0 0 24px;font-size:14px;color:#991b1b;">
    We've recorded that you declined proposal {{ $proposal->reference }}.
</div>

<p style="font-size:15px;line-height:1.6;color:#334155;margin:0 0 16px;">If this was a mistake, or you'd like to discuss an alternative, just reply to this email and we'll be glad to help.</p>
@endsection
