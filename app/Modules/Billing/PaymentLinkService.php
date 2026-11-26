<?php

declare(strict_types=1);

namespace App\Modules\Billing;

use App\Core\Database;
use App\Core\SecretBox;
use App\Core\Tenant;
use App\Modules\Auth\SmtpMailer;
use RuntimeException;

/**
 * Enlaces de pago temporales de una nota de cobro. Quien tiene el enlace ve el resumen de la nota y puede pagarla
 * en Bold sin iniciar sesión, hasta que vence o se revoca. El pago se atribuye a la cuenta destinataria elegida.
 */
final class PaymentLinkService
{
    /** Vigencias que puede elegir la administración, en horas. */
    public const DURATIONS = [24 => '24 horas', 72 => '3 días', 168 => '7 días', 360 => '15 días'];
    private const PORTAL_HOURS = 24;

    /**
     * @return array{id: int, url: string, expires_at: string}
     */
    public function create(int $statementId, int $userId, int $hours, int $createdBy, string $channel): array
    {
        if (!isset(self::DURATIONS[$hours]) && !($channel === 'portal' && $hours === self::PORTAL_HOURS)) {
            throw new RuntimeException('Selecciona una vigencia válida para el enlace.');
        }
        $statement = (new BillingService())->payableStatement($statementId);
        if (!BillingService::isRecipient($statement, $userId)) {
            throw new RuntimeException('Selecciona una cuenta destinataria de la nota de cobro.');
        }

        $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $pdo = Database::connection();
        $pdo->prepare(
            'INSERT INTO billing_payment_links
                (tenant_id, statement_id, user_id, token_hash, token_ciphertext, channel, expires_at, created_by)
             VALUES (?, ?, ?, ?, ?, ?, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL ? HOUR), ?)'
        )->execute([
            Tenant::id(),
            $statementId,
            $userId,
            hash('sha256', $token),
            SecretBox::encrypt($token, $this->context()),
            $channel,
            $hours,
            $createdBy,
        ]);
        $id = (int) $pdo->lastInsertId();
        $link = $this->find($id);

