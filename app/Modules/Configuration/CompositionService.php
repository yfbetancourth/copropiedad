<?php

declare(strict_types=1);

namespace App\Modules\Configuration;

use App\Core\Database;
use JsonException;
use RuntimeException;

final class CompositionService
{
    /**
     * @return array{housing_type: string, answers: array<string, int>, units: ?array, completed: bool}|null
     */
    public function current(): ?array
    {
        $row = Database::connection()->query(
            'SELECT housing_type, answers, units, completed
             FROM copropiedad_composition
             WHERE id = 1'
        )->fetch();
        if (!is_array($row)) {
            return null;
        }

        try {
            $answers = json_decode((string) $row['answers'], true, 512, JSON_THROW_ON_ERROR);
            $units = $row['units'] === null
                ? null
                : json_decode((string) $row['units'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('No se pudo leer la estructura guardada de la copropiedad.', 0, $exception);
        }
        if (!is_array($answers) || ($units !== null && !is_array($units))) {
            throw new RuntimeException('La estructura guardada de la copropiedad tiene un formato invalido.');
        }

        return [
            'housing_type' => (string) $row['housing_type'],
            'answers' => $answers,
            'units' => $units,
            'completed' => (bool) $row['completed'],
        ];
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
                'INSERT INTO copropiedad_composition (id, housing_type, answers, units, completed)
                 VALUES (1, ?, ?, NULL, 0)
                 ON DUPLICATE KEY UPDATE
                    housing_type = VALUES(housing_type),
                    answers = VALUES(answers),
                    units = NULL,
                    completed = 0'
            )->execute([$housingType, $encoded]);
            $pdo->exec('DELETE FROM property_unit_assignments');
            $pdo->commit();
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * @param list<array{id?: string, category: string, name: string}> $units
     */
    public function saveUnits(array $units, bool $completed): void
    {
        $normalized = [];
        $ids = [];
        foreach ($units as $unit) {
            $id = $unit['id'] ?? '';
            if ($id === '') {
                $id = bin2hex(random_bytes(16));
            }
            if (!is_string($id) || preg_match('/^[a-f0-9]{32}$/D', $id) !== 1 || isset($ids[$id])) {
                throw new RuntimeException('Los identificadores de los inmuebles no son validos.');
            }
            $ids[$id] = true;
            $normalized[] = [
                'id' => $id,
                'category' => $unit['category'],
                'name' => $unit['name'],
            ];
        }

        $encoded = json_encode($normalized, JSON_THROW_ON_ERROR);
        $pdo = Database::connection();
        $current = $this->current();
        $previousIds = [];
        foreach ($current['units'] ?? [] as $unit) {
            if (is_array($unit) && is_string($unit['id'] ?? null)) {
                $previousIds[$unit['id']] = true;
            }
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'UPDATE copropiedad_composition
                 SET units = ?, completed = ?
                 WHERE id = 1'
            )->execute([$encoded, $completed ? 1 : 0]);

            $removeAssignment = $pdo->prepare(
                'DELETE FROM property_unit_assignments WHERE unit_id = ?'
            );
            foreach (array_diff_key($previousIds, $ids) as $removedId => $_) {
                $removeAssignment->execute([$removedId]);
            }
            $pdo->commit();
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * Add persistent identifiers to legacy property structures when needed.
     */
    public function ensureUnitIds(): ?array
    {
        $composition = $this->current();
        if (!is_array($composition) || !is_array($composition['units'])) {
            return $composition;
        }

        $units = $composition['units'];
        $used = [];
        $changed = false;
        foreach ($units as &$unit) {
            if (!is_array($unit)) {
                throw new RuntimeException('La estructura guardada contiene un inmueble invalido.');
            }
            $id = $unit['id'] ?? null;
            if (!is_string($id) || preg_match('/^[a-f0-9]{32}$/D', $id) !== 1 || isset($used[$id])) {
                do {
                    $id = bin2hex(random_bytes(16));
                } while (isset($used[$id]));
                $unit['id'] = $id;
                $changed = true;
            }
            $used[$id] = true;
        }
        unset($unit);

        if ($changed) {
            $this->saveUnits($units, $composition['completed']);
            $composition['units'] = $units;
        }

        return $composition;
    }

    public function clear(): void
    {
        $pdo = Database::connection();
        $pdo->exec('DELETE FROM property_unit_assignments');
        $pdo->exec('DELETE FROM copropiedad_composition WHERE id = 1');
    }
}
