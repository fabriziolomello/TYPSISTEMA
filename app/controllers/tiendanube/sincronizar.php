<?php
// app/controllers/tiendanube/sincronizar.php
// Actualiza stock, precio y variantes en TN para todos los productos ya publicados

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/seguridad.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/api.php';

$_esAdmin = ($_SESSION['usuario_rol'] ?? '') === 'ADMIN';
$_dep     = (int)($_SESSION['usuario_deposito'] ?? 0);
if (!$_esAdmin && $_dep !== 1) {
    echo json_encode(['success' => false, 'error' => 'Sin permisos']);
    exit;
}

try {
    $db   = new Database();
    $conn = $db->getConnection();

    $config     = tn_get_config($conn);
    $idDeposito = (int)$config['id_deposito'];

    // Productos publicados habilitados para sync
    $productos = $conn->query("
        SELECT
            p.id,
            p.nombre,
            tp.tn_product_id,
            MAX(CASE WHEN lp.tipo_lista = 'MINORISTA' THEN lp.precio END) AS precio_minorista
        FROM productos p
        INNER JOIN tiendanube_producto tp ON tp.id_producto = p.id
        LEFT JOIN lista_precio lp ON lp.id_producto = p.id
        WHERE p.activo = 1 AND p.sincronizar_tn = 1
        GROUP BY p.id, p.nombre, tp.tn_product_id
    ")->fetch_all(MYSQLI_ASSOC);

    if (empty($productos)) {
        echo json_encode(['success' => true, 'mensaje' => 'No hay productos publicados para sincronizar.', 'sincronizados' => 0]);
        exit;
    }

    $sincronizados        = 0;
    $variantesAgregadas   = 0;
    $variantesEliminadas  = 0;
    $errores              = [];

    $stmtSync      = $conn->prepare("UPDATE tiendanube_producto SET sincronizado_at = NOW() WHERE tn_product_id = ?");
    $stmtInsertVar = $conn->prepare("INSERT IGNORE INTO tiendanube_variante (id_variante, tn_variant_id) VALUES (?, ?)");
    $stmtDeleteVar = $conn->prepare("DELETE FROM tiendanube_variante WHERE tn_variant_id = ?");

    $stmtLocales = $conn->prepare("
        SELECT pv.id, pv.color, pv.talle, pv.codigo_barras,
               COALESCE(sd.stock_actual, 0) AS stock
        FROM producto_variante pv
        LEFT JOIN stock_deposito sd ON sd.id_variante = pv.id AND sd.id_deposito = ?
        WHERE pv.id_producto = ? AND pv.activo = 1
    ");

    $stmtMapeadas = $conn->prepare("
        SELECT tv.id_variante, tv.tn_variant_id
        FROM tiendanube_variante tv
        INNER JOIN producto_variante pv ON pv.id = tv.id_variante
        WHERE pv.id_producto = ?
    ");

    foreach ($productos as $prod) {
        try {
            $tnProductId = (int)$prod['tn_product_id'];
            $precio      = number_format((float)($prod['precio_minorista'] ?? 0), 2, '.', '');

            // Variantes activas del sistema
            $stmtLocales->bind_param('ii', $idDeposito, $prod['id']);
            $stmtLocales->execute();
            $variantesLocales = $stmtLocales->get_result()->fetch_all(MYSQLI_ASSOC);

            // Variantes ya mapeadas en TN
            $stmtMapeadas->bind_param('i', $prod['id']);
            $stmtMapeadas->execute();
            $variantesMapeadas = $stmtMapeadas->get_result()->fetch_all(MYSQLI_ASSOC);

            $mapeadasPorLocal = array_column($variantesMapeadas, 'tn_variant_id', 'id_variante');
            $localesPorId     = array_column($variantesLocales, null, 'id');

            $usaColor       = !empty(array_filter(array_column($variantesLocales, 'color')));
            $usaTalle       = !empty(array_filter(array_column($variantesLocales, 'talle')));
            $tieneAtributos = $usaColor || $usaTalle;

            // UPDATE: variantes ya mapeadas y que siguen activas
            foreach ($variantesMapeadas as $vm) {
                if (!isset($localesPorId[$vm['id_variante']])) continue;
                $lv = $localesPorId[$vm['id_variante']];
                try {
                    tn_request('PUT',
                        "products/{$tnProductId}/variants/{$vm['tn_variant_id']}",
                        ['stock' => max(0, (int)$lv['stock']), 'price' => $precio],
                        $config
                    );
                    $sincronizados++;
                } catch (Throwable $e) {
                    $errores[] = "{$prod['nombre']} variante {$vm['id_variante']}: " . $e->getMessage();
                }
            }

            // ADD: variantes locales activas sin mapeo en TN
            foreach ($variantesLocales as $lv) {
                if (isset($mapeadasPorLocal[$lv['id']])) continue;
                try {
                    $tnVar = ['price' => $precio, 'stock' => max(0, (int)$lv['stock'])];
                    if ($lv['codigo_barras']) $tnVar['sku'] = $lv['codigo_barras'];
                    if ($tieneAtributos) {
                        $values = [];
                        if ($usaColor) $values[] = ['es' => !empty($lv['color']) ? $lv['color'] : 'Único'];
                        if ($usaTalle) $values[] = ['es' => !empty($lv['talle']) ? $lv['talle'] : 'Único'];
                        $tnVar['values'] = $values;
                    }
                    $nueva   = tn_request('POST', "products/{$tnProductId}/variants", $tnVar, $config);
                    $tnVarId = (int)($nueva['id'] ?? 0);
                    if ($tnVarId) {
                        $stmtInsertVar->bind_param('ii', $lv['id'], $tnVarId);
                        $stmtInsertVar->execute();
                        $variantesAgregadas++;
                    }
                } catch (Throwable $e) {
                    $errores[] = "{$prod['nombre']} agregar variante {$lv['id']}: " . $e->getMessage();
                }
            }

            // DELETE: variantes mapeadas cuya local fue desactivada o eliminada
            foreach ($variantesMapeadas as $vm) {
                if (isset($localesPorId[$vm['id_variante']])) continue;
                try {
                    tn_request('DELETE', "products/{$tnProductId}/variants/{$vm['tn_variant_id']}", [], $config);
                    $stmtDeleteVar->bind_param('i', $vm['tn_variant_id']);
                    $stmtDeleteVar->execute();
                    $variantesEliminadas++;
                } catch (Throwable $e) {
                    $errores[] = "{$prod['nombre']} eliminar variante TN {$vm['tn_variant_id']}: " . $e->getMessage();
                }
            }

            $stmtSync->bind_param('i', $tnProductId);
            $stmtSync->execute();

        } catch (Throwable $e) {
            $errores[] = "Producto {$prod['nombre']}: " . $e->getMessage();
        }
    }

    $stmtLocales->close();
    $stmtMapeadas->close();
    $stmtSync->close();
    $stmtInsertVar->close();
    $stmtDeleteVar->close();

    echo json_encode([
        'success'              => true,
        'sincronizados'        => $sincronizados,
        'variantes_agregadas'  => $variantesAgregadas,
        'variantes_eliminadas' => $variantesEliminadas,
        'errores'              => $errores,
    ]);

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
