<?php
// app/controllers/config/usuarios/guardar.php

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/seguridad.php';
require_once __DIR__ . '/../../../config/database.php';

if (($_SESSION['usuario_rol'] ?? '') !== 'ADMIN') {
    echo json_encode(['success' => false, 'error' => 'Sin permisos']);
    exit;
}

try {
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!$data) throw new Exception('JSON inválido');

    $id         = (int)($data['id']         ?? 0);
    $nombre     = trim($data['nombre']      ?? '');
    $password   = trim($data['password']    ?? '');
    $rol        = $data['rol']              ?? 'VENDEDOR';
    $idDeposito = (int)($data['id_deposito'] ?? 1);

    if ($nombre === '') throw new Exception('El nombre es obligatorio');

    $rolesValidos = ['ADMIN', 'VENDEDOR'];
    if (!in_array($rol, $rolesValidos)) throw new Exception('Rol inválido');

    $db   = new Database();
    $conn = $db->getConnection();

    if ($id > 0) {
        // Editar
        if ($password !== '') {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE usuarios SET nombre = ?, password_hash = ?, rol = ?, id_deposito = ? WHERE id = ?");
            $stmt->bind_param('sssii', $nombre, $hash, $rol, $idDeposito, $id);
        } else {
            $stmt = $conn->prepare("UPDATE usuarios SET nombre = ?, rol = ?, id_deposito = ? WHERE id = ?");
            $stmt->bind_param('ssii', $nombre, $rol, $idDeposito, $id);
        }
    } else {
        // Nuevo
        if ($password === '') throw new Exception('La contraseña es obligatoria');
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO usuarios (nombre, password_hash, rol, id_deposito) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('sssi', $nombre, $hash, $rol, $idDeposito);
    }

    $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => true]);

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
