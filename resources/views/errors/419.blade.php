<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="refresh" content="0;url={{ url('/login') }}">
    <title>Sessão expirada</title>
</head>
<body>
    <p>Sua sessão expirou. <a href="{{ url('/login') }}">Entrar novamente</a></p>
    <script>window.location.replace(@json(url('/login')));</script>
</body>
</html>
