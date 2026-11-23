<?php

declare(strict_types=1);

namespace App\Modules\Community;

use App\Core\Database;
use App\Core\Tenant;
use App\Modules\Auth\UserNotifier;
use App\Modules\PropertyAssignment\PropertyAssignmentService;
use RuntimeException;

/**
 * Importación de residentes desde un archivo CSV en dos pasos: revisar (sin escribir nada) y confirmar.
 *
 * Columnas (el orden no importa; se reconocen por su encabezado):
 *   nombre_apellidos*, documento* (cédula o NIT), correo*, tipo_documento, tipo_residente, telefono, inmueble
 *
 * `inmueble` es obligatorio para los tipos que exigen propietario (p. ej. Inquilino): la fila solo es válida si ese
 * inmueble ya tiene propietario, y al confirmar el residente queda vinculado a él como inquilino.
 */
final class ResidentImportService
{
    public const MAX_BYTES = 1048576;
    public const MAX_ROWS = 2000;
    public const TEMPLATE_HEADERS = ['nombre_apellidos', 'tipo_documento', 'documento', 'correo', 'tipo_residente', 'telefono', 'inmueble'];

    /** Encabezados aceptados (normalizados: minúsculas, sin tildes ni signos) → campo. */
    private const HEADERS = [
        'full_name' => ['nombreapellidos', 'nombreyapellidos', 'nombrecompleto', 'nombres', 'nombre', 'nombresyapellidos'],
        'document_type' => ['tipodocumento', 'tipodedocumento', 'tipodoc', 'tipoidentificacion'],
        'document_number' => ['documento', 'cedulaonit', 'cedula', 'nit', 'numerodedocumento', 'numerodocumento', 'identificacion', 'cc'],
        'email' => ['correo', 'correoelectronico', 'email', 'mail'],
        'resident_type' => ['tiporesidente', 'tipoderesidente', 'tipo'],
        'phone' => ['telefono', 'celular', 'movil'],
        'unit' => ['inmueble', 'inmueblequearrienda', 'unidad', 'apartamento'],
    ];
    private const REQUIRED = ['full_name' => 'nombre_apellidos', 'document_number' => 'documento', 'email' => 'correo'];

    /** Palabras que se aceptan como tipo de documento, además de los códigos (CC, NIT…). */
    private const DOCUMENT_WORDS = ['cedula' => 'CC', 'cedulaciudadania' => 'CC', 'cedulaextranjeria' => 'CE', 'pasaporte' => 'PP'];

    public function __construct(
        private readonly ResidentService $residents = new ResidentService(),
        private readonly ResidentTypeService $types = new ResidentTypeService(),
        private readonly PropertyAssignmentService $assignments = new PropertyAssignmentService(),
    ) {
    }

