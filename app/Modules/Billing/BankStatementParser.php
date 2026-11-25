<?php

declare(strict_types=1);

namespace App\Modules\Billing;

use DateTimeImmutable;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Lee extractos bancarios y devuelve solo los ingresos (créditos). Formatos:
 *   - Movimientos de la banca virtual (AV Villas y la mayoría de bancos colombianos): Excel .xlsx, CSV o TXT con una
 *     fila de títulos. Las columnas se reconocen por su nombre: fecha, descripción/concepto, referencia, documento,
 *     crédito/abono y débito/cargo, o un solo «valor» con signo.
 *   - Archivo de recaudo Asobancaria 2001 (convenio de recaudo con referencia): registros de ancho fijo 01/05/06/08/09.
 * No toca la base de datos: la propuesta y la aplicación las hace BankImportService.
 */
final class BankStatementParser
{
    public const MAX_BYTES = 3 * 1024 * 1024;
    public const MAX_ROWS = 3000;

    /** Sinónimos de los títulos de columna (sin tildes, en minúsculas). El orden importa: el primero que coincide gana. */
    private const COLUMNS = [
        'date' => ['fecha movimiento', 'fecha transaccion', 'fecha de transaccion', 'fecha operacion', 'fecha'],
        'description' => ['descripcion', 'concepto', 'detalle', 'transaccion', 'movimiento', 'nombre'],
        'reference' => ['referencia 1', 'referencia1', 'referencia', 'ref'],
        'document' => ['numero de documento', 'no. documento', 'nro documento', 'documento', 'comprobante', 'numero'],
        'office' => ['oficina', 'sucursal'],
        'credit' => ['valor credito', 'creditos', 'credito', 'abonos', 'abono', 'ingresos', 'consignaciones'],
        'debit' => ['valor debito', 'debitos', 'debito', 'cargos', 'cargo', 'retiros', 'egresos'],
        'amount' => ['valor', 'monto', 'importe'],
        'nature' => ['naturaleza', 'tipo', 'd/c', 'db/cr'],
    ];

