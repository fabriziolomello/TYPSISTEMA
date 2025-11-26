<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../config/seguridad.php';
require_once __DIR__ . '/../../config/database.php';

$db   = new Database();
$conn = $db->getConnection();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$returnUrl = $_GET['return'] ?? '/TYPSISTEMA/app/views/dashboard/index.php';

if ($id <= 0) {
    header("Location: {$returnUrl}");
    exit;
}

$stmt = $conn->prepare("SELECT tipo_venta FROM ventas WHERE id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $stmt->close();
    header("Location: {$returnUrl}");
    exit;
}

$row = $result->fetch_assoc();
$stmt->close();

$tipoActual = $row['tipo_venta'];

if ($tipoActual === 'MINORISTA') {
    $nuevoTipo = 'MAYORISTA';
} elseif ($tipoActual === 'MAYORISTA') {
    $nuevoTipo = 'MINORISTA';
} else {
    header("Location: {$returnUrl}");
    exit;
}

$stmt = $conn->prepare("UPDATE ventas SET tipo_venta = ? WHERE id = ?");
$stmt->bind_param('si', $nuevoTipo, $id);
$stmt->execute();
$stmt->close();

header("Location: {$returnUrl}");
exit;