<?php
// app/controllers/base_datos/productos/importar.php

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/seguridad.php';
require_once __DIR__ . '/../../../config/database.php';

try {
    if (empty($_FILES['archivo'])) throw new Exception('No se recibió ningún archivo');

    $file = $_FILES['archivo'];
    if ($file['error'] !== UPLOAD_ERR_OK) throw new Exception('Error al subir el archivo');

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext !== 'csv') throw new Exception('El archivo debe ser .csv');

    $handle = fopen($file['tmp_name'], 'r');
    if (!$handle) throw new Exception('No se pudo leer el archivo');

    // Saltar BOM si existe
    $bom = fread($handle, 3);
    if ($bom !== "\xEF\xBB\xBF") rewind($handle);

    // Saltar cabecera
    fgetcsv($handle, 0, ';');

    $db   = new Database();
    $conn = $db->getConnection();
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    $conn->begin_transaction();

    $insertados  = 0;
    $errores     = [];
    $fila        = 1;

    // Statements reutilizables
    $stmtProd = $conn->prepare("
        INSERT INTO productos (nombre, codigo_barras, precio_costo, id_categoria, id_proveedor)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE nombre = nombre
    ");
    $stmtLp = $conn->prepare("
        INSERT INTO lista_precio (id_producto, tipo_lista, precio)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE precio = VALUES(precio)
    ");
    $stmtVar = $conn->prepare("
        INSERT INTO producto_variante (id_producto, nombre_variante, codigo_barras, stock_actual)
        VALUES (?, ?, ?, ?)
    ");
    $stmtGetProd = $conn->prepare("SELECT id FROM productos WHERE nombre = ? LIMIT 1");
    $stmtGetCat  = $conn->prepare("SELECT id FROM categoria WHERE nombre = ? LIMIT 1");
    $stmtInsCat  = $conn->prepare("INSERT INTO categoria (nombre) VALUES (?)");
    $stmtGetProv = $conn->prepare("SELECT id FROM proveedor WHERE nombre = ? LIMIT 1");
    $stmtInsProv = $conn->prepare("INSERT INTO proveedor (nombre) VALUES (?)");

    while (($row = fgetcsv($handle, 0, ';')) !== false) {
        $fila++;
        if (count($row) < 8) { $errores[] = "Fila $fila: faltan columnas"; continue; }

        $nombre    = trim($row[0]);
        $codProd   = trim($row[1]) ?: null;
        $catNombre = trim($row[2]);
        $provNombre= trim($row[3]);
        $costo     = (float)str_replace(',', '.', str_replace('.', '', $row[4]));
        $minorista = (float)str_replace(',', '.', str_replace('.', '', $row[5]));
        $mayorista = (float)str_replace(',', '.', str_replace('.', '', $row[6]));
        $variante  = trim($row[7]) ?: 'unica';
        $codVar    = trim($row[8] ?? '') ?: null;
        $stock     = (int)($row[9] ?? 0);

        if (!$nombre) { $errores[] = "Fila $fila: nombre vacío"; continue; }

        // Categoría
        $idCat = null;
        if ($catNombre) {
            $stmtGetCat->bind_param('s', $catNombre);
            $stmtGetCat->execute();
            $r = $stmtGetCat->get_result()->fetch_assoc();
            if ($r) {
                $idCat = (int)$r['id'];
            } else {
                $stmtInsCat->bind_param('s', $catNombre);
                $stmtInsCat->execute();
                $idCat = $conn->insert_id;
            }
        }

        // Proveedor
        $idProv = null;
        if ($provNombre) {
            $stmtGetProv->bind_param('s', $provNombre);
            $stmtGetProv->execute();
            $r = $stmtGetProv->get_result()->fetch_assoc();
            if ($r) {
                $idProv = (int)$r['id'];
            } else {
                $stmtInsProv->bind_param('s', $provNombre);
                $stmtInsProv->execute();
                $idProv = $conn->insert_id;
            }
        }

        // Producto: buscar si ya existe por nombre
        $stmtGetProd->bind_param('s', $nombre);
        $stmtGetProd->execute();
        $prodExistente = $stmtGetProd->get_result()->fetch_assoc();

        if ($prodExistente) {
            $idProducto = (int)$prodExistente['id'];
        } else {
            $stmtProd->bind_param('ssdii', $nombre, $codProd, $costo, $idCat, $idProv);
            $stmtProd->execute();
            $idProducto = $conn->insert_id;

            // Precios
            foreach (['MINORISTA' => $minorista, 'MAYORISTA' => $mayorista] as $tipo => $precio) {
                $stmtLp->bind_param('isd', $idProducto, $tipo, $precio);
                $stmtLp->execute();
            }

            $insertados++;
        }

        // Variante: insertar si no existe
        $existeVar = $conn->prepare("SELECT id FROM producto_variante WHERE id_producto = ? AND nombre_variante = ? LIMIT 1");
        $existeVar->bind_param('is', $idProducto, $variante);
        $existeVar->execute();
        if ($existeVar->get_result()->num_rows === 0) {
            $stmtVar->bind_param('issi', $idProducto, $variante, $codVar, $stock);
            $stmtVar->execute();
        }
        $existeVar->close();
    }

    fclose($handle);
    $conn->commit();

    echo json_encode([
        'success'   => true,
        'insertados' => $insertados,
        'errores'   => $errores,
    ]);

} catch (Throwable $e) {
    if (isset($conn)) try { $conn->rollback(); } catch (Throwable $ignored) {}
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
