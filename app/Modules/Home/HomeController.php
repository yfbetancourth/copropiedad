<?php

declare(strict_types=1);

namespace App\Modules\Home;

use App\Core\Tenant;
use App\Core\ModuleCatalog;
use App\Core\View;
use App\Modules\Classifieds\ClassifiedsService;
use App\Modules\Configuration\PropertyGalleryService;
use App\Modules\Configuration\PublicSiteService;
use App\Modules\Configuration\SettingsService;
use App\Modules\Privacy\ContactService;
use App\Modules\Privacy\PrivacyService;

final class HomeController
{
    public function index(): void
    {
        if (!Tenant::isActive()) {
            http_response_code(503);
            echo 'Este conjunto residencial no está disponible temporalmente.';
            return;
        }

        $this->render((new PublicSiteService())->published(), false);
    }

    /**
     * Portada publicada con el formulario de contacto devuelto con errores (sin sesión: se renderiza directamente).
     *
     * @param array{errors: array<string, string>, old: array<string, string>} $contact
     */
    public function renderWithContact(array $contact): void
    {
        $this->render((new PublicSiteService())->published(), false, $contact);
    }

    /**
     * Portada con los textos dados. En vista previa (borrador desde el panel) no se indexa ni se cachea.
     *
     * @param array<string, string> $siteTexts
     * @param array{errors?: array<string, string>, old?: array<string, string>} $contact
     */
    public function render(array $siteTexts, bool $preview, array $contact = []): void
    {
        $settings = (new SettingsService())->current();
        $name = trim((string) ($settings['property_name'] ?? '')) ?: 'Copropiedad';
        $address = trim((string) ($settings['address'] ?? ''));
        $customDescription = PublicSiteService::fill(trim($siteTexts['meta_description'] ?? ''), $name);
        if ($preview) {
            header('Cache-Control: no-store');
            header('X-Robots-Tag: noindex, nofollow');
        }

        View::renderPublic('Home/index', [
            'title' => $address !== '' ? 'Comunidad en ' . $address : 'Vivir en comunidad',
            'metaDescription' => $customDescription !== '' ? $customDescription : ($address !== ''
                ? $name . ' · Vida en comunidad en ' . $address . '. Conoce nuestros espacios, ubicación y clasificados de inmuebles.'
                : $name . ' · Un espacio para vivir y construir comunidad. Conoce nuestros espacios y explora los clasificados de inmuebles.'),
            'canonicalUrl' => $this->canonical('/'),
            'settings' => $settings,
            'siteTexts' => $siteTexts,
            'sitePreview' => $preview,
            'galleryImages' => (new PropertyGalleryService())->images(),
            'listings' => ModuleCatalog::enabled('clasificados') ? (new ClassifiedsService())->publicListings(3) : [],
            'classifiedsEnabled' => ModuleCatalog::enabled('clasificados'),
            'contactForm' => $this->contactForm($contact, $preview),
        ]);
    }

    /**
     * Datos del formulario de contacto, o null si no está disponible (sin política y autorización publicadas).
     *
     * @param array{errors?: array<string, string>, old?: array<string, string>} $contact
     */
    private function contactForm(array $contact, bool $preview): ?array
    {
        $privacy = new PrivacyService();
        if (!$privacy->contactFormAvailable()) {
            return null;
        }
        if (($contact['errors'] ?? []) !== [] || ($contact['old'] ?? []) !== []) {
            header('Cache-Control: no-store');
        }

        return [
            'token' => ContactService::formToken(),
            'honeypot' => ContactService::HONEYPOT_FIELD,
            'authorizationText' => (string) $privacy->current('authorization')['content'],
            'errors' => $contact['errors'] ?? [],
            'old' => $contact['old'] ?? [],
            'sent' => !$preview && ($_GET['contacto'] ?? null) === 'enviado',
            'preview' => $preview,
        ];
    }

    private function canonical(string $path): string
    {
        $baseUrl = rtrim(Tenant::absoluteUrl('/'), '/');

        return $baseUrl . ($path === '/' ? '/' : $path);
    }
}
