<?php
// app/controllers/base_datos/productos/editar.php

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/seguridad.php';
require_once __DIR__ . '/../../../config/database.php';

try {
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!$data) throw new Exception('JSON inválido');

    $id        = (int)($data['id'] ?? 0);
    $nombre    = trim($data['nombre'] ?? '');
    $codigo    = trim($data['codigo'] ?? '') ?: null;
    $categoria = $data['categoria'] ? (int)$data['categoria'] : null;
    $proveedor = $data['proveedor'] ? (int)$data['proveedor'] : null;
    $costo     = (float)($data['costo']     ?? 0);
    $minorista = (float)($data['minorista'] ?? 0);
    $mayorista = (float)($data['mayorista'] ?? 0);
    $variantes = $data['variantes'] ?? [];

    if ($id <= 0)  throw new Exception('ID inválido');
    if (!$nombre)  throw new Exception('El nombre es obligatorio');

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

    // Actualizar producto
    $stmt = $conn->prepare("
        UPDATE productos
        SET nombre = ?, codigo_barras = ?, precio_costo = ?, id_categoria = ?, id_proveedor = ?
        WHERE id = ?
    ");
    $stmt->bind_param('ssdiii', $nombre, $codigo, $costo, $categoria, $proveedor, $id);
    $stmt->execute();
    $stmt->close();

    // Actualizar precios (UPDATE si existe, INSERT si no)
    foreach (['MINORISTA' => $minorista, 'MAYORISTA' => $mayorista] as $tipo => $precio) {
        $stmtLp = $conn->prepare("
            INSERT INTO lista_precio (id_producto, tipo_lista, precio)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE precio = ?
        ");
        $stmtLp->bind_param('isdd', $id, $tipo, $precio, $precio);
        $stmtLp->execute();
        $stmtLp->close();
    }

    // Actualizar/insertar variantes
    if (!empty($variantes)) {
        $stmtUpVar = $conn->prepare("
            UPDATE producto_variante SET nombre_variante = ?, color = ?, talle = ?, codigo_barras = ? WHERE id = ? AND id_producto = ?
        ");
        $stmtInsVar = $conn->prepare("
            INSERT INTO producto_variante (id_producto, nombre_variante, color, talle, codigo_barras, stock_actual) VALUES (?, ?, ?, ?, ?, ?)
        ");

        foreach ($variantes as $v) {
            $vid     = (int)($v['id'] ?? 0);
            $vcolor  = trim($v['color'] ?? '') ?: null;
            $vtalle  = trim($v['talle'] ?? '') ?: null;
            $vcodigo = trim($v['codigo'] ?? '') ?: null;
            $vstock  = (int)($v['stock'] ?? 0);
            $vnombre = generarNombreVariante($vcolor, $vtalle);

            if ($vid > 0) {
                $stmtUpVar->bind_param('ssssii', $vnombre, $vcolor, $vtalle, $vcodigo, $vid, $id);
                $stmtUpVar->execute();
            } else {
                $stmtInsVar->bind_param('issssi', $id, $vnombre, $vcolor, $vtalle, $vcodigo, $vstock);
                $stmtInsVar->execute();
            }
        }
        $stmtUpVar->close();
        $stmtInsVar->close();
    }

    $conn->commit();

    echo json_encode(['success' => true]);

} catch (Throwable $e) {
    if (isset($conn)) try { $conn->rollback(); } catch (Throwable $ignored) {}
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
