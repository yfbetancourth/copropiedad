<?php

declare(strict_types=1);

/**
 * Genera public/assets/css/icons.css: solo los iconos de Bootstrap Icons que usa la aplicación, como máscaras CSS
 * (reemplaza la fuente completa de 2.078 iconos). El marcado no cambia: <i class="bi bi-house" aria-hidden="true">.
 *
 * Al usar un icono nuevo, vuelva a generarlo con la carpeta icons/ del paquete:
 *   npm pack bootstrap-icons@1.13.2 && tar -xzf bootstrap-icons-1.13.2.tgz
 *   php bin/build-icons.php package/icons
 */

$root = dirname(__DIR__);
$source = $argv[1] ?? '';
if ($source === '' || !is_dir($source)) {
    fwrite(STDERR, "Uso: php bin/build-icons.php <carpeta icons/ de bootstrap-icons>\n");
    exit(1);
}

// Nombres bi-* que aparecen en plantillas, controladores y scripts.
$used = [];
foreach ([$root . '/app', $root . '/public/assets/js'] as $directory) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (!in_array($file->getExtension(), ['php', 'js'], true)) {
            continue;
        }
        preg_match_all('/\bbi-([a-z0-9]+(?:-[a-z0-9]+)*)/', (string) file_get_contents($file->getPathname()), $matches);
        foreach ($matches[1] as $name) {
            $used[$name] = true;
        }
    }
}
ksort($used);

$css = "/* Bootstrap Icons 1.13.2 (MIT, https://icons.getbootstrap.com) — solo los iconos usados, como máscaras CSS.\n"
    . "   Archivo generado por bin/build-icons.php: no editar a mano. */\n"
    . ".bi::before {\n"
    . "    content: \"\";\n"
    . "    display: inline-block;\n"
    . "    width: 1em;\n"
    . "    height: 1em;\n"
    . "    vertical-align: -0.125em;\n"
    . "    background-color: currentColor;\n"
    . "    -webkit-mask: var(--bi) no-repeat center / contain;\n"
    . "    mask: var(--bi) no-repeat center / contain;\n"
    . "    flex: none;\n"
    . "    pointer-events: none;\n"
    . "    /* En modo de alto contraste el navegador reemplaza background-color: sin esto el icono desaparece. */\n"
    . "    forced-color-adjust: none;\n"
    . "}\n";
$found = 0;
$missing = [];
foreach (array_keys($used) as $name) {
    $file = rtrim($source, '/\\') . '/' . $name . '.svg';
    if (!is_file($file)) {
        $missing[] = $name; // Prefijos armados en código (p. ej. «bi-» . $x) o clases que no son iconos.
        continue;
    }
    $svg = (string) file_get_contents($file);
    // Solo la geometría: sin width/height/class/fill (la máscara toma la forma y currentColor el color).
    $svg = preg_replace('/\s(width|height|class|fill)="[^"]*"/', '', $svg);
    $svg = preg_replace('/>\s+</', '><', trim($svg));
    $data = rawurlencode($svg);
    // Codificación mínima legible y válida dentro de url("…").
    $data = strtr($data, ['%20' => ' ', '%3D' => '=', '%3A' => ':', '%2F' => '/', '%2C' => ',', '%3B' => ';', '%27' => "'"]);
    $css .= '.bi-' . $name . '{--bi:url("data:image/svg+xml,' . $data . '")}' . "\n";
    $found++;
}

file_put_contents($root . '/public/assets/css/icons.css', $css);
printf("icons.css: %d iconos, %s KB.\n", $found, number_format(strlen($css) / 1024, 1));
if ($missing !== []) {
    printf("Sin archivo SVG (revisar si son iconos armados dinámicamente): %s\n", implode(', ', $missing));
}
