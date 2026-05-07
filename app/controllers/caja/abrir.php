<?php
// app/controllers/caja/abrir.php

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/seguridad.php';
require_once __DIR__ . '/../../config/database.php';

try {
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!$data) throw new Exception('JSON inválido');

    $saldoInicial = (float)($data['saldo_inicial'] ?? 0);
    $observaciones = trim($data['observaciones'] ?? '');

    $idUsuario  = $_SESSION['usuario_id'] ?? null;
    if (!$idUsuario) throw new Exception('Sin sesión activa');

    $idSucursal = (int)($_SESSION['usuario_deposito'] ?? 1);

    $db   = new Database();
    $conn = $db->getConnection();
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    $conn->begin_transaction();

    // Verificar que no haya caja abierta para esta sucursal
    $stmtCheck = $conn->prepare("SELECT id FROM caja WHERE estado = 'ABIERTA' AND id_sucursal = ? LIMIT 1");
    $stmtCheck->bind_param('i', $idSucursal);
    $stmtCheck->execute();
    if ($stmtCheck->get_result()->num_rows > 0) {
        throw new Exception('Ya hay una caja abierta');
    }
    $stmtCheck->close();

    // Insertar apertura
    $stmt = $conn->prepare("
        INSERT INTO caja (id_sucursal, fecha, id_usuario_apertura, saldo_inicial, estado, observaciones)
        VALUES (?, CURDATE(), ?, ?, 'ABIERTA', ?)
    ");
    $stmt->bind_param('iids', $idSucursal, $idUsuario, $saldoInicial, $observaciones);
    $stmt->execute();
    $stmt->close();

    $conn->commit();

    echo json_encode(['success' => true]);

} catch (Throwable $e) {
    if (isset($conn)) try { $conn->rollback(); } catch (Throwable $ignored) {}
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
