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
     * Portada con los textos dados. En vista previa (borrador desde el panel) no se indexa ni se cachea.
     *
     * @param array<string, string> $siteTexts
     */
    public function render(array $siteTexts, bool $preview): void
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
        ]);
    }

    private function canonical(string $path): string
    {
        $baseUrl = rtrim(Tenant::absoluteUrl('/'), '/');

        return $baseUrl . ($path === '/' ? '/' : $path);
    }
}
