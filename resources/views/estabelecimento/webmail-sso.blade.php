<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acessar Webmail</title>
    <style>
        body {
            font-family: system-ui, -apple-system, sans-serif;
            background: #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
        }
        .card {
            background: #fff;
            border-radius: 12px;
            padding: 2rem 2.5rem;
            box-shadow: 0 4px 24px rgba(0,0,0,.08);
            text-align: center;
            max-width: 420px;
            width: 100%;
        }
        h1 { font-size: 1rem; color: #1e293b; margin: 0 0 .25rem; }
        p { color: #64748b; font-size: .85rem; margin: 0 0 1.25rem; }
        .campo { text-align: left; margin-bottom: .85rem; }
        .campo label { display: block; font-size: .75rem; color: #64748b; margin-bottom: .25rem; }
        .cred {
            display: flex;
            align-items: center;
            gap: .5rem;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: .55rem .75rem;
            font-family: monospace;
            font-size: .85rem;
            color: #334155;
        }
        .cred span { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; text-align: left; }
        .cred button {
            border: none;
            background: #e2e8f0;
            color: #334155;
            border-radius: 6px;
            padding: .3rem .55rem;
            font-size: .75rem;
            cursor: pointer;
            flex-shrink: 0;
        }
        .cred button:hover { background: #cbd5e1; }
        .btn {
            display: block;
            width: 100%;
            box-sizing: border-box;
            background: #3b82f6;
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: .7rem 1.25rem;
            font-size: .9rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            margin-top: 1.25rem;
        }
        .btn:hover { background: #2563eb; }
        .aviso { margin-top: .75rem; font-size: .75rem; color: #94a3b8; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Acessar e-mail da plataforma</h1>
        <p>Copie as credenciais abaixo e entre no Webmail.</p>

        <div class="campo">
            <label>E-mail</label>
            <div class="cred">
                <span id="campoEmail">{{ $email }}</span>
                <button type="button" onclick="copiar('campoEmail', this)">Copiar</button>
            </div>
        </div>

        <div class="campo">
            <label>Senha</label>
            <div class="cred">
                <span id="campoSenha">{{ $senha }}</span>
                <button type="button" onclick="copiar('campoSenha', this)">Copiar</button>
            </div>
        </div>

        <a class="btn" href="{{ $webmailUrl }}/" target="_blank" rel="noopener">Abrir Webmail</a>
        <p class="aviso">O login automático não está disponível — cole as credenciais na tela do Roundcube.</p>
    </div>

    <script>
        function copiar(id, botao) {
            var texto = document.getElementById(id).textContent;
            navigator.clipboard.writeText(texto).then(function () {
                var original = botao.textContent;
                botao.textContent = 'Copiado!';
                setTimeout(function () { botao.textContent = original; }, 1500);
            });
        }
    </script>
</body>
</html>
