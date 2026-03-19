<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Midori Sync — {{ $success ? 'Signed In' : 'Error' }}</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            background: {{ $success ? '#f0fdf4' : '#fef2f2' }};
            color: {{ $success ? '#166534' : '#991b1b' }};
        }
        .container { text-align: center; padding: 2rem; max-width: 420px; }
        .icon { font-size: 48px; margin-bottom: 16px; }
        h1 { font-size: 1.25rem; font-weight: 600; margin: 0 0 0.5rem; }
        p { font-size: 0.875rem; color: {{ $success ? '#15803d' : '#b91c1c' }}; margin: 0; }
        .hint { margin-top: 1rem; font-size: 0.75rem; color: #6b7280; }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">{{ $success ? '✅' : '❌' }}</div>
        <h1>{{ $success ? 'Welcome, ' . ($userName ?? 'User') . '!' : 'Authentication Failed' }}</h1>
        <p>{{ $message }}</p>
        @if($success)
            <p class="hint">This tab will close automatically. If it doesn't, you can close it manually.</p>
        @endif
    </div>
</body>
</html>
