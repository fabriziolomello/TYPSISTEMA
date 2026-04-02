<?php
// app/controllers/stock/movimientos/guardar.php

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/seguridad.php';
require_once __DIR__ . '/../../../config/database.php';

try {
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!$data) throw new Exception('JSON inválido');

    $fecha         = trim($data['fecha']         ?? '');
    $observaciones = trim($data['observaciones'] ?? '');
    $items         = $data['items']              ?? [];
    $idDeposito    = (int)($data['id_deposito']  ?? 0);

    if (!$fecha || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
        throw new Exception('Fecha inválida');
    }
    if (empty($items)) throw new Exception('No hay productos en el movimiento');
    if ($idDeposito <= 0) throw new Exception('Depósito inválido');

    $tiposPermitidos = ['INGRESO', 'EGRESO', 'AJUSTE_POSITIVO', 'AJUSTE_NEGATIVO'];

    $idUsuario = $_SESSION['usuario_id'] ?? null;
    if (!$idUsuario) throw new Exception('Sin sesión activa');

    $db   = new Database();
    $conn = $db->getConnection();
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    $conn->begin_transaction();

    // 1) Cabecera
    $stmtCab = $conn->prepare("
        INSERT INTO movimiento_manual (fecha, id_usuario, observaciones)
        VALUES (?, ?, ?)
    ");
    $stmtCab->bind_param('sis', $fecha, $idUsuario, $observaciones);
    $stmtCab->execute();
    $idManual = $conn->insert_id;
    $stmtCab->close();

    // 2) Statements detalle
    $stmtIns = $conn->prepare("
        INSERT INTO movimiento_stock (fecha_hora, id_variante, tipo, cantidad, id_movimiento_manual, observaciones, id_deposito)
        VALUES (NOW(), ?, ?, ?, ?, '', ?)
    ");

    // stock_actual en producto_variante (total global)
    $stmtUpVar = $conn->prepare("
        UPDATE producto_variante SET stock_actual = stock_actual + ? WHERE id = ?
    ");

    // stock_actual en productos (total global)
    $stmtUpProd = $conn->prepare("
        UPDATE productos p
        INNER JOIN producto_variante pv ON pv.id = ?
        SET p.stock_actual = p.stock_actual + ?
        WHERE p.id = pv.id_producto
    ");

    // stock_deposito (por depósito)
    $stmtUpDep = $conn->prepare("
        INSERT INTO stock_deposito (id_variante, id_deposito, stock_actual)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE stock_actual = GREATEST(0, stock_actual + ?)
    ");

    foreach ($items as $item) {
        $idVariante = (int)($item['id_variante'] ?? 0);
        $tipo       = $item['tipo'] ?? '';
        $cantidad   = (int)($item['cantidad'] ?? 0);

        if ($idVariante <= 0 || !in_array($tipo, $tiposPermitidos, true) || $cantidad <= 0) {
            throw new Exception('Datos inválidos en uno de los productos');
        }

        $delta = in_array($tipo, ['EGRESO', 'AJUSTE_NEGATIVO'], true) ? -$cantidad : $cantidad;

        $stmtIns->bind_param('isiii', $idVariante, $tipo, $cantidad, $idManual, $idDeposito);
        $stmtIns->execute();

        $stmtUpVar->bind_param('ii', $delta, $idVariante);
        $stmtUpVar->execute();

        $stmtUpProd->bind_param('ii', $idVariante, $delta);
        $stmtUpProd->execute();

        $stmtUpDep->bind_param('iiii', $idVariante, $idDeposito, $delta, $delta);
        $stmtUpDep->execute();
    }

    $stmtIns->close();
    $stmtUpVar->close();
    $stmtUpProd->close();
    $stmtUpDep->close();

    $conn->commit();

    echo json_encode(['success' => true]);

} catch (Throwable $e) {
    if (isset($conn)) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
