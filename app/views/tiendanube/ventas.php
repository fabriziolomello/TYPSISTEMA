<?php
// app/views/tiendanube/ventas.php

$titulo    = "Tienda Nube — Pedidos";
$css_extra = '<link rel="stylesheet" href="/TYPSISTEMA/public/css/tiendanube.css">';

require_once __DIR__ . '/../../config/seguridad.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../controllers/tiendanube/api.php';

$db   = new Database();
$conn = $db->getConnection();

$error   = '';
$pedidos = [];
$config  = $conn->query("SELECT store_id, access_token, id_deposito FROM tiendanube_config LIMIT 1")->fetch_assoc();
$configurado = !empty($config['store_id']) && !empty($config['access_token']);

if ($configurado) {
    try {
        // Últimos 50 pedidos ordenados por fecha desc
        $pedidos = tn_request('GET', 'orders?per_page=50&sort_by=created_at&sort_direction=desc', [], $config);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

ob_start();
?>

<div class="tn-container">

    <div class="tn-header">
        <h1 class="tn-titulo">Tienda Nube — Pedidos</h1>
        <a href="/TYPSISTEMA/app/views/tiendanube/productos.php" class="btn-link">Ver productos</a>
    </div>

    <?php if (!$configurado): ?>
        <div class="tn-aviso">⚠ Tienda Nube no está configurada. <a href="/TYPSISTEMA/app/views/tiendanube/productos.php">Configurar</a></div>
    <?php elseif ($error): ?>
        <div class="tn-aviso tn-aviso--error">Error al conectar con Tienda Nube: <?= htmlspecialchars($error) ?></div>
    <?php elseif (empty($pedidos)): ?>
        <p style="color:#888;">No hay pedidos en Tienda Nube.</p>
    <?php else: ?>
        <div class="tn-tabla-wrapper">
            <table class="tn-tabla">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Fecha</th>
                        <th>Cliente</th>
                        <th>Productos</th>
                        <th>Total</th>
                        <th>Estado pago</th>
                        <th>Estado envío</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pedidos as $pedido): ?>
                        <?php
                            $cliente   = $pedido['contact_name'] ?? ($pedido['customer']['name'] ?? 'Sin nombre');
                            $total     = number_format((float)($pedido['total'] ?? 0), 2, ',', '.');
                            $fecha     = isset($pedido['created_at']) ? date('d/m/Y H:i', strtotime($pedido['created_at'])) : '-';
                            $estadoPago  = $pedido['payment_status']  ?? '-';
                            $estadoEnvio = $pedido['shipping_status'] ?? '-';
                            $items     = $pedido['products'] ?? [];
                        ?>
                        <tr>
                            <td><strong>#<?= htmlspecialchars((string)($pedido['number'] ?? $pedido['id'])) ?></strong></td>
                            <td><?= $fecha ?></td>
                            <td><?= htmlspecialchars($cliente) ?></td>
                            <td>
                                <?php foreach ($items as $item): ?>
                                    <div style="font-size:13px;"><?= htmlspecialchars($item['name'] ?? '') ?> × <?= (int)($item['quantity'] ?? 1) ?></div>
                                <?php endforeach; ?>
                            </td>
                            <td class="col-monto">$<?= $total ?></td>
                            <td><span class="tn-badge tn-badge--<?= strtolower($estadoPago) ?>"><?= htmlspecialchars($estadoPago) ?></span></td>
                            <td><span class="tn-badge tn-badge--<?= strtolower($estadoEnvio) ?>"><?= htmlspecialchars($estadoEnvio) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

</div>

<?php
$contenido = ob_get_clean();
require __DIR__ . '/../layouts/main.php';
