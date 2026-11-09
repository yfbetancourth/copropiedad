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
        Database::connection()->prepare(
            'INSERT INTO copropiedad_composition (id, housing_type, answers, units, completed)
             VALUES (1, ?, ?, NULL, 0)
             ON DUPLICATE KEY UPDATE
                housing_type = VALUES(housing_type),
                answers = VALUES(answers),
                units = NULL,
                completed = 0'
        )->execute([$housingType, $encoded]);
    }

    /**
     * @param list<array{category: string, name: string}> $units
     */
    public function saveUnits(array $units, bool $completed): void
    {
        $encoded = json_encode($units, JSON_THROW_ON_ERROR);
        Database::connection()->prepare(
            'UPDATE copropiedad_composition
             SET units = ?, completed = ?
             WHERE id = 1'
        )->execute([$encoded, $completed ? 1 : 0]);
    }

    public function clear(): void
    {
        Database::connection()->exec('DELETE FROM copropiedad_composition WHERE id = 1');
    }
}
