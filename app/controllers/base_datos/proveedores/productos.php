<?php
// app/controllers/base_datos/proveedores/productos.php

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/seguridad.php';
require_once __DIR__ . '/../../../config/database.php';

try {
    $idProv = (int)($_GET['id'] ?? 0);
    if ($idProv <= 0) throw new Exception('Proveedor inválido');

    $db   = new Database();
    $conn = $db->getConnection();

    $stmt = $conn->prepare("
        SELECT
            p.nombre,
            p.stock_actual,
            p.precio_costo,
            MAX(CASE WHEN lp.tipo_lista = 'MINORISTA' THEN lp.precio END) AS precio_minorista,
            MAX(CASE WHEN lp.tipo_lista = 'MAYORISTA' THEN lp.precio END) AS precio_mayorista,
            c.nombre AS categoria,
            p.activo
        FROM productos p
        LEFT JOIN lista_precio lp ON lp.id_producto = p.id
        LEFT JOIN categoria c     ON c.id = p.id_categoria
        WHERE p.id_proveedor = ?
        GROUP BY p.id, p.nombre, p.stock_actual, p.precio_costo, c.nombre, p.activo
        ORDER BY p.nombre ASC
    ");
    $stmt->bind_param('i', $idProv);
    $stmt->execute();
    $productos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    echo json_encode(['success' => true, 'productos' => $productos]);

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
