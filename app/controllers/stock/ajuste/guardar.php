<?php
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
    $idDeposito    = (int)($_SESSION['usuario_deposito'] ?? 0);
    $idUsuario     = (int)($_SESSION['usuario_id']       ?? 0);

    if (!$fecha || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) throw new Exception('Fecha inválida');
    if (empty($items))    throw new Exception('No hay productos en el ajuste');
    if ($idDeposito <= 0) throw new Exception('Depósito inválido');
    if ($idUsuario  <= 0) throw new Exception('Sin sesión activa');

    $db   = new Database();
    $conn = $db->getConnection();
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    // Pre-fetch stock actual por depósito para todos los items de una sola query
    $ids          = array_map(fn($i) => (int)($i['id_variante'] ?? 0), $items);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $typesStock   = 'i' . str_repeat('i', count($ids));
    $paramsStock  = array_merge([$idDeposito], $ids);

    $stmtS = $conn->prepare("
        SELECT id_variante, COALESCE(stock_actual, 0) AS stock
        FROM stock_deposito
        WHERE id_deposito = ? AND id_variante IN ($placeholders)
    ");
    $stmtS->bind_param($typesStock, ...$paramsStock);
    $stmtS->execute();
    $resS = $stmtS->get_result();

    $stockMap = [];
    while ($row = $resS->fetch_assoc()) {
        $stockMap[(int)$row['id_variante']] = (int)$row['stock'];
    }
    $stmtS->close();

    $conn->begin_transaction();

    $stmtCab = $conn->prepare("INSERT INTO movimiento_manual (fecha, id_usuario, observaciones) VALUES (?, ?, ?)");
    $stmtCab->bind_param('sis', $fecha, $idUsuario, $observaciones);
    $stmtCab->execute();
    $idManual = $conn->insert_id;
    $stmtCab->close();

    $stmtIns = $conn->prepare("
        INSERT INTO movimiento_stock (fecha_hora, id_variante, tipo, cantidad, id_movimiento_manual, observaciones, id_deposito)
        VALUES (NOW(), ?, ?, ?, ?, '', ?)
    ");
    $stmtUpVar  = $conn->prepare("UPDATE producto_variante SET stock_actual = stock_actual + ? WHERE id = ?");
    $stmtUpProd = $conn->prepare("
        UPDATE productos p
        INNER JOIN producto_variante pv ON pv.id = ?
        SET p.stock_actual = p.stock_actual + ?
        WHERE p.id = pv.id_producto
    ");
    $stmtUpDep  = $conn->prepare("
        INSERT INTO stock_deposito (id_variante, id_deposito, stock_actual)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE stock_actual = GREATEST(0, stock_actual + ?)
    ");

    foreach ($items as $item) {
        $idVariante    = (int)($item['id_variante']    ?? 0);
        $cantidadNueva = (int)($item['cantidad_nueva'] ?? -1);

        if ($idVariante <= 0 || $cantidadNueva < 0) throw new Exception('Datos inválidos en un producto');

        $stockActual = $stockMap[$idVariante] ?? 0;
        $delta       = $cantidadNueva - $stockActual;

        if ($delta === 0) continue;

        $tipo     = $delta > 0 ? 'AJUSTE_POSITIVO' : 'AJUSTE_NEGATIVO';
        $cantidad = abs($delta);

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
    if (isset($conn)) try { $conn->rollback(); } catch (Throwable $ignored) {}
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
