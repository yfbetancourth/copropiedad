<?php

declare(strict_types=1);

namespace App\Modules\PropertyAssignment;

use App\Core\Database;
use App\Core\Tenant;
use App\Modules\Configuration\CompositionService;
use RuntimeException;
use Throwable;

final class PropertyAssignmentService
{
    /**
     * @return array<string, mixed>
     */
    public function pageData(?int $selectedUserId): array
    {
        $units = [];
        $composition = (new CompositionService())->current();
        if (is_array($composition) && $composition['completed'] && is_array($composition['units'])) {
            $composition = (new CompositionService())->ensureUnitIds();
            $units = $composition['units'] ?? [];
        }

        $usersStatement = Database::connection()->prepare(
            'SELECT users.id, users.username, users.email, users.is_active, roles.name AS role_name
             FROM admin_users AS users
             JOIN admin_roles AS roles ON roles.id = users.role_id
             WHERE users.tenant_id = ? AND roles.is_system = 0
             ORDER BY users.is_active DESC, users.username'
        );
        $usersStatement->execute([Tenant::id()]);
        $users = $usersStatement->fetchAll();

        $validUnitIds = [];
        foreach ($units as $unit) {
            if (is_array($unit) && is_string($unit['id'] ?? null)) {
                $validUnitIds[$unit['id']] = true;
            }
        }

        // Todas las asignaciones: sirven para contar por usuario, mostrar los demás vinculados y medir la cobertura.
        $userCounts = [];
        $unitOwners = [];
        $selectedAssignments = [];
        $assignmentsStatement = Database::connection()->prepare(
            'SELECT assignments.unit_id, assignments.user_id, assignments.relation_type, assignments.resides,
                    assignments.started_on, users.username
             FROM property_unit_assignments AS assignments
             JOIN admin_users AS users ON users.id = assignments.user_id
             WHERE assignments.tenant_id = ?
             ORDER BY assignments.relation_type, users.username'
        );
        $assignmentsStatement->execute([Tenant::id()]);
        foreach ($assignmentsStatement->fetchAll() as $assignment) {
            $unitId = (string) $assignment['unit_id'];
            if (!isset($validUnitIds[$unitId])) {
                continue;
            }
            $userId = (int) $assignment['user_id'];
            $userCounts[$userId] = ($userCounts[$userId] ?? 0) + 1;
            $unitOwners[$unitId][] = [
                'id' => $userId,
                'username' => (string) $assignment['username'],
                'relation' => RelationshipHistory::label((string) $assignment['relation_type'], (bool) $assignment['resides']),
            ];
            if ($userId === $selectedUserId) {
                $selectedAssignments[$unitId] = [
                    'relation' => (string) $assignment['relation_type'],
                    'resides' => (bool) $assignment['resides'],
                    'started_on' => (string) $assignment['started_on'],
                ];
            }
        }

        $selectedUser = null;
        foreach ($users as &$user) {
            $user['id'] = (int) $user['id'];
            $user['assigned_count'] = $userCounts[$user['id']] ?? 0;
            if ($user['id'] === $selectedUserId) {
                $selectedUser = $user;
            }
        }
        unset($user);

        return [
            'units' => $units,
            'users' => $users,
            'selectedUser' => $selectedUser,
            'assignedUnitIds' => array_keys($selectedAssignments),
            'assignedLinks' => $selectedAssignments,
            'userHistory' => $selectedUser !== null ? RelationshipHistory::forUser($selectedUser['id']) : [],
            'unitOwners' => $unitOwners,
            'coveredUnits' => count($unitOwners),
        ];
    }

