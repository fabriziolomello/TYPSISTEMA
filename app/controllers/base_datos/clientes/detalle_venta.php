<?php
// app/controllers/base_datos/clientes/detalle_venta.php

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/seguridad.php';
require_once __DIR__ . '/../../../config/database.php';

try {
    $idVenta   = (int)($_GET['id_venta']   ?? 0);
    $idCliente = (int)($_GET['id_cliente'] ?? 0);

    if ($idVenta <= 0 || $idCliente <= 0) throw new Exception('Parámetros inválidos');

    $db   = new Database();
    $conn = $db->getConnection();

    // Verificar que la venta pertenece al cliente
    $stmtCheck = $conn->prepare("SELECT id FROM ventas WHERE id = ? AND id_cliente = ?");
    $stmtCheck->bind_param('ii', $idVenta, $idCliente);
    $stmtCheck->execute();
    if ($stmtCheck->get_result()->num_rows === 0) throw new Exception('Venta no encontrada');
    $stmtCheck->close();

    $stmt = $conn->prepare("
        SELECT
            p.nombre                                        AS producto,
            CASE WHEN pv.nombre_variante = 'unica' THEN '' ELSE pv.nombre_variante END AS variante,
            dv.cantidad,
            dv.precio_unitario,
            dv.descuento,
            dv.subtotal
        FROM detalle_ventas dv
        JOIN producto_variante pv ON pv.id = dv.id_variante
        JOIN productos p          ON p.id  = pv.id_producto
        WHERE dv.id_venta = ?
        ORDER BY p.nombre ASC
    ");
    $stmt->bind_param('i', $idVenta);
    $stmt->execute();
    $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    echo json_encode(['success' => true, 'items' => $items]);

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
