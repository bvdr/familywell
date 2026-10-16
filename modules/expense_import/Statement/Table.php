<?php

namespace Modules\ExpenseImport\Statement;

use RuntimeException;
use ZipArchive;

// A bank statement file -> a plain table (list of rows, each a list of cell strings). CSV with the standard
// library; XLSX read straight from its zip/XML (first sheet), so no spreadsheet dependency for what is, for a
// statement, a single sheet of strings and numbers.
class Table
{
    // A statement is a few hundred rows; this keeps a wrong (or hostile) file from eating the request.
    public const MAX_ROWS = 5000;

    /** @return list<list<string>> */
    public static function read(string $path, string $filename): array
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $rows = $ext === 'xlsx' ? self::xlsx($path) : self::csv((string) file_get_contents($path));

        // Drop fully empty rows and trailing empty cells; keep the rest exactly as the bank wrote it.
        $out = [];
        foreach ($rows as $row) {
            $row = array_map(fn ($c) => trim((string) $c), $row);
            while ($row !== [] && end($row) === '') {
                array_pop($row);
            }
            if ($row !== []) {
                $out[] = $row;
            }
            if (count($out) >= self::MAX_ROWS) {
                break;
            }
        }

        return $out;
    }

    /** @return list<list<string>> */
    public static function csv(string $text): array
    {
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text; // UTF-8 BOM
        // Romanian bank exports are often Windows-1250 (ș, ț, ă...). Anything that isn't valid UTF-8 is read as that.
        // iconv, not mbstring: mbstring has no Windows-1250 (CP1250) table and throws on it.
        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = iconv('CP1250', 'UTF-8//IGNORE', $text) ?: $text;
        }

        // Delimiter: whichever of , ; tab splits the first lines into the most columns (EU banks use ';').
        $sample = implode("\n", array_slice(preg_split('/\R/', $text) ?: [], 0, 10));
        $delimiter = collect([',', ';', "\t"])->sortByDesc(fn ($d) => substr_count($sample, $d))->first();

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $text);
        rewind($handle);
        $rows = [];
        while (($row = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
            if ($row !== [null]) {
                $rows[] = $row;
            }
            if (count($rows) > self::MAX_ROWS) {
                break;
            }
        }
        fclose($handle);

        return $rows;
    }

    /** @return list<list<string>> */
    public static function xlsx(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('not an xlsx file');
        }

        // Text cells point into the shared-strings table (a <si> is plain <t> or rich-text runs <r><t>).
        $shared = [];
        if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            foreach (simplexml_load_string($xml)->si as $si) {
                $shared[] = isset($si->t) ? (string) $si->t : implode('', array_map('strval', $si->xpath('.//*[local-name()="t"]')));
            }
        }

        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        if ($sheet === false) {
            throw new RuntimeException('xlsx without a first sheet');
        }

        $rows = [];
        foreach (simplexml_load_string($sheet)->sheetData->row as $r) {
            $row = [];
            foreach ($r->c as $c) {
                // Cells can skip blanks, so place each by its column letters (B7 -> index 1).
                $col = self::columnIndex((string) $c['r']);
                $type = (string) $c['t'];
                $value = match ($type) {
                    's' => $shared[(int) $c->v] ?? '',
                    'inlineStr' => (string) ($c->is->t ?? ''),
                    'b' => (string) $c->v === '1' ? 'TRUE' : 'FALSE',
                    default => (string) $c->v, // numbers (incl. dates as Excel serials, see Values::date) and str
                };
                $row[$col] = $value;
            }
            if ($row !== []) {
                $filled = array_fill(0, max(array_keys($row)) + 1, '');
                $rows[] = array_replace($filled, $row);
            }
            if (count($rows) > self::MAX_ROWS) {
                break;
            }
        }

        return $rows;
    }

    private static function columnIndex(string $ref): int
    {
        $letters = preg_replace('/\d+/', '', $ref) ?: 'A';
        $n = 0;
        foreach (str_split(strtoupper($letters)) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return $n - 1;
    }
}
