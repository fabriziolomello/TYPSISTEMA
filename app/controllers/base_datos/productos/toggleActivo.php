<?php
// app/controllers/base_datos/productos/toggleActivo.php

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/seguridad.php';
require_once __DIR__ . '/../../../config/database.php';

try {
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!$data) throw new Exception('JSON inválido');

    $id     = (int)($data['id']     ?? 0);
    $activo = (int)($data['activo'] ?? 0);

    if ($id <= 0) throw new Exception('ID inválido');

    $db   = new Database();
    $conn = $db->getConnection();

    $stmt = $conn->prepare("UPDATE productos SET activo = ? WHERE id = ?");
    $stmt->bind_param('ii', $activo, $id);
    $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => true]);

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