    /**
     * Guarda los vínculos vigentes de un usuario y deja la trazabilidad de cada cambio.
     *
     * @param list<mixed> $links Cada elemento: {unit_id, relation} con relation en
     *                           propietario | propietario_residente | inquilino | residente.
     */
    public function assign(int $userId, array $links, string $effectiveDate, ?int $actorId): void
    {
        $userStatement = Database::connection()->prepare(
            'SELECT users.id, users.username
             FROM admin_users AS users
             JOIN admin_roles AS roles ON roles.id = users.role_id
             WHERE users.id = ? AND users.tenant_id = ? AND roles.is_system = 0
             LIMIT 1'
        );
        $userStatement->execute([$userId, Tenant::id()]);
        $user = $userStatement->fetch();
        if (!is_array($user)) {
            throw new RuntimeException('Selecciona un usuario valido.');
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $effectiveDate);
        if ($date === false || $date->format('Y-m-d') !== $effectiveDate) {
            throw new RuntimeException('La fecha del cambio no es válida.');
        }
        if ($effectiveDate > date('Y-m-d')) {
            throw new RuntimeException('La fecha del cambio no puede ser futura.');
        }

        $composition = (new CompositionService())->current();
        if (!is_array($composition) || !$composition['completed'] || !is_array($composition['units'])) {
            throw new RuntimeException('Primero finaliza la estructura de inmuebles en la seccion de conformacion.');
        }
        $composition = (new CompositionService())->ensureUnitIds();
        if (!is_array($composition) || !is_array($composition['units'])) {
            throw new RuntimeException('No se pudo leer la estructura de inmuebles actual.');
        }

        $unitNames = [];
        foreach ($composition['units'] as $unit) {
            if (is_array($unit) && is_string($unit['id'] ?? null)) {
                $unitNames[$unit['id']] = (string) ($unit['name'] ?? '');
            }
        }
        if (count($links) > count($unitNames)) {
            throw new RuntimeException('La seleccion contiene inmuebles que no pertenecen a la estructura actual.');
        }

        $wanted = [];
        foreach ($links as $link) {
            $unitId = is_array($link) ? ($link['unit_id'] ?? null) : null;
            $relation = is_array($link) ? ($link['relation'] ?? null) : null;
            if (!is_string($unitId) || preg_match('/^[a-f0-9]{32}$/D', $unitId) !== 1
                || !isset($unitNames[$unitId]) || isset($wanted[$unitId])
            ) {
                throw new RuntimeException('La seleccion contiene un inmueble invalido. Recarga la pagina e intenta nuevamente.');
            }
            [$type, $resides] = match ($relation) {
                'propietario' => ['propietario', false],
                'propietario_residente' => ['propietario', true],
                'inquilino' => ['inquilino', true],
                'residente' => ['residente', true],
                default => throw new RuntimeException('Elige el tipo de vínculo de ' . $unitNames[$unitId] . '.'),
            };
            $wanted[$unitId] = ['relation' => $type, 'resides' => $resides];
        }

        $pdo = Database::connection();
        // Si quien llama ya abrió una transacción (crear residente + vincularlo), todo se confirma o se revierte junto.
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $currentStatement = $pdo->prepare(
                'SELECT unit_id, relation_type, resides, started_on
                 FROM property_unit_assignments
                 WHERE tenant_id = ? AND user_id = ?
                 FOR UPDATE'
            );
            $currentStatement->execute([Tenant::id(), $userId]);
            $current = [];
            foreach ($currentStatement->fetchAll() as $row) {
                $current[(string) $row['unit_id']] = $row;
            }

            // Un inquilino solo se vincula a un inmueble que ya tiene propietario vigente (otra persona).
            $ownerCheck = $pdo->prepare(
                "SELECT 1 FROM property_unit_assignments
                 WHERE tenant_id = ? AND unit_id = ? AND relation_type = 'propietario' AND user_id <> ?
                 LIMIT 1 FOR UPDATE"
            );
            foreach ($wanted as $unitId => $link) {
                $alreadyTenant = isset($current[$unitId]) && (string) $current[$unitId]['relation_type'] === 'inquilino';
                if ($link['relation'] !== 'inquilino' || $alreadyTenant) {
                    continue;
                }
                $ownerCheck->execute([Tenant::id(), $unitId, $userId]);
                if ($ownerCheck->fetchColumn() === false) {
                    throw new RuntimeException(sprintf(
                        'No se puede registrar a %s como inquilino de %s: el inmueble aún no tiene propietario. Vincula primero al propietario.',
                        (string) $user['username'],
                        $unitNames[$unitId]
                    ));
                }
            }

            $userName = (string) $user['username'];
            $delete = $pdo->prepare('DELETE FROM property_unit_assignments WHERE tenant_id = ? AND user_id = ? AND unit_id = ?');
            $insert = $pdo->prepare(
                'INSERT INTO property_unit_assignments (tenant_id, unit_id, user_id, relation_type, resides, started_on)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $update = $pdo->prepare(
                'UPDATE property_unit_assignments SET relation_type = ?, resides = ?, started_on = ?
                 WHERE tenant_id = ? AND user_id = ? AND unit_id = ?'
            );

            foreach ($current as $unitId => $row) {
                $ending = !isset($wanted[$unitId])
                    || $wanted[$unitId]['relation'] !== (string) $row['relation_type']
                    || $wanted[$unitId]['resides'] !== (bool) $row['resides'];
                if ($ending && $effectiveDate < (string) $row['started_on']) {
                    throw new RuntimeException(sprintf(
                        'La fecha del cambio no puede ser anterior al inicio del vínculo con %s (%s).',
                        $unitNames[$unitId] ?? 'el inmueble',
                        (string) $row['started_on']
                    ));
                }
                if (!isset($wanted[$unitId])) {
                    $delete->execute([Tenant::id(), $userId, $unitId]);
                    RelationshipHistory::close($pdo, $unitId, $userId, $effectiveDate, 'retiro', $actorId);
                } elseif ($ending) {
                    $link = $wanted[$unitId];
                    $update->execute([$link['relation'], $link['resides'] ? 1 : 0, $effectiveDate, Tenant::id(), $userId, $unitId]);
                    RelationshipHistory::close($pdo, $unitId, $userId, $effectiveDate, 'cambio_vinculo', $actorId);
                    RelationshipHistory::open($pdo, $unitId, $unitNames[$unitId], $userId, $userName,
                        $link['relation'], $link['resides'], $effectiveDate, $actorId);
                }
            }
            foreach ($wanted as $unitId => $link) {
                if (isset($current[$unitId])) {
                    continue;
                }
                $insert->execute([Tenant::id(), $unitId, $userId, $link['relation'], $link['resides'] ? 1 : 0, $effectiveDate]);
                RelationshipHistory::open($pdo, $unitId, $unitNames[$unitId], $userId, $userName,
                    $link['relation'], $link['resides'], $effectiveDate, $actorId);
            }
            if ($ownTransaction) {
                $pdo->commit();
            }
        } catch (Throwable $exception) {
            if ($ownTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * Inmuebles de la estructura con sus propietarios vigentes, para elegir dónde vive un inquilino.
     *
     * @return array<string, array{name: string, owners: list<string>}> por id de inmueble, en el orden de la estructura
     */
    public function unitsWithOwners(): array
    {
        $composition = (new CompositionService())->current();
        $units = [];
        foreach ((is_array($composition) && is_array($composition['units'] ?? null)) ? $composition['units'] : [] as $unit) {
            if (is_array($unit) && is_string($unit['id'] ?? null) && $unit['id'] !== '') {
                $units[$unit['id']] = ['name' => (string) ($unit['name'] ?? ''), 'owners' => []];
            }
        }
        $statement = Database::connection()->prepare(
            "SELECT assignments.unit_id, COALESCE(NULLIF(profiles.full_name, ''), users.username) AS owner
             FROM property_unit_assignments AS assignments
             JOIN admin_users AS users ON users.id = assignments.user_id
             LEFT JOIN resident_profiles AS profiles ON profiles.user_id = users.id
             WHERE assignments.tenant_id = ? AND assignments.relation_type = 'propietario'
             ORDER BY owner"
        );
        $statement->execute([Tenant::id()]);
        foreach ($statement->fetchAll() as $row) {
            if (isset($units[(string) $row['unit_id']])) {
                $units[(string) $row['unit_id']]['owners'][] = (string) $row['owner'];
            }
        }

        return $units;
    }

    /**
     * Datos de la pantalla de trazabilidad: lista de inmuebles con sus vínculos vigentes y,
     * si se eligió uno, su historial completo.
     *
     * @return array{units: list<array>, unitOwners: array, historyCounts: array<string, int>, selectedUnit: ?array, history: list<array>}
     */
    public function historyPageData(?string $unitId): array
    {
        $units = [];
        $composition = (new CompositionService())->current();
        if (is_array($composition) && is_array($composition['units'])) {
            $composition = (new CompositionService())->ensureUnitIds();
            $units = array_values(array_filter($composition['units'] ?? [], 'is_array'));
        }

        $selectedUnit = null;
        $history = [];
        if ($unitId !== null) {
            foreach ($units as $unit) {
                if (($unit['id'] ?? null) === $unitId) {
                    $selectedUnit = $unit;
                }
            }
            $history = RelationshipHistory::forUnit($unitId);
            if ($selectedUnit === null && $history !== []) {
                // El inmueble ya no existe en la estructura, pero su historial se conserva.
                $selectedUnit = ['id' => $unitId, 'name' => (string) $history[0]['unit_name'], 'category' => '', 'removed' => true];
            }
        }

        $owners = [];
        $statement = Database::connection()->prepare(
            'SELECT assignments.unit_id, assignments.relation_type, assignments.resides, users.username
             FROM property_unit_assignments AS assignments
             JOIN admin_users AS users ON users.id = assignments.user_id
             WHERE assignments.tenant_id = ?
             ORDER BY assignments.relation_type, users.username'
        );
        $statement->execute([Tenant::id()]);
        foreach ($statement->fetchAll() as $row) {
            $owners[(string) $row['unit_id']][] = [
                'username' => (string) $row['username'],
                'relation' => RelationshipHistory::label((string) $row['relation_type'], (bool) $row['resides']),
            ];
        }

        return [
            'units' => $units,
            'unitOwners' => $owners,
            'historyCounts' => RelationshipHistory::countsByUnit(),
            'selectedUnit' => $selectedUnit,
            'history' => $history,
        ];
    }
}
