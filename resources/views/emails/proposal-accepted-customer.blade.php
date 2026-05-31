@extends('emails.layout')

@section('content')
<h1 style="font-size:22px;font-weight:700;color:#0f172a;margin:0 0 8px;">Thank you</h1>
<p style="font-size:14px;color:#64748b;margin:0 0 24px;">Proposal {{ $proposal->reference }} accepted</p>

<p style="font-size:15px;line-height:1.6;color:#334155;margin:0 0 16px;">Hi {{ $contactName ?? 'there' }},</p>

<div style="background:#dcfce7;border-left:4px solid #22c55e;padding:16px;border-radius:4px;margin:0 0 24px;font-size:14px;color:#166534;">
    Thank you for accepting proposal {{ $proposal->reference }}.
</div>

<div style="font-size:28px;font-weight:700;color:#F59E0B;margin:0 0 16px;">£{{ number_format((float) $proposal->total, 2) }}</div>

<p style="font-size:15px;line-height:1.6;color:#334155;margin:0 0 16px;">You will receive your contract and first invoice shortly. A copy of the accepted proposal is attached for your records.</p>
@endsection
