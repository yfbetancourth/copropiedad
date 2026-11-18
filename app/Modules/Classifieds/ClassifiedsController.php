<?php

declare(strict_types=1);

namespace App\Modules\Classifieds;

use App\Core\Authorization;
use App\Core\Session;
use App\Core\Url;
use App\Core\View;
use PDOException;
use RuntimeException;

final class ClassifiedsController
{
    public function __construct(private readonly ClassifiedsService $service = new ClassifiedsService())
    {
    }

    public function index(): void
    {
        View::renderPublic('Classifieds/index', [
            'title' => 'Clasificados de inmuebles',
            'listings' => $this->service->publicListings(),
        ]);
    }

    public function save(): void
    {
        $user = Authorization::requirePermission('usuario.portal', 'read');
        if ($user === null) {
            return;
        }
        $this->requireCsrf();

        try {
            $this->service->createListing((int) $user['id'], $_POST, $_FILES['images'] ?? []);
            $this->flash('success', 'Tu clasificado se publicó en la página pública.');
        } catch (PDOException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            $this->flash('error', $exception->getMessage());
        }

        $this->redirect('/usuario#clasificados');
    }

    public function changeStatus(): void
    {
        $user = Authorization::requirePermission('usuario.portal', 'read');
        if ($user === null) {
            return;
        }
        $this->requireCsrf();

        $listingId = filter_var($_POST['listing_id'] ?? null, FILTER_VALIDATE_INT);
        $status = is_string($_POST['status'] ?? null) ? $_POST['status'] : '';
        if (!is_int($listingId) || $listingId < 1 || !in_array($status, ['published', 'closed'], true)) {
            $this->flash('error', 'El clasificado seleccionado no es válido.');
            $this->redirect('/usuario#clasificados');
        }

        if (!$this->service->setStatus($listingId, (int) $user['id'], $status)) {
            $this->flash('error', 'No se encontró un clasificado tuyo para actualizar.');
        } else {
            $this->flash('success', $status === 'published'
                ? 'El clasificado volvió a publicarse.'
                : 'El clasificado se cerró y ya no aparece al público.');
        }

        $this->redirect('/usuario#clasificados');
    }

    public function delete(): void
    {
        $user = Authorization::requirePermission('usuario.portal', 'read');
        if ($user === null) {
            return;
        }
        $this->requireCsrf();

        $listingId = filter_var($_POST['listing_id'] ?? null, FILTER_VALIDATE_INT);
        if (!is_int($listingId) || $listingId < 1) {
            $this->flash('error', 'El clasificado seleccionado no es válido.');
            $this->redirect('/usuario#clasificados');
        }

        if (!$this->service->deleteListing($listingId, (int) $user['id'])) {
            $this->flash('error', 'No se encontró un clasificado tuyo para eliminar.');
        } else {
            $this->flash('success', 'El clasificado y sus imágenes se eliminaron.');
        }

        $this->redirect('/usuario#clasificados');
    }

    public function image(): void
    {
        $imageId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
        if (!is_int($imageId) || $imageId < 1) {
            $this->notFound();
        }

        $image = $this->service->imageForDisplay($imageId);
        if ($image === null) {
            $this->notFound();
        }

        if ($image['status'] !== 'published') {
            $user = Authorization::currentUser();
            if ($user === null || (int) $user['id'] !== (int) $image['user_id']) {
                $this->notFound();
            }
        }

        if (!is_file($image['path']) || !is_readable($image['path'])) {
            error_log('Una imagen registrada de clasificado no está disponible en el almacenamiento.');
            $this->notFound();
        }

        header('Content-Type: ' . $image['mime_type']);
        header('Content-Length: ' . (int) $image['byte_size']);
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: inline');
        header($image['status'] === 'published'
            ? 'Cache-Control: public, max-age=900'
            : 'Cache-Control: no-store, private');
        readfile($image['path']);
    }

    private function requireCsrf(): void
    {
        if (!Session::verifyCsrf($_POST['_token'] ?? null)) {
            http_response_code(419);
            echo 'La sesion del formulario vencio. Recarga la pagina e intenta nuevamente.';
            exit;
        }
    }

    private function flash(string $type, string $message): void
    {
        Session::start();
        $_SESSION['user_profile_flash'] = ['type' => $type, 'message' => $message];
    }

    private function redirect(string $path): never
    {
        header('Location: ' . Url::to($path), true, 303);
        exit;
    }

    private function notFound(): never
    {
        http_response_code(404);
        echo 'Imagen no encontrada.';
        exit;
    }
}
