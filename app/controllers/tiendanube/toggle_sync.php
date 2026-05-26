<?php
// app/controllers/tiendanube/toggle_sync.php
// Activa o desactiva la sincronización TN para un producto

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/seguridad.php';
require_once __DIR__ . '/../../config/database.php';

$_esAdmin = ($_SESSION['usuario_rol'] ?? '') === 'ADMIN';
$_dep     = (int)($_SESSION['usuario_deposito'] ?? 0);
if (!$_esAdmin && $_dep !== 1) {
    echo json_encode(['success' => false, 'error' => 'Sin permisos']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$id   = (int)($data['id'] ?? 0);
$sync = isset($data['sincronizar_tn']) ? (int)(bool)$data['sincronizar_tn'] : null;

if (!$id || $sync === null) {
    echo json_encode(['success' => false, 'error' => 'Parámetros inválidos']);
    exit;
}

try {
    $db   = new Database();
    $conn = $db->getConnection();

    $stmt = $conn->prepare("UPDATE productos SET sincronizar_tn = ? WHERE id = ?");
    $stmt->bind_param('ii', $sync, $id);
    $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => true, 'id' => $id, 'sincronizar_tn' => $sync]);

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
