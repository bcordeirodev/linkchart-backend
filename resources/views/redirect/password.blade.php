{{-- Password challenge page rendered by RedirectController::renderPasswordPage.

     Served for password-protected links to humans, bots/social crawlers and
     ?preview=1 alike. It deliberately contains ZERO information about the
     destination: no original_url, no fetched OG metadata — only generic
     "Link protegido" meta-tags, so social previews cannot leak the target.

     Escaping follows the redirect-views pattern (see interstitial.blade.php):
     the controller has ALREADY applied context-aware escaping before passing
     values in, and this template emits them raw via {!! !!}:
       - $formAction    : e() (relative unlock path, keeps custom-domain host)
       - $errorMessage  : e() or null (static PT-BR string, never user input)
     @csrf is the standard Blade directive (session-backed token from the web
     middleware group, which applies ValidateCsrfToken to the POST). --}}
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">

    <!-- Open Graph genérico — nunca expõe metadados do destino -->
    <meta property="og:type" content="website">
    <meta property="og:title" content="Link protegido">
    <meta property="og:description" content="Este link é protegido por senha.">
    <meta property="og:site_name" content="LinkChart">
    <meta property="og:locale" content="pt_BR">

    <!-- Twitter -->
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="Link protegido">
    <meta name="twitter:description" content="Este link é protegido por senha.">

    <title>Link protegido</title>

    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
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
        }
        h1 {
            color: rgb(17, 24, 39);
            font-size: 24px;
            margin-bottom: 10px;
            font-weight: 600;
        }
        p {
            color: rgb(107, 114, 128);
            font-size: 16px;
            margin-bottom: 20px;
        }
        .error {
            background: #fdecea;
            color: #b71c1c;
            border: 1px solid rgba(183, 28, 28, 0.25);
            border-radius: 8px;
            padding: 12px;
            font-size: 14px;
            margin-bottom: 20px;
        }
        input[type="password"] {
            width: 100%;
            padding: 12px 15px;
            font-size: 16px;
            border: 1px solid rgba(0, 0, 0, 0.23);
            border-radius: 8px;
            margin-bottom: 20px;
            outline: none;
        }
        input[type="password"]:focus {
            border-color: #1976d2;
            box-shadow: 0 0 0 2px rgba(25, 118, 210, 0.2);
        }
        .btn {
            display: inline-block;
            width: 100%;
            background: #1976d2;
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 2px 8px rgba(25, 118, 210, 0.3);
        }
        .btn:hover {
            background: #0D47A1;
            box-shadow: 0 4px 16px rgba(25, 118, 210, 0.4);
        }
        .footer {
            margin-top: 20px;
            font-size: 12px;
            color: rgb(107, 114, 128);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">🔒</div>
        <h1>Link protegido</h1>
        <p>Este link é protegido por senha. Digite a senha para continuar.</p>
        @if ($errorMessage)
        <div class="error">{!! $errorMessage !!}</div>
        @endif
        <form method="POST" action="{!! $formAction !!}">
            @csrf
            <input type="password" name="password" placeholder="Senha" required autofocus autocomplete="off">
            <button type="submit" class="btn">Acessar link</button>
        </form>
        <div class="footer">
            🔗 Powered by LinkChart
        </div>
    </div>
</body>
</html>
