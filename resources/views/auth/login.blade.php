<!doctype html>
<html lang="pt-BR">
<head>
    @include('partials.head-meta', [
        'pageTitle' => 'Entrar',
        'description' => 'Acesse a plataforma Express Payments para gerenciar estabelecimentos, faturamento, comissões e operações.',
    ])
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <script src="https://cdn.tailwindcss.com/3.4.16"></script>
    @php
        $loginPrimary = $primaryColor ?? '#2563eb';
    @endphp
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        express: {
                            50: '#eff6ff',
                            100: '#dbeafe',
                            500: '#3b82f6',
                            600: '{{ $loginPrimary }}',
                            700: '{{ $loginPrimary }}',
                            800: '#1e40af',
                        },
                    },
                },
            },
        };
    </script>
    <style>
        :root { --login-primary: {{ $loginPrimary }}; }
        .login-input {
            padding-top: 1.35rem;
            padding-bottom: 0.55rem;
        }
        .login-input:focus ~ .login-label,
        .login-input:not(:placeholder-shown) ~ .login-label {
            top: 0.45rem;
            font-size: 0.65rem;
            font-weight: 600;
            color: var(--login-primary);
        }
        .login-panel-gradient {
            background: linear-gradient(to bottom right, var(--login-primary), color-mix(in srgb, var(--login-primary) 85%, #000), color-mix(in srgb, var(--login-primary) 70%, #000));
        }
    </style>
</head>
<body class="min-h-screen bg-white antialiased">
    <div class="flex min-h-screen flex-col lg:flex-row">
        {{-- Painel esquerdo: ilustração e marca --}}
        <div class="login-panel-gradient relative flex min-h-[280px] flex-1 flex-col items-center justify-between overflow-hidden px-8 py-10 text-white lg:min-h-screen lg:max-w-[50%] lg:px-12 lg:py-14">
            <div class="absolute -left-16 -top-16 h-56 w-56 rounded-full bg-white/10"></div>
            <div class="absolute -bottom-20 -right-10 h-72 w-72 rounded-full bg-white/5"></div>
            <div class="absolute right-12 top-24 h-3 w-3 rounded-full bg-amber-400/80"></div>
            <div class="absolute left-20 top-32 h-2 w-2 rounded-full bg-emerald-400/80"></div>
            <div class="absolute bottom-40 left-10 h-2 w-2 rounded-full bg-rose-400/80"></div>

            <div class="relative z-10 my-6 flex w-full max-w-lg flex-1 items-center justify-center">
                @if ($logoWhiteUrl ?? $logoUrl)
                    <img
                        src="{{ $logoWhiteUrl ?? $logoUrl }}"
                        alt="{{ $appName }}"
                        class="w-full max-w-md object-contain object-center brightness-0 invert drop-shadow-lg"
                    >
                @else
                    <p class="text-3xl font-bold tracking-tight text-white">{{ $appName }}</p>
                @endif
            </div>

            <div class="relative z-10 w-full max-w-lg text-center lg:text-left">
                <p class="text-lg font-semibold leading-snug text-white/95 lg:text-xl">
                    Gestão de faturamento inteligente e em tempo real.
                </p>
                <p class="mt-2 text-sm text-blue-100/90">
                    Acompanhe transações EDI, comissões e relatórios financeiros em um só painel.
                </p>
            </div>
        </div>

        {{-- Painel direito: formulário --}}
        <div class="flex flex-1 items-center justify-center px-6 py-10 lg:max-w-[50%] lg:px-16 lg:py-12">
            <div class="w-full max-w-md">
                <div class="mb-8 lg:hidden">
                    @if ($logoUrl)
                        <img src="{{ $logoUrl }}" alt="{{ $appName }}" class="mx-auto h-16 w-auto max-w-xs object-contain sm:h-20">
                    @else
                        <p class="text-center text-2xl font-bold text-gray-900">{{ $appName }}</p>
                    @endif
                </div>

                <h1 class="text-center text-2xl font-bold text-gray-900 lg:text-left lg:text-3xl">Faça seu login</h1>
                <p class="mt-2 text-center text-sm text-gray-500 lg:text-left">
                    Acesse sua conta na plataforma {{ $appName }}.
                </p>

                @php
                    use App\Support\TenantBranding;

                    $tenantParam = $tenantParam ?? config('tenant.local_query', 'tenant');
                    $tenantSlug = $tenantSlug ?? TenantBranding::porSlugAtivo(old($tenantParam, request()->query($tenantParam)))?->slug;
                @endphp

                @if (session('status'))
                    <div class="mt-6 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">
                        {{ session('status') }}
                    </div>
                @endif

                @if ($errors->any())
                    <div class="mt-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                        @foreach ($errors->all() as $error)
                            <p>{{ $error }}</p>
                        @endforeach
                    </div>
                @endif

                @if ($sessaoAtiva ?? false)
                    <div class="mt-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                        <p class="font-semibold">Você já está logado</p>
                        <p class="mt-1">Conta atual: <strong>{{ $usuarioLogado->email ?? $usuarioLogado->nome ?? 'usuário' }}</strong>. Saia para entrar com outro e-mail (ex.: usuário operacional do marketplace).</p>
                        <form method="POST" action="{{ route('logout') }}" class="mt-3">
                            @csrf
                            @if ($tenantSlug)
                                <input type="hidden" name="{{ $tenantParam }}" value="{{ $tenantSlug }}">
                            @endif
                            <button type="submit" class="rounded-lg bg-amber-800 px-4 py-2 text-xs font-semibold text-white hover:bg-amber-900">
                                Sair e trocar de conta
                            </button>
                        </form>
                    </div>
                @endif

                <form method="POST" action="{{ $tenantSlug ? route('login.store', [$tenantParam => $tenantSlug]) : route('login.store') }}" class="mt-8 space-y-5">
                    @csrf
                    @if ($tenantSlug)
                        <input type="hidden" name="{{ $tenantParam }}" value="{{ $tenantSlug }}">
                    @endif

                    <div class="relative">
                        <span class="login-field-icon pointer-events-none absolute left-4 top-4 z-10 flex h-5 w-5 items-center justify-center text-gray-400">
                            <i class="fa-regular fa-envelope text-[15px] leading-none"></i>
                        </span>
                        <input
                            id="email"
                            name="email"
                            type="email"
                            value="{{ old('email') }}"
                            placeholder=" "
                            required
                            autocomplete="email"
                            class="login-input peer w-full rounded-xl border border-gray-300 bg-white pl-11 pr-4 text-sm text-gray-800 outline-none transition focus:border-express-600 focus:ring-2 focus:ring-express-600/20"
                        >
                        <label for="email" class="login-label pointer-events-none absolute left-11 top-4 text-sm text-gray-500 transition-all duration-200">
                            E-mail
                        </label>
                    </div>

                    <div class="relative">
                        <span class="login-field-icon pointer-events-none absolute left-4 top-4 z-10 flex h-5 w-5 items-center justify-center text-gray-400">
                            <i class="fa-solid fa-lock text-[15px] leading-none"></i>
                        </span>
                        <input
                            id="password"
                            name="password"
                            type="password"
                            placeholder=" "
                            required
                            autocomplete="current-password"
                            class="login-input peer w-full rounded-xl border border-gray-300 bg-white pl-11 pr-11 text-sm text-gray-800 outline-none transition focus:border-express-600 focus:ring-2 focus:ring-express-600/20"
                        >
                        <label for="password" class="login-label pointer-events-none absolute left-11 top-4 text-sm text-gray-500 transition-all duration-200">
                            Senha
                        </label>
                        <button
                            type="button"
                            id="toggle-password"
                            class="absolute right-3 top-1/2 z-10 -translate-y-1/2 text-gray-400 transition hover:text-express-600"
                            aria-label="Mostrar senha"
                        >
                            <i class="fa-regular fa-eye" id="toggle-password-icon"></i>
                        </button>
                    </div>

                    <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
                        <label class="flex cursor-pointer items-center gap-2 text-gray-600">
                            <input name="remember" type="checkbox" class="h-4 w-4 rounded border-gray-300 text-express-600 focus:ring-express-600">
                            Lembrar de mim
                        </label>
                        <a href="{{ $tenantSlug ? route('password.request', [$tenantParam => $tenantSlug]) : route('password.request') }}" class="font-medium text-express-600 transition hover:text-express-700 hover:underline">
                            Esqueci minha senha
                        </a>
                    </div>

                    <button
                        type="submit"
                        class="w-full rounded-xl bg-express-600 py-3 text-sm font-semibold text-white shadow-md shadow-express-600/25 transition hover:bg-express-700 focus:outline-none focus:ring-2 focus:ring-express-600 focus:ring-offset-2"
                    >
                        Entrar
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
        document.getElementById('toggle-password')?.addEventListener('click', () => {
            const input = document.getElementById('password');
            const icon = document.getElementById('toggle-password-icon');
            if (!input || !icon) return;
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            icon.classList.toggle('fa-eye', !show);
            icon.classList.toggle('fa-eye-slash', show);
        });
    </script>
</body>
</html>
