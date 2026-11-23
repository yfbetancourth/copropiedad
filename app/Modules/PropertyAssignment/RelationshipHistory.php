<?php

declare(strict_types=1);

namespace App\Modules\PropertyAssignment;

use App\Core\Database;
use App\Core\Tenant;
use PDO;

/**
 * Trazabilidad de quién ha estado vinculado a cada inmueble, con qué vínculo y entre qué fechas.
 * Los registros nunca se borran: al terminar un vínculo se cierra con su fecha y motivo.
 */
final class RelationshipHistory
{
    public const RELATIONS = [
        'propietario' => 'Propietario',
        'inquilino' => 'Inquilino',
        'residente' => 'Residente',
    ];

    public const END_REASONS = [
        'retiro' => 'Se desvinculó',
        'cambio_vinculo' => 'Cambió el tipo de vínculo',
        'inmueble_eliminado' => 'El inmueble salió de la estructura',
        'reinicio_conformacion' => 'Se reinició la conformación',
    ];

    public static function label(string $relation, bool $resides): string
    {
        $label = self::RELATIONS[$relation] ?? $relation;

        return $relation === 'propietario' && $resides ? $label . ' residente' : $label;
    }

    public static function open(
        PDO $pdo,
        string $unitId,
        string $unitName,
        int $userId,
        string $userName,
        string $relation,
        bool $resides,
        string $startedOn,
        ?int $actorId
    ): void {
        $pdo->prepare(
            'INSERT INTO property_unit_relationship_history
                (tenant_id, unit_id, unit_name, user_id, user_name, relation_type, resides, started_on, started_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([Tenant::id(), $unitId, $unitName, $userId, $userName, $relation, $resides ? 1 : 0, $startedOn, $actorId]);
    }

    /**
     * Cierra los vínculos abiertos de un inmueble (y opcionalmente de un usuario).
     */
    public static function close(PDO $pdo, string $unitId, ?int $userId, string $endedOn, string $reason, ?int $actorId): void
    {
        $sql = 'UPDATE property_unit_relationship_history
                SET ended_on = GREATEST(started_on, ?), end_reason = ?, ended_by = ?
                WHERE tenant_id = ? AND unit_id = ? AND ended_on IS NULL';
        $params = [$endedOn, $reason, $actorId, Tenant::id(), $unitId];
        if ($userId !== null) {
            $sql .= ' AND user_id = ?';
            $params[] = $userId;
        }
        $pdo->prepare($sql)->execute($params);
    }

    /**
     * Cierra todos los vínculos abiertos del conjunto (reinicio de la conformación).
     */
    public static function closeAll(PDO $pdo, string $reason, ?int $actorId): void
    {
        $pdo->prepare(
            'UPDATE property_unit_relationship_history
             SET ended_on = GREATEST(started_on, CURDATE()), end_reason = ?, ended_by = ?
             WHERE tenant_id = ? AND ended_on IS NULL'
        )->execute([$reason, $actorId, Tenant::id()]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function forUnit(string $unitId): array
    {
        return self::fetch('history.unit_id = ?', $unitId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function forUser(int $userId): array
    {
        return self::fetch('history.user_id = ?', $userId);
    }

    /**
     * Número de registros (vigentes e históricos) por inmueble.
     *
     * @return array<string, int>
     */
    public static function countsByUnit(): array
    {
        $statement = Database::connection()->prepare(
            'SELECT unit_id, COUNT(*) FROM property_unit_relationship_history WHERE tenant_id = ? GROUP BY unit_id'
        );
        $statement->execute([Tenant::id()]);

        return array_map('intval', $statement->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function fetch(string $condition, int|string $value): array
    {
        $statement = Database::connection()->prepare(
            'SELECT history.*, starter.username AS started_by_name, ender.username AS ended_by_name
             FROM property_unit_relationship_history AS history
             LEFT JOIN admin_users AS starter ON starter.id = history.started_by
             LEFT JOIN admin_users AS ender ON ender.id = history.ended_by
             WHERE history.tenant_id = ? AND ' . $condition . '
             ORDER BY history.ended_on IS NULL DESC, history.started_on DESC, history.id DESC'
        );
        $statement->execute([Tenant::id(), $value]);
        $rows = $statement->fetchAll();
        foreach ($rows as &$row) {
            $row['relation_label'] = self::label((string) $row['relation_type'], (bool) $row['resides']);
            $row['end_reason_label'] = self::END_REASONS[(string) ($row['end_reason'] ?? '')] ?? '';
        }
        unset($row);

        return $rows;
    }
}