    /**
     * Lee el CSV y clasifica cada fila: nueva, ya registrada u con error. No escribe en la base.
     *
     * @return list<array{line: int, status: 'new'|'exists'|'error', message: string, data: array<string, string>}>
     */
    public function review(string $content): array
    {
        $records = $this->records($content);
        $map = $this->headerMap(array_shift($records) ?? []);
        if (count($records) > self::MAX_ROWS) {
            throw new RuntimeException(sprintf('El archivo tiene %d filas; se admiten hasta %d por importación.', count($records), self::MAX_ROWS));
        }

        // Inmuebles por nombre (sin distinguir mayúsculas) con sus propietarios vigentes.
        $units = [];
        foreach ($this->assignments->unitsWithOwners() as $unitId => $unit) {
            $units[mb_strtolower($unit['name'], 'UTF-8')] = ['id' => $unitId] + $unit;
        }
        $rows = [];
        $seenEmails = [];
        $seenDocuments = [];
        foreach ($records as $index => $record) {
            if (implode('', array_map('trim', $record)) === '') {
                continue; // fila vacía
            }
            $line = $index + 2;
            $cell = static fn (string $field): string => isset($map[$field]) ? trim((string) ($record[$map[$field]] ?? '')) : '';
            $data = [
                'full_name' => (string) preg_replace('/\s+/u', ' ', $cell('full_name')),
                'email' => strtolower($cell('email')),
                'document_type' => $cell('document_type'),
                'document_number' => $cell('document_number'),
                'phone' => $cell('phone'),
                'resident_type' => $cell('resident_type'),
                'resident_type_id' => '',
                'unit' => (string) preg_replace('/\s+/u', ' ', $cell('unit')),
                'unit_id' => '',
            ];
            $row = ['line' => $line, 'status' => 'new', 'message' => '', 'data' => $data];
            try {
                [$row['data']['document_type'], $row['data']['document_number']] = $this->document($data['document_type'], $data['document_number']);
                if ($row['data']['document_number'] === '') {
                    throw new RuntimeException('Falta la cédula o el NIT.');
                }
                if ($data['resident_type'] !== '') {
                    $type = $this->types->findByName($data['resident_type']);
                    if ($type === null) {
                        throw new RuntimeException('El tipo «' . $data['resident_type'] . '» no está en el catálogo de tipos de residente.');
                    }
                    if (!(bool) $type['is_active']) {
                        throw new RuntimeException('El tipo «' . $type['name'] . '» está inactivo.');
                    }
                    $row['data']['resident_type'] = (string) $type['name'];
                    $row['data']['resident_type_id'] = (string) $type['id'];
                    if ((bool) $type['requires_unit_owner']) {
                        $unit = $units[mb_strtolower($data['unit'], 'UTF-8')] ?? null;
                        if ($data['unit'] === '') {
                            throw new RuntimeException('Un «' . $type['name'] . '» exige la columna inmueble: solo se registra si ese inmueble ya tiene propietario.');
                        }
                        if ($unit === null) {
                            throw new RuntimeException('El inmueble «' . $data['unit'] . '» no existe en la conformación.');
                        }
                        if ($unit['owners'] === []) {
                            throw new RuntimeException('El inmueble «' . $unit['name'] . '» aún no tiene propietario: vincula primero al propietario.');
                        }
                        $row['data']['unit'] = $unit['name'];
                        $row['data']['unit_id'] = $unit['id'];
                    }
                }
                $normalized = $this->residents->validate($row['data'], false);
                $row['data']['document_number'] = (string) $normalized['document_number'];
                $documentKey = $normalized['document_type'] . ' ' . $normalized['document_number'];
                if (isset($seenEmails[$normalized['email']])) {
                    throw new RuntimeException('Correo repetido en el archivo (fila ' . $seenEmails[$normalized['email']] . ').');
                }
                if (isset($seenDocuments[$documentKey])) {
                    throw new RuntimeException('Documento repetido en el archivo (fila ' . $seenDocuments[$documentKey] . ').');
                }
                $seenEmails[$normalized['email']] = $line;
                $seenDocuments[$documentKey] = $line;
                $existing = $this->existing($normalized['email'], (string) $normalized['document_type'], (string) $normalized['document_number']);
                if ($existing !== null) {
                    $row['status'] = 'exists';
                    $row['message'] = $existing;
                }
            } catch (RuntimeException $exception) {
                $row['status'] = 'error';
                $row['message'] = $exception->getMessage();
            }
            $rows[] = $row;
        }
        if ($rows === []) {
            throw new RuntimeException('El archivo no tiene filas de residentes debajo del encabezado.');
        }

        return $rows;
    }

    /**
     * Crea las filas nuevas de una revisión. Cada residente se guarda por separado: un error no detiene a los demás.
     *
     * @param list<array{line: int, status: string, data: array<string, string>}> $rows
     * @return array{created: int, failed: list<array{line: int, message: string}>, invited: bool}
     */
    public function import(array $rows, bool $sendInvitations): array
    {
        $created = [];
        $failed = [];
        foreach ($rows as $row) {
            if (($row['status'] ?? '') !== 'new') {
                continue;
            }
            try {
                $result = $this->residents->save(0, $row['data'] + ['send_invitation' => '0'], false);
                $created[] = $result['id'];
            } catch (RuntimeException $exception) {
                $failed[] = ['line' => (int) $row['line'], 'message' => $exception->getMessage()];
            }
        }
        if ($sendInvitations && $created !== []) {
            // Los correos salen después de responder: con cientos de residentes la página no se queda esperando.
            $residents = $this->residents;
            UserNotifier::afterResponse(static function () use ($created, $residents): void {
                foreach ($created as $userId) {
                    try {
                        $residents->invite($userId);
                    } catch (\Throwable $exception) {
                        error_log('Importación de residentes: no se envió la invitación a ' . $userId . ': ' . $exception->getMessage());
                    }
                }
            });
        }

        return ['created' => count($created), 'failed' => $failed, 'invited' => $sendInvitations && $created !== []];
    }

