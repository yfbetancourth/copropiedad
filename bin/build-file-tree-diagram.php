<?php

declare(strict_types=1);

/**
 * Genera docs/diagramas/arbol-archivos.html: el árbol de archivos del proyecto como diagrama (skill diagram-design,
 * tipo «Tree», perfil de marca «powertech»), calculado desde el repositorio real.
 *
 * Uso:   php bin/build-file-tree-diagram.php            escribe el diagrama si cambió
 *        php bin/build-file-tree-diagram.php --check    sale con 1 si el diagrama está desactualizado
 *
 * Lo ejecuta el hook .githooks/pre-commit antes de cada commit, así el diagrama sigue al árbol.
 *
 * Reglas (presupuesto del tipo Tree de la skill: 9 nodos, 5 por nivel, orden determinista sin fechas):
 *   - Raíz: el proyecto y su total de archivos versionados (git ls-files: incluye lo que está en el índice).
 *   - Nivel 1: carpetas versionadas por tamaño (las ocultas, de herramientas, al final); si no caben, las últimas
 *     se agrupan en un nodo. Las carpetas con
 *     archivos pero sin versionar (copias de conjuntos, vendor) van juntas en un nodo punteado «generado».
 *   - Nivel 2: las subcarpetas de la carpeta versionada más grande (máximo 3; el resto se agrupa).
 *   - Acento: la subcarpeta más grande del nivel 2 (hoy app/Modules), el corazón del código.
 */

$root = dirname(__DIR__);
$output = $root . '/docs/diagramas/arbol-archivos.html';
$checkOnly = in_array('--check', $argv, true);

$tracked = trackedFiles($root);
$model = buildModel($root, $tracked);
$html = render($model);

$current = is_file($output) ? file_get_contents($output) : null;
if ($current === $html) {
    fwrite(STDOUT, "arbol-archivos.html ya está al día.\n");
    exit(0);
}
if ($checkOnly) {
    fwrite(STDERR, "arbol-archivos.html está desactualizado: ejecuta php bin/build-file-tree-diagram.php\n");
    exit(1);
}
if (!is_dir(dirname($output)) && !mkdir(dirname($output), 0775, true) && !is_dir(dirname($output))) {
    fwrite(STDERR, "No se pudo crear docs/diagramas.\n");
    exit(1);
}
file_put_contents($output, $html);
fwrite(STDOUT, "arbol-archivos.html actualizado.\n");
exit(0);

/** @return list<string> rutas versionadas (incluye lo agregado al índice), con «/» */
function trackedFiles(string $root): array
{
    $command = 'git -C ' . escapeshellarg($root) . ' ls-files -z';
    $raw = shell_exec($command);
    if (!is_string($raw)) {
        fwrite(STDERR, "No se pudo ejecutar git ls-files.\n");
        exit(1);
    }

    return array_values(array_filter(explode("\0", $raw), static fn (string $path): bool => $path !== ''));
}

/** Archivos reales bajo una carpeta (para lo que no se versiona). */
function countFilesOnDisk(string $directory): int
{
    if (!is_dir($directory)) {
        return 0;
    }
    $count = 0;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $count++;
        }
    }

    return $count;
}

/**
 * @param list<string> $tracked
 * @return array<string, mixed>
 */
