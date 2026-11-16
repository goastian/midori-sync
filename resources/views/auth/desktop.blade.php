<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Connect Midori {{ ($deviceType ?? 'desktop') === 'mobile' ? 'Android' : 'Desktop' }}</title>
</head>
<body>
    <main>
        <h1>Connect Midori {{ ($deviceType ?? 'desktop') === 'mobile' ? 'Android' : 'Desktop' }}</h1>
        <p>Connect to <strong>{{ $accountEmail }}</strong>?</p>
        <p>Enter the connection code shown in the Midori app you are connecting. Only continue if you started this request yourself.</p>
        <p>If Midori is not showing a connection code, cancel this request. Do not use a code sent by someone else.</p>
        <p>This request expires at {{ $expiresAt }}.</p>
        <form method="post" action="{{ route('auth.desktop.approve') }}">
            @csrf
            <input type="hidden" name="request_id" value="{{ $requestId }}">
            <label for="verification-code">Connection code from Midori</label>
            <input id="verification-code" name="verification_code" type="text" required minlength="10" maxlength="11"
                pattern="[0-9A-Fa-f]{5}-?[0-9A-Fa-f]{5}" autocomplete="off" autocapitalize="characters"
                spellcheck="false" aria-describedby="code-help{{ $invalidCode ? ' code-error' : '' }}"
                @if ($invalidCode) aria-invalid="true" @endif>
            <p id="code-help">The code contains two groups of five letters or numbers.</p>
            @if ($invalidCode)
                <p id="code-error" role="alert">That code does not match this request. Check the code in Midori or cancel and start again.</p>
            @endif
            <button type="submit" name="decision" value="approve">Verify and connect account</button>
            <button type="submit" name="decision" value="cancel" formnovalidate>Cancel connection</button>
        </form>
    </main>
</body>
</html>
