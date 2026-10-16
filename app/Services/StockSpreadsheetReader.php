<?php

namespace App\Services;

use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Reads a bounded Excel export, without executing spreadsheet formulas or macros.
 * CSV and the first worksheet of a regular XLSX are supported.
 */
class StockSpreadsheetReader
{
    public const MAX_BYTES = 4 * 1024 * 1024;
    public const MAX_ROWS = 1000;
    private const MAX_UNCOMPRESSED = 8 * 1024 * 1024;

    /**
     * @return list<array{line:int, data:array<string, string>}>
     */
    public function read(string $path, string $extension): array
    {
        $size = filesize($path);
        if (!$size || $size > self::MAX_BYTES) {
            throw new InvalidArgumentException('El archivo debe pesar menos de 4 MB y no estar vacío.');
        }

        $extension = strtolower($extension);
        $rows = match ($extension) {
            'csv', 'txt' => $this->readCsv($path),
            'xlsx' => $this->readXlsx($path),
            default => throw new InvalidArgumentException('Subí un archivo .xlsx o .csv. El formato .xls antiguo no es compatible.'),
        };

        if (count($rows) < 2) {
            throw new InvalidArgumentException('El archivo necesita una fila de encabezados y al menos un producto.');
        }

        $header = $this->normalizeHeaders(array_shift($rows));
        $result = [];

        foreach ($rows as $index => $cells) {
            if (!array_filter($cells, static fn ($value) => trim((string)$value) !== '')) continue;
            if (count($result) >= self::MAX_ROWS) {
                throw new InvalidArgumentException('El archivo supera las 1000 filas admitidas por importación.');
            }
            $data = [];
            foreach ($header as $column => $key) {
                if ($key !== null) $data[$key] = trim((string) ($cells[$column] ?? ''));
            }
            $result[] = ['line' => $index + 2, 'data' => $data];
        }

        if (!$result) throw new InvalidArgumentException('No encontré productos en el archivo.');
        return $result;
    }

    /** @return list<list<string>> */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if (!$handle) throw new RuntimeException('No se pudo leer el archivo.');

