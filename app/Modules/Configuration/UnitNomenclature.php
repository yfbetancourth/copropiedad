<?php

declare(strict_types=1);

namespace App\Modules\Configuration;

/**
 * Reglas de nombres de torres y apartamentos que comparten el asistente de conformación y sus vistas.
 */
final class UnitNomenclature
{
    public const TOWER_FORMATS = [
        'numeric' => 'Con números · Torre 1, Torre 2…',
        'alphabetic' => 'Con letras · Torre A, Torre B…',
    ];

    public const APARTMENT_FORMATS = [
        'piso_numero' => 'Piso y número · 101, 102, 201…',
        'piso_apartamento' => 'Piso separado · Piso 1 - Apartamento 01',
        'consecutivo' => 'Consecutivo por torre · 1, 2, 3…',
    ];

    public static function isTowerFormat(mixed $format): bool
    {
        return is_string($format) && array_key_exists($format, self::TOWER_FORMATS);
    }

    public static function isApartmentFormat(mixed $format): bool
    {
        return is_string($format) && array_key_exists($format, self::APARTMENT_FORMATS);
    }

    public static function towerName(int $number, string $towerFormat): string
    {
        if ($towerFormat !== 'alphabetic') {
            return (string) $number;
        }

        $name = '';
        while ($number > 0) {
            $number--;
            $name = chr(65 + ($number % 26)) . $name;
            $number = intdiv($number, 26);
        }

        return $name;
    }

    /**
     * @param int $floor Piso, desde 1.
     * @param int $unit Apartamento dentro del piso, desde 1 (máximo 99).
     */
    public static function apartmentName(
        string $towerName,
        int $floor,
        int $unit,
        int $unitsPerFloor,
        string $apartmentFormat,
    ): string {
        return match ($apartmentFormat) {
            'piso_apartamento' => sprintf(
                'Torre %s - Piso %d - Apartamento %s',
                $towerName,
                $floor,
                str_pad((string) $unit, 2, '0', STR_PAD_LEFT)
            ),
            'consecutivo' => sprintf('Torre %s - Apartamento %d', $towerName, ($floor - 1) * $unitsPerFloor + $unit),
            default => sprintf('Torre %s - Apartamento %d%s', $towerName, $floor, str_pad((string) $unit, 2, '0', STR_PAD_LEFT)),
        };
    }

    /** Dos nombres de ejemplo (primero y último del primer piso) para las vistas previas. */
    public static function examples(string $towerFormat, string $apartmentFormat, int $unitsPerFloor = 2): array
    {
        $tower = self::towerName(1, $towerFormat);
        $unitsPerFloor = max(1, min(99, $unitsPerFloor));

        return [
            self::apartmentName($tower, 1, 1, $unitsPerFloor, $apartmentFormat),
            self::apartmentName($tower, 1, max(2, $unitsPerFloor), max(2, $unitsPerFloor), $apartmentFormat),
        ];
    }

    /**
     * Un apartamento editado a mano debe seguir terminando en «Apartamento X», donde X es
     * un número (01, 101, 7) o un código corto con letras (101A, PH-1).
     */
    public static function isValidApartmentName(string $name): bool
    {
        return preg_match('/(?:^| - )Apartamento [0-9A-Za-z][0-9A-Za-z-]{0,9}$/D', $name) === 1;
    }
}
