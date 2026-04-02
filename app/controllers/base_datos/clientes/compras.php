<?php
// app/controllers/base_datos/clientes/compras.php

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/seguridad.php';
require_once __DIR__ . '/../../../config/database.php';

try {
    $idCliente = (int)($_GET['id'] ?? 0);
    if ($idCliente <= 0) throw new Exception('Cliente inválido');

    $db   = new Database();
    $conn = $db->getConnection();

    $stmt = $conn->prepare("
        SELECT
            v.id,
            v.fecha_hora,
            v.total,
            COALESCE(SUM(mc.monto), 0) AS total_cobrado,
            v.estado_pago
        FROM ventas v
        LEFT JOIN movimiento_caja mc ON mc.id_venta = v.id
        WHERE v.id_cliente = ?
          AND v.estado_pago != 'ANULADA'
        GROUP BY v.id, v.fecha_hora, v.total, v.estado_pago
        ORDER BY
            CASE WHEN v.estado_pago IN ('PENDIENTE','PARCIAL') THEN 0 ELSE 1 END ASC,
            v.fecha_hora DESC
    ");
    $stmt->bind_param('i', $idCliente);
    $stmt->execute();
    $ventas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    echo json_encode(['success' => true, 'ventas' => $ventas]);

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
