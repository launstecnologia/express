<?php

namespace App\Support;

use RuntimeException;
use ZipArchive;

/**
 * Gravador mínimo de .xlsx (uma planilha) sem dependências externas.
 */
class SimpleXlsxWriter
{
    /**
     * @param  list<string>  $cabecalhos
     * @param  iterable<int, list<string|int|float|null>>  $linhas
     */
    public static function binary(array $cabecalhos, iterable $linhas, string $nomePlanilha = 'Planilha'): string
    {
        $caminho = self::file($cabecalhos, $linhas, $nomePlanilha);
        $conteudo = file_get_contents($caminho);
        @unlink($caminho);

        if ($conteudo === false) {
            throw new RuntimeException('Não foi possível ler o Excel gerado.');
        }

        return $conteudo;
    }

    /**
     * Gera o .xlsx em arquivo temporário (não carrega a planilha inteira na memória).
     *
     * @param  list<string>  $cabecalhos
     * @param  iterable<int, list<string|int|float|null>>  $linhas
     */
    public static function file(array $cabecalhos, iterable $linhas, string $nomePlanilha = 'Planilha'): string
    {
        return self::fileSheets([[
            'nome' => $nomePlanilha,
            'cabecalhos' => $cabecalhos,
            'linhas' => $linhas,
            'autoFiltro' => true,
        ]]);
    }

    /**
     * @param  list<array{
     *     nome: string,
     *     linhas: iterable<int, list<mixed>>,
     *     cabecalhos?: list<string>,
     *     autoFiltro?: bool,
     *     autoFiltroInicio?: string,
     *     autoFiltroColunaFim?: string,
     *     larguras?: list<float|int>,
     *     congelar?: int,
     *     mesclar?: list<string>
     * }>  $planilhas
     */
    public static function fileSheets(array $planilhas): string
    {
        if ($planilhas === []) {
            throw new RuntimeException('Nenhuma planilha para gravar no Excel.');
        }

        $zipTmp = tempnam(sys_get_temp_dir(), 'xlsx');
        if ($zipTmp === false) {
            throw new RuntimeException('Não foi possível criar arquivo temporário para o Excel.');
        }

        $zip = new ZipArchive;
        if ($zip->open($zipTmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($zipTmp);
            throw new RuntimeException('Não foi possível criar o arquivo Excel.');
        }

        $nomes = [];
        $temporarios = [];

        try {
            foreach (array_values($planilhas) as $indice => $planilha) {
                $sheetTmp = tempnam(sys_get_temp_dir(), 'xlsx-sheet');
                if ($sheetTmp === false) {
                    throw new RuntimeException('Não foi possível criar arquivo temporário para o Excel.');
                }
                $temporarios[] = $sheetTmp;

                $cabecalhos = array_values($planilha['cabecalhos'] ?? []);
                $autoFiltro = (bool) ($planilha['autoFiltro'] ?? $cabecalhos !== []);
                self::escreverSheet($sheetTmp, $cabecalhos, $planilha['linhas'] ?? [], [
                    'autoFiltro' => $autoFiltro,
                    'autoFiltroInicio' => $planilha['autoFiltroInicio'] ?? null,
                    'autoFiltroColunaFim' => $planilha['autoFiltroColunaFim'] ?? null,
                    'larguras' => $planilha['larguras'] ?? [],
                    'congelar' => (int) ($planilha['congelar'] ?? 0),
                    'mesclar' => $planilha['mesclar'] ?? [],
                ]);

                $numero = $indice + 1;
                $nomes[] = self::nomeUnico((string) ($planilha['nome'] ?? 'Planilha '.$numero), $nomes);
                $zip->addFile($sheetTmp, 'xl/worksheets/sheet'.$numero.'.xml');
            }

            $zip->addFromString('[Content_Types].xml', self::contentTypes(count($nomes)));
            $zip->addFromString('_rels/.rels', self::rels());
            $zip->addFromString('xl/styles.xml', self::styles());
            $zip->addFromString('xl/workbook.xml', self::workbookSheets($nomes));
            $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRelsSheets(count($nomes)));
            $zip->close();
        } catch (\Throwable $e) {
            $zip->close();
            foreach ($temporarios as $tmp) {
                @unlink($tmp);
            }
            @unlink($zipTmp);
            throw $e;
        }

        foreach ($temporarios as $tmp) {
            @unlink($tmp);
        }

        return $zipTmp;
    }