    private const MONTHS = ['ene' => 1, 'feb' => 2, 'mar' => 3, 'abr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'ago' => 8, 'sep' => 9, 'set' => 9, 'oct' => 10, 'nov' => 11, 'dic' => 12];

    /**
     * @return array{format: string, movements: list<array{line: int, date: string, amount_cents: int, description: string, reference: string, document: string}>, skipped: int, warnings: list<string>}
     */
    public function parse(string $path, string $originalName): array
    {
        $size = @filesize($path);
        if ($size === false || $size < 1) {
            throw new RuntimeException('El archivo está vacío.');
        }
        if ($size > self::MAX_BYTES) {
            throw new RuntimeException('El archivo supera 3 MB. Descarga un rango de fechas más corto.');
        }
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $head = (string) file_get_contents($path, false, null, 0, 8);
        if ($extension === 'xls' || str_starts_with($head, "\xD0\xCF\x11\xE0")) {
            throw new RuntimeException('El formato .xls (Excel 97-2003) no se puede leer. Ábrelo en Excel y guárdalo como .xlsx o como CSV.');
        }
        if (str_starts_with($head, '%PDF')) {
            throw new RuntimeException('El extracto en PDF no trae los movimientos en columnas. Descarga los movimientos en Excel o CSV desde la banca virtual.');
        }
        if (str_starts_with($head, "PK\x03\x04")) {
            return $this->fromRows($this->xlsxRows($path), 'Excel (.xlsx)');
        }

        $text = $this->toUtf8((string) file_get_contents($path));
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        if ($this->looksAsobancaria($lines)) {
            return $this->asobancaria($lines);
        }

        return $this->fromRows($this->csvRows($text), 'CSV/TXT');
    }

    /* -------------------------------- Movimientos con títulos -------------------------------- */

    /** @param list<list<string>> $rows */
    private function fromRows(array $rows, string $format): array
    {
        [$headerIndex, $columns] = $this->findHeader($rows);
        $movements = [];
        $skipped = 0;
        $warnings = [];
        $total = count($rows);
        for ($index = $headerIndex + 1; $index < $total; $index++) {
            $row = $rows[$index];
            if (implode('', array_map('trim', $row)) === '') {
                continue;
            }
            $value = static fn (?int $column): string => $column === null ? '' : trim((string) ($row[$column] ?? ''));
            $date = $this->parseDate($value($columns['date']));
            if ($date === null) {
                // Filas de totales, saldos o notas al pie: no son movimientos.
                $skipped++;
                continue;
            }
            $amount = $this->creditAmount($value, $columns);
            if ($amount === null) {
                $skipped++;
                continue;
            }
            $description = trim(implode(' · ', array_filter([$value($columns['description']), $value($columns['office']) !== '' ? 'Oficina ' . $value($columns['office']) : ''])));
            $movements[] = [
                'line' => $index + 1,
                'date' => $date,
                'amount_cents' => $amount,
                'description' => mb_substr($description, 0, 255, 'UTF-8'),
                'reference' => mb_substr($value($columns['reference']), 0, 80, 'UTF-8'),
                'document' => mb_substr($value($columns['document']), 0, 40, 'UTF-8'),
            ];
            if (count($movements) > self::MAX_ROWS) {
                throw new RuntimeException('El archivo tiene más de ' . self::MAX_ROWS . ' ingresos. Descarga un rango de fechas más corto.');
            }
        }
        if ($movements === []) {
            $warnings[] = 'No se encontraron ingresos (créditos) en el archivo.';
        }

        return ['format' => $format, 'movements' => $movements, 'skipped' => $skipped, 'warnings' => $warnings];
    }

    /**
     * Valor del ingreso en centavos, o null si la fila es un débito o no tiene valor.
     *
     * @param callable(?int): string $value
     */
    private function creditAmount(callable $value, array $columns): ?int
    {
        if ($columns['credit'] !== null) {
            $credit = $this->parseMoney($value($columns['credit']));
            if ($credit !== null && $credit !== 0) {
                return $credit > 0 ? $credit : null;
            }
            if ($columns['amount'] === null) {
                return null;
            }
        }
        if ($columns['amount'] === null) {
            return null;
        }
        $amount = $this->parseMoney($value($columns['amount']));
        if ($amount === null || $amount === 0) {
            return null;
        }
        if ($columns['nature'] !== null) {
            $nature = strtoupper($this->plain($value($columns['nature'])));
            if (preg_match('/^(C|CR|CREDITO|ABONO|NC)$/', $nature) === 1) {
                return abs($amount);
            }
            if (preg_match('/^(D|DB|DEBITO|CARGO|ND)$/', $nature) === 1) {
                return null;
            }
        }
        if ($columns['debit'] !== null && $this->parseMoney($value($columns['debit'])) > 0) {
            return null;
        }

        return $amount > 0 ? $amount : null;
    }

    /**
     * Fila de títulos: la primera (de las 30 primeras) que tenga una columna de fecha y una de valor.
     *
     * @param list<list<string>> $rows
     * @return array{0: int, 1: array<string, ?int>}
     */
    private function findHeader(array $rows): array
    {
        foreach (array_slice($rows, 0, 30, true) as $index => $row) {
            $columns = array_fill_keys(array_keys(self::COLUMNS), null);
            $titles = array_map(fn (string $cell): string => $this->plain($cell), $row);
            foreach (self::COLUMNS as $key => $synonyms) {
                foreach ($synonyms as $synonym) {
                    foreach ($titles as $position => $title) {
                        if ($title === '' || in_array($position, $columns, true)) {
                            continue;
                        }
                        if ($title === $synonym || str_starts_with($title, $synonym . ' ') || (strlen($synonym) > 4 && str_contains($title, $synonym))) {
                            $columns[$key] = $position;
                            continue 3;
                        }
                    }
                }
            }
            if ($columns['date'] !== null && ($columns['credit'] !== null || $columns['amount'] !== null)) {
                return [$index, $columns];
            }
        }
        throw new RuntimeException('No se reconocieron las columnas del extracto. El archivo debe tener una fila de títulos con «Fecha» y «Valor» (o «Crédito»). Descarga los movimientos en Excel o CSV desde la banca virtual.');
    }

    /** @return list<list<string>> */
    private function csvRows(string $text): array
    {
        $sample = implode("\n", array_slice(preg_split('/\r\n|\r|\n/', $text) ?: [], 0, 15));
        $delimiter = ';';
        $best = -1;
        foreach ([';', ',', "\t", '|'] as $candidate) {
            $count = substr_count($sample, $candidate);
            if ($count > $best) {
                $best = $count;
                $delimiter = $candidate;
            }
        }
        $handle = fopen('php://memory', 'w+');
        fwrite($handle, $text);
        rewind($handle);
        $rows = [];
        while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            $rows[] = array_map(static fn ($cell): string => (string) $cell, $row);
            if (count($rows) > self::MAX_ROWS + 50) {
                break;
            }
        }
        fclose($handle);

        return $rows;
    }

