<?php
// app/controllers/tiendanube/api.php
// Helper para llamadas a la API de Tienda Nube

function tn_request(string $method, string $endpoint, array $body = [], array $config = []): array {
    $storeId     = $config['store_id'];
    $accessToken = $config['access_token'];

    $url = "https://api.tiendanube.com/v1/{$storeId}/{$endpoint}";

    $headers = [
        "Authentication: bearer {$accessToken}",
        "User-Agent: TYPSistema (lomellofabrizio@gmail.com)",
        "Content-Type: application/json",
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    } elseif ($method === 'PUT') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    } elseif ($method === 'DELETE') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) throw new Exception("cURL error: $curlError");

    $data = json_decode($response, true);

    if ($httpCode >= 400) {
        $msg = $data['description'] ?? $data['message'] ?? $data['error'] ?? "HTTP $httpCode";
        // Incluir detalle de validación si existe (errores de campo específicos)
        if (!empty($data['description']) && is_array($data['description'])) {
            $msg = json_encode($data['description']);
        } elseif (!empty($data['validation_errors'])) {
            $msg = json_encode($data['validation_errors']);
        }
        if ($msg === "HTTP $httpCode") {
            $msg .= ' | ' . substr($response, 0, 300);
        }
        throw new Exception("TN API error: $msg");
    }

    return $data ?? [];
}

function tn_get_config($conn): array {
    $row = $conn->query("SELECT store_id, access_token, id_deposito FROM tiendanube_config LIMIT 1")->fetch_assoc();
    if (!$row || !$row['store_id'] || !$row['access_token']) {
        throw new Exception('Tienda Nube no configurada. Ingresá el Store ID y Access Token.');
    }
    return $row;
}

/**
 * Sincroniza un producto con TiendaNube: actualiza precio/stock en variantes mapeadas,
 * agrega variantes nuevas y elimina las que fueron desactivadas.
 * Solo actúa si el producto está publicado en TN. Lanza excepción ante errores.
 */
function tn_sincronizar_producto($conn, array $config, int $idProducto): void
{
    $tp = $conn->query("
        SELECT tn_product_id FROM tiendanube_producto WHERE id_producto = $idProducto LIMIT 1
    ")->fetch_assoc();
    if (!$tp) return;

    $tnProductId = (int)$tp['tn_product_id'];
    $idDeposito  = (int)$config['id_deposito'];

    $lpRow  = $conn->query("
        SELECT precio FROM lista_precio
        WHERE id_producto = $idProducto AND tipo_lista = 'MINORISTA' LIMIT 1
    ")->fetch_assoc();
    $precio = number_format((float)($lpRow['precio'] ?? 0), 2, '.', '');

    $stmtLoc = $conn->prepare("
        SELECT pv.id, pv.color, pv.talle, pv.codigo_barras,
               COALESCE(sd.stock_actual, 0) AS stock
        FROM producto_variante pv
        LEFT JOIN stock_deposito sd ON sd.id_variante = pv.id AND sd.id_deposito = ?
        WHERE pv.id_producto = ? AND pv.activo = 1
    ");
    $stmtLoc->bind_param('ii', $idDeposito, $idProducto);
    $stmtLoc->execute();
    $variantesLocales = $stmtLoc->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmtLoc->close();

    $mapeadas = $conn->query("
        SELECT tv.id_variante, tv.tn_variant_id
        FROM tiendanube_variante tv
        INNER JOIN producto_variante pv ON pv.id = tv.id_variante
        WHERE pv.id_producto = $idProducto
    ")->fetch_all(MYSQLI_ASSOC);

    $mapeadasPorLocal = array_column($mapeadas, 'tn_variant_id', 'id_variante');
    $localesPorId     = array_column($variantesLocales, null, 'id');

    $usaColor       = !empty(array_filter(array_column($variantesLocales, 'color')));
    $usaTalle       = !empty(array_filter(array_column($variantesLocales, 'talle')));
    $tieneAtributos = $usaColor || $usaTalle;

    foreach ($mapeadas as $vm) {
        if (!isset($localesPorId[$vm['id_variante']])) continue;
        $lv = $localesPorId[$vm['id_variante']];
        tn_request('PUT',
            "products/{$tnProductId}/variants/{$vm['tn_variant_id']}",
            ['stock' => max(0, (int)$lv['stock']), 'price' => $precio],
            $config
        );
    }

    foreach ($variantesLocales as $lv) {
        if (isset($mapeadasPorLocal[$lv['id']])) continue;
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
            $idLv = (int)$lv['id'];
            $conn->query("INSERT IGNORE INTO tiendanube_variante (id_variante, tn_variant_id) VALUES ($idLv, $tnVarId)");
        }
    }

    foreach ($mapeadas as $vm) {
        if (isset($localesPorId[$vm['id_variante']])) continue;
        tn_request('DELETE', "products/{$tnProductId}/variants/{$vm['tn_variant_id']}", [], $config);
        $tnVid = (int)$vm['tn_variant_id'];
        $conn->query("DELETE FROM tiendanube_variante WHERE tn_variant_id = $tnVid");
    }

    $conn->query("UPDATE tiendanube_producto SET sincronizado_at = NOW() WHERE tn_product_id = $tnProductId");
}
