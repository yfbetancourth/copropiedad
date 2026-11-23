<?php

declare(strict_types=1);

namespace App\Modules\Billing;

use App\Core\Authorization;
use App\Core\Http;
use RuntimeException;

/** Avisos de las pasarelas (Bold y Wompi) y regreso del comprador después de pagar. */
final class PaymentWebhookController
{
    private const MAX_BODY_BYTES = 65536;

    public function receive(): void
    {
        [$rawBody, $event] = $this->readJsonBody();
        if ($event === null) {
            return;
        }

        try {
            $terminalReference = $event['data']['metadata']['reference'] ?? null;
            if (is_string($terminalReference) && str_starts_with($terminalReference, 'CPT-')) {
                $signature = $_SERVER['HTTP_X_BOLD_SIGNATURE'] ?? '';
                if (!is_string($signature)
                    || !(new BoldTerminalService())->verifyWebhookSignature($rawBody, $signature)
                ) {
                    http_response_code(400);
                    echo 'Invalid signature';
                    return;
                }
                (new BoldTerminalService())->handleWebhook($event);
            } else {
                (new BoldPaymentService())->handleWebhook($event);
            }
            http_response_code(200);
            echo 'OK';
        } catch (RuntimeException $exception) {
            error_log('No se pudo conciliar el evento de Bold: ' . $exception->getMessage());
            http_response_code(503);
        }
    }

    /**
     * Eventos de Wompi. Responde 200 solo si el evento se procesó; ante una firma inválida o una falla responde
     * otro código para que Wompi lo reintente (hasta 3 veces en 24 horas) mientras se corrige la configuración.
     */
    public function receiveWompi(): void
    {
        [, $event] = $this->readJsonBody();
        if ($event === null) {
            return;
        }

        try {
            $checksum = $_SERVER['HTTP_X_EVENT_CHECKSUM'] ?? '';
            (new WompiPaymentService())->handleEvent($event, is_string($checksum) ? $checksum : '');
            http_response_code(200);
            echo 'OK';
        } catch (RuntimeException $exception) {
            error_log('No se pudo conciliar el evento de Wompi: ' . $exception->getMessage());
            http_response_code(str_contains($exception->getMessage(), 'firma') ? 401 : 503);
        }
    }

    public function returnFromBold(): void
    {
        $reference = $_GET['reference'] ?? null;
        if (!is_string($reference) || !preg_match('/^CP-[A-F0-9]{32}$/D', $reference)) {
            http_response_code(400);
            echo 'La referencia de pago no es válida.';
            return;
        }

        try {
            $status = (new BoldPaymentService())->refreshPayment($reference);
        } catch (RuntimeException $exception) {
            error_log('No se pudo verificar el retorno de Bold: ' . $exception->getMessage());
            $status = 'pending';
        }
        $this->redirectWithResult($status);
    }

    /** Wompi regresa al comprador con ?id=<transacción>; el estado se consulta a Wompi, nunca se toma de la URL. */
    public function returnFromWompi(): void
    {
        try {
            $status = (new WompiPaymentService())->confirmReturn(Http::string($_GET['id'] ?? null));
        } catch (RuntimeException $exception) {
            error_log('No se pudo verificar el retorno de Wompi: ' . $exception->getMessage());
            $status = 'pending';
        }
        $this->redirectWithResult($status);
    }

    /** Quien pagó con un enlace temporal puede no tener sesión: ve el resultado en una página pública. */
    private function redirectWithResult(string $status): never
    {
        Http::redirect(Authorization::currentUser() === null
            ? '/pagar/resultado?estado=' . rawurlencode($status)
            : '/usuario/pagos?pago=' . rawurlencode($status));
    }

    /**
     * Cuerpo JSON del aviso, con límites de tamaño. Si no es válido responde el error y devuelve [.., null].
     *
     * @return array{0: string, 1: array<string, mixed>|null}
     */
    private function readJsonBody(): array
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            header('Allow: POST');
            return ['', null];
        }
        $contentLength = filter_var($_SERVER['CONTENT_LENGTH'] ?? null, FILTER_VALIDATE_INT);
        if (is_int($contentLength) && $contentLength > self::MAX_BODY_BYTES) {
            http_response_code(413);
            return ['', null];
        }
        $rawBody = file_get_contents('php://input');
        if (!is_string($rawBody) || $rawBody === '' || strlen($rawBody) > self::MAX_BODY_BYTES) {
            http_response_code(400);
            return ['', null];
        }
        try {
            $event = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            http_response_code(400);
            return ['', null];
        }
        if (!is_array($event)) {
            http_response_code(400);
            return ['', null];
        }

        return [$rawBody, $event];
    }
}