function buildModel(string $root, array $tracked): array
{
    // Carpetas de primer nivel versionadas: archivos y subcarpetas.
    $dirs = [];
    $rootFiles = 0;
    foreach ($tracked as $path) {
        $parts = explode('/', $path);
        if (count($parts) === 1) {
            $rootFiles++;
            continue;
        }
        $dirs[$parts[0]]['files'] = ($dirs[$parts[0]]['files'] ?? 0) + 1;
        if (count($parts) > 2) {
            $dirs[$parts[0]]['subdirs'][$parts[1]] = ($dirs[$parts[0]]['subdirs'][$parts[1]] ?? 0) + 1;
        }
    }
    // Primero las carpetas de la aplicación por tamaño; las ocultas (herramientas, p. ej. .agents/) al final, para
    // que no desplacen al código ni sean el padre del nivel 2.
    uksort($dirs, static fn (string $a, string $b): int
        => [$a[0] === '.', $dirs[$b]['files'], $a] <=> [$b[0] === '.', $dirs[$a]['files'], $b]);

    // Carpetas con archivos en disco pero sin versionar (generadas o de terceros).
    $generated = [];
    foreach (scandir($root) ?: [] as $entry) {
        if ($entry[0] === '.' || isset($dirs[$entry]) || !is_dir($root . '/' . $entry)) {
            continue;
        }
        $count = countFilesOnDisk($root . '/' . $entry);
        if ($count > 0) {
            $generated[$entry] = $count;
        }
    }
    ksort($generated);

    $slots = $generated === [] ? 5 : 4;
    $names = array_keys($dirs);
    $level1 = [];
    foreach ($names as $index => $name) {
        if ($index < $slots - 1 || count($names) <= $slots) {
            $level1[] = [
                'name' => $name . '/',
                'sub' => sprintf('%d archivos · %d carpetas', $dirs[$name]['files'], count($dirs[$name]['subdirs'] ?? [])),
                'kind' => 'dir',
            ];
        }
    }
    if (count($names) > $slots) {
        $rest = array_slice($names, $slots - 1);
        $level1[] = [
            'name' => groupLabel($rest),
            'sub' => sprintf('%d archivos', array_sum(array_map(static fn (string $n): int => $dirs[$n]['files'], $rest))),
            'kind' => 'dir',
        ];
    }
    if ($generated !== []) {
        $level1[] = [
            'name' => groupLabel(array_keys($generated)),
            'sub' => sprintf('%d archivos sin versionar', array_sum($generated)),
            'kind' => 'generated',
        ];
    }

    // Nivel 2: subcarpetas de la carpeta versionada más grande.
    $biggest = $names[0] ?? null;
    $level2 = [];
    if ($biggest !== null && !empty($dirs[$biggest]['subdirs'])) {
        $subdirs = $dirs[$biggest]['subdirs'];
        uksort($subdirs, static fn (string $a, string $b): int => strcmp($a, $b));
        $children = [];
        foreach (array_keys($subdirs) as $sub) {
            $nested = [];
            foreach ($tracked as $path) {
                $parts = explode('/', $path);
                if (count($parts) > 3 && $parts[0] === $biggest && $parts[1] === $sub) {
                    $nested[$parts[2]] = true;
                }
            }
            $children[] = ['name' => $sub . '/', 'files' => $subdirs[$sub], 'subdirs' => count($nested)];
        }
        if (count($children) > 3) {
            $rest = array_slice($children, 2);
            $children = array_slice($children, 0, 2);
            $children[] = [
                'name' => groupLabel(array_map(static fn (array $c): string => rtrim($c['name'], '/'), $rest)),
                'files' => array_sum(array_column($rest, 'files')),
                'subdirs' => array_sum(array_column($rest, 'subdirs')),
            ];
        }
        $maxFiles = max(array_column($children, 'files'));
        foreach ($children as $child) {
            $level2[] = [
                'name' => $child['name'],
                'sub' => sprintf('%d archivos · %d carpetas', $child['files'], $child['subdirs']),
                'kind' => $child['files'] === $maxFiles ? 'focal' : 'dir',
            ];
        }
    }

    return [
        'total' => count($tracked),
        'rootFiles' => $rootFiles,
        'level1' => $level1,
        'level2' => $level2,
        'level2Parent' => $biggest,
    ];
}

/** «bin/ · docs/» o «a/ · b/ +2» si no cabe. */
function groupLabel(array $names): string
{
    $label = '';
    foreach ($names as $index => $name) {
        $candidate = $label === '' ? $name . '/' : $label . ' · ' . $name . '/';
        if (mb_strlen($candidate) > 22) {
            return $label . ' +' . (count($names) - $index);
        }
        $label = $candidate;
    }

    return $label;
}

