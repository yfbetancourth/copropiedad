<?php

declare(strict_types=1);

namespace App\Modules\Auth;

use App\Modules\Configuration\MailOAuthService;
use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;

final class SmtpMailer
{
    public function __construct(private readonly ?MailOAuthService $oauth = null)
    {
    }

    /**
     * @param array{host: string, port: int, username: string, password: string, encryption: string, from_address: string, from_name: string} $settings
     */
    public function send(
        array $settings,
        string $recipient,
        string $recipientName,
        string $subject,
        string $htmlBody,
        string $textBody
    ): void {
        // Cuenta conectada con Google o Microsoft: se arma el mismo mensaje y se envía por la API del proveedor.
        if (isset($settings['oauth_connection_id'])) {
            $this->sendWithConnectedAccount($settings, $recipient, $recipientName, $subject, $htmlBody, $textBody);
            return;
        }
        if ($settings['host'] === '' || $settings['port'] < 1 || $settings['port'] > 65535
            || $settings['username'] === '' || $settings['password'] === ''
            || !filter_var($settings['from_address'], FILTER_VALIDATE_EMAIL)
            || !filter_var($recipient, FILTER_VALIDATE_EMAIL)
        ) {
            throw new RuntimeException('Completa una configuracion SMTP valida antes de enviar correos.');
        }

        $mailer = new PHPMailer(true);
        $mailer->isSMTP();
        $mailer->Host = $settings['host'];
        $mailer->Port = $settings['port'];
        $mailer->SMTPAuth = true;
        $mailer->Username = $settings['username'];
        $mailer->Password = $settings['password'];
        $mailer->Timeout = 10;
        $mailer->SMTPDebug = 0;

        if ($settings['encryption'] === 'tls') {
            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($settings['encryption'] === 'ssl') {
            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            throw new RuntimeException('Selecciona STARTTLS o SSL como cifrado SMTP.');
        }

        $mailer->CharSet = PHPMailer::CHARSET_UTF8;
        $mailer->setFrom($settings['from_address'], $settings['from_name'] ?: 'Copropiedad');
        $mailer->addAddress($recipient, $recipientName);
        $mailer->isHTML(true);
        $mailer->Subject = $subject;
        $mailer->Body = $htmlBody;
        $mailer->AltBody = $textBody;
        $mailer->send();
    }

    /** @param array<string, mixed> $settings */
    private function sendWithConnectedAccount(array $settings, string $recipient, string $recipientName, string $subject, string $htmlBody, string $textBody): void
    {
        if (!filter_var($settings['from_address'], FILTER_VALIDATE_EMAIL) || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('La cuenta conectada o el destinatario no tienen una dirección de correo válida.');
        }
        $mailer = new PHPMailer(true);
        $mailer->CharSet = PHPMailer::CHARSET_UTF8;
        // El remitente debe ser la cuenta conectada: Gmail y Outlook no permiten enviar como otra dirección.
        $mailer->setFrom((string) $settings['from_address'], (string) ($settings['from_name'] ?: 'Copropiedad'));
        $mailer->addAddress($recipient, $recipientName);
        $mailer->isHTML(true);
        $mailer->Subject = $subject;
        $mailer->Body = $htmlBody;
        $mailer->AltBody = $textBody;
        $mailer->preSend();

        ($this->oauth ?? new MailOAuthService())->sendMime((int) $settings['oauth_connection_id'], $mailer->getSentMIMEMessage());
    }
}
