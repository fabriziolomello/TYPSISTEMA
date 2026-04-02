<?php
// app/controllers/base_datos/lista_precios/guardar.php

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/seguridad.php';
require_once __DIR__ . '/../../../config/database.php';

try {
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!$data) throw new Exception('JSON inválido');

    $idProducto = (int)($data['id_producto'] ?? 0);
    $idMin      = (int)($data['id_min']      ?? 0);
    $idMay      = (int)($data['id_may']      ?? 0);
    $minorista  = (float)($data['minorista'] ?? 0);
    $mayorista  = (float)($data['mayorista'] ?? 0);

    if ($idProducto <= 0) throw new Exception('Producto inválido');

    $db   = new Database();
    $conn = $db->getConnection();

    $stmt = $conn->prepare("
        INSERT INTO lista_precio (id, id_producto, tipo_lista, precio)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE precio = VALUES(precio)
    ");

    foreach ([
        [$idMin, $idProducto, 'MINORISTA', $minorista],
        [$idMay, $idProducto, 'MAYORISTA', $mayorista],
    ] as [$lid, $lprod, $tipo, $precio]) {
        if ($lid > 0) {
            $stmt->bind_param('iisd', $lid, $lprod, $tipo, $precio);
        } else {
            $null = null;
            $stmt->bind_param('iisd', $null, $lprod, $tipo, $precio);
        }
        $stmt->execute();
    }

    $stmt->close();

    echo json_encode(['success' => true]);

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
