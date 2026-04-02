<?php
// app/controllers/base_datos/proveedores/guardar.php

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/seguridad.php';
require_once __DIR__ . '/../../../config/database.php';

try {
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!$data) throw new Exception('JSON inválido');

    $id     = (int)($data['id']     ?? 0);
    $nombre = trim($data['nombre']  ?? '');

    if ($nombre === '') throw new Exception('El nombre es obligatorio');

    $db   = new Database();
    $conn = $db->getConnection();

    if ($id > 0) {
        $stmt = $conn->prepare("UPDATE proveedor SET nombre = ? WHERE id = ?");
        $stmt->bind_param('si', $nombre, $id);
    } else {
        $stmt = $conn->prepare("INSERT INTO proveedor (nombre) VALUES (?)");
        $stmt->bind_param('s', $nombre);
    }

    $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => true]);

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
