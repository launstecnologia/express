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
        $zipTmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $sheetTmp = tempnam(sys_get_temp_dir(), 'xlsx-sheet');

        if ($zipTmp === false || $sheetTmp === false) {
            throw new RuntimeException('Não foi possível criar arquivo temporário para o Excel.');
        }

        self::escreverSheet($sheetTmp, $cabecalhos, $linhas);

        $zip = new ZipArchive;
        if ($zip->open($zipTmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($zipTmp);
            @unlink($sheetTmp);
            throw new RuntimeException('Não foi possível criar o arquivo Excel.');
        }

        $zip->addFromString('[Content_Types].xml', self::contentTypes());
        $zip->addFromString('_rels/.rels', self::rels());
        $zip->addFromString('xl/workbook.xml', self::workbook($nomePlanilha));
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRels());
        $zip->addFile($sheetTmp, 'xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($sheetTmp);

        return $zipTmp;
    }

    /**
     * @param  list<string>  $cabecalhos
     * @param  iterable<int, list<string|int|float|null>>  $linhas
     */
    private static function escreverSheet(string $caminho, array $cabecalhos, iterable $linhas): void
    {
        $handle = fopen($caminho, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Não foi possível gravar a planilha Excel.');
        }

        fwrite($handle, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>');
        fwrite($handle, '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>');
        fwrite($handle, self::xmlLinha(1, $cabecalhos));

        $numero = 1;
        foreach ($linhas as $linha) {
            $numero++;
            fwrite($handle, self::xmlLinha($numero, $linha));
        }

        $ultimaColuna = self::coluna(max(1, count($cabecalhos)));
        fwrite($handle, '</sheetData>');
        fwrite($handle, '<autoFilter ref="A1:'.$ultimaColuna.$numero.'"/>');
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

    private static function contentTypes(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
</Types>
XML;
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

    private static function workbook(string $nomePlanilha): string
    {
        $nome = htmlspecialchars($nomePlanilha, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="{$nome}" sheetId="1" r:id="rId1"/>
  </sheets>
</workbook>
XML;
    }

    private static function workbookRels(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
</Relationships>
XML;
    }
}
