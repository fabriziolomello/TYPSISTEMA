<?php
// app/controllers/base_datos/clientes/pago.php

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/seguridad.php';
require_once __DIR__ . '/../../../config/database.php';

try {
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!$data) throw new Exception('JSON inválido');

    $idVenta   = (int)($data['id_venta']   ?? 0);
    $idCliente = (int)($data['id_cliente'] ?? 0);
    $monto     = (float)($data['monto']    ?? 0);
    $medioPago = trim($data['medio_pago']  ?? '');

    if ($idVenta   <= 0) throw new Exception('Venta inválida');
    if ($idCliente <= 0) throw new Exception('Cliente inválido');
    if ($monto     <= 0) throw new Exception('El monto debe ser mayor a 0');

    $mediosValidos = ['EFECTIVO', 'TARJETA', 'TRANSFERENCIA', 'QR'];
    if (!in_array($medioPago, $mediosValidos)) throw new Exception('Medio de pago inválido');

    $db   = new Database();
    $conn = $db->getConnection();

    $idUsuario = (int)$_SESSION['usuario_id'];

    $conn->begin_transaction();

    // Obtener venta y verificar que pertenece al cliente
    $stmtV = $conn->prepare("SELECT id, total, estado_pago, id_caja FROM ventas WHERE id = ? AND id_cliente = ? FOR UPDATE");
    $stmtV->bind_param('ii', $idVenta, $idCliente);
    $stmtV->execute();
    $venta = $stmtV->get_result()->fetch_assoc();
    $stmtV->close();

    if (!$venta) throw new Exception('Venta no encontrada');
    if (!in_array($venta['estado_pago'], ['PENDIENTE', 'PARCIAL'])) {
        throw new Exception('La venta ya está pagada o anulada');
    }

    // Calcular total cobrado actual
    $stmtCob = $conn->prepare("SELECT COALESCE(SUM(monto), 0) AS cobrado FROM movimiento_caja WHERE id_venta = ?");
    $stmtCob->bind_param('i', $idVenta);
    $stmtCob->execute();
    $cobrado = (float)$stmtCob->get_result()->fetch_assoc()['cobrado'];
    $stmtCob->close();

    $saldoRestante = (float)$venta['total'] - $cobrado;
    if ($monto > $saldoRestante + 0.001) throw new Exception('El monto supera el saldo pendiente');

    // Obtener caja abierta
    $resCaja = $conn->query("SELECT id FROM caja WHERE estado = 'ABIERTA' ORDER BY id DESC LIMIT 1");
    $idCaja  = $resCaja->num_rows > 0 ? (int)$resCaja->fetch_assoc()['id'] : (int)$venta['id_caja'];

    // Registrar movimiento de caja
    $stmtMov = $conn->prepare("
        INSERT INTO movimiento_caja (id_caja, id_venta, fecha_hora, tipo, monto, medio_pago, referencia, id_usuario)
        VALUES (?, ?, NOW(), 'VENTA', ?, ?, 'Cobro pendiente', ?)
    ");
    $stmtMov->bind_param('iidsi', $idCaja, $idVenta, $monto, $medioPago, $idUsuario);
    $stmtMov->execute();
    $stmtMov->close();

    // Determinar nuevo estado_pago
    $nuevoCobrado = $cobrado + $monto;
    if ($nuevoCobrado >= (float)$venta['total'] - 0.001) {
        $nuevoEstado = 'PAGADA';
    } else {
        $nuevoEstado = 'PARCIAL';
    }

    $stmtEst = $conn->prepare("UPDATE ventas SET estado_pago = ? WHERE id = ?");
    $stmtEst->bind_param('si', $nuevoEstado, $idVenta);
    $stmtEst->execute();
    $stmtEst->close();

    // Actualizar saldo_pendiente del cliente
    $stmtSaldo = $conn->prepare("UPDATE clientes SET saldo_pendiente = GREATEST(0, saldo_pendiente - ?) WHERE id = ?");
    $stmtSaldo->bind_param('di', $monto, $idCliente);
    $stmtSaldo->execute();
    $stmtSaldo->close();

    $conn->commit();

    echo json_encode(['success' => true]);

} catch (Throwable $e) {
    if (isset($conn)) $conn->rollback();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
