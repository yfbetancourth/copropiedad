<?php

declare(strict_types=1);

/**
 * Script común del panel administrativo y del portal. Ya no se cargan bootstrap.bundle.js ni adminlte.js:
 * modales, menús, pestañas, colapsables y el menú lateral usan elementos nativos atendidos por app.js.
 * Bootstrap y AdminLTE se siguen usando solo como CSS.
 */
?>
<script src="<?= $escape(\App\Core\Csp::appScriptUrl()) ?>" defer></script>
