<?php

declare(strict_types=1);

/** Scripts comunes del panel administrativo y del portal (AdminLTE + Bootstrap), servidos localmente. */
$vendor = $escape(\App\Core\Url::publicBasePath()) . '/assets/vendor';
?>
<script src="<?= $vendor ?>/bootstrap@5.3.9/js/bootstrap.bundle.min.js"></script>
<script src="<?= $vendor ?>/admin-lte@4.10.2/js/adminlte.min.js"></script>
