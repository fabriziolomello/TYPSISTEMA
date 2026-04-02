<?php
// app/controllers/ventas/buscar_clientes.php

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/seguridad.php';
require_once __DIR__ . '/../../config/database.php';

$q = trim($_GET['q'] ?? '');

if ($q === '') {
    echo json_encode([]);
    exit;
}

$db   = new Database();
$conn = $db->getConnection();

$palabras = array_filter(explode(' ', $q));
$conds    = [];
$params   = [];
$types    = '';

foreach ($palabras as $palabra) {
    $conds[]  = "nombre LIKE CONCAT('%',?,'%')";
    $params[] = $palabra;
    $types   .= 's';
}

$where = implode(' AND ', $conds);

$stmt = $conn->prepare("SELECT id, nombre FROM clientes WHERE $where ORDER BY nombre ASC LIMIT 10");
$stmt->bind_param($types, ...$params);
$stmt->execute();
$clientes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

echo json_encode($clientes);
