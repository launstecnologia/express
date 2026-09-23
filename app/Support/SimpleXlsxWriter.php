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
     * @param  list<array{nome: string, linhas: iterable<int, list<string|int|float|null>>, cabecalhos?: list<string>, autoFiltro?: bool}>  $planilhas
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
                self::escreverSheet($sheetTmp, $cabecalhos, $planilha['linhas'] ?? [], $autoFiltro);

                $numero = $indice + 1;
                $nomes[] = self::nomeUnico((string) ($planilha['nome'] ?? 'Planilha '.$numero), $nomes);
                $zip->addFile($sheetTmp, 'xl/worksheets/sheet'.$numero.'.xml');
            }

            $zip->addFromString('[Content_Types].xml', self::contentTypes(count($nomes)));
            $zip->addFromString('_rels/.rels', self::rels());
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
     * @param  iterable<int, list<string|int|float|null>>  $linhas
     */
    private static function escreverSheet(string $caminho, array $cabecalhos, iterable $linhas, bool $autoFiltro = true): void
    {
        $handle = fopen($caminho, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Não foi possível gravar a planilha Excel.');
        }

        fwrite($handle, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>');
        fwrite($handle, '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>');

        $numero = 0;
        $largura = max(1, count($cabecalhos));
        if ($cabecalhos !== []) {
            $numero = 1;
            fwrite($handle, self::xmlLinha(1, $cabecalhos));
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
            fwrite($handle, '<autoFilter ref="A1:'.self::coluna($largura).$numero.'"/>');
        }
        fwrite($handle, '</worksheet>');
        fclose($handle);
    }

    /**
     * @param  list<string|int|float|null>  $valores
     */
    private static function xmlLinha(int $numero, array $valores): string
    {
        $celulas = [];
        foreach (array_values($valores) as $idx => $valor) {
            $ref = self::coluna($idx + 1).$numero;
            $celulas[] = self::xmlCelula($ref, $valor);
        }

        return '<row r="'.$numero.'">'.implode('', $celulas).'</row>';
    }

    private static function xmlCelula(string $ref, mixed $valor): string
    {
        if ($valor === null || $valor === '') {
            return '<c r="'.$ref.'"/>';
        }

        if (is_int($valor) || is_float($valor)) {
            return '<c r="'.$ref.'"><v>'.$valor.'</v></c>';
        }

        $texto = htmlspecialchars((string) $valor, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return '<c r="'.$ref.'" t="inlineStr"><is><t xml:space="preserve">'.$texto.'</t></is></c>';
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
        $rels = [];
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
