@extends('emails.layout')

@section('content')
<h1 style="font-size:22px;font-weight:700;color:#0f172a;margin:0 0 8px;">Your online account is ready</h1>
<p style="font-size:14px;color:#64748b;margin:0 0 24px;">Manage your account online</p>

<p style="font-size:15px;line-height:1.6;color:#334155;margin:0 0 16px;">Hi {{ $contactName ?? 'there' }}, you've been invited to manage your account online.</p>

<table style="width:100%;border-collapse:collapse;margin:0 0 8px;">
    <tr><td style="padding:8px 0;font-size:14px;color:#94a3b8;width:40%;border-bottom:1px solid #f8fafc;">Email</td><td style="padding:8px 0;font-size:14px;color:#334155;border-bottom:1px solid #f8fafc;">{{ $loginEmail }}</td></tr>
    <tr><td style="padding:8px 0;font-size:14px;color:#94a3b8;border-bottom:1px solid #f8fafc;">Temporary password</td><td style="padding:8px 0;font-size:14px;color:#334155;border-bottom:1px solid #f8fafc;"><strong style="font-family:monospace;">{{ $tempPassword }}</strong></td></tr>
</table>

<a href="{{ $loginUrl }}" style="display:inline-block;background:#F59E0B;color:#fff;padding:12px 28px;border-radius:6px;text-decoration:none;font-weight:600;font-size:15px;margin:8px 0 24px;">Log in now &rarr;</a>

<div style="background:#fef3c7;border-left:4px solid #F59E0B;padding:16px;border-radius:4px;margin:0;font-size:14px;color:#92400e;">
    Please change your password after your first login.
</div>
@endsection
