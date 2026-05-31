@extends('emails.layout')

@section('content')
<h1 style="font-size:22px;font-weight:700;color:#0f172a;margin:0 0 8px;">Powerhouse email is working</h1>
<p style="font-size:14px;color:#64748b;margin:0 0 24px;">Postmark delivery test</p>

<div style="background:#dcfce7;border-left:4px solid #22c55e;padding:16px;border-radius:4px;margin:0 0 24px;font-size:14px;color:#166534;">
    If you're reading this, transactional email delivery is configured correctly.
</div>

<p style="font-size:15px;line-height:1.6;color:#334155;margin:0 0 16px;">Triggered by {{ $senderName }} at {{ $sentAt }}.</p>
@endsection
