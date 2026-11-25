<?php

declare(strict_types=1);

namespace App\Modules\Billing;

use App\Core\Database;
use App\Core\Tenant;
use PDOException;

/**
 * Referencia de pago de cada inmueble: un número corto y estable (secuencia del conjunto + dígito de verificación
 * Luhn) que el residente escribe en la consignación o transferencia. Solo dígitos, porque muchos canales bancarios
 * no aceptan letras en el campo de referencia. El dígito de verificación detecta un número mal digitado.
 * Se asigna la primera vez que se necesita y no cambia mientras el inmueble exista.
 */
final class PaymentReferenceService
{
    private const FIRST_SEQUENCE = 101;

    /** @var array<string, string> */
    private array $cache = [];

    /** Referencia del inmueble; la crea si aún no tiene. null si la migración 038 no está aplicada. */
    public function forUnit(string $unitId): ?string
    {
        return $this->forUnits([$unitId])[$unitId] ?? null;
    }

    /**
     * @param list<string> $unitIds
     * @return array<string, string> unit_id => referencia
     */
    public function forUnits(array $unitIds): array
    {
        $unitIds = array_values(array_unique(array_filter($unitIds, static fn ($id): bool => is_string($id) && $id !== '')));
        $missing = array_values(array_filter($unitIds, fn (string $id): bool => !isset($this->cache[$id])));
        if ($missing !== []) {
            try {
                $this->load($missing);
                foreach ($missing as $unitId) {
                    if (!isset($this->cache[$unitId])) {
                        $this->cache[$unitId] = $this->create($unitId);
                    }
                }
            } catch (PDOException $exception) {
                if ((string) $exception->getCode() === '42S02') {
                    return [];
                }
                throw $exception;
            }
        }

        return array_intersect_key($this->cache, array_flip($unitIds));
    }

    /** Inmueble de una referencia escrita (ignora espacios y guiones); null si no existe o el dígito no cuadra. */
    public function unitFor(string $reference): ?string
    {
        $digits = preg_replace('/\D/', '', $reference) ?? '';
        if (!self::isValid($digits)) {
            return null;
        }
        $statement = Database::connection()->prepare(
            'SELECT unit_id FROM billing_unit_references WHERE tenant_id = ? AND reference = ?'
        );
        $statement->execute([Tenant::id(), $digits]);
        $unitId = $statement->fetchColumn();

        return is_string($unitId) ? $unitId : null;
    }

    /**
     * Referencias válidas escritas dentro de un texto libre (la referencia o descripción que muestra el banco).
     *
     * @return array<string, string> referencia => unit_id
     */
    public function findInText(string $text): array
    {
        $found = [];
        // Grupos de 4 a 12 dígitos; una referencia válida puede venir pegada a otros datos del banco.
        preg_match_all('/(?<!\d)\d{4,12}(?!\d)/', $text, $matches);
        foreach (array_unique($matches[0]) as $candidate) {
            $unitId = $this->unitFor($candidate);
            if ($unitId !== null) {
                $found[$candidate] = $unitId;
            }
        }

        return $found;
    }

    public static function isValid(string $digits): bool
    {
        return preg_match('/^\d{4,12}$/D', $digits) === 1
            && self::checkDigit(substr($digits, 0, -1)) === (int) substr($digits, -1);
    }

    /** Dígito de verificación Luhn (el de las tarjetas): detecta un dígito errado y casi todas las transposiciones. */
    public static function checkDigit(string $number): int
    {
        $sum = 0;
        $double = true;
        for ($index = strlen($number) - 1; $index >= 0; $index--) {
            $digit = (int) $number[$index];
            if ($double) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
            $double = !$double;
        }

        return (10 - ($sum % 10)) % 10;
    }

    /** @param list<string> $unitIds */
    private function load(array $unitIds): void
    {
        $placeholders = implode(',', array_fill(0, count($unitIds), '?'));
        $statement = Database::connection()->prepare(
            "SELECT unit_id, reference FROM billing_unit_references WHERE tenant_id = ? AND unit_id IN ($placeholders)"
        );
        $statement->execute([Tenant::id(), ...$unitIds]);
        foreach ($statement->fetchAll() as $row) {
            $this->cache[(string) $row['unit_id']] = (string) $row['reference'];
        }
    }

    /**
     * Asigna la siguiente secuencia. Si otra petición simultánea tomó la misma secuencia o ya creó la referencia
     * del inmueble, la llave única lo rechaza y se vuelve a intentar con lo que quedó guardado.
     */
    private function create(string $unitId): string
    {
        $pdo = Database::connection();
        for ($attempt = 1; ; $attempt++) {
            try {
                $next = $pdo->prepare('SELECT COALESCE(MAX(sequence), ?) + 1 FROM billing_unit_references WHERE tenant_id = ?');
                $next->execute([self::FIRST_SEQUENCE - 1, Tenant::id()]);
                $sequence = (int) $next->fetchColumn();
                $reference = $sequence . self::checkDigit((string) $sequence);
                $pdo->prepare(
                    'INSERT INTO billing_unit_references (tenant_id, unit_id, sequence, reference) VALUES (?, ?, ?, ?)'
                )->execute([Tenant::id(), $unitId, $sequence, $reference]);

                return $reference;
            } catch (PDOException $exception) {
                if ((string) $exception->getCode() !== '23000' || $attempt >= 5) {
                    throw $exception;
                }
                $this->load([$unitId]);
                if (isset($this->cache[$unitId])) {
                    return $this->cache[$unitId];
                }
            }
        }
    }
}