        return ['id' => $id, 'url' => $link['url'], 'expires_at' => (string) $link['expires_at']];
    }

    /**
     * Enlace que el residente usa para pagar desde otro dispositivo: reutiliza el suyo si le quedan más de
     * 2 horas de vigencia; si no, crea uno de 24 horas.
     *
     * @return array{id: int, url: string, expires_at: string}
     */
    public function portalLink(int $statementId, int $userId): array
    {
        $query = Database::connection()->prepare(
            "SELECT id FROM billing_payment_links
             WHERE tenant_id = ? AND statement_id = ? AND user_id = ? AND channel = 'portal'
               AND revoked_at IS NULL AND expires_at > DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 2 HOUR)
             ORDER BY expires_at DESC LIMIT 1"
        );
        $query->execute([Tenant::id(), $statementId, $userId]);
        $existing = $query->fetchColumn();
        if ($existing !== false) {
            (new BillingService())->payableStatement($statementId);
            $link = $this->find((int) $existing);

            return ['id' => (int) $link['id'], 'url' => $link['url'], 'expires_at' => (string) $link['expires_at']];
        }

        return $this->create($statementId, $userId, self::PORTAL_HOURS, $userId, 'portal');
    }

    /** Enlaces de una nota (los más recientes primero), con su estado y su URL para volver a compartirla. */
    public function forStatement(int $statementId): array
    {
        $query = Database::connection()->prepare(
            'SELECT links.*, users.username AS payer_name, creators.username AS creator_name,
                    links.expires_at <= CURRENT_TIMESTAMP AS expired
             FROM billing_payment_links AS links
             JOIN admin_users AS users ON users.id = links.user_id
             JOIN admin_users AS creators ON creators.id = links.created_by
             WHERE links.tenant_id = ? AND links.statement_id = ?
             ORDER BY links.created_at DESC, links.id DESC
             LIMIT 30'
        );
        $query->execute([Tenant::id(), $statementId]);
        $links = [];
        foreach ($query->fetchAll() as $row) {
            $links[] = $this->decorate($row);
        }

        return $links;
    }

    /**
     * Enlace vigente por su token. Devuelve null si no existe, venció o fue revocado.
     */
    public function findActiveByToken(string $token): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{32}$/D', $token)) {
            return null;
        }
        $query = Database::connection()->prepare(
            'SELECT links.*, links.expires_at <= CURRENT_TIMESTAMP AS expired
             FROM billing_payment_links AS links
             WHERE links.token_hash = ? AND links.tenant_id = ?'
        );
        $query->execute([hash('sha256', $token), Tenant::id()]);
        $row = $query->fetch();
        if (!is_array($row) || (bool) $row['expired'] || $row['revoked_at'] !== null) {
            return null;
        }

        return $this->decorate($row);
    }

    public function markOpened(int $linkId): void
    {
        Database::connection()->prepare(
            'UPDATE billing_payment_links SET open_count = open_count + 1, last_opened_at = CURRENT_TIMESTAMP
             WHERE id = ? AND tenant_id = ?'
        )->execute([$linkId, Tenant::id()]);
    }

    public function revoke(int $linkId, int $actorId): int
    {
        $link = $this->find($linkId);
        Database::connection()->prepare(
            'UPDATE billing_payment_links SET revoked_at = CURRENT_TIMESTAMP, revoked_by = ?
             WHERE id = ? AND tenant_id = ? AND revoked_at IS NULL'
        )->execute([$actorId, $linkId, Tenant::id()]);

        return (int) $link['statement_id'];
    }

    /** Envía el enlace por correo a la cuenta destinataria, desde el correo del administrador o la cuenta general. */
    public function send(int $linkId, int $actorId): string
    {
        $link = $this->find($linkId);
        if ((bool) $link['expired'] || $link['revoked_at'] !== null) {
            throw new RuntimeException('El enlace ya no está vigente. Genera uno nuevo.');
        }
        $statement = (new BillingService())->statement((int) $link['statement_id']);
        $recipient = Database::connection()->prepare('SELECT username, email FROM admin_users WHERE id = ? AND tenant_id = ?');
        $recipient->execute([(int) $link['user_id'], Tenant::id()]);
        $user = $recipient->fetch();
        if (!is_array($user) || !filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('La cuenta destinataria no tiene un correo válido.');
        }

        $amount = BillingService::money((int) $statement['payable_balance']);
        $expires = date('d/m/Y H:i', strtotime((string) $link['expires_at']) ?: time());
        $text = sprintf(
            "Hola %s,\n\nLa administración de %s te comparte un enlace para pagar la nota de cobro %s (%s, período %s).\n\nSaldo a pagar: %s\nPagar en línea: %s\n\nEl enlace vence el %s. Puedes pagar con los medios habilitados en Bold (tarjeta, PSE, Nequi, etc.). Si ya pagaste, ignora este mensaje.",
            $user['username'],
            $statement['property_name'],
            $statement['number'],
            $statement['unit_name'],
            $statement['period'],
            $amount,
            $link['url'],
            $expires
        );
        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<div style="font-family:system-ui,-apple-system,Segoe UI,sans-serif;color:#17414E;max-width:560px">'
            . '<p>Hola ' . $e((string) $user['username']) . ',</p>'
            . '<p>La administración de <strong>' . $e((string) $statement['property_name']) . '</strong> te comparte un enlace para pagar la nota de cobro <strong>'
            . $e((string) $statement['number']) . '</strong> (' . $e((string) $statement['unit_name']) . ', período ' . $e((string) $statement['period']) . ').</p>'
            . '<p style="font-size:20px;margin:16px 0"><strong>' . $e($amount) . '</strong></p>'
            . '<p><a href="' . $e($link['url']) . '" style="display:inline-block;padding:12px 20px;border-radius:8px;background:#0E7490;color:#fff;text-decoration:none;font-weight:600">Pagar en línea</a></p>'
            . '<p style="color:#5f675f;font-size:13px">El enlace vence el ' . $e($expires) . '. Si el botón no funciona, copia esta dirección: ' . $e($link['url']) . '</p>'
            . '<p style="color:#5f675f;font-size:13px">Si ya pagaste, ignora este mensaje.</p></div>';

        $billing = new BillingService();
        $sender = $billing->senderFor($actorId);
        try {
            (new SmtpMailer())->send(
                $sender['settings'],
                (string) $user['email'],
                (string) $user['username'],
                'Enlace de pago · ' . $statement['number'] . ' · ' . $statement['property_name'],
                $html,
                $text
            );
        } catch (\PHPMailer\PHPMailer\Exception $exception) {
            error_log('No se pudo enviar el enlace de pago: ' . $exception->getMessage());
            throw new RuntimeException('No se pudo enviar el correo desde ' . $sender['address'] . '. Revisa la cuenta SMTP; el enlace sigue disponible para copiarlo.');
        }
        Database::connection()->prepare(
            'UPDATE billing_payment_links SET sent_to = ?, sent_at = CURRENT_TIMESTAMP WHERE id = ? AND tenant_id = ?'
        )->execute([(string) $user['email'], $linkId, Tenant::id()]);

        return (string) $user['email'];
    }

    private function find(int $linkId): array
    {
        $query = Database::connection()->prepare(
            'SELECT links.*, links.expires_at <= CURRENT_TIMESTAMP AS expired
             FROM billing_payment_links AS links WHERE links.id = ? AND links.tenant_id = ?'
        );
        $query->execute([$linkId, Tenant::id()]);
        $row = $query->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('El enlace de pago no existe.');
        }

        return $this->decorate($row);
    }

    private function decorate(array $row): array
    {
        $row['expired'] = (bool) $row['expired'];
        try {
            $row['url'] = Tenant::absoluteUrl('/pagar?t=' . SecretBox::decrypt((string) $row['token_ciphertext'], $this->context()));
        } catch (RuntimeException) {
            $row['url'] = '';
        }
        $row['state'] = $row['revoked_at'] !== null ? 'revoked' : ($row['expired'] ? 'expired' : 'active');
        unset($row['token_hash'], $row['token_ciphertext']);

        return $row;
    }

    private function context(): string
    {
        return 'billing-payment-link:' . Tenant::id();
    }
}
