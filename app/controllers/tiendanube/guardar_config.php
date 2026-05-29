<?php
// app/controllers/tiendanube/guardar_config.php

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/seguridad.php';
require_once __DIR__ . '/../../config/database.php';

if (($_SESSION['usuario_rol'] ?? '') !== 'ADMIN') {
    echo json_encode(['success' => false, 'error' => 'Sin permisos']);
    exit;
}

try {
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);

    $storeId     = trim($data['store_id']     ?? '');
    $accessToken = trim($data['access_token'] ?? '');
    $idDeposito  = (int)($data['id_deposito'] ?? 1);

    if (!$storeId || !$accessToken) throw new Exception('Store ID y Access Token son obligatorios');

    $db   = new Database();
    $conn = $db->getConnection();

    $stmt = $conn->prepare("INSERT INTO tiendanube_config (id, store_id, access_token, id_deposito) VALUES (1, ?, ?, ?) ON DUPLICATE KEY UPDATE store_id = VALUES(store_id), access_token = VALUES(access_token), id_deposito = VALUES(id_deposito)");
    $stmt->bind_param('ssi', $storeId, $accessToken, $idDeposito);
    $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => true]);

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
