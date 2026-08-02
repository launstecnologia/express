<?php

namespace App\Support;

use Illuminate\Support\Str;

class AutomacaoErroInterpretador
{
    /** @var array<string, string> */
    private const ETAPAS_SCREENSHOT = [
        'timeout_plano_promocao' => 'Plano / promoção mobile',
        'timeout_condicoes_comerciais' => 'Plano / promoção mobile',
        'timeout_segmento' => 'Segmento',
        'timeout_endereco' => 'Endereço',
        'erro_validacao_segmento' => 'Segmento',
        'erro_validacao_endereco' => 'Endereço',
        'etapa_confirmacao' => 'Plano / promoção mobile',
        'etapa_plano_promocao' => 'Plano / promoção mobile',
        'etapa6_segmento' => 'Segmento',
        'etapa5_endereco' => 'Endereço',
        'pf_etapa2_endereco' => 'Endereço (PF)',
        'etapa2_dados_empresa' => 'Dados da empresa',
        'etapa2_dados_empresa_preenchido' => 'Dados da empresa',
        'etapa1_pos_email' => 'Dados da empresa',
        'etapa3_proprietario' => 'Dados do proprietário',
        'etapa4_endereco' => 'Endereço',
        'cadastro_concluido' => 'Confirmação',
    ];

    /** @var array<string, string> Etapa concluída → provável etapa da falha */
    private const PROXIMA_ETAPA_APOS_SCREENSHOT = [
        'etapa1_pos_email' => 'Dados da empresa',
        'etapa2_dados_empresa' => 'Dados do proprietário',
        'etapa2_dados_empresa_preenchido' => 'Dados do proprietário',
        'etapa3_proprietario' => 'Endereço',
        'etapa4_endereco' => 'Segmento',
        'pf_etapa2_endereco' => 'Endereço (PF)',
        'etapa5_endereco' => 'Segmento',
        'etapa6_segmento' => 'Plano / promoção mobile',
        'etapa_plano_promocao' => 'Confirmação',
        'etapa_confirmacao' => 'Confirmação',
    ];

