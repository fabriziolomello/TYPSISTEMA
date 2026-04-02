<?php
// app/controllers/caja/cerrar.php

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/seguridad.php';
require_once __DIR__ . '/../../config/database.php';

try {
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!$data) throw new Exception('JSON inválido');

    $idCaja       = (int)($data['id_caja'] ?? 0);
    $observaciones = trim($data['observaciones'] ?? '');
    $detalle      = $data['detalle'] ?? [];

    if ($idCaja <= 0)     throw new Exception('Caja inválida');
    if (empty($detalle))  throw new Exception('Sin detalle de cierre');

    $idUsuario = $_SESSION['usuario_id'] ?? null;
    if (!$idUsuario) throw new Exception('Sin sesión activa');

    $mediosValidos = ['EFECTIVO', 'TARJETA', 'TRANSFERENCIA', 'QR'];

    $db   = new Database();
    $conn = $db->getConnection();
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    $conn->begin_transaction();

    // Verificar que la caja esté abierta y sea la correcta
    $stmtCheck = $conn->prepare("SELECT id FROM caja WHERE id = ? AND estado = 'ABIERTA' LIMIT 1");
    $stmtCheck->bind_param('i', $idCaja);
    $stmtCheck->execute();
    if ($stmtCheck->get_result()->num_rows === 0) {
        throw new Exception('La caja no está abierta');
    }
    $stmtCheck->close();

    // Calcular saldo final (total_real de efectivo)
    $saldoFinal = 0;
    foreach ($detalle as $item) {
        if ($item['medio_pago'] === 'EFECTIVO') {
            $saldoFinal = (float)($item['total_real'] ?? 0);
            break;
        }
    }

    // Cerrar la caja
    $stmtCierre = $conn->prepare("
        UPDATE caja
        SET estado = 'CERRADA',
            id_usuario_cierre = ?,
            saldo_final = ?,
            observaciones = CONCAT(COALESCE(observaciones, ''), IF(observaciones IS NOT NULL AND observaciones <> '', ' | ', ''), ?)
        WHERE id = ?
    ");
    $stmtCierre->bind_param('idsi', $idUsuario, $saldoFinal, $observaciones, $idCaja);
    $stmtCierre->execute();
    $stmtCierre->close();

    // Guardar detalle por medio de pago
    $stmtDet = $conn->prepare("
        INSERT INTO caja_cierre_detalle (id_caja, medio_pago, total_esperado, total_real, diferencia)
        VALUES (?, ?, ?, ?, ?)
    ");

    foreach ($detalle as $item) {
        $medio    = $item['medio_pago'] ?? '';
        $esperado = (float)($item['total_esperado'] ?? 0);
        $real     = (float)($item['total_real'] ?? 0);
        $dif      = (float)($item['diferencia'] ?? 0);

        if (!in_array($medio, $mediosValidos, true)) continue;

        $stmtDet->bind_param('isddd', $idCaja, $medio, $esperado, $real, $dif);
        $stmtDet->execute();
    }
    $stmtDet->close();

    $conn->commit();

    echo json_encode(['success' => true]);

} catch (Throwable $e) {
    if (isset($conn)) try { $conn->rollback(); } catch (Throwable $ignored) {}
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
