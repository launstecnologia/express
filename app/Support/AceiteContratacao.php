<?php

namespace App\Support;

use App\Models\Estabelecimento;
use App\Models\PlanoTaxa;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class AceiteContratacao
{
    public const VERSAO = '2026-10-23';

    public const PRAZO = 'Conforme o plano, após a aprovação da transação';

    public const TEXTO_TAXAS = 'As taxas abaixo são o percentual total descontado da venda na modalidade indicada, incluindo os componentes aplicáveis ao plano. O parcelamento considera os custos suportados pelo estabelecimento. Os prazos de recebimento seguem o plano contratado e a análise de segurança das transações.';

    public const TEXTO_CADASTRO = 'Ao confirmar, você declara ser o titular do cadastro ou representante autorizado do estabelecimento, que os dados e documentos fornecidos são verdadeiros e que a atividade comercial informada corresponde à atividade exercida. Compromete-se a comunicar alterações cadastrais e a usar a maquininha apenas em operações comerciais legítimas. Não são permitidas vendas simuladas, operações para gerar crédito sem venda efetiva ou uso indevido de dados e cartões de terceiros.';

    public const TEXTO_CHARGEBACK = 'Uma transação pode ser contestada pelo titular do cartão e resultar em chargeback, isto é, na reversão do pagamento. O tratamento da contestação, a apresentação de documentos e o eventual ajuste de valores seguem o contrato aplicável e a legislação, com apuração das circunstâncias e responsabilidades. A aprovação da venda não afasta a possibilidade de contestação posterior. Mantenha os comprovantes de venda, entrega ou prestação do serviço e apresente-os quando solicitados.';

    public const TEXTO_CONFERENCIA = 'Confira se este resumo corresponde ao que foi oferecido. Em caso de divergência, fale com a empresa parceira antes de assinar. Este registro complementa os documentos da contratação e não substitui os contratos aplicáveis do PagBank. O silêncio ou o simples recebimento destas condições não constituem aceite. A concordância ocorre somente com a assinatura nesta página.';

    public const CONDICOES = [
        'titular' => 'Titular do cadastro',
        'representante' => 'Representante autorizado',
    ];

    /**
     * @return array<int, array{tipo: string, titulo: string, ajuda: string, camera: string}>
     */
    public static function documentosExigidos(?string $pessoaTipo): array
    {
        $meta = [
            'RG/CNH - Frente' => [
                'titulo' => 'CNH ou RG — frente',
                'ajuda' => 'Foto nítida da frente da CNH ou do RG. Envie só um dos documentos.',
                'camera' => 'environment',
            ],
            'RG/CNH - Verso' => [
                'titulo' => 'CNH ou RG — verso',
                'ajuda' => 'Verso do mesmo documento enviado na frente.',
                'camera' => 'environment',
            ],
            'Contrato Social (Se for MEI - CCMEI)' => [
                'titulo' => 'Contrato social ou CCMEI',
                'ajuda' => 'Contrato social da empresa. Se for MEI, envie o CCMEI.',
                'camera' => 'environment',
            ],
            'Comprovante de Endereço' => [
                'titulo' => 'Comprovante de endereço',
                'ajuda' => 'Conta de consumo ou documento recente com o endereço do estabelecimento.',
                'camera' => 'environment',
            ],
            'Comprovante da Atividade' => [
                'titulo' => 'Comprovante de atividade',
                'ajuda' => 'Foto da fachada, do ponto comercial ou de algo que mostre a atividade funcionando.',
                'camera' => 'environment',
            ],
            'Selfie Segurando Documento' => [
                'titulo' => 'Selfie com o documento',
                'ajuda' => 'Seu rosto e o documento de identificação precisam aparecer juntos na foto.',
                'camera' => 'user',
            ],
        ];

        $itens = [];

        foreach (KycTipoDocumentoMapper::tiposEstabelecimento($pessoaTipo) as $tipo) {
            $itens[] = array_merge($meta[$tipo] ?? [
                'titulo' => $tipo,
                'ajuda' => '',
                'camera' => 'environment',
            ], ['tipo' => $tipo]);
        }

        return $itens;
    }

    /**
     * @return array<string, mixed>
     */
    public static function resumo(Estabelecimento $estabelecimento): array
    {
        $estabelecimento->loadMissing(['plano.taxas', 'marketplace', 'revenda']);

        $ehPj = $estabelecimento->pessoa_tipo === 'juridica';
        $nome = $estabelecimento->nome_fantasia
            ?: $estabelecimento->razao_social
            ?: $estabelecimento->nome_completo
            ?: 'Estabelecimento';
        $documentoBruto = $ehPj ? $estabelecimento->cnpj : $estabelecimento->cpf;
        $responsavel = $ehPj
            ? ($estabelecimento->rep_nome ?: $estabelecimento->nome_completo)
            : $estabelecimento->nome_completo;
        $cpfSugerido = $ehPj ? $estabelecimento->rep_cpf : $estabelecimento->cpf;

        $partesEndereco = array_filter([
            trim(($estabelecimento->endereco ?: '').($estabelecimento->numero ? ', '.$estabelecimento->numero : '')),
            $estabelecimento->complemento,
            $estabelecimento->bairro,
            trim(($estabelecimento->cidade ?: '').($estabelecimento->uf ? '/'.$estabelecimento->uf : '')),
            $estabelecimento->cep ? 'CEP '.$estabelecimento->cep : null,
        ]);

        return [
            'versao' => self::VERSAO,
            'pessoa_tipo' => $estabelecimento->pessoa_tipo,
            'pessoa_label' => $ehPj ? 'Pessoa jurídica' : 'Pessoa física',
            'estabelecimento' => $nome,
            'razao_social' => $estabelecimento->razao_social,
            'documento' => DocumentoBrasil::formatarCpfOuCnpj((string) $documentoBruto) ?: '—',
            'documento_digitos' => DocumentoBrasil::apenasDigitos((string) $documentoBruto),
            'responsavel' => trim((string) $responsavel),
            'cpf_sugerido' => DocumentoBrasil::formatarCpf((string) $cpfSugerido),
            'endereco' => $partesEndereco !== [] ? implode(' · ', $partesEndereco) : null,
            'email' => $estabelecimento->email,
            'telefone' => $estabelecimento->celular ?: $estabelecimento->telefone,
            'segmento' => $estabelecimento->segmento,
            'plano_id' => $estabelecimento->plano_id,
            'plano_nome' => $estabelecimento->plano?->nome,
            'empresa' => self::empresaParceira($estabelecimento),
            'taxas' => self::linhasDeTaxas($estabelecimento->plano?->taxas ?? collect()),
            'prazo' => self::PRAZO,
            'texto_taxas' => self::TEXTO_TAXAS,
            'texto_cadastro' => self::TEXTO_CADASTRO,
            'texto_chargeback' => self::TEXTO_CHARGEBACK,
            'texto_conferencia' => self::TEXTO_CONFERENCIA,
        ];
    }

    /**
     * @param  Collection<int, PlanoTaxa>  $taxas
     * @return array<int, array<string, mixed>>
     */
    public static function linhasDeTaxas(Collection $taxas): array
    {
        $ativas = $taxas->filter(function ($taxa) {
            return $taxa instanceof PlanoTaxa
                && (bool) $taxa->ativo
                && $taxa->taxa_percentual !== null
                && $taxa->taxa_percentual !== '';
        });

        $linhas = [];

        $debito = $ativas->where('tipo_transacao', 'debito');
        if ($debito->isNotEmpty()) {
            $linhas[] = self::linhaModalidade('Débito', $debito);
        }

        $credito = $ativas
            ->where('tipo_transacao', 'credito')
            ->groupBy(fn (PlanoTaxa $taxa) => (int) $taxa->parcelas)
            ->sortKeys();

        foreach ($credito as $parcelas => $grupo) {
            $rotulo = (int) $parcelas <= 1
                ? 'Crédito à vista'
                : 'Crédito parcelado em '.$parcelas.'x';
            $linhas[] = self::linhaModalidade($rotulo, $grupo);
        }

        $pix = $ativas->where('tipo_transacao', 'pix');
        if ($pix->isNotEmpty()) {
            $linhas[] = self::linhaModalidade('Pix na maquininha', $pix);
        }

        return $linhas;
    }

    public static function declaracao(
        string $estabelecimento,
        string $documento,
        ?string $plano,
        string $nomeResponsavel,
        string $cpfResponsavel,
        string $condicaoLabel,
    ): string {
        $planoTxt = filled($plano) ? 'plano '.$plano : 'plano informado neste aceite';

        return "Li e concordo com as condições da contratação do estabelecimento {$estabelecimento}, CPF/CNPJ {$documento}, {$planoTxt}, incluindo as taxas, os prazos de recebimento e as regras de contestação (chargeback) descritas neste aceite. Confirmo que os dados cadastrais e os documentos enviados são verdadeiros e que a atividade informada corresponde à atividade exercida. Declaro possuir poderes para representar o estabelecimento na condição de {$condicaoLabel}. Nome do responsável: {$nomeResponsavel}. CPF: {$cpfResponsavel}.";
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function hashConfirmacao(array $payload): string
    {
        return self::hashDoCanonico(self::ordenar($payload));
    }

    /**
     * @param  array<string, mixed>  $ordenado
     */
    public static function hashDoCanonico(array $ordenado): string
    {
        $json = json_encode($ordenado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new \RuntimeException('Falha ao gerar o hash de confirmação.');
        }

        return hash('sha256', $json);
    }

    public static function ordenar(mixed $valor): mixed
    {
        if (! is_array($valor)) {
            return $valor;
        }

        if (array_is_list($valor)) {
            return array_map([self::class, 'ordenar'], $valor);
        }

        ksort($valor);

        foreach ($valor as $chave => $item) {
            $valor[$chave] = self::ordenar($item);
        }

        return $valor;
    }

    /**
     * @return array{bin?: string, erro?: string}
     */
    public static function validarAssinatura(string $dataUrl): array
    {
        if (! preg_match('#^data:image/png;base64,([A-Za-z0-9+/=\r\n]+)$#', $dataUrl, $encontrado)) {
            return ['erro' => 'A assinatura precisa ser uma imagem válida.'];
        }

        $bin = base64_decode($encontrado[1], true);

        if ($bin === false || strlen($bin) < 100 || strlen($bin) > 1_500_000) {
            return ['erro' => 'A assinatura está vazia ou grande demais.'];
        }

        if (! str_starts_with($bin, "\x89PNG\r\n\x1a\n")) {
            return ['erro' => 'A assinatura precisa ser uma imagem PNG.'];
        }

        if (! function_exists('imagecreatefromstring')) {
            return ['bin' => $bin];
        }

        $imagem = @imagecreatefromstring($bin);

        if (! $imagem) {
            return ['erro' => 'Não foi possível ler a assinatura.'];
        }

        if (function_exists('imagepalettetotruecolor')) {
            imagepalettetotruecolor($imagem);
        }

        $largura = imagesx($imagem);
        $altura = imagesy($imagem);
        $opaca = imagecreatetruecolor($largura, $altura);
        $branco = imagecolorallocate($opaca, 255, 255, 255);
        imagefilledrectangle($opaca, 0, 0, $largura, $altura, $branco);
        imagecopy($opaca, $imagem, 0, 0, 0, 0, $largura, $altura);
        imagedestroy($imagem);

        $temTraco = false;

        for ($y = 0; $y < $altura && ! $temTraco; $y++) {
            for ($x = 0; $x < $largura && ! $temTraco; $x++) {
                $cor = imagecolorat($opaca, $x, $y);
                $vermelho = ($cor >> 16) & 0xFF;
                $verde = ($cor >> 8) & 0xFF;
                $azul = $cor & 0xFF;

                if ($vermelho < 240 || $verde < 240 || $azul < 240) {
                    $temTraco = true;
                }
            }
        }

        imagedestroy($opaca);

        if (! $temTraco) {
            return ['erro' => 'Assine no quadro antes de confirmar.'];
        }

        return ['bin' => $bin];
    }

    public static function arquivoDiverge(?string $caminhoRelativo, ?string $shaEsperado): bool
    {
        if (! $shaEsperado || ! $caminhoRelativo) {
            return false;
        }

        $absoluto = Storage::disk('public')->path($caminhoRelativo);

        if (! is_file($absoluto)) {
            return true;
        }

        return hash_file('sha256', $absoluto) !== $shaEsperado;
    }

    /**
     * @param  Collection<int, PlanoTaxa>  $grupo
     * @return array<string, mixed>
     */
    private static function linhaModalidade(string $modalidade, Collection $grupo): array
    {
        $porValor = [];

        foreach ($grupo as $taxa) {
            $valor = number_format((float) $taxa->taxa_percentual, 2, '.', '');
            $bandeira = self::nomeBandeira((string) $taxa->instituicao);
            $porValor[$valor][$bandeira] = $bandeira;
        }

        $detalhes = [];

        foreach ($porValor as $valor => $bandeiras) {
            $lista = array_values($bandeiras);
            sort($lista, SORT_STRING);
            $detalhes[] = [
                'bandeiras' => $lista,
                'taxa' => (string) $valor,
            ];
        }

        usort($detalhes, function (array $a, array $b): int {
            return [$a['taxa'], implode(',', $a['bandeiras'])] <=> [$b['taxa'], implode(',', $b['bandeiras'])];
        });

        $unica = count($detalhes) === 1;

        return [
            'bandeiras' => $unica ? $detalhes[0]['bandeiras'] : [],
            'detalhes' => $unica ? [] : $detalhes,
            'modalidade' => $modalidade,
            'prazo' => self::PRAZO,
            'taxa' => $unica ? $detalhes[0]['taxa'] : null,
        ];
    }

    private static function nomeBandeira(string $instituicao): string
    {
        return match (strtoupper($instituicao)) {
            'MASTERCARD' => 'Mastercard',
            'HIPERCARD' => 'Hipercard',
            'BANRICOMPRAS' => 'Banricompras',
            'BACEN' => 'Pix',
            'AMEX' => 'Amex',
            'DINERS' => 'Diners',
            'CABAL' => 'Cabal',
            default => strtoupper($instituicao),
        };
    }

    /**
     * @return array{nome: string, razao_social: ?string, documento: string, telefone: ?string, email: ?string}
     */
    private static function empresaParceira(Estabelecimento $estabelecimento): array
    {
        $parceiro = $estabelecimento->marketplace ?: $estabelecimento->revenda;

        if ($parceiro) {
            $documento = $parceiro->pessoa_tipo === 'fisica' ? $parceiro->cpf : $parceiro->cnpj;

            return [
                'nome' => $parceiro->nomeExibicao(),
                'razao_social' => $parceiro->razao_social ?: $parceiro->nome_completo,
                'documento' => DocumentoBrasil::formatarCpfOuCnpj((string) $documento),
                'telefone' => $parceiro->telefone ?: $parceiro->celular,
                'email' => $parceiro->email,
            ];
        }

        $plataforma = PlatformSettings::dadosEmpresa();

        return [
            'nome' => (string) $plataforma['nome_exibicao'],
            'razao_social' => $plataforma['razao_social'] ?: $plataforma['nome_exibicao'],
            'documento' => filled($plataforma['cnpj'])
                ? DocumentoBrasil::formatarCnpj((string) $plataforma['cnpj'])
                : '',
            'telefone' => $plataforma['telefone'] ?: $plataforma['celular'],
            'email' => $plataforma['email'],
        ];
    }
}
