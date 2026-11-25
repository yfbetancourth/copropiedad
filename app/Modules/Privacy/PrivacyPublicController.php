<?php

declare(strict_types=1);

namespace App\Modules\Privacy;

use App\Core\Http;
use App\Core\Tenant;
use App\Core\View;
use App\Modules\Home\HomeController;
use PDOException;
use RuntimeException;

/** Página pública: políticas publicadas y envío del formulario de contacto con su autorización. */
final class PrivacyPublicController
{
    public function __construct(private readonly PrivacyService $privacy = new PrivacyService())
    {
    }

    public function dataPolicy(): void
    {
        $this->document('data_policy', 'Política de tratamiento de datos personales');
    }

    public function authorization(): void
    {
        $this->document('authorization', 'Autorización para el tratamiento de datos');
    }

    public function cookiePolicy(): void
    {
        $this->document('cookie_policy', 'Política de cookies');
    }

    public function submitContact(): void
    {
        if (!Tenant::isActive()) {
            http_response_code(503);
            echo 'Este conjunto residencial no está disponible temporalmente.';
            return;
        }

        $input = [
            'name' => Http::string($_POST['name'] ?? null),
            'email' => Http::string($_POST['email'] ?? null),
            'phone' => Http::string($_POST['phone'] ?? null),
            'message' => Http::string($_POST['message'] ?? null),
            'authorization' => Http::string($_POST['authorization'] ?? null),
            'token' => Http::string($_POST['token'] ?? null),
            ContactService::HONEYPOT_FIELD => Http::string($_POST[ContactService::HONEYPOT_FIELD] ?? null),
        ];
        try {
            $result = (new ContactService($this->privacy))->submit(
                $input,
                (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
                (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
            );
        } catch (PDOException $exception) {
            // Un error de base de datos nunca se muestra al público (PDOException también es RuntimeException).
            throw $exception;
        } catch (RuntimeException $exception) {
            $result = ['errors' => ['form' => $exception->getMessage()], 'id' => null];
        }

        if ($result['errors'] !== []) {
            http_response_code(422);
            (new HomeController())->renderWithContact(['errors' => $result['errors'], 'old' => $input]);
            return;
        }

        // Post/Redirect/Get: recargar la página no reenvía el mensaje.
        header('Location: ' . \App\Core\Url::to('/?contacto=enviado') . '#contacto', true, 303);
    }

    private function document(string $document, string $title): void
    {
        $version = Tenant::isActive() ? $this->privacy->current($document) : null;
        if ($version === null) {
            http_response_code(404);
        }

        View::renderPublic('Privacy/document', [
            'title' => $title,
            'metaDescription' => $title . ' del sitio web.',
            'canonicalUrl' => rtrim(Tenant::absoluteUrl('/'), '/') . PrivacyService::DOCUMENTS[$document]['public_path'],
            'documentKey' => $document,
            'version' => $version,
            'blocks' => $version === null ? [] : PrivacyService::blocks($version['content']),
        ]);
    }
}
