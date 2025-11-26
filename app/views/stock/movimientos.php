<?php
$titulo = "Ingreso / Egreso de stock";
require_once __DIR__ . '/../../config/seguridad.php';

ob_start();
?>

<h1>Movimientos de stock</h1>

<?php
$contenido = ob_get_clean();
require __DIR__ . '/../layouts/main.php';