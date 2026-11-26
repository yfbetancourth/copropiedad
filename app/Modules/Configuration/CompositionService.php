<?php

declare(strict_types=1);

namespace App\Modules\Configuration;

use App\Core\Database;
use App\Core\Tenant;
use App\Modules\PropertyAssignment\RelationshipHistory;
use JsonException;
use RuntimeException;

final class CompositionService
{
    /**
     * Estructura del conjunto. units es null mientras el asistente no haya generado inmuebles; cada inmueble trae
     * su coeficiente como texto con 4 decimales ('' si falta), igual que lo escribe el formulario.
     *
     * @return array{housing_type: string, answers: array<string, int>, units: ?list<array{id: string, category: string, name: string, coefficient: string}>, completed: bool}|null
     */
    public function current(): ?array
    {
        $pdo = Database::connection();
        $statement = $pdo->prepare(
            'SELECT housing_type, answers, completed
             FROM copropiedad_composition
             WHERE tenant_id = ?'
        );
        $statement->execute([Tenant::id()]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }

        try {
            $answers = json_decode((string) $row['answers'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('No se pudo leer la estructura guardada de la copropiedad.', 0, $exception);
        }
        if (!is_array($answers)) {
            throw new RuntimeException('La estructura guardada de la copropiedad tiene un formato invalido.');
        }

        $units = [];
        foreach ($this->storedUnits() as $unit) {
            $units[] = [
                'id' => $unit['id'],
                'category' => $unit['category'],
                'name' => $unit['name'],
                'coefficient' => $unit['coefficient'],
            ];
        }

        return [
            'housing_type' => (string) $row['housing_type'],
            'answers' => $answers,
            'units' => $units === [] ? null : $units,
            'completed' => (bool) $row['completed'],
        ];
    }

    /**
     * Inmuebles guardados del conjunto en su orden de presentación, indexados por identificador.
     *
     * @return array<string, array{id: string, category: string, name: string, coefficient: string, sort_order: int}>
     */
    private function storedUnits(): array
    {
        $statement = Database::connection()->prepare(
            'SELECT id, category, name, coefficient, sort_order
             FROM property_units
             WHERE tenant_id = ?
             ORDER BY sort_order, id'
        );
        $statement->execute([Tenant::id()]);
        $units = [];
        foreach ($statement->fetchAll() as $row) {
            $units[(string) $row['id']] = [
                'id' => (string) $row['id'],
                'category' => (string) $row['category'],
                'name' => (string) $row['name'],
                'coefficient' => $row['coefficient'] === null ? '' : (string) $row['coefficient'],
                'sort_order' => (int) $row['sort_order'],
            ];
        }

        return $units;
    }

    /**
     * @param array<string, int> $answers
     */
    public function saveAnswers(string $housingType, array $answers): void
    {
        $encoded = json_encode($answers, JSON_THROW_ON_ERROR);
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO copropiedad_composition (tenant_id, housing_type, answers, completed)
                 VALUES (?, ?, ?, 0)
                 ON DUPLICATE KEY UPDATE
                    housing_type = VALUES(housing_type),
                    answers = VALUES(answers),
                    completed = 0'
            )->execute([Tenant::id(), $housingType, $encoded]);
            $pdo->prepare('DELETE FROM property_unit_assignments WHERE tenant_id = ?')->execute([Tenant::id()]);
            RelationshipHistory::closeAll($pdo, 'reinicio_conformacion', self::actorId());
            $pdo->prepare('DELETE FROM property_units WHERE tenant_id = ?')->execute([Tenant::id()]);
            $pdo->commit();
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * @param list<array{id?: string, category: string, name: string, coefficient?: string}> $units
     */
    public function saveUnits(array $units, bool $completed): void
    {
        $normalized = [];
        foreach (array_values($units) as $position => $unit) {
            $id = $unit['id'] ?? '';
            if ($id === '') {
                $id = bin2hex(random_bytes(16));
            }
            if (!is_string($id) || preg_match('/^[a-f0-9]{32}$/D', $id) !== 1 || isset($normalized[$id])) {
                throw new RuntimeException('Los identificadores de los inmuebles no son validos.');
            }
            $coefficient = (string) ($unit['coefficient'] ?? '');
            if ($coefficient !== '' && preg_match('/^\d{1,3}(?:\.\d{1,4})?$/D', $coefficient) !== 1) {
                throw new RuntimeException('El coeficiente de «' . $unit['name'] . '» no es valido.');
            }
            $normalized[$id] = [
                'id' => $id,
                'category' => (string) $unit['category'],
                'name' => (string) $unit['name'],
                'coefficient' => $coefficient,
                'sort_order' => $position + 1,
            ];
        }

        $tenantId = Tenant::id();
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            // Serializa los guardados simultáneos de la estructura del mismo conjunto.
            $pdo->prepare('SELECT tenant_id FROM copropiedad_composition WHERE tenant_id = ? FOR UPDATE')->execute([$tenantId]);
            $stored = $this->storedUnits();

            $removed = array_keys(array_diff_key($stored, $normalized));
            $removeAssignment = $pdo->prepare('DELETE FROM property_unit_assignments WHERE tenant_id = ? AND unit_id = ?');
            foreach ($removed as $removedId) {
                $removeAssignment->execute([$tenantId, $removedId]);
                RelationshipHistory::close($pdo, $removedId, null, date('Y-m-d'), 'inmueble_eliminado', self::actorId());
            }
            // La llave foránea de la cartera impide quitar un inmueble con notas, cargos o referencia de pago.
            foreach (array_chunk($removed, 500) as $chunk) {
                $pdo->prepare('DELETE FROM property_units WHERE tenant_id = ? AND id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')')
                    ->execute([$tenantId, ...$chunk]);
            }

            // Libera primero los nombres que cambian: así un intercambio (A↔B) no choca con la llave única del nombre.
            $renamed = [];
            $changed = [];
            $new = [];
            foreach ($normalized as $id => $unit) {
                $before = $stored[$id] ?? null;
                if ($before === null) {
                    $new[] = $unit;
                } elseif (array_diff_assoc($unit, $before) !== []) {
                    $changed[] = $unit;
                    if ($before['name'] !== $unit['name']) {
                        $renamed[] = $id;
                    }
                }
            }
            foreach (array_chunk($renamed, 500) as $chunk) {
                $pdo->prepare("UPDATE property_units SET name = CONCAT('~', id) WHERE tenant_id = ? AND id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ')')
                    ->execute([$tenantId, ...$chunk]);
            }
            $update = $pdo->prepare(
                'UPDATE property_units SET sort_order = ?, category = ?, name = ?, coefficient = ? WHERE tenant_id = ? AND id = ?'
            );
            foreach ($changed as $unit) {
                $update->execute([$unit['sort_order'], $unit['category'], $unit['name'], self::coefficientValue($unit['coefficient']), $tenantId, $unit['id']]);
            }
            foreach (array_chunk($new, 500) as $chunk) {
                $values = [];
                foreach ($chunk as $unit) {
                    array_push($values, $tenantId, $unit['id'], $unit['sort_order'], $unit['category'], $unit['name'], self::coefficientValue($unit['coefficient']));
                }
                $pdo->prepare(
                    'INSERT INTO property_units (tenant_id, id, sort_order, category, name, coefficient) VALUES '
                    . implode(',', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?)'))
                )->execute($values);
            }

            $pdo->prepare('UPDATE copropiedad_composition SET completed = ? WHERE tenant_id = ?')
                ->execute([$completed ? 1 : 0, $tenantId]);
            $pdo->commit();
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($exception instanceof \PDOException) {
                $driverCode = (int) ($exception->errorInfo[1] ?? 0);
                if ($driverCode === 1062) {
                    throw new RuntimeException('Hay dos inmuebles con el mismo nombre (sin distinguir mayúsculas ni tildes).', 0, $exception);
                }
                if ($driverCode === 1451) {
                    throw new RuntimeException('Un inmueble que intentas quitar tiene cartera o referencia de pago y no se puede quitar.', 0, $exception);
                }
            }
            throw $exception;
        }
    }

    /** '' (coeficiente pendiente) se guarda como NULL. */
    private static function coefficientValue(string $coefficient): ?string
    {
        return $coefficient === '' ? null : $coefficient;
    }

    /**
     * Borra la estructura y sus vínculos vigentes. Quien llama abre la transacción.
     */
    public function clear(): void
    {
        $pdo = Database::connection();
        $pdo->prepare('DELETE FROM property_unit_assignments WHERE tenant_id = ?')->execute([Tenant::id()]);
        RelationshipHistory::closeAll($pdo, 'reinicio_conformacion', self::actorId());
        $pdo->prepare('DELETE FROM property_units WHERE tenant_id = ?')->execute([Tenant::id()]);
        $pdo->prepare('DELETE FROM copropiedad_composition WHERE tenant_id = ?')->execute([Tenant::id()]);
    }

    /**
     * Usuario que hace el cambio, para dejarlo en la trazabilidad de vínculos.
     */
    private static function actorId(): ?int
    {
        $id = $_SESSION['admin_user_id'] ?? null;

        return is_int($id) && $id > 0 ? $id : null;
    }

    /**
     * Inmuebles con cartera: notas de cobro, cargos mensuales o referencia de pago. No pueden desaparecer de la
     * estructura (las llaves foráneas hacia property_units lo impiden); se consulta antes para avisar con claridad.
     *
     * @return array<string, true>
     */
    public function unitIdsWithBillingHistory(): array
    {
        try {
            $statement = Database::connection()->prepare(
                'SELECT unit_id FROM billing_statements WHERE tenant_id = ?
                 UNION SELECT unit_id FROM billing_monthly_items WHERE tenant_id = ?
                 UNION SELECT unit_id FROM billing_unit_references WHERE tenant_id = ?'
            );
            $statement->execute([Tenant::id(), Tenant::id(), Tenant::id()]);
            $ids = $statement->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\PDOException) {
            return []; // El módulo de cartera aún no está migrado.
        }

        return array_fill_keys(array_map('strval', $ids), true);
    }

    public function assignmentCount(): int
    {
        $statement = Database::connection()->prepare('SELECT COUNT(*) FROM property_unit_assignments WHERE tenant_id = ?');
        $statement->execute([Tenant::id()]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Estado de los tres pasos de la conformación, para la guía que comparten sus pantallas.
     *
     * @return array{
     *     housing_type: string, unit_count: int, completed: bool, missing_coefficients: int,
     *     coefficient_total: string, assigned_units: int, assignments: int, billing_locked: bool
     * }
     */
    public function progress(string $housingType): array
    {
        $composition = $this->current();
        $units = is_array($composition) && $composition['housing_type'] === $housingType && is_array($composition['units'])
            ? $composition['units']
            : [];
        $missing = 0;
        $totalTenThousandths = 0;
        foreach ($units as $unit) {
            $coefficient = is_array($unit) ? (string) ($unit['coefficient'] ?? '') : '';
            // 0 es un coeficiente válido (inmueble que no paga cuota); solo falta si está vacío o mal escrito.
            if (preg_match('/^(\d{1,3})\.(\d{4})$/D', $coefficient, $parts) !== 1) {
                $missing++;
                continue;
            }
            $totalTenThousandths += (int) $parts[1] * 10000 + (int) $parts[2];
        }

        $assignedUnits = 0;
        if ($units !== []) {
            $validIds = [];
            foreach ($units as $unit) {
                if (is_array($unit) && is_string($unit['id'] ?? null)) {
                    $validIds[$unit['id']] = true;
                }
            }
            $assigned = Database::connection()->prepare('SELECT DISTINCT unit_id FROM property_unit_assignments WHERE tenant_id = ?');
            $assigned->execute([Tenant::id()]);
            foreach ($assigned->fetchAll(\PDO::FETCH_COLUMN) as $unitId) {
                if (isset($validIds[(string) $unitId])) {
                    $assignedUnits++;
                }
            }
        }

        return [
            'housing_type' => $housingType,
            'unit_count' => count($units),
            'completed' => $units !== [] && (bool) ($composition['completed'] ?? false),
            'missing_coefficients' => $missing,
            'coefficient_total' => intdiv($totalTenThousandths, 10000) . '.' . str_pad((string) ($totalTenThousandths % 10000), 4, '0', STR_PAD_LEFT),
            'assigned_units' => $assignedUnits,
            'assignments' => $this->assignmentCount(),
            'billing_locked' => $this->unitIdsWithBillingHistory() !== [],
        ];
    }
}