    /**
     * @param  list<string>  $cabecalhos
     * @param  iterable<int, list<mixed>>  $linhas
     * @param  array{
     *     autoFiltro?: bool,
     *     autoFiltroInicio?: ?string,
     *     autoFiltroColunaFim?: ?string,
     *     larguras?: list<float|int>,
     *     congelar?: int,
     *     mesclar?: list<string>
     * }  $opcoes
     */
    private static function escreverSheet(string $caminho, array $cabecalhos, iterable $linhas, array $opcoes = []): void
    {
        $handle = fopen($caminho, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Não foi possível gravar a planilha Excel.');
        }

        $autoFiltro = (bool) ($opcoes['autoFiltro'] ?? true);
        $larguras = array_values($opcoes['larguras'] ?? []);
        $congelar = (int) ($opcoes['congelar'] ?? 0);
        $mesclar = array_values($opcoes['mesclar'] ?? []);

        fwrite($handle, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>');
        fwrite($handle, '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">');
        fwrite($handle, self::xmlSheetViews($congelar));
        fwrite($handle, '<sheetFormatPr defaultRowHeight="22" customHeight="1"/>');
        fwrite($handle, self::xmlCols($larguras));
        fwrite($handle, '<sheetData>');

        $numero = 0;
        $largura = max(1, count($cabecalhos), count($larguras));
        if ($cabecalhos !== []) {
            $numero = 1;
            $celulas = [];
            foreach ($cabecalhos as $valor) {
                $celulas[] = is_array($valor) ? $valor : ['v' => $valor, 'estilo' => 'cabecalho'];
            }
            fwrite($handle, self::xmlLinha(1, $celulas));
        }

        foreach ($linhas as $linha) {
            $numero++;
            $valores = is_array($linha) ? $linha : [];
            $largura = max($largura, count($valores));
            fwrite($handle, self::xmlLinha($numero, $valores));
        }

        $numero = max(1, $numero);
        fwrite($handle, '</sheetData>');

        if ($autoFiltro) {
            fwrite($handle, '<autoFilter ref="'.self::refFiltro($opcoes, $largura, $numero).'"/>');
        }
        if ($mesclar !== []) {
            fwrite($handle, '<mergeCells count="'.count($mesclar).'">');
            foreach ($mesclar as $ref) {
                fwrite($handle, '<mergeCell ref="'.htmlspecialchars((string) $ref, ENT_XML1 | ENT_QUOTES, 'UTF-8').'"/>');
            }
            fwrite($handle, '</mergeCells>');
        }
        fwrite($handle, '</worksheet>');
        fclose($handle);
    }

    /**
     * @param  array<string, mixed>  $opcoes
     */
    private static function refFiltro(array $opcoes, int $largura, int $ultimaLinha): string
    {
        $inicio = trim((string) ($opcoes['autoFiltroInicio'] ?? ''));
        if ($inicio === '') {
            return 'A1:'.self::coluna($largura).$ultimaLinha;
        }

        $colunaFim = strtoupper(trim((string) ($opcoes['autoFiltroColunaFim'] ?? '')));
        if ($colunaFim === '') {
            $colunaFim = self::coluna($largura);
        }

        return $inicio.':'.$colunaFim.$ultimaLinha;
    }

    /**
     * @param  list<float|int>  $larguras
     */
    private static function xmlCols(array $larguras): string
    {
        if ($larguras === []) {
            return '';
        }

        $cols = [];
        foreach ($larguras as $indice => $largura) {
            $n = $indice + 1;
            $cols[] = '<col min="'.$n.'" max="'.$n.'" width="'.htmlspecialchars((string) $largura, ENT_XML1 | ENT_QUOTES, 'UTF-8').'" customWidth="1"/>';
        }

        return '<cols>'.implode('', $cols).'</cols>';
    }

    private static function xmlSheetViews(int $congelar): string
    {
        if ($congelar < 1) {
            return '<sheetViews><sheetView workbookViewId="0"/></sheetViews>';
        }

        $abaixo = $congelar + 1;

        return '<sheetViews><sheetView workbookViewId="0">'
            .'<pane ySplit="'.$congelar.'" topLeftCell="A'.$abaixo.'" activePane="bottomLeft" state="frozen"/>'
            .'<selection pane="bottomLeft" activeCell="A'.$abaixo.'" sqref="A'.$abaixo.'"/>'
            .'</sheetView></sheetViews>';
    }

    /**
     * @param  list<mixed>  $valores
     */
    private static function xmlLinha(int $numero, array $valores): string
    {
        $celulas = [];
        foreach (array_values($valores) as $idx => $valor) {
            $ref = self::coluna($idx + 1).$numero;
            $celulas[] = self::xmlCelula($ref, $valor);
        }

        return '<row r="'.$numero.'" ht="22" customHeight="1">'.implode('', $celulas).'</row>';
    }

    private static function xmlCelula(string $ref, mixed $valor): string
    {
        $estilo = null;
        if (is_array($valor) && array_key_exists('v', $valor)) {
            $estilo = isset($valor['estilo']) ? (string) $valor['estilo'] : null;
            $valor = $valor['v'];
        }

        $estiloId = self::idEstilo($estilo, $valor);
        $attrEstilo = ' s="'.$estiloId.'"';

        if ($valor === null || $valor === '') {
            return '<c r="'.$ref.'"'.$attrEstilo.'/>';
        }

        if (is_int($valor) || is_float($valor)) {
            $numero = is_float($valor) ? sprintf('%.2f', $valor) : (string) $valor;

            return '<c r="'.$ref.'"'.$attrEstilo.'><v>'.$numero.'</v></c>';
        }

        $texto = htmlspecialchars((string) $valor, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return '<c r="'.$ref.'"'.$attrEstilo.' t="inlineStr"><is><t xml:space="preserve">'.$texto.'</t></is></c>';
    }

    private static function idEstilo(?string $nome, mixed $valor): int
    {
        return match ($nome) {
            'cabecalho' => 1,
            'numero' => 2,
            'numero_negrito' => 3,
            'titulo' => 4,
            default => is_float($valor) ? 2 : 0,
        };
    }

    private static function styles(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <numFmts count="1">
    <numFmt numFmtId="164" formatCode="#,##0.00"/>
  </numFmts>
  <fonts count="3">
    <font><sz val="11"/><name val="Calibri"/><family val="2"/></font>
    <font><b/><sz val="11"/><name val="Calibri"/><family val="2"/></font>
    <font><b/><sz val="16"/><name val="Calibri"/><family val="2"/></font>
  </fonts>
  <fills count="3">
    <fill><patternFill patternType="none"/></fill>
    <fill><patternFill patternType="gray125"/></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFD6E3F0"/><bgColor rgb="FFD6E3F0"/></patternFill></fill>
  </fills>
  <borders count="1">
    <border><left/><right/><top/><bottom/><diagonal/></border>
  </borders>
  <cellStyleXfs count="1">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>
  </cellStyleXfs>
  <cellXfs count="5">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="center" wrapText="false"/></xf>
    <xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="false"/></xf>
    <xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="right" vertical="center" wrapText="false"/></xf>
    <xf numFmtId="164" fontId="1" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1" applyAlignment="1"><alignment horizontal="right" vertical="center" wrapText="false"/></xf>
    <xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="left" vertical="center" wrapText="false"/></xf>
  </cellXfs>
</styleSheet>
XML;
    }

    private static function coluna(int $indice): string
    {
        $letra = '';
        while ($indice > 0) {
            $indice--;
            $letra = chr(65 + ($indice % 26)).$letra;
            $indice = intdiv($indice, 26);
        }

        return $letra;
    }

    private static function contentTypes(int $qtdPlanilhas = 1): string
    {
        $overrides = [
            '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>',
            '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>',
        ];
        for ($i = 1; $i <= $qtdPlanilhas; $i++) {
            $overrides[] = '<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .implode('', $overrides)
            .'</Types>';
    }

    private static function rels(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>
XML;
    }

    /**
     * @param  list<string>  $nomes
     */
    private static function workbookSheets(array $nomes): string
    {
        $sheets = [];
        foreach ($nomes as $indice => $nome) {
            $n = $indice + 1;
            $sheets[] = '<sheet name="'.htmlspecialchars($nome, ENT_XML1 | ENT_QUOTES, 'UTF-8').'" sheetId="'.$n.'" r:id="rId'.$n.'"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'.implode('', $sheets).'</sheets>'
            .'</workbook>';
    }

    private static function workbookRelsSheets(int $qtdPlanilhas): string
    {
        $rels = [
            '<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>',
        ];
        for ($i = 1; $i <= $qtdPlanilhas; $i++) {
            $rels[] = '<Relationship Id="rId'.$i.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$i.'.xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .implode('', $rels)
            .'</Relationships>';
    }

    /**
     * @param  list<string>  $usados
     */
    private static function nomeUnico(string $nome, array $usados): string
    {
        $limpo = trim(preg_replace('/[:\\\\\\/\\?\\*\\[\\]]+/', ' ', $nome) ?? $nome);
        $limpo = $limpo !== '' ? $limpo : 'Planilha';
        $base = mb_substr($limpo, 0, 31);
        $candidato = $base;
        $n = 2;
        while (in_array($candidato, $usados, true)) {
            $sufixo = ' ('.$n.')';
            $candidato = mb_substr($base, 0, 31 - strlen($sufixo)).$sufixo;
            $n++;
        }

        return $candidato;
    }
}