        try {
            $first = fgets($handle, 65536);
            if ($first === false) return [];
            $first = preg_replace('/^\xEF\xBB\xBF/', '', $first);
            $delimiterCounts = [
                ';' => count(str_getcsv($first, ';', '"', '')),
                ',' => count(str_getcsv($first, ',', '"', '')),
                "\t" => count(str_getcsv($first, "\t", '"', '')),
            ];
            arsort($delimiterCounts);
            $delimiter = array_key_first($delimiterCounts);
            rewind($handle);

            $rows = [];
            while (($cells = fgetcsv($handle, 65536, $delimiter, '"', '')) !== false) {
                if (count($rows) > self::MAX_ROWS + 1) {
                    throw new InvalidArgumentException('El archivo tiene demasiadas filas.');
                }
                if ($rows === [] && isset($cells[0])) {
                    $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', $cells[0]);
                }
                $rows[] = array_map(static fn ($cell) => trim((string)$cell), $cells);
            }
            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /** @return list<list<string>> */
    private function readXlsx(string $path): array
    {
        if (!class_exists(ZipArchive::class) || !function_exists('simplexml_load_string')) {
            throw new RuntimeException('Este servidor no tiene habilitada la lectura de Excel. Usá CSV o habilitá zip y XML en PHP.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) throw new InvalidArgumentException('El Excel está dañado o no es un XLSX válido.');

        try {
            $sheetPath = $this->firstWorksheetPath($zip);
            $allowed = [$sheetPath, 'xl/sharedStrings.xml'];
            foreach ($allowed as $name) {
                $stat = $zip->statName($name);
                if ($stat && (int)$stat['size'] > self::MAX_UNCOMPRESSED) {
                    throw new InvalidArgumentException('La hoja Excel contiene demasiados datos.');
                }
            }
            $xml = $zip->getFromName($sheetPath);
            if ($xml === false) throw new InvalidArgumentException('El Excel no contiene una primera hoja legible.');

            $shared = [];
            $stringsXml = $zip->getFromName('xl/sharedStrings.xml');
            if ($stringsXml !== false) {
                $strings = $this->xml($stringsXml);
                foreach ($strings->xpath('//*[local-name()="si"]') ?: [] as $item) {
                    $shared[] = implode('', array_map(
                        static fn ($node) => (string)$node,
                        $item->xpath('.//*[local-name()="t"]') ?: []
                    ));
                    if (count($shared) > 30000) throw new InvalidArgumentException('El Excel contiene demasiadas celdas de texto.');
                }
            }

            $sheet = $this->xml($xml);
            $rows = [];
            foreach ($sheet->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') ?: [] as $row) {
                $cells = [];
                foreach ($row->xpath('./*[local-name()="c"]') ?: [] as $cell) {
                    $address = (string)$cell['r'];
                    if (!preg_match('/^([A-Z]{1,3})\d+$/i', $address, $matches)) continue;
                    $column = 0;
                    foreach (str_split(strtoupper($matches[1])) as $letter) $column = $column * 26 + ord($letter) - 64;
                    $column--;
                    if ($column < 0 || $column >= 35) continue;
                    if ($cell->xpath('./*[local-name()="f"]')) {
                        throw new InvalidArgumentException('El Excel contiene fórmulas. Convertí la columna de cantidades a valores antes de importarlo.');
                    }
                    $valueNode = ($cell->xpath('./*[local-name()="v"]') ?: [])[0] ?? null;
                    $type = (string)$cell['t'];
                    $value = $valueNode !== null ? (string)$valueNode : '';
                    if ($type === 's') {
                        $value = $shared[(int)$value] ?? '';
                    } elseif ($type === 'inlineStr') {
                        $value = implode('', array_map(
                            static fn ($node) => (string)$node,
                            $cell->xpath('.//*[local-name()="t"]') ?: []
                        ));
                    }
                    $cells[$column] = trim($value);
                }
                if ($cells) {
                    $length = max(array_keys($cells)) + 1;
                    $rows[] = array_replace(array_fill(0, $length, ''), $cells);
                }
                if (count($rows) > self::MAX_ROWS + 1) throw new InvalidArgumentException('El Excel tiene demasiadas filas.');
            }
            return $rows;
        } finally {
            $zip->close();
        }
    }

    /**
     * Resolves the first worksheet through workbook relationships, rather than
     * assuming that a renamed or reordered sheet still uses sheet1.xml.
     */
    private function firstWorksheetPath(ZipArchive $zip): string
    {
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbookXml === false || $relsXml === false) {
            return 'xl/worksheets/sheet1.xml';
        }

        $workbook = $this->xml($workbookXml);
        $rels = $this->xml($relsXml);
        $firstSheet = ($workbook->xpath('//*[local-name()="sheets"]/*[local-name()="sheet"]') ?: [])[0] ?? null;
        if ($firstSheet === null) return 'xl/worksheets/sheet1.xml';

        $relation = $firstSheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $relationId = trim((string)($relation['id'] ?? ''));
        if ($relationId === '') return 'xl/worksheets/sheet1.xml';

        foreach ($rels->xpath('//*[local-name()="Relationship"]') ?: [] as $item) {
            if ((string)$item['Id'] !== $relationId) continue;
            $target = (string)$item['Target'];
            if (str_starts_with($target, '/xl/worksheets/')) {
                $path = ltrim($target, '/');
            } elseif (str_starts_with($target, 'worksheets/')) {
                $path = 'xl/' . $target;
            } else {
                break;
            }
            if (!preg_match('#^xl/worksheets/[a-zA-Z0-9_-]+\.xml$#', $path)) {
                throw new InvalidArgumentException('La referencia a la hoja de cálculo no es válida.');
            }
            return $path;
        }
        throw new InvalidArgumentException('El Excel no tiene una hoja de datos accesible.');
    }

    private function xml(string $xml): SimpleXMLElement
    {
        if (stripos($xml, '<!DOCTYPE') !== false || strlen($xml) > self::MAX_UNCOMPRESSED) {
            throw new InvalidArgumentException('El contenido XML del Excel no es seguro.');
        }
        $document = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);
        if ($document === false) throw new InvalidArgumentException('El Excel contiene una hoja XML inválida.');
        return $document;
    }

    /** @param list<string> $header @return list<?string> */
    private function normalizeHeaders(array $header): array
    {
        $aliases = [
            'codigo' => 'code', 'codigo interno' => 'code', 'sku' => 'code', 'cod' => 'code',
            'codigo de barras' => 'barcode', 'barcode' => 'barcode',
            'producto' => 'name', 'nombre' => 'name', 'articulo' => 'name', 'insumo' => 'name',
            'cantidad' => 'quantity', 'stock' => 'quantity', 'existencia' => 'quantity',
            'existencias' => 'quantity', 'cantidad real' => 'quantity', 'stock real' => 'quantity',
            'stock contado' => 'quantity',
            'lote' => 'lot', 'codigo de lote' => 'lot',
            'presentacion' => 'presentation', 'unidad de compra' => 'presentation',
        ];
        $keys = array_map(
            static fn ($header) => $aliases[Str::lower(Str::ascii(trim((string)$header)))] ?? null,
            $header
        );
        if (!in_array('quantity', $keys, true) ||
            !(in_array('code', $keys, true) || in_array('barcode', $keys, true) || in_array('name', $keys, true))) {
            throw new InvalidArgumentException('Encabezados requeridos: Código o Producto y Cantidad. Opcionales: Lote, Presentación.');
        }
        if (count(array_filter($keys)) !== count(array_unique(array_filter($keys)))) {
            throw new InvalidArgumentException('Hay encabezados duplicados en el Excel.');
        }
        return $keys;
    }
}
