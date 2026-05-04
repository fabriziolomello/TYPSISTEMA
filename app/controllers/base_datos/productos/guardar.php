<?php
// app/controllers/base_datos/productos/guardar.php

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/seguridad.php';
require_once __DIR__ . '/../../../config/database.php';

try {
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!$data) throw new Exception('JSON inválido');

    $nombre    = trim($data['nombre'] ?? '');
    $codigo    = trim($data['codigo'] ?? '') ?: null;
    $categoria = $data['categoria'] ? (int)$data['categoria'] : null;
    $proveedor = $data['proveedor'] ? (int)$data['proveedor'] : null;
    $costo     = (float)($data['costo']     ?? 0);
    $minorista = (float)($data['minorista'] ?? 0);
    $mayorista = (float)($data['mayorista'] ?? 0);
    $variantes = $data['variantes'] ?? [];

    if (!$nombre)          throw new Exception('El nombre es obligatorio');
    if (empty($variantes)) throw new Exception('Debe tener al menos una variante');

    function generarNombreVariante(?string $color, ?string $talle): string {
        if ($color && $talle) return "$color / $talle";
        if ($color)           return $color;
        if ($talle)           return $talle;
        return 'unica';
    }

    $db   = new Database();
    $conn = $db->getConnection();
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    $conn->begin_transaction();

    // 1) Insertar producto
    $stmt = $conn->prepare("
        INSERT INTO productos (nombre, codigo_barras, precio_costo, id_categoria, id_proveedor)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->bind_param('ssdii', $nombre, $codigo, $costo, $categoria, $proveedor);
    $stmt->execute();
    $idProducto = $conn->insert_id;
    $stmt->close();

    // 2) Precios de lista
    $stmtLp = $conn->prepare("
        INSERT INTO lista_precio (id_producto, tipo_lista, precio) VALUES (?, ?, ?)
    ");
    foreach (['MINORISTA' => $minorista, 'MAYORISTA' => $mayorista] as $tipo => $precio) {
        $stmtLp->bind_param('isd', $idProducto, $tipo, $precio);
        $stmtLp->execute();
    }
    $stmtLp->close();

    $idDeposito = (int)($_SESSION['usuario_deposito'] ?? 1);

    // 3) Variantes + stock inicial en stock_deposito
    $stmtVar = $conn->prepare("
        INSERT INTO producto_variante (id_producto, nombre_variante, color, talle, codigo_barras, stock_actual)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmtSd = $conn->prepare("
        INSERT INTO stock_deposito (id_variante, id_deposito, stock_actual) VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE stock_actual = stock_actual
    ");
    foreach ($variantes as $v) {
        $vColor  = trim($v['color'] ?? '') ?: null;
        $vTalle  = trim($v['talle'] ?? '') ?: null;
        $vCodigo = trim($v['codigo'] ?? '') ?: null;
        $vStock  = (int)($v['stock'] ?? 0);
        $vNombre = generarNombreVariante($vColor, $vTalle);
        $stmtVar->bind_param('issssi', $idProducto, $vNombre, $vColor, $vTalle, $vCodigo, $vStock);
        $stmtVar->execute();
        $idVariante = $conn->insert_id;
        $stmtSd->bind_param('iii', $idVariante, $idDeposito, $vStock);
        $stmtSd->execute();
    }
    $stmtVar->close();
    $stmtSd->close();

    $conn->commit();

    echo json_encode(['success' => true, 'id' => $idProducto]);

} catch (Throwable $e) {
    if (isset($conn)) try { $conn->rollback(); } catch (Throwable $ignored) {}
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