/** @param array<string, mixed> $model */
function render(array $model): string
{
    $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $paper = '#f8f9fa';
    $ink = '#212529';
    $muted = '#5c636a';
    $soft = '#5f6b78';
    $accent = '#0b69f6';
    $mono = "'Geist Mono', monospace";
    $sans = "'Inter', sans-serif";

    // Geometría (rejilla de 4 px): raíz a la izquierda, nivel 1 en columna, nivel 2 junto a su padre.
    $nodeH = 52;
    $gap = 20;
    $top = 72;
    $rootX = 40;
    $rootW = 180;
    $busX1 = 260;
    $l1X = 300;
    $l1W = 200;
    $busX2 = 540;
    $l2X = 580;
    $l2W = 220;
    $level1 = $model['level1'];
    $level2 = $model['level2'];
    $l1Centers = [];
    foreach ($level1 as $i => $_) {
        $l1Centers[] = $top + $i * ($nodeH + $gap) + intdiv($nodeH, 2);
    }
    $rootCenter = $l1Centers[intdiv(count($l1Centers) - 1, 2)];
    // El padre del nivel 2 es la carpeta más grande: el primer nodo del nivel 1.
    $parentCenter = $l1Centers[0];
    $l2Centers = [];
    foreach ($level2 as $i => $_) {
        $l2Centers[] = $parentCenter + $i * ($nodeH + $gap);
    }
    $contentBottom = max(end($l1Centers), $l2Centers === [] ? 0 : end($l2Centers)) + intdiv($nodeH, 2);
    $legendY = $contentBottom + 40;
    $height = $legendY + 48;
    $width = 880;

    $svg = [];
    // Conectores (antes que los nodos): raíz → bus → nivel 1.
    $svg[] = sprintf('<line x1="%d" y1="%d" x2="%d" y2="%d" stroke="%s" stroke-width="1"/>', $rootX + $rootW, $rootCenter, $busX1, $rootCenter, $muted);
    $svg[] = sprintf('<line x1="%d" y1="%d" x2="%d" y2="%d" stroke="%s" stroke-width="1"/>', $busX1, $l1Centers[0], $busX1, end($l1Centers), $muted);
    foreach ($l1Centers as $cy) {
        $svg[] = sprintf('<line x1="%d" y1="%d" x2="%d" y2="%d" stroke="%s" stroke-width="1"/>', $busX1, $cy, $l1X, $cy, $muted);
    }
    if ($level2 !== []) {
        $svg[] = sprintf('<line x1="%d" y1="%d" x2="%d" y2="%d" stroke="%s" stroke-width="1"/>', $l1X + $l1W, $parentCenter, $busX2, $parentCenter, $muted);
        if (count($l2Centers) > 1) {
            $svg[] = sprintf('<line x1="%d" y1="%d" x2="%d" y2="%d" stroke="%s" stroke-width="1"/>', $busX2, $l2Centers[0], $busX2, end($l2Centers), $muted);
        }
        foreach ($l2Centers as $cy) {
            $svg[] = sprintf('<line x1="%d" y1="%d" x2="%d" y2="%d" stroke="%s" stroke-width="1"/>', $busX2, $cy, $l2X, $cy, $muted);
        }
    }

    $node = static function (int $x, int $cy, int $w, string $name, string $sub, string $kind, string $tag) use ($e, $paper, $ink, $muted, $soft, $accent, $mono, $sans, $nodeH): string {
        $y = $cy - intdiv($nodeH, 2);
        [$fill, $stroke, $dash, $tagStroke, $tagText] = match ($kind) {
            'focal' => ['rgba(11,105,246,0.08)', $accent, '', 'rgba(11,105,246,0.50)', $accent],
            'root' => ['#ffffff', $ink, '', 'rgba(33,37,41,0.40)', $ink],
            'generated' => ['rgba(33,37,41,0.02)', 'rgba(33,37,41,0.20)', ' stroke-dasharray="4,3"', 'rgba(33,37,41,0.20)', $soft],
            default => ['rgba(33,37,41,0.05)', $muted, '', 'rgba(92,99,106,0.50)', $muted],
        };
        $tagW = max(24, (int) (ceil((mb_strlen($tag) * 4.9 + 8) / 4) * 4));

        return implode("\n        ", [
            sprintf('<rect x="%d" y="%d" width="%d" height="%d" rx="6" fill="%s"/>', $x, $y, $w, $nodeH, $paper),
            sprintf('<rect x="%d" y="%d" width="%d" height="%d" rx="6" fill="%s" stroke="%s" stroke-width="1"%s/>', $x, $y, $w, $nodeH, $fill, $stroke, $dash),
            sprintf('<rect x="%d" y="%d" width="%d" height="12" rx="2" fill="transparent" stroke="%s" stroke-width="0.8"/>', $x + 8, $y + 6, $tagW, $tagStroke),
            sprintf('<text x="%d" y="%d" fill="%s" font-size="7" font-family="%s" text-anchor="middle" letter-spacing="0.08em">%s</text>', $x + 8 + intdiv($tagW, 2), $y + 15, $tagText, $mono, $e($tag)),
            sprintf('<text x="%d" y="%d" fill="%s" font-size="12" font-weight="600" font-family="%s" text-anchor="middle">%s</text>', $x + intdiv($w, 2), $cy + 4, $ink, $sans, $e($name)),
            sprintf('<text x="%d" y="%d" fill="%s" font-size="9" font-family="%s" text-anchor="middle">%s</text>', $x + intdiv($w, 2), $cy + 18, $muted, $mono, $e($sub)),
        ]);
    };

    $svg[] = $node($rootX, $rootCenter, $rootW, 'Copropiedad/', sprintf('%d archivos versionados', $model['total']), 'root', 'RAÍZ');
    foreach ($level1 as $i => $item) {
        $svg[] = $node($l1X, $l1Centers[$i], $l1W, $item['name'], $item['sub'], $item['kind'], $item['kind'] === 'generated' ? 'GEN' : 'DIR');
    }
    foreach ($level2 as $i => $item) {
        $svg[] = $node($l2X, $l2Centers[$i], $l2W, $item['name'], $item['sub'], $item['kind'], 'DIR');
    }

    // Leyenda.
    $svg[] = sprintf('<line x1="40" y1="%d" x2="%d" y2="%d" stroke="rgba(33,37,41,0.10)" stroke-width="0.8"/>', $legendY - 8, $width - 40, $legendY - 8);
    $svg[] = sprintf('<text x="40" y="%d" fill="%s" font-size="8" font-family="%s" letter-spacing="0.18em">LEYENDA</text>', $legendY + 8, $muted, $mono);
    $items = [
        ['rgba(11,105,246,0.08)', $accent, '', 'Carpeta con más código'],
        ['rgba(33,37,41,0.05)', $muted, '', 'Carpeta versionada'],
        ['rgba(33,37,41,0.02)', 'rgba(33,37,41,0.20)', ' stroke-dasharray="4,3"', 'Generada, sin versionar'],
    ];
    $x = 40;
    foreach ($items as [$fill, $stroke, $dash, $label]) {
        $svg[] = sprintf('<rect x="%d" y="%d" width="14" height="10" rx="2" fill="%s" stroke="%s" stroke-width="1"%s/>', $x, $legendY + 20, $fill, $stroke, $dash);
        $svg[] = sprintf('<text x="%d" y="%d" fill="%s" font-size="9" font-family="%s">%s</text>', $x + 20, $legendY + 28, $muted, $sans, $e($label));
        $x += 200;
    }
    $svg[] = sprintf('<text x="%d" y="%d" fill="%s" font-size="9" font-style="italic" font-family="%s">Se regenera en cada commit.</text>', $x, $legendY + 28, $muted, $sans);

    $desc = sprintf(
        'Árbol de archivos de Copropiedad con %d archivos versionados: %s; dentro de %s están %s.',
        $model['total'],
        implode(', ', array_map(static fn (array $i): string => $i['name'] . ' (' . $i['sub'] . ')', $level1)),
        ($model['level2Parent'] ?? '') . '/',
        implode(', ', array_map(static fn (array $i): string => $i['name'], $level2))
    );
    $body = implode("\n        ", $svg);
    $title = 'Árbol de archivos de Copropiedad';

    return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{$e($title)}</title>
  <link href="https://fonts.googleapis.com/css2?family=Instrument+Serif:ital@0;1&family=Inter:wght@400;500;600&family=Geist+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --color-paper:   {$paper};
      --color-ink:     {$ink};
      --color-muted:   {$muted};
      --color-accent:  {$accent};
      --font-sans:     'Inter', system-ui, sans-serif;
      --font-serif:    'Instrument Serif', 'Noto Serif', serif;
      --font-mono:     'Geist Mono', ui-monospace, monospace;
    }
    body {
      font-family: var(--font-sans);
      background: var(--color-paper);
      color: var(--color-ink);
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 3rem 2rem;
    }
    .frame { max-width: 920px; width: 100%; min-width: 0; }
    .diagram-container { width: 100%; overflow-x: auto; }
    .eyebrow {
      font-family: var(--font-mono);
      font-size: 0.66rem;
      font-weight: 500;
      letter-spacing: 0.18em;
      text-transform: uppercase;
      color: var(--color-muted);
      margin-bottom: 0.5rem;
    }
    h1 {
      font-family: var(--font-serif);
      font-size: clamp(1.5rem, 2.4vw + 0.75rem, 2rem);
      font-weight: 400;
      letter-spacing: -0.02em;
      line-height: 1.15;
      color: var(--color-ink);
      margin-bottom: 1.5rem;
    }
    svg { width: 100%; min-width: {$width}px; display: block; }
    @media print {
      .diagram-container { overflow-x: visible; }
      svg { min-width: 0; }
    }
  </style>
</head>
<body>
  <div class="frame">
    <p class="eyebrow">Árbol de archivos · Copropiedad</p>
    <h1>{$e($title)}</h1>

    <div class="diagram-container">
      <svg viewBox="0 0 {$width} {$height}" xmlns="http://www.w3.org/2000/svg" role="img" aria-labelledby="arbol-archivos-title arbol-archivos-desc">
        <title id="arbol-archivos-title">{$e($title)}</title>
        <desc id="arbol-archivos-desc">{$e($desc)}</desc>
        <rect width="100%" height="100%" fill="{$paper}"/>
        {$body}
      </svg>
    </div>
  </div>
</body>
</html>

HTML;
}
