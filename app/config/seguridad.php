<?php
// app/config/seguridad.php
session_start();

// ¿Está logueado?
$logueado = isset($_SESSION['usuario_id']);

// Si NO está logueado…
if (!$logueado) {

    // Detectar si la petición es AJAX/fetch
    $esAjax = (
        !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
    ) || strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;

    if ($esAjax) {
        // Respuesta adecuada para fetch()
        echo json_encode([
            "success" => false,
            "error" => "No hay sesión activa"
        ]);
        exit;
    }

    // Si NO es AJAX, redirigir al login
    header('Location: ../../views/auth/login.php');
    exit;
}