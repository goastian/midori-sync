<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Connect Midori Desktop</title>
</head>
<body>
    <main>
        <h1>Connect Midori Desktop</h1>
        <p>Allow this Midori Desktop browser to sync with your account?</p>
        <form method="post" action="{{ route('auth.desktop.approve') }}">
            @csrf
            <button type="submit">Connect account</button>
        </form>
    </main>
</body>
</html>
