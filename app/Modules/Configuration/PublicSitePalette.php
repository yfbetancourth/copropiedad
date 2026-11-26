<?php

declare(strict_types=1);

namespace App\Modules\Configuration;

/**
 * Paletas de color del sitio público (Configuración / Sitio público). Fuente única: el sitio público escribe los
 * tokens de la paleta elegida y el selector del panel dibuja sus muestras con los mismos valores.
 *
 * Contrastes verificados (WCAG AA) en cada paleta: texto ≥ 7:1, texto secundario y atenuado ≥ 4.5:1 sobre el fondo
 * y sobre las franjas, enlaces y énfasis ≥ 4.5:1, texto blanco sobre botones y sellos ≥ 4.5:1, foco ≥ 3:1.
 * Una paleta nueva debe pasar las mismas verificaciones antes de agregarse aquí.
 */
final class PublicSitePalette
{
    /** La identidad original del sitio: los conjuntos que no eligen siguen viéndose igual. */
    public const DEFAULT = 'bosque';

    /**
     * Cada color es un token de public.css. accent-text es el acento cuando se usa como texto (≥ 4.5:1);
     * accent queda para trazos, puntos y el anillo de foco.
     *
     * @var array<string, array{label: string, description: string, colors: array<string, string>}>
     */
    public const PALETTES = [
        'bosque' => [
            'label' => 'Bosque',
            'description' => 'Verde musgo con toques terracota. Cálida y natural.',
            'colors' => [
                'paper' => '#F6F4EE', 'paper-soft' => '#FBFAF6', 'paper-deep' => '#ECEADF',
                'ink' => '#1F2721', 'ink-soft' => '#3C463E', 'muted' => '#5A6359',
                'brand' => '#46604C', 'brand-deep' => '#2F4637', 'brand-hover' => '#233629', 'brand-tint' => '#E4EADF',
                'accent' => '#C2703F', 'accent-text' => '#9A5226', 'accent-tint' => '#F6E5D8', 'accent-ink' => '#8A4A23',
            ],
        ],
        'fresca' => [
            'label' => 'Fresca',
            'description' => 'Cian calmado con verde salud, como el panel. Suave, aireada y muy legible.',
            'colors' => [
                'paper' => '#F4FAFB', 'paper-soft' => '#FAFDFE', 'paper-deep' => '#E0F1F5',
                'ink' => '#17414E', 'ink-soft' => '#2C4A55', 'muted' => '#4A6470',
                'brand' => '#0E7490', 'brand-deep' => '#155E75', 'brand-hover' => '#164E63', 'brand-tint' => '#DDF2F7',
                'accent' => '#059669', 'accent-text' => '#047857', 'accent-tint' => '#DDF6EC', 'accent-ink' => '#065F46',
            ],
        ],
        'confianza' => [
            'label' => 'Confianza',
            'description' => 'Verde azulado y azul profesional. Sobria y clara.',
            'colors' => [
                'paper' => '#F3F8F7', 'paper-soft' => '#FAFDFC', 'paper-deep' => '#E2EEEB',
                'ink' => '#0D3B38', 'ink-soft' => '#24504C', 'muted' => '#4A6461',
                'brand' => '#0F766E', 'brand-deep' => '#115E59', 'brand-hover' => '#0D4A46', 'brand-tint' => '#D5F0EB',
                'accent' => '#0369A1', 'accent-text' => '#0369A1', 'accent-tint' => '#E0F0FA', 'accent-ink' => '#075985',
            ],
        ],
        'mar' => [
            'label' => 'Mar',
            'description' => 'Azul profundo con acento coral. Fresca y serena.',
            'colors' => [
                'paper' => '#F4F7FB', 'paper-soft' => '#FAFCFE', 'paper-deep' => '#E3EBF4',
                'ink' => '#172338', 'ink-soft' => '#2E3C55', 'muted' => '#55627A',
                'brand' => '#2E5C8A', 'brand-deep' => '#1E3F66', 'brand-hover' => '#162F4D', 'brand-tint' => '#DCE7F3',
                'accent' => '#C2553A', 'accent-text' => '#A8432A', 'accent-tint' => '#F8E3DC', 'accent-ink' => '#8C3620',
            ],
        ],
        'terracota' => [
            'label' => 'Terracota',
            'description' => 'Barro cocido con acento oliva. Acogedora, de tierra caliente.',
            'colors' => [
                'paper' => '#F8F3EE', 'paper-soft' => '#FCF9F6', 'paper-deep' => '#EFE4DA',
                'ink' => '#2B1F1A', 'ink-soft' => '#4A3A31', 'muted' => '#6B5A50',
                'brand' => '#9A4B2E', 'brand-deep' => '#7A3A22', 'brand-hover' => '#5E2C19', 'brand-tint' => '#F3E0D6',
                'accent' => '#4F6B4A', 'accent-text' => '#4F6B4A', 'accent-tint' => '#E1EADC', 'accent-ink' => '#3D5539',
            ],
        ],
        'pizarra' => [
            'label' => 'Pizarra',
            'description' => 'Gris azulado con acento dorado. Elegante y corporativa.',
            'colors' => [
                'paper' => '#F4F5F7', 'paper-soft' => '#FAFBFC', 'paper-deep' => '#E5E8ED',
                'ink' => '#1C2128', 'ink-soft' => '#343B45', 'muted' => '#5A626E',
                'brand' => '#3E4C5E', 'brand-deep' => '#2A3442', 'brand-hover' => '#1E2631', 'brand-tint' => '#E1E6ED',
                'accent' => '#A87412', 'accent-text' => '#8A5F0E', 'accent-tint' => '#F5EBD7', 'accent-ink' => '#6E4B0B',
            ],
        ],
        'ciruela' => [
            'label' => 'Ciruela',
            'description' => 'Morado ciruela con acento cobre. Moderna y distinguida.',
            'colors' => [
                'paper' => '#F6F4F8', 'paper-soft' => '#FBFAFC', 'paper-deep' => '#EAE5EF',
                'ink' => '#241E2B', 'ink-soft' => '#3E3547', 'muted' => '#625A6B',
                'brand' => '#6B4C8A', 'brand-deep' => '#523870', 'brand-hover' => '#3E2955', 'brand-tint' => '#E9E0F2',
                'accent' => '#C2703F', 'accent-text' => '#9A5226', 'accent-tint' => '#F6E5D8', 'accent-ink' => '#8A4A23',
            ],
        ],
    ];

    public static function exists(string $key): bool
    {
        return isset(self::PALETTES[$key]);
    }

    /** Clave válida o la paleta por defecto (p. ej. si se retiró una paleta que algún conjunto tenía guardada). */
    public static function resolve(?string $key): string
    {
        return $key !== null && self::exists($key) ? $key : self::DEFAULT;
    }

    /** Declaraciones CSS de la paleta: "--paper: #F6F4EE; …" (valores del catálogo, nunca datos del usuario). */
    public static function declarations(string $key): string
    {
        $declarations = [];
        foreach (self::PALETTES[self::resolve($key)]['colors'] as $token => $value) {
            $declarations[] = '--' . $token . ': ' . $value . ';';
        }

        return implode(' ', $declarations);
    }

    /** Color de la barra del navegador en el celular (meta theme-color). */
    public static function themeColor(string $key): string
    {
        return self::PALETTES[self::resolve($key)]['colors']['paper'];
    }
}
