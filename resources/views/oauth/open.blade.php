<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Open Sendae</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #fff; color: #111; font: 15px/1.5 -apple-system, BlinkMacSystemFont, sans-serif; }
        main { width: min(28rem, calc(100% - 48px)); }
        h1 { font-size: 22px; font-weight: 600; letter-spacing: -0.2px; margin: 0 0 8px; }
        p { margin: 0 0 20px; color: #555; }
        a { display: inline-block; background: #111; color: #fff; text-decoration: none; border-radius: 8px; padding: 10px 16px; }
    </style>
</head>
<body>
<main>
    <h1>Open Sendae</h1>
    <p>Approve this connection in the Sendae app on this Mac. If nothing happens, open Sendae and use the button.</p>
    <a href="{{ $url }}">Open Sendae</a>
</main>
<script>
    window.location.href = @json($url);
</script>
</body>
</html>
