<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $subjectLine ?? ($entityName ?? config('app.name')) }}</title>
</head>
<body style="font-family: -apple-system, 'Segoe UI', Arial, sans-serif; background: #f8fafc; margin: 0; padding: 0;">
    <div style="max-width: 600px; margin: 40px auto; padding: 0 16px;">
        <div style="background: #ffffff; border-radius: 8px; border: 1px solid #e2e8f0; overflow: hidden;">

            <div style="padding: 24px 32px; border-bottom: 1px solid #f1f5f9;">
                @if(!empty($logoUrl))
                    <img src="{{ $logoUrl }}" alt="{{ $entityName ?? 'Powerhouse' }}" height="36" style="height: 36px; display: block;">
                @else
                    <span style="font-weight: 700; font-size: 18px; color: #0f172a; letter-spacing: -0.01em;">{{ $entityName ?? config('app.name') }}</span>
                @endif
            </div>

            <div style="padding: 32px;">
                @yield('content')
            </div>

            <div style="padding: 20px 32px; background: #f8fafc; border-top: 1px solid #f1f5f9;">
                <p style="font-size: 12px; color: #94a3b8; line-height: 1.6; margin: 0;">
                    {{ $entityName ?? config('app.name') }}
                    @if(!empty($entityAddress))
                        <br>{{ $entityAddress }}
                    @endif
                    @if(!empty($entityVatNumber))
                        <br>VAT: {{ $entityVatNumber }}
                    @endif
                    <br><br>
                    You received this email because you have an account with us.
                    @if(!empty($portalUrl))
                        <br>
                        <a href="{{ $portalUrl }}" style="color: #F59E0B;">Manage your account</a>
                    @endif
                </p>
            </div>

        </div>
    </div>
</body>
</html>
