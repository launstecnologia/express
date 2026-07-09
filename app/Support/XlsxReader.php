<?php

namespace App\Support;

use RuntimeException;
use ZipArchive;

/**
 * Leitor mínimo de planilhas .xlsx (Office Open XML) sem dependências externas.
 */
class XlsxReader
{
    /** @var array<int, string> */
    private array $sharedStrings = [];

    /** @var array<int, array<int, mixed>> */
    private array $rows = [];

    public static function load(string $path, ?string $sheetName = null): self
    {
        $reader = new self;
        $reader->parse($path, $sheetName);

        return $reader;
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    public function rows(): array
    {
        return $this->rows;
    }

    /**
     * @return list<string>
     */
    public function sheetNames(string $path): array
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException("Não foi possível abrir o arquivo: {$path}");
        }

        $workbook = $zip->getFromName('xl/workbook.xml');
        $zip->close();

        if ($workbook === false) {
            return [];
        }

        preg_match_all('/<sheet[^>]+name="([^"]+)"/', $workbook, $matches);

        return $matches[1] ?? [];
    }

    private function parse(string $path, ?string $sheetName): void
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException("Não foi possível abrir o arquivo: {$path}");
        }

        $shared = $zip->getFromName('xl/sharedStrings.xml');

        if ($shared !== false) {
            $this->sharedStrings = $this->parseSharedStrings($shared);
        }

        $sheetPath = $this->resolveSheetPath($zip, $sheetName);
        $sheetXml = $zip->getFromName($sheetPath);

        if ($sheetXml === false) {
            $zip->close();
            throw new RuntimeException("Aba não encontrada: {$sheetName}");
        }

        $this->rows = $this->parseSheet($sheetXml);
        $zip->close();
    }

    private function resolveSheetPath(ZipArchive $zip, ?string $sheetName): string
    {
        if ($sheetName === null) {
            return 'xl/worksheets/sheet1.xml';
        }

        $workbook = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if ($workbook === false || $rels === false) {
            throw new RuntimeException('Estrutura XLSX inválida.');
        }

        preg_match_all('/<sheet[^>]+name="([^"]+)"[^>]+r:id="([^"]+)"/', $workbook, $sheets, PREG_SET_ORDER);
        preg_match_all('/<Relationship[^>]+Id="([^"]+)"[^>]+Target="([^"]+)"/', $rels, $relationships, PREG_SET_ORDER);

        $targets = [];

        foreach ($relationships as $rel) {
            $targets[$rel[1]] = ltrim(str_replace('../', '', $rel[2]), '/');
        }

        foreach ($sheets as $sheet) {
            if (trim($sheet[1]) === trim($sheetName)) {
                $target = $targets[$sheet[2]] ?? null;

                if ($target) {
                    return 'xl/'.$target;
                }
            }
        }

        throw new RuntimeException("Aba \"{$sheetName}\" não encontrada no arquivo.");
    }

    /**
     * @return array<int, string>
     */
    private function parseSharedStrings(string $xml): array
    {
        $doc = simplexml_load_string($xml);

        if ($doc === false) {
            return [];
        }

        $strings = [];

        foreach ($doc->si as $si) {
            if (isset($si->t)) {
                $strings[] = (string) $si->t;
            } else {
                $text = '';

                foreach ($si->r as $run) {
                    $text .= (string) $run->t;
                }

                $strings[] = $text;
            }
        }

        return $strings;
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function parseSheet(string $xml): array
    {
        $doc = simplexml_load_string($xml);

        if ($doc === false) {
            return [];
        }

        $rows = [];

        foreach ($doc->sheetData->row as $row) {
            $rowIndex = (int) $row['r'];
            $cells = [];

            foreach ($row->c as $cell) {
                $ref = (string) $cell['r'];
                $col = $this->columnIndex($ref);
                $cells[$col] = $this->cellValue($cell);
            }

            if ($cells !== []) {
                $max = max(array_keys($cells));
                $line = [];

                for ($i = 0; $i <= $max; $i++) {
                    $line[] = $cells[$i] ?? null;
                }

                $rows[$rowIndex] = $line;
            }
        }

        ksort($rows);

        return array_values($rows);
    }

    private function cellValue(\SimpleXMLElement $cell): mixed
    {
        $type = (string) $cell['t'];

        if ($type === 'inlineStr') {
            return (string) ($cell->is->t ?? '');
        }

        $value = (string) ($cell->v ?? '');

        return match ($type) {
            's' => $this->sharedStrings[(int) $value] ?? $value,
            'b' => $value === '1',
            'str' => $value,
            default => is_numeric($value)
                ? (str_contains($value, '.') ? (float) $value : (int) $value)
                : $value,
        };
    }

    private function columnIndex(string $cellRef): int
    {
        preg_match('/^([A-Z]+)/', strtoupper($cellRef), $matches);
        $letters = $matches[1] ?? 'A';
        $index = 0;

        for ($i = 0; $i < strlen($letters); $i++) {
            $index = $index * 26 + (ord($letters[$i]) - 64);
        }

        return $index - 1;
    }
}
