<?php
// app/controllers/base_datos/productos/variantes.php

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/seguridad.php';
require_once __DIR__ . '/../../../config/database.php';

try {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) throw new Exception('ID inválido');

    $db   = new Database();
    $conn = $db->getConnection();

    $stmt = $conn->prepare("
        SELECT id, nombre_variante, codigo_barras, stock_actual, activo
        FROM producto_variante
        WHERE id_producto = ?
        ORDER BY id ASC
    ");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $variantes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    echo json_encode(['success' => true, 'variantes' => $variantes]);

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
