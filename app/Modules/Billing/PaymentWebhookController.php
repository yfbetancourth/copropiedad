<?php

declare(strict_types=1);

namespace App\Modules\Billing;

use RuntimeException;

final class PaymentWebhookController
{
    public function receive(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            header('Allow: POST');
            return;
        }

        $contentLength = filter_var($_SERVER['CONTENT_LENGTH'] ?? null, FILTER_VALIDATE_INT);
        if (is_int($contentLength) && $contentLength > 65536) {
            http_response_code(413);
            return;
        }

        $rawBody = file_get_contents('php://input');
        if (!is_string($rawBody) || $rawBody === '' || strlen($rawBody) > 65536) {
            http_response_code(400);
            return;
        }

        try {
            $event = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            http_response_code(400);
            return;
        }
        if (!is_array($event)) {
            http_response_code(400);
            return;
        }

        try {
            (new BoldPaymentService())->handleWebhook($event);
            http_response_code(200);
            echo 'OK';
        } catch (RuntimeException $exception) {
            error_log('No se pudo conciliar el evento de Bold: ' . $exception->getMessage());
            http_response_code(503);
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

        header('Location: ' . \App\Core\Url::to('/usuario?pago=' . rawurlencode($status) . '#documentos'), true, 303);
    }
}
