<?php
$titulo = "Movimientos de caja";
require_once __DIR__ . '/../../config/seguridad.php';

ob_start();
?>

<h1>Movimiento de caja</h1>

<?php
$contenido = ob_get_clean();
require __DIR__ . '/../layouts/main.php';