    /** Primera hoja de un .xlsx, sin librerías: ZIP + XML. Las fechas de Excel llegan como número de serie. */
    private function xlsxRows(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('No se pudo abrir el archivo de Excel.');
        }
        $shared = [];
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if (is_string($sharedXml)) {
            $strings = $this->xml($sharedXml);
            foreach ($strings->si as $item) {
                $shared[] = isset($item->t) ? (string) $item->t : implode('', array_map('strval', iterator_to_array($item->xpath('.//*[local-name()="t"]') ?: [], false)));
            }
        }
        $sheetName = 'xl/worksheets/sheet1.xml';
        if ($zip->locateName($sheetName) === false) {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = (string) $zip->getNameIndex($index);
                if (preg_match('~^xl/worksheets/sheet\d+\.xml$~', $name) === 1) {
                    $sheetName = $name;
                    break;
                }
            }
        }
        $sheetXml = $zip->getFromName($sheetName);
        $zip->close();
        if (!is_string($sheetXml)) {
            throw new RuntimeException('El archivo de Excel no tiene hojas con datos.');
        }

        $rows = [];
        foreach ($this->xml($sheetXml)->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $cell) {
                $reference = (string) $cell['r'];
                $column = $this->columnIndex(preg_replace('/\d+/', '', $reference) ?? 'A');
                $type = (string) $cell['t'];
                $raw = isset($cell->v) ? (string) $cell->v : '';
                $cells[$column] = match ($type) {
                    's' => $shared[(int) $raw] ?? '',
                    'inlineStr' => (string) ($cell->is->t ?? ''),
                    'str', 'b', 'e' => $raw,
                    // Número de Excel (puede traer ruido de punto flotante): se fija con dos decimales.
                    default => is_numeric($raw) && str_contains($raw, '.') ? number_format((float) $raw, 2, '.', '') : $raw,
                };
            }
            if ($cells === []) {
                $rows[] = [];
                continue;
            }
            $line = array_fill(0, max(array_keys($cells)) + 1, '');
            foreach ($cells as $column => $text) {
                $line[$column] = $text;
            }
            $rows[] = $line;
            if (count($rows) > self::MAX_ROWS + 50) {
                break;
            }
        }

        return $rows;
    }

    private function xml(string $content): SimpleXMLElement
    {
        // Sin entidades externas (XXE): LIBXML_NONET y sin LIBXML_NOENT.
        $xml = simplexml_load_string($content, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        if (!$xml instanceof SimpleXMLElement) {
            throw new RuntimeException('El archivo de Excel está dañado.');
        }

        return $xml;
    }

    private function columnIndex(string $letters): int
    {
        $index = 0;
        foreach (str_split(strtoupper($letters)) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return max(0, $index - 1);
    }

    /* ------------------------------------ Asobancaria 2001 ------------------------------------ */

    /** @param list<string> $lines */
    private function looksAsobancaria(array $lines): bool
    {
        // Los registros vienen rellenos con espacios: se mide sin quitar el relleno, solo el fin de línea.
        $first = $lines[0] ?? '';

        return preg_match('/^01\d{10}\d{8}/', $first) === 1
            && (bool) array_filter(
                $lines,
                static fn (string $line): bool => str_starts_with($line, '06') && strlen(rtrim($line, "\r\n")) >= 64
            );
    }

    /**
     * Registro 01 (encabezado): tipo(2) NIT(10) fecha de recaudo AAAAMMDD(8) ...
     * Registro 06 (detalle): tipo(2) referencia del usuario(48) valor recaudado(14, con 2 decimales) procedencia(2)
     * medio de pago(2) número de operación(6) autorización(6) entidad debitada(3) sucursal(4) secuencia(7) ...
     *
     * @param list<string> $lines
     */
    private function asobancaria(array $lines): array
    {
        $collectionDate = null;
        $movements = [];
        $warnings = [];
        foreach ($lines as $number => $line) {
            if (str_starts_with($line, '01')) {
                $collectionDate = $this->parseDate(substr($line, 12, 8));
                continue;
            }
            if (!str_starts_with($line, '06')) {
                continue;
            }
            $amount = substr($line, 50, 14);
            if ($collectionDate === null || preg_match('/^\d{14}$/D', $amount) !== 1) {
                $warnings[] = 'Línea ' . ($number + 1) . ': registro de detalle con formato inesperado; se omitió.';
                continue;
            }
            $cents = (int) ltrim($amount, '0');
            if ($cents <= 0) {
                continue;
            }
            $reference = ltrim(trim(substr($line, 2, 48)), '0');
            $movements[] = [
                'line' => $number + 1,
                'date' => $collectionDate,
                'amount_cents' => $cents,
                'description' => 'Recaudo con referencia · sucursal ' . trim(substr($line, 83, 4)),
                'reference' => mb_substr($reference, 0, 80, 'UTF-8'),
                'document' => trim(substr($line, 68, 6)),
            ];
        }
        if ($movements === []) {
            $warnings[] = 'El archivo de recaudo no trae pagos.';
        }

        return ['format' => 'Asobancaria 2001', 'movements' => $movements, 'skipped' => 0, 'warnings' => $warnings];
    }

    /* ---------------------------------------- Valores ---------------------------------------- */

    /** Fecha en los formatos usuales de los bancos colombianos, o número de serie de Excel. */
    public function parseDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d{5}(?:\.\d+)?$/D', $value) === 1 && (float) $value > 20000 && (float) $value < 80000) {
            return (new DateTimeImmutable('1899-12-30'))->modify('+' . (int) $value . ' days')->format('Y-m-d');
        }
        $value = preg_replace('/\s+\d{1,2}:\d{2}(:\d{2})?(\s*[ap]\.?\s*m\.?)?$/i', '', $value) ?? $value;
        $patterns = [
            '/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})$/' => [1, 2, 3],
            '/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})$/' => [3, 2, 1],
            '/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{2})$/' => [3, 2, 1],
            '/^(\d{4})(\d{2})(\d{2})$/' => [1, 2, 3],
        ];
        foreach ($patterns as $pattern => [$y, $m, $d]) {
            if (preg_match($pattern, $value, $parts) === 1) {
                $year = (int) $parts[$y];
                $year += $year < 100 ? 2000 : 0;

                return checkdate((int) $parts[$m], (int) $parts[$d], $year) ? sprintf('%04d-%02d-%02d', $year, $parts[$m], $parts[$d]) : null;
            }
        }
        if (preg_match('/^(\d{1,2})[-\/ ]([a-z]{3})[a-z]*\.?[-\/ ](\d{2,4})$/i', $this->plain($value), $parts) === 1 && isset(self::MONTHS[$parts[2]])) {
            $year = (int) $parts[3] + ((int) $parts[3] < 100 ? 2000 : 0);

            return checkdate(self::MONTHS[$parts[2]], (int) $parts[1], $year) ? sprintf('%04d-%02d-%02d', $year, self::MONTHS[$parts[2]], $parts[1]) : null;
        }

        return null;
    }

    /**
     * Valor en centavos con formato colombiano ($ 1.234.567,89) o internacional (1,234,567.89). Negativos con signo
     * o entre paréntesis. null si no es un número.
     */
    public function parseMoney(string $value): ?int
    {
        $value = trim(str_replace(["\u{00A0}", ' ', '$', 'COP', 'cop'], '', $value));
        if ($value === '') {
            return null;
        }
        $negative = str_starts_with($value, '-') || str_ends_with($value, '-') || (str_starts_with($value, '(') && str_ends_with($value, ')'));
        $value = trim($value, '-()+');
        if (preg_match('/^[\d.,]+$/D', $value) !== 1) {
            return null;
        }
        $lastDot = strrpos($value, '.');
        $lastComma = strrpos($value, ',');
        if ($lastDot !== false && $lastComma !== false) {
            $decimal = $lastDot > $lastComma ? '.' : ',';
        } elseif ($lastComma !== false) {
            $decimal = preg_match('/,\d{1,2}$/D', $value) === 1 && substr_count($value, ',') === 1 ? ',' : null;
        } elseif ($lastDot !== false) {
            // «1.500» en COP son mil quinientos; «1500.5» o «1500.50» llevan decimales.
            $decimal = preg_match('/\.\d{1,2}$/D', $value) === 1 && substr_count($value, '.') === 1 ? '.' : null;
        } else {
            $decimal = null;
        }
        if ($decimal === null) {
            $integer = str_replace(['.', ','], '', $value);
            $fraction = '';
        } else {
            $position = strrpos($value, $decimal);
            $integer = str_replace(['.', ','], '', substr($value, 0, $position));
            $fraction = substr($value, $position + 1);
        }
        if ($integer === '' && $fraction === '') {
            return null;
        }
        $cents = (int) $integer * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');

        return $negative ? -$cents : $cents;
    }

    private function toUtf8(string $text): string
    {
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
        }
        if (str_starts_with($text, "\xFF\xFE") || str_starts_with($text, "\xFE\xFF")) {
            return (string) mb_convert_encoding(substr($text, 2), 'UTF-8', str_starts_with($text, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE');
        }

        return mb_check_encoding($text, 'UTF-8') ? $text : (string) mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
    }

    /** Minúsculas, sin tildes ni espacios repetidos (para comparar títulos). */
    private function plain(string $text): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        $text = strtr($text, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);

        return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    }
}
