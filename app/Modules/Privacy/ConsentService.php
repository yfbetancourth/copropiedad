<?php

declare(strict_types=1);

namespace App\Modules\Privacy;

use App\Core\Database;
use App\Core\Tenant;
use RuntimeException;

/**
 * Registro de autorizaciones de tratamiento de datos: prueba de quién autorizó, cuándo, desde dónde y qué versiones
 * de la política y de la autorización aceptó (art. 17 lit. b de la Ley 1581 de 2012; art. 8 del Decreto 1377 de 2013).
 * Las autorizaciones no se borran: la revocación se registra con fecha, responsable y nota (art. 9 del Decreto 1377).
 */
final class ConsentService
{
    public const SOURCE_LABELS = ['contact_form' => 'Formulario de contacto'];
    private const PAGE_SIZE = 50;

    /**
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int}
     */
    public function search(string $status, string $query, int $page): array
    {
        [$where, $parameters] = $this->filters($status, $query);
        $count = Database::connection()->prepare('SELECT COUNT(*) FROM data_consents AS consents WHERE ' . $where);
        $count->execute($parameters);
        $total = (int) $count->fetchColumn();
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min(max(1, $page), $pages);

        $statement = Database::connection()->prepare(
            $this->selectSql() . ' WHERE ' . $where . ' ORDER BY consents.granted_at DESC, consents.id DESC
             LIMIT ' . self::PAGE_SIZE . ' OFFSET ' . (($page - 1) * self::PAGE_SIZE)
        );
        $statement->execute($parameters);

        return ['rows' => $statement->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /** @return array{active: int, revoked: int} */
    public function counts(): array
    {
        $statement = Database::connection()->prepare(
            'SELECT SUM(revoked_at IS NULL) AS active, SUM(revoked_at IS NOT NULL) AS revoked FROM data_consents WHERE tenant_id = ?'
        );
        $statement->execute([Tenant::id()]);
        $row = $statement->fetch() ?: [];

        return ['active' => (int) ($row['active'] ?? 0), 'revoked' => (int) ($row['revoked'] ?? 0)];
    }

    public function revoke(int $consentId, string $note, int $actorId): void
    {
        $note = trim(preg_replace('/\s+/u', ' ', $note) ?? '');
        if (mb_strlen($note, 'UTF-8') < 5 || mb_strlen($note, 'UTF-8') > 255) {
            throw new RuntimeException('Describe cómo y cuándo pidió el titular la revocación (entre 5 y 255 caracteres).');
        }
        $statement = Database::connection()->prepare(
            'UPDATE data_consents SET revoked_at = NOW(), revoked_by = ?, revocation_note = ?
             WHERE id = ? AND tenant_id = ? AND revoked_at IS NULL'
        );
        $statement->execute([$actorId, $note, $consentId, Tenant::id()]);
        if ($statement->rowCount() === 0) {
            throw new RuntimeException('La autorización no existe o ya estaba revocada.');
        }
    }

    /**
     * Escribe el registro filtrado como CSV (separado por punto y coma, UTF-8 con BOM para Excel).
     *
     * @param resource $output
     */
    public function exportCsv(string $status, string $query, $output): void
    {
        [$where, $parameters] = $this->filters($status, $query);
        $statement = Database::connection()->prepare(
            $this->selectSql() . ' WHERE ' . $where . ' ORDER BY consents.granted_at DESC, consents.id DESC'
        );
        $statement->execute($parameters);

        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, [
            'ID', 'Fecha de autorización', 'Origen', 'Nombre', 'Correo', 'Teléfono', 'Versión política de datos',
            'Versión autorización', 'Dirección IP', 'Navegador', 'Estado', 'Fecha de revocación', 'Revocada por', 'Nota de revocación',
        ], ';');
        while (($row = $statement->fetch()) !== false) {
            fputcsv($output, array_map([self::class, 'csvSafe'], [
                $row['id'], $row['granted_at'], self::SOURCE_LABELS[$row['source']] ?? $row['source'], $row['subject_name'],
                $row['subject_email'], $row['subject_phone'] ?? '', 'v' . $row['policy_version'], 'v' . $row['authorization_version'],
                $row['ip_address'], $row['user_agent'] ?? '', $row['revoked_at'] === null ? 'Vigente' : 'Revocada',
                $row['revoked_at'] ?? '', $row['revoked_by_name'] ?? '', $row['revocation_note'] ?? '',
            ]), ';');
        }
    }

    /** Evita que Excel interprete como fórmula un valor escrito por el titular (inyección CSV). */
    private static function csvSafe(mixed $value): string
    {
        $value = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'" . $value : $value;
    }

    private function selectSql(): string
    {
        return 'SELECT consents.id, consents.source, consents.subject_name, consents.subject_email, consents.subject_phone,
                       consents.granted_at, consents.ip_address, consents.user_agent, consents.revoked_at, consents.revocation_note,
                       consents.data_policy_version_id, consents.authorization_version_id,
                       policy_doc.version AS policy_version, auth_doc.version AS authorization_version,
                       revokers.username AS revoked_by_name
                FROM data_consents AS consents
                JOIN privacy_document_versions AS policy_doc ON policy_doc.id = consents.data_policy_version_id
                JOIN privacy_document_versions AS auth_doc ON auth_doc.id = consents.authorization_version_id
                LEFT JOIN admin_users AS revokers ON revokers.id = consents.revoked_by';
    }

    /** @return array{0: string, 1: list<mixed>} */
    private function filters(string $status, string $query): array
    {
        $where = 'consents.tenant_id = ?';
        $parameters = [Tenant::id()];
        if ($status === 'vigentes') {
            $where .= ' AND consents.revoked_at IS NULL';
        } elseif ($status === 'revocadas') {
            $where .= ' AND consents.revoked_at IS NOT NULL';
        }
        $query = trim($query);
        if ($query !== '') {
            $like = '%' . addcslashes(mb_substr($query, 0, 100, 'UTF-8'), '%_\\') . '%';
            $where .= ' AND (consents.subject_name LIKE ? OR consents.subject_email LIKE ? OR consents.subject_phone LIKE ?)';
            array_push($parameters, $like, $like, $like);
        }

        return [$where, $parameters];
    }
}
