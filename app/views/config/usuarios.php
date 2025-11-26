<?php
$titulo = "Usuarios y permisos";
require_once __DIR__ . '/../../config/seguridad.php';

ob_start();
?>

<h1>Usuarios y permisos</h1>

<?php
$contenido = ob_get_clean();
require __DIR__ . '/../layouts/main.php';