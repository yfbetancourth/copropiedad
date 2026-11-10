<?php

declare(strict_types=1);

namespace App\Modules\PropertyAssignment;

use App\Core\Database;
use App\Modules\Configuration\CompositionService;
use RuntimeException;
use Throwable;

final class PropertyAssignmentService
{
    /**
     * @return array{units: array, users: array, selectedUser: ?array, assignedUnitIds: array}
     */
    public function pageData(?int $selectedUserId): array
    {
        $units = [];
        $composition = (new CompositionService())->current();
        if (is_array($composition) && $composition['completed'] && is_array($composition['units'])) {
            $composition = (new CompositionService())->ensureUnitIds();
            $units = $composition['units'] ?? [];
        }

        $users = Database::connection()->query(
            'SELECT users.id, users.username, users.email, users.is_active, roles.name AS role_name
             FROM admin_users AS users
             JOIN admin_roles AS roles ON roles.id = users.role_id
             WHERE roles.is_system = 0
             ORDER BY users.is_active DESC, users.username'
        )->fetchAll();

        $validUnitIds = [];
        foreach ($units as $unit) {
            if (is_array($unit) && is_string($unit['id'] ?? null)) {
                $validUnitIds[$unit['id']] = true;
            }
        }

        $userCounts = [];
        foreach (Database::connection()->query(
            'SELECT user_id, COUNT(*) AS assignment_count
             FROM property_unit_assignments
             GROUP BY user_id'
        )->fetchAll() as $assignmentCount) {
            $userCounts[(int) $assignmentCount['user_id']] = (int) $assignmentCount['assignment_count'];
        }

        $selectedAssignments = [];
        if ($selectedUserId !== null) {
            $statement = Database::connection()->prepare(
                'SELECT unit_id FROM property_unit_assignments WHERE user_id = ?'
            );
            $statement->execute([$selectedUserId]);
            foreach ($statement->fetchAll() as $assignment) {
                $unitId = (string) $assignment['unit_id'];
                if (isset($validUnitIds[$unitId])) {
                    $selectedAssignments[] = $unitId;
                }
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
            'assignedUnitIds' => $selectedAssignments,
        ];
    }

    /**
     * @param list<string> $unitIds
     */
    public function assign(int $userId, array $unitIds): void
    {
        $userStatement = Database::connection()->prepare(
            'SELECT users.id
             FROM admin_users AS users
             JOIN admin_roles AS roles ON roles.id = users.role_id
             WHERE users.id = ? AND roles.is_system = 0
             LIMIT 1'
        );
        $userStatement->execute([$userId]);
        if ($userStatement->fetchColumn() === false) {
            throw new RuntimeException('Selecciona un usuario valido.');
        }

        $composition = (new CompositionService())->current();
        if (!is_array($composition) || !$composition['completed'] || !is_array($composition['units'])) {
            throw new RuntimeException('Primero finaliza la estructura de inmuebles en la seccion de conformacion.');
        }
        $composition = (new CompositionService())->ensureUnitIds();
        if (!is_array($composition) || !is_array($composition['units'])) {
            throw new RuntimeException('No se pudo leer la estructura de inmuebles actual.');
        }

        $validUnitIds = [];
        foreach ($composition['units'] as $unit) {
            if (is_array($unit) && is_string($unit['id'] ?? null)) {
                $validUnitIds[$unit['id']] = true;
            }
        }
        if (count($unitIds) > count($validUnitIds)) {
            throw new RuntimeException('La seleccion contiene inmuebles que no pertenecen a la estructura actual.');
        }

        $uniqueIds = [];
        foreach ($unitIds as $unitId) {
            if (!is_string($unitId) || preg_match('/^[a-f0-9]{32}$/D', $unitId) !== 1
                || !isset($validUnitIds[$unitId]) || isset($uniqueIds[$unitId])
            ) {
                throw new RuntimeException('La seleccion contiene un inmueble invalido. Recarga la pagina e intenta nuevamente.');
            }
            $uniqueIds[$unitId] = true;
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM property_unit_assignments WHERE user_id = ?')->execute([$userId]);
            $insert = $pdo->prepare(
                'INSERT INTO property_unit_assignments (unit_id, user_id) VALUES (?, ?)'
            );
            foreach (array_keys($uniqueIds) as $unitId) {
                $insert->execute([$unitId, $userId]);
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }
}
