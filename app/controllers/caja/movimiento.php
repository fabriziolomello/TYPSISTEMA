<?php
// app/controllers/caja/movimiento.php

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/seguridad.php';
require_once __DIR__ . '/../../config/database.php';

try {
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!$data) throw new Exception('JSON inválido');

    $idCaja    = (int)($data['id_caja']    ?? 0);
    $tipo      = $data['tipo']             ?? '';
    $medioPago = $data['medio_pago']       ?? '';
    $monto     = (float)($data['monto']    ?? 0);
    $referencia = trim($data['referencia'] ?? '');

    if ($idCaja <= 0) throw new Exception('Caja inválida');

    $tiposValidos  = ['INGRESO', 'EGRESO'];
    $mediosValidos = ['EFECTIVO', 'TARJETA', 'TRANSFERENCIA', 'QR'];

    if (!in_array($tipo, $tiposValidos, true))       throw new Exception('Tipo inválido');
    if (!in_array($medioPago, $mediosValidos, true))  throw new Exception('Medio de pago inválido');
    if ($monto <= 0)                                  throw new Exception('Monto inválido');

    $idUsuario = $_SESSION['usuario_id'] ?? null;
    if (!$idUsuario) throw new Exception('Sin sesión activa');

    $db   = new Database();
    $conn = $db->getConnection();
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    // Verificar que la caja esté abierta
    $stmtCheck = $conn->prepare("SELECT id FROM caja WHERE id = ? AND estado = 'ABIERTA' LIMIT 1");
    $stmtCheck->bind_param('i', $idCaja);
    $stmtCheck->execute();
    if ($stmtCheck->get_result()->num_rows === 0) {
        throw new Exception('La caja no está abierta');
    }
    $stmtCheck->close();

    // Insertar movimiento
    $stmt = $conn->prepare("
        INSERT INTO movimiento_caja (id_caja, fecha_hora, tipo, monto, medio_pago, referencia, id_usuario)
        VALUES (?, NOW(), ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param('isdssi', $idCaja, $tipo, $monto, $medioPago, $referencia, $idUsuario);
    $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => true]);

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
