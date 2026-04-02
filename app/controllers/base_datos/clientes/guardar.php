<?php
// app/controllers/base_datos/clientes/guardar.php

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/seguridad.php';
require_once __DIR__ . '/../../../config/database.php';

try {
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!$data) throw new Exception('JSON inválido');

    $id       = (int)($data['id']       ?? 0);
    $nombre   = trim($data['nombre']    ?? '');
    $cuit     = trim($data['cuit']      ?? '');
    $telefono = trim($data['telefono']  ?? '');
    $email    = trim($data['email']     ?? '');

    if ($nombre === '') throw new Exception('El nombre es obligatorio');

    $db   = new Database();
    $conn = $db->getConnection();

    if ($id > 0) {
        // Editar
        $stmt = $conn->prepare("
            UPDATE clientes
            SET nombre = ?, cuit = ?, telefono = ?, email = ?
            WHERE id = ?
        ");
        $stmt->bind_param('ssssi', $nombre, $cuit, $telefono, $email, $id);
        $stmt->execute();
        $stmt->close();
    } else {
        // Nuevo
        $stmt = $conn->prepare("
            INSERT INTO clientes (nombre, cuit, telefono, email, saldo_pendiente)
            VALUES (?, ?, ?, ?, 0)
        ");
        $stmt->bind_param('ssss', $nombre, $cuit, $telefono, $email);
        $stmt->execute();
        $stmt->close();
    }

    echo json_encode(['success' => true]);

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
