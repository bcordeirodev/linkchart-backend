{{-- Bot / social-crawler / ?preview=1 interstitial page.

     Rendered by RedirectController::renderRedirectPage. Every interpolation
     uses {!! !!} (raw echo) because the controller has ALREADY applied the
     correct context-aware escaping before passing the value in:
       - $title / $description / $ogType : e() (HTML text)
       - $metaUrl / $displayUrl          : htmlspecialchars ENT_QUOTES|ENT_HTML5
       - $targetUrl                       : json_encode JSON_HEX_* (includes quotes)
       - $imageTag / $twitterImageTag     : pre-built <meta> tags (e() on image)
       - $imageDimTags                    : pre-built og:image:width/height block
     Using {!! !!} keeps the output byte-identical to the previous heredoc and
     prevents Blade from double-escaping the already-escaped values.
     Whitespace here is significant — it is locked by characterization tests. --}}
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="refresh" content="{!! $refreshDelay !!};url={!! $metaUrl !!}">

    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="{!! $ogType !!}">
    <meta property="og:url" content="{!! $metaUrl !!}">
    <meta property="og:title" content="{!! $title !!}">
    <meta property="og:description" content="{!! $description !!}">
    {!! $imageTag !!}
{!! $imageDimTags !!}

    <!-- Twitter -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:url" content="{!! $metaUrl !!}">
    <meta name="twitter:title" content="{!! $title !!}">
    <meta name="twitter:description" content="{!! $description !!}">
    {!! $twitterImageTag !!}

    <!-- Canonical -->
    <link rel="canonical" href="{!! $metaUrl !!}">

    <!-- Metadados adicionais -->
    <meta property="og:site_name" content="LinkChart">
    <meta property="og:locale" content="pt_BR">

    <title>{!! $title !!}</title>

    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #252f3e 0%, #0d121b 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            background: white;
            padding: 40px;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-width: 500px;
            width: 100%;
            text-align: center;
        }
        .icon {
            font-size: 64px;
            margin-bottom: 20px;
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }
        h1 {
            color: rgb(17, 24, 39);
            font-size: 24px;
            margin-bottom: 10px;
            word-wrap: break-word;
            font-weight: 600;
        }
        p {
            color: rgb(107, 114, 128);
            font-size: 16px;
            margin-bottom: 20px;
        }
        .url {
            background: #f6f7f9;
            padding: 15px;
            border-radius: 8px;
            word-break: break-all;
            color: #1976d2;
            font-weight: 600;
            margin-bottom: 20px;
            font-size: 14px;
            border: 1px solid rgba(0, 0, 0, 0.12);
        }
        .spinner {
            width: 40px;
            height: 40px;
            border: 4px solid #f6f7f9;
            border-top: 4px solid #1976d2;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 20px auto;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .btn {
            display: inline-block;
            background: #1976d2;
            color: white;
            padding: 12px 30px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
            margin-top: 10px;
            box-shadow: 0 2px 8px rgba(25, 118, 210, 0.3);
        }
        .btn:hover {
            background: #0D47A1;
            box-shadow: 0 4px 16px rgba(25, 118, 210, 0.4);
            transform: translateY(-2px);
        }
        .footer {
            margin-top: 20px;
            font-size: 12px;
            color: rgb(107, 114, 128);
        }
        .countdown {
            font-size: 64px;
            font-weight: bold;
            color: #1976d2;
            margin: 10px 0;
            animation: pulse 1s infinite;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">🚀</div>
        <h1>{!! $title !!}</h1>
        <p>Você será redirecionado automaticamente em:</p>
        <div class="countdown" id="countdown">2</div>
        <div class="url">{!! $displayUrl !!}</div>
        <div class="spinner"></div>
        <p style="font-size: 14px; color: #999;">
            Ou clique no botão abaixo:
        </p>
        <a href="{!! $metaUrl !!}" class="btn">Ir Agora</a>
        <div class="footer">
            🔗 Powered by LinkChart
        </div>
    </div>

    <script>
        let timeLeft = 2;
        const countdownElement = document.getElementById('countdown');

        const countdownInterval = setInterval(function() {
            timeLeft--;
            if (timeLeft > 0) {
                countdownElement.textContent = timeLeft;
            } else {
                countdownElement.textContent = '•••';
                countdownElement.style.fontSize = '32px';
                clearInterval(countdownInterval);
            }
        }, 1000);

        setTimeout(function() {
            window.location.href = {!! $targetUrl !!};
        }, 2000);

        setTimeout(function() {
            if (document.visibilityState === 'visible') {
                window.location.replace({!! $targetUrl !!});
            }
        }, 2500);
    </script>
</body>
</html>