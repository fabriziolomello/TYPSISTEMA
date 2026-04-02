<?php
// app/controllers/stock/transferencia/guardar.php

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/seguridad.php';
require_once __DIR__ . '/../../../config/database.php';

try {
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!$data) throw new Exception('JSON inválido');

    $fecha         = trim($data['fecha']         ?? '');
    $observaciones = trim($data['observaciones'] ?? '');
    $idOrigen      = (int)($data['id_origen']    ?? 0);
    $idDestino     = (int)($data['id_destino']   ?? 0);
    $items         = $data['items']              ?? [];

    if (!$fecha || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) throw new Exception('Fecha inválida');
    if ($idOrigen <= 0 || $idDestino <= 0) throw new Exception('Depósito inválido');
    if ($idOrigen === $idDestino) throw new Exception('El origen y destino no pueden ser el mismo');
    if (empty($items)) throw new Exception('No hay productos');

    $idUsuario = $_SESSION['usuario_id'] ?? null;
    if (!$idUsuario) throw new Exception('Sin sesión activa');

    $db   = new Database();
    $conn = $db->getConnection();
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn->begin_transaction();

    // Cabecera en movimiento_manual
    $stmtCab = $conn->prepare("INSERT INTO movimiento_manual (fecha, id_usuario, observaciones) VALUES (?, ?, ?)");
    $stmtCab->bind_param('sis', $fecha, $idUsuario, $observaciones);
    $stmtCab->execute();
    $idManual = $conn->insert_id;
    $stmtCab->close();

    // Egreso del origen
    $stmtEgreso = $conn->prepare("
        INSERT INTO movimiento_stock (fecha_hora, id_variante, tipo, cantidad, id_movimiento_manual, observaciones, id_deposito)
        VALUES (NOW(), ?, 'EGRESO', ?, ?, 'Transferencia salida', ?)
    ");

    // Ingreso al destino
    $stmtIngreso = $conn->prepare("
        INSERT INTO movimiento_stock (fecha_hora, id_variante, tipo, cantidad, id_movimiento_manual, observaciones, id_deposito)
        VALUES (NOW(), ?, 'INGRESO', ?, ?, 'Transferencia entrada', ?)
    ");

    // Actualizar stock_deposito
    $stmtDep = $conn->prepare("
        INSERT INTO stock_deposito (id_variante, id_deposito, stock_actual)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE stock_actual = GREATEST(0, stock_actual + ?)
    ");

    foreach ($items as $item) {
        $idVariante = (int)($item['id_variante'] ?? 0);
        $cantidad   = (int)($item['cantidad']    ?? 0);

        if ($idVariante <= 0 || $cantidad <= 0) throw new Exception('Datos inválidos');

        // Verificar stock suficiente en origen
        $stmtCheck = $conn->prepare("SELECT stock_actual FROM stock_deposito WHERE id_variante = ? AND id_deposito = ?");
        $stmtCheck->bind_param('ii', $idVariante, $idOrigen);
        $stmtCheck->execute();
        $rowCheck = $stmtCheck->get_result()->fetch_assoc();
        $stmtCheck->close();

        $stockOrigen = (int)($rowCheck['stock_actual'] ?? 0);
        if ($stockOrigen < $cantidad) {
            throw new Exception("Stock insuficiente en origen para variante ID $idVariante (disponible: $stockOrigen)");
        }

        // Egreso origen
        $stmtEgreso->bind_param('iiii', $idVariante, $cantidad, $idManual, $idOrigen);
        $stmtEgreso->execute();

        // Ingreso destino
        $stmtIngreso->bind_param('iiii', $idVariante, $cantidad, $idManual, $idDestino);
        $stmtIngreso->execute();

        // Descontar origen
        $deltaOrigen = -$cantidad;
        $stmtDep->bind_param('iiii', $idVariante, $idOrigen, $deltaOrigen, $deltaOrigen);
        $stmtDep->execute();

        // Sumar destino
        $stmtDep->bind_param('iiii', $idVariante, $idDestino, $cantidad, $cantidad);
        $stmtDep->execute();
    }

    $stmtEgreso->close();
    $stmtIngreso->close();
    $stmtDep->close();

    $conn->commit();

    echo json_encode(['success' => true]);

} catch (Throwable $e) {
    if (isset($conn)) { try { $conn->rollback(); } catch (Throwable $ignored) {} }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
