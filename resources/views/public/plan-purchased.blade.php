<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Thank you — {{ $product->name }}</title>
    <style>
        body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f8fafc; color: #0f172a; display: flex; min-height: 100vh; align-items: center; justify-content: center; }
        .pw-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 40px 44px; max-width: 460px; margin: 24px; text-align: center; }
        .pw-tick { width: 56px; height: 56px; border-radius: 50%; background: #dcfce7; color: #16a34a; font-size: 28px; line-height: 56px; margin: 0 auto 20px; }
        h1 { font-size: 22px; margin: 0 0 10px; }
        p { font-size: 14px; color: #64748b; line-height: 1.6; margin: 0 0 8px; }
    </style>
</head>
<body>
    <div class="pw-card">
        <div class="pw-tick">✓</div>
        <h1>Thank you — payment received</h1>
        <p>Your {{ $product->name }} plan is being set up.</p>
        <p>A receipt and your invoice are on their way to your email address.</p>
    </div>
</body>
</html>