    /**
     * @param  array<string, mixed>|null  $contexto
     * @param  iterable<int, object>|null  $logsRecentes
     * @return array{
     *     titulo: string,
     *     etapa: ?string,
     *     resumo: string,
     *     mensagem_amigavel: string,
     *     tecnico: ?string,
     *     tem_screenshots: bool
     * }
     */
    public static function interpretar(?string $erro, ?array $contexto = null, ?iterable $logsRecentes = null): array
    {
        $detalhe = self::extrairDetalhe($contexto);
        $screenshots = self::coletarScreenshots($contexto, $logsRecentes);

        $etapa = data_get($detalhe, 'etapa_falha_label')
            ?: self::rotuloEtapa(data_get($detalhe, 'etapa_falha'))
            ?: self::rotuloEtapaTexto(data_get($contexto, 'etapa_atual'))
            ?: self::inferirEtapaDeErroFatal($screenshots)
            ?: self::rotuloEtapaTexto(data_get($contexto, 'etapa_log'))
            ?: self::inferirEtapaDeLogs($logsRecentes)
            ?: self::inferirEtapaDeScreenshots($screenshots, $erro);

        $etapa = self::normalizarRotuloEtapa($etapa);

        $tecnico = self::extrairErroTecnico($erro, $contexto);
        $resumo = self::resumirMensagem($tecnico ?: $erro);

        if ($etapa === 'Plano / promoção mobile' && (
            $resumo === 'Erro desconhecido na automação.'
            || str_contains($resumo, 'não respondeu a tempo')
            || str_contains($resumo, 'não avançou')
        )) {
            $resumo = 'Não foi possível selecionar o plano (promoção mobile) no PagBank. '
                .'Confira se o código FV do plano está correto e aparece na lista "Promoção mobile".';
        } elseif ($resumo === 'Erro desconhecido na automação.' && $etapa) {
            $resumo = 'O portal PagBank não avançou nesta etapa. Confira os screenshots para ver em qual tela parou.';
        } elseif (self::erroPareceTimeout($erro) && $etapa) {
            $ultimaAtualizacao = data_get($contexto, 'atualizado_em');
            $versao = data_get($contexto, 'codigo_versao');
            $extras = [];
            if ($ultimaAtualizacao) {
                $extras[] = "última atividade registrada em {$ultimaAtualizacao}";
            }
            if ($versao) {
                $extras[] = "versão da automação: {$versao}";
            }
            $resumo = 'A automação não concluiu dentro do prazo esperado'
                .($extras !== [] ? ' ('.implode('; ', $extras).')' : '')
                .'. Confira os screenshots — a tela onde parou indica a etapa real.';
        }

        $titulo = $etapa ? "Falha na etapa: {$etapa}" : 'Erro no cadastro PagBank';
        $mensagemAmigavel = $etapa ? "{$titulo}. {$resumo}" : $resumo;

        return [
            'titulo' => $titulo,
            'etapa' => $etapa,
            'resumo' => $resumo,
            'mensagem_amigavel' => $mensagemAmigavel,
            'tecnico' => $tecnico ?: $erro,
            'tem_screenshots' => $screenshots !== [],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $contexto
     */
    public static function mensagemTimeout(?array $contexto): string
    {
        $etapa = self::rotuloEtapaTexto(data_get($contexto, 'etapa_atual'))
            ?: 'desconhecida';
        $status = data_get($contexto, 'status', 'em_andamento');

        $partes = [
            "Timeout: automação parou na etapa \"{$etapa}\" (status Python: {$status}).",
        ];

        if ($erroPython = data_get($contexto, 'erro_tecnico')) {
            $partes[] = 'Detalhe: '.Str::limit((string) $erroPython, 300);
        }

        if ($versao = data_get($contexto, 'codigo_versao')) {
            $partes[] = "Versão automação: {$versao}.";
        }

        return implode(' ', $partes);
    }

    private static function erroPareceTimeout(?string $erro): bool
    {
        if (blank($erro)) {
            return false;
        }

        $texto = Str::lower($erro);

        return str_contains($texto, 'timeout')
            || str_contains($texto, 'prazo esperado')
            || str_contains($texto, 'parou na etapa');
    }

    public static function logPareceErro(object $log): bool
    {
        $nivel = Str::lower((string) ($log->nivel ?? ''));

        if (in_array($nivel, ['erro', 'error', 'fatal'], true)) {
            return true;
        }

        $mensagem = (string) ($log->mensagem ?? '');

        return str_contains($mensagem, 'Stacktrace:')
            || str_contains($mensagem, 'Message:')
            || str_contains(Str::lower($mensagem), 'erro no cadastro pagbank')
            || str_contains(Str::lower((string) ($log->etapa ?? '')), 'erro');
    }

    public static function resumirMensagem(?string $erro): string
    {
        if (blank($erro)) {
            return 'Erro desconhecido na automação.';
        }

        if (preg_match('/Falha na etapa "[^"]+":\s*(.+)$/s', $erro, $matches)) {
            return self::resumirMensagem(trim($matches[1]));
        }

        if (str_contains($erro, 'PagBank não avançou')) {
            return Str::limit(trim(Str::before($erro, 'Stacktrace:')), 400);
        }

        if (preg_match('/Message:\s*(.+?)(?:\nStacktrace:|\n#0|\z)/s', $erro, $matches)) {
            $mensagem = trim($matches[1]);
            if ($mensagem !== '') {
                return Str::limit($mensagem, 400);
            }
        }

        if (str_contains($erro, 'Stacktrace:')) {
            return 'O portal PagBank não respondeu a tempo ou a tela esperada não apareceu.';
        }

        foreach (preg_split('/\R/', $erro) as $linha) {
            $linha = trim($linha);
            if ($linha === '' || $linha === 'Message:' || str_starts_with($linha, '#')) {
                continue;
            }

            if (str_starts_with($linha, 'Message:')) {
                $resto = trim(substr($linha, 8));
                if ($resto !== '') {
                    return Str::limit($resto, 400);
                }

                continue;
            }

            return Str::limit($linha, 400);
        }

        return Str::limit(trim($erro), 400);
    }

    /**
     * @param  array<int, string>|mixed  $screenshots
     */
    public static function inferirEtapaDeScreenshots(mixed $screenshots, ?string $erro = null): ?string
    {
        if (! is_array($screenshots) || $screenshots === []) {
            return self::inferirEtapaDoTexto($erro);
        }

        $nomes = array_map(
            fn ($caminho) => self::prefixoScreenshot((string) $caminho),
            $screenshots,
        );

        foreach (array_reverse($nomes) as $nome) {
            if (isset(self::ETAPAS_SCREENSHOT[$nome])) {
                if (in_array($nome, ['erro_fatal', 'timeout_condicoes_comerciais', 'timeout_segmento'], true)
                    || str_starts_with($nome, 'timeout_')
                    || str_starts_with($nome, 'erro_validacao_')) {
                    return self::ETAPAS_SCREENSHOT[$nome];
                }
            }
        }

        $ultimoConcluido = null;
        foreach ($nomes as $nome) {
            if (in_array($nome, ['erro_fatal'], true) || str_starts_with($nome, 'timeout_')) {
                continue;
            }
            $ultimoConcluido = $nome;
        }

        if ($ultimoConcluido && isset(self::PROXIMA_ETAPA_APOS_SCREENSHOT[$ultimoConcluido])) {
            return self::PROXIMA_ETAPA_APOS_SCREENSHOT[$ultimoConcluido];
        }

        if ($ultimoConcluido && isset(self::ETAPAS_SCREENSHOT[$ultimoConcluido]) && ! in_array('erro_fatal', $nomes, true)) {
            return self::ETAPAS_SCREENSHOT[$ultimoConcluido];
        }

        return self::inferirEtapaDoTexto($erro);
    }

    private static function inferirEtapaDoTexto(?string $erro): ?string
    {
        if (blank($erro)) {
            return null;
        }

        $texto = Str::lower($erro);

        return match (true) {
            str_contains($texto, 'segmento') => 'Segmento',
            str_contains($texto, 'condições comerciais') || str_contains($texto, 'condicoes comerciais') => 'Plano / promoção mobile',
            str_contains($texto, 'promoção') || str_contains($texto, 'promocao') || str_contains($texto, 'plano') => 'Plano / promoção mobile',
            str_contains($texto, 'endereço') || str_contains($texto, 'endereco') => 'Endereço',
            str_contains($texto, 'e-mail') || str_contains($texto, 'email') => 'Validação de e-mail',
            str_contains($texto, 'proposta') => 'Proposta comercial',
            str_contains($texto, 'login') => 'Login no portal',
            default => null,
        };
    }

    private static function prefixoScreenshot(string $caminho): string
    {
        $nome = basename($caminho);
        $nome = preg_replace('/_\d+\.png$/', '', $nome) ?? $nome;

        return $nome;
    }

    private static function rotuloEtapaTexto(?string $texto): ?string
    {
        if (blank($texto)) {
            return null;
        }

        $normalizado = Str::lower(Str::ascii($texto));

        return match (true) {
            str_contains($normalizado, 'condicoes comerciais') => 'Plano / promoção mobile',
            str_contains($normalizado, 'promocao') || str_contains($normalizado, 'plano') => 'Plano / promoção mobile',
            str_contains($normalizado, 'segmento') => 'Segmento',
            str_contains($normalizado, 'endereco') => 'Endereço',
            str_contains($normalizado, 'dados pessoais') => 'Dados pessoais (PF)',
            str_contains($normalizado, 'dados da empresa') => 'Dados da empresa',
            str_contains($normalizado, 'proprietario') => 'Dados do proprietário',
            str_contains($normalizado, 'dados iniciais') => 'Dados iniciais',
            str_contains($normalizado, 'e-mail') || str_contains($normalizado, 'email') => 'Validação de e-mail',
            str_contains($normalizado, 'proposta') => 'Proposta comercial',
            str_contains($normalizado, 'login') || str_contains($normalizado, 'portal') => 'Login no portal',
            default => null,
        };
    }

    /**
     * @param  iterable<int, object>|null  $logs
     */
    private static function inferirEtapaDeLogs(?iterable $logs): ?string
    {
        if ($logs === null) {
            return null;
        }

        $ultimaInfo = null;

        foreach ($logs as $log) {
            if (self::logPareceErro($log)) {
                break;
            }

            if (($log->nivel ?? '') === 'info' && filled($log->etapa ?? null)) {
                $ultimaInfo = (string) $log->etapa;
            }
        }

        return self::rotuloEtapaTexto($ultimaInfo);
    }

    /**
     * Quando há erro_fatal, a falha ocorreu na etapa APÓS o último screenshot de sucesso.
     *
     * @param  array<int, string>  $screenshots
     */
    private static function inferirEtapaDeErroFatal(array $screenshots): ?string
    {
        if ($screenshots === []) {
            return null;
        }

        $nomes = array_map(
            fn ($caminho) => self::prefixoScreenshot((string) $caminho),
            $screenshots,
        );

        if (! in_array('erro_fatal', $nomes, true)) {
            return null;
        }

        $ultimoConcluido = null;
        foreach ($nomes as $nome) {
            if ($nome === 'erro_fatal') {
                break;
            }

            if (! str_starts_with($nome, 'timeout_')) {
                $ultimoConcluido = $nome;
            }
        }

        if ($ultimoConcluido && isset(self::PROXIMA_ETAPA_APOS_SCREENSHOT[$ultimoConcluido])) {
            return self::PROXIMA_ETAPA_APOS_SCREENSHOT[$ultimoConcluido];
        }

        return null;
    }

    private static function normalizarRotuloEtapa(?string $etapa): ?string
    {
        if (blank($etapa)) {
            return null;
        }

        return match ($etapa) {
            'Condições comerciais', 'condicoes_comerciais' => 'Plano / promoção mobile',
            default => $etapa,
        };
    }

    /**
     * @param  array<string, mixed>|null  $contexto
     * @param  iterable<int, object>|null  $logsRecentes
     * @return array<int, string>
     */
    private static function coletarScreenshots(?array $contexto, ?iterable $logsRecentes): array
    {
        $detalhe = self::extrairDetalhe($contexto);
        $screenshots = data_get($contexto, 'screenshots') ?? data_get($detalhe, 'screenshots');

        if (is_array($screenshots) && $screenshots !== []) {
            return array_values(array_map('strval', $screenshots));
        }

        if ($logsRecentes === null) {
            return [];
        }

        $lista = $logsRecentes instanceof \Illuminate\Support\Collection
            ? $logsRecentes->reverse()->values()
            : collect(is_array($logsRecentes) ? $logsRecentes : iterator_to_array($logsRecentes))->reverse()->values();

        foreach ($lista as $log) {
            if (! is_array($log->detalhe ?? null)) {
                continue;
            }

            $candidatos = self::coletarScreenshots($log->detalhe, null);
            if ($candidatos !== []) {
                return $candidatos;
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>|null  $contexto
     */
    private static function extrairErroTecnico(?string $erro, ?array $contexto): ?string
    {
        $tecnico = data_get($contexto, 'erro_tecnico');
        if (filled($tecnico)) {
            return (string) $tecnico;
        }

        $detalhe = self::extrairDetalhe($contexto);
        $erroDetalhe = data_get($detalhe, 'erro');
        if (filled($erroDetalhe) && $erroDetalhe !== $erro) {
            return (string) $erroDetalhe;
        }

        if (str_contains((string) $erro, 'Stacktrace:')) {
            return $erro;
        }

        return null;
    }

    private static function rotuloEtapa(?string $codigo): ?string
    {
        if (blank($codigo)) {
            return null;
        }

        return match ($codigo) {
            'segmento' => 'Segmento',
            'condicoes_comerciais' => 'Plano / promoção mobile',
            'plano_promocao' => 'Plano / promoção mobile',
            'endereco' => 'Endereço',
            'dados_pf' => 'Dados pessoais (PF)',
            'dados_empresa' => 'Dados da empresa',
            'dados_proprietario' => 'Dados do proprietário',
            'dados_iniciais' => 'Dados iniciais',
            'login' => 'Login no portal',
            'email' => 'Validação de e-mail',
            'proposta' => 'Proposta comercial',
            default => Str::headline(str_replace('_', ' ', $codigo)),
        };
    }

    /**
     * @param  array<string, mixed>|null  $contexto
     */
    private static function extrairDetalhe(?array $contexto): ?array
    {
        if (! is_array($contexto)) {
            return null;
        }

        if (isset($contexto['resultado']) && is_array($contexto['resultado'])) {
            $resultado = $contexto['resultado'];
            if (isset($resultado['detalhe']) && is_array($resultado['detalhe'])) {
                return $resultado['detalhe'];
            }

            return $resultado;
        }

        if (isset($contexto['status'], $contexto['resultado'])) {
            return self::extrairDetalhe(['resultado' => $contexto['resultado']]);
        }

        if (isset($contexto['detalhe']) && is_array($contexto['detalhe'])) {
            return $contexto['detalhe'];
        }

        return $contexto;
    }

    /**
     * @param  array<string, mixed>|null  $detalhe
     * @deprecated use coletarScreenshots()
     */
    private static function temScreenshots(?array $detalhe): bool
    {
        $screenshots = data_get($detalhe, 'screenshots');

        return is_array($screenshots) && $screenshots !== [];
    }
}
