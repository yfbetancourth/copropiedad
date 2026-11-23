<?php

declare(strict_types=1);

namespace App\Modules\Billing;

use RuntimeException;

/**
 * Pasarelas de pago en línea disponibles en el conjunto (Bold y Wompi, cada una con su módulo habilitado por el
 * Superadmin y su configuración completa) y el inicio del checkout en la elegida.
 */
final class PaymentGateways
{
    public const LABELS = ['bold' => 'Bold', 'wompi' => 'Wompi'];

    /** @var array<string, bool>|null */
    private static ?array $ready = null;

    /** @return list<string> Pasarelas listas para cobrar, en orden de presentación. */
    public static function ready(): array
    {
        self::$ready ??= [
            'bold' => (new BoldPaymentService())->isReady(),
            'wompi' => (new WompiPaymentService())->isReady(),
        ];

        return array_keys(array_filter(self::$ready));
    }

    public static function anyReady(): bool
    {
        return self::ready() !== [];
    }

    public static function isReady(string $gateway): bool
    {
        return in_array($gateway, self::ready(), true);
    }

    /** URL del checkout de la pasarela para pagar el saldo vigente de la nota a nombre de $userId. */
    public static function checkoutUrl(string $gateway, int $statementId, int $userId): string
    {
        if (!self::isReady($gateway)) {
            throw new RuntimeException('Ese medio de pago en línea no está disponible.');
        }

        return match ($gateway) {
            'bold' => (new BoldPaymentService())->createPaymentLink($statementId, $userId),
            'wompi' => (new WompiPaymentService())->createCheckout($statementId, $userId),
        };
    }
}
