<?php
// Obligamos a que haya sesión iniciada
require_once __DIR__ . '/../../config/seguridad.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($titulo ?? 'Sistema TyP') ?></title>

    <!-- CSS general -->
    <link rel="stylesheet" href="/TYPSISTEMA/public/css/reset.css">
    <link rel="stylesheet" href="/TYPSISTEMA/public/css/layout.css">

    <!-- CSS específico de cada vista -->
    <?= $css_extra ?? '' ?>
</head>
<body>

    <!-- HEADER -->
    <?php require __DIR__ . '/../partials/header.php'; ?>

    <div class="app-container">

        <!-- MENÚ HAMBURGUESA -->
        <?php require __DIR__ . '/../partials/menu.php'; ?>

        <!-- CONTENIDO PRINCIPAL -->
        <main class="app-main">
            <?= $contenido ?? '' ?>
        </main>
    </div>

    <!-- FOOTER -->
    <?php require __DIR__ . '/../partials/footer.php'; ?>

    <!-- JS menú -->
    <script defer src="/TYPSISTEMA/public/js/menu.js"></script>

    <!-- JS específico de cada vista -->
    <?= $js_extra ?? '' ?>

</body>
</html>