@extends('emails.layout')

@section('content')
{{-- Body is a pre-rendered plain-text template from the workflow builder.
     Escape it, then convert newlines to <br> — never trust it as raw HTML. --}}
<div style="font-size:15px;line-height:1.6;color:#334155;">{!! nl2br(e($body)) !!}</div>
@endsection
