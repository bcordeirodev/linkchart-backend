{{-- 404 error page rendered by RedirectController::renderErrorPage.

     $safeMessage is already e()-escaped and $frontendUrl comes from
     config('app.frontend_url'); both are emitted raw via {!! !!} to keep the
     output byte-identical to the previous heredoc. Whitespace is locked by
     characterization tests. --}}
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Link não encontrado</title>
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
            animation: shake 0.5s;
        }
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-10px); }
            75% { transform: translateX(10px); }
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
        .btn {
            display: inline-block;
            background: #1976d2;
            color: white;
            padding: 12px 30px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
            box-shadow: 0 2px 8px rgba(25, 118, 210, 0.3);
        }
        .btn:hover {
            background: #0D47A1;
            box-shadow: 0 4px 16px rgba(25, 118, 210, 0.4);
            transform: translateY(-2px);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">❌</div>
        <h1>Oops!</h1>
        <p>{!! $safeMessage !!}</p>
        <a href="{!! $frontendUrl !!}" class="btn">Voltar à Página Inicial</a>
    </div>
</body>
</html>