    /** Plantilla CSV con encabezados y dos filas de ejemplo (separador «;», como la abre Excel en español). */
    public function template(): string
    {
        $types = array_column($this->types->all(true), 'name');
        $owner = in_array('Propietario', $types, true) ? 'Propietario' : ($types[0] ?? '');
        $lines = [
            self::TEMPLATE_HEADERS,
            ['Ana María Pérez Gómez', 'CC', '1020304050', 'ana.perez@example.com', $owner, '3001234567', ''],
            ['Inversiones Los Pinos SAS', 'NIT', '900123456-7', 'contacto@example.com', $owner, '', ''],
        ];

        return "\u{FEFF}" . implode("\r\n", array_map(static fn (array $line): string => implode(';', $line), $lines)) . "\r\n";
    }

    /** @return list<list<string>> */
    private function records(string $content): array
    {
        if (str_starts_with($content, "\u{FEFF}")) {
            $content = substr($content, 3);
        }
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252'); // CSV guardado por Excel en Windows
        }
        $firstLine = strtok($content, "\r\n") ?: '';
        $delimiter = ';';
        $best = -1;
        foreach ([';', ',', "\t"] as $candidate) {
            if (substr_count($firstLine, $candidate) > $best) {
                $best = substr_count($firstLine, $candidate);
                $delimiter = $candidate;
            }
        }
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);
        $records = [];
        while (($record = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
            if ($record !== [null]) {
                $records[] = array_map(static fn (?string $value): string => (string) $value, $record);
            }
        }
        fclose($stream);

        return $records;
    }

    /** @return array<string, int> campo → posición de la columna */
    private function headerMap(array $header): array
    {
        $map = [];
        foreach ($header as $position => $title) {
            $key = self::normalize((string) $title);
            foreach (self::HEADERS as $field => $aliases) {
                if (!isset($map[$field]) && in_array($key, $aliases, true)) {
                    $map[$field] = (int) $position;
                    break;
                }
            }
        }
        $missing = array_diff_key(self::REQUIRED, $map);
        if ($missing !== []) {
            throw new RuntimeException('Faltan columnas en el encabezado: ' . implode(', ', $missing)
                . '. Descarga la plantilla para ver la estructura esperada.');
        }

        return $map;
    }

    /**
     * Tipo y número del documento. Sin tipo explícito: NIT si el número trae dígito de verificación («900123456-7»)
     * o el prefijo NIT; cédula de ciudadanía en otro caso.
     *
     * @return array{0: string, 1: string}
     */
    private function document(string $type, string $number): array
    {
        $number = strtoupper(str_replace(['.', ' '], '', $number));
        if (preg_match('/^(NIT|CC|CE)[:#-]?(.+)$/D', $number, $match) === 1 && $type === '') {
            [$type, $number] = [$match[1], $match[2]];
        }
        $code = strtoupper(trim($type));
        $code = self::DOCUMENT_WORDS[self::normalize($type)] ?? $code;
        if ($code === '') {
            $code = preg_match('/^\d{6,12}-\d$/D', $number) === 1 ? 'NIT' : 'CC';
        }
        if ($number !== '' && !isset(ResidentService::DOCUMENT_TYPES[$code])) {
            throw new RuntimeException('Tipo de documento «' . $type . '» no reconocido: usa CC, CE, TI, PP, PPT, NIT u OTRO.');
        }

        return [$number === '' ? '' : $code, $number];
    }

    /** Explica por qué una fila ya está registrada, o null si es nueva. */
    private function existing(string $email, string $documentType, string $documentNumber): ?string
    {
        $pdo = Database::connection();
        $byEmail = $pdo->prepare('SELECT 1 FROM admin_users WHERE tenant_id = ? AND email = ?');
        $byEmail->execute([Tenant::id(), $email]);
        if ($byEmail->fetchColumn() !== false) {
            return 'Ya hay una cuenta con ese correo: se omite.';
        }
        $byDocument = $pdo->prepare('SELECT 1 FROM resident_profiles WHERE tenant_id = ? AND document_type = ? AND document_number = ?');
        $byDocument->execute([Tenant::id(), $documentType, $documentNumber]);

        return $byDocument->fetchColumn() !== false ? 'Ya hay un residente con ese documento: se omite.' : null;
    }

    private static function normalize(string $text): string
    {
        $ascii = strtr(mb_strtolower(trim($text), 'UTF-8'), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);

        return (string) preg_replace('/[^a-z]/', '', $ascii);
    }
}
