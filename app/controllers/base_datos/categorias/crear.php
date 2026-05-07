<?php
// app/controllers/base_datos/categorias/crear.php

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/seguridad.php';
require_once __DIR__ . '/../../../config/database.php';

try {
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);

    $tipo   = $data['tipo']   ?? '';
    $nombre = trim($data['nombre'] ?? '');

    if (!$nombre) throw new Exception('El nombre es obligatorio');
    if (!in_array($tipo, ['categoria', 'subcategoria'], true)) throw new Exception('Tipo inválido');

    $tabla = $tipo === 'categoria' ? 'categoria' : 'subcategoria';

    $db   = new Database();
    $conn = $db->getConnection();

    $stmt = $conn->prepare("INSERT INTO $tabla (nombre) VALUES (?)");
    $stmt->bind_param('s', $nombre);
    $stmt->execute();
    $id = $conn->insert_id;
    $stmt->close();

    echo json_encode(['success' => true, 'id' => $id, 'nombre' => $nombre]);

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
