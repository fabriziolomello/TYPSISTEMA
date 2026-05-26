<?php
// app/views/tiendanube/ventas.php

$titulo    = "Tienda Nube — Pedidos";
$css_extra = '<link rel="stylesheet" href="/TYPSISTEMA/public/css/tiendanube.css">';

require_once __DIR__ . '/../../config/seguridad.php';
require_once __DIR__ . '/../../config/database.php';

$_esAdmin = ($_SESSION['usuario_rol'] ?? '') === 'ADMIN';
$_dep     = (int)($_SESSION['usuario_deposito'] ?? 0);
if (!$_esAdmin && $_dep !== 1) {
    header('Location: /TYPSISTEMA/app/views/dashboard/index.php');
    exit;
}
require_once __DIR__ . '/../../controllers/tiendanube/api.php';

$db   = new Database();
$conn = $db->getConnection();

$error   = '';
$pedidos = [];
$config  = $conn->query("SELECT store_id, access_token, id_deposito FROM tiendanube_config LIMIT 1")->fetch_assoc();
$configurado = !empty($config['store_id']) && !empty($config['access_token']);

if ($configurado) {
    try {
        $pedidos = tn_request('GET', 'orders?per_page=50&sort_by=created_at&sort_direction=desc', [], $config);
    } catch (Throwable $e) {
        if (str_contains($e->getMessage(), 'Last page is 0')) {
            $pedidos = [];
        } else {
            $error = $e->getMessage();
        }
    }
}

// Pedidos ya registrados como ventas
$yaRegistrados = [];
if (!empty($pedidos)) {
    $ids = implode(',', array_map(fn($p) => (int)$p['id'], $pedidos));
    $rows = $conn->query("SELECT tn_order_id, id_venta FROM tiendanube_pedido WHERE tn_order_id IN ($ids)")->fetch_all(MYSQLI_ASSOC);
    foreach ($rows as $r) {
        $yaRegistrados[(int)$r['tn_order_id']] = (int)$r['id_venta'];
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

        <div id="tn-venta-msg" style="display:none;" class="tn-aviso"></div>

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
                        <th style="text-align:center;">Venta</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pedidos as $pedido): ?>
                        <?php
                            $tnId        = (int)$pedido['id'];
                            $cliente     = $pedido['contact_name'] ?? ($pedido['customer']['name'] ?? 'Sin nombre');
                            $total       = number_format((float)($pedido['total'] ?? 0), 2, ',', '.');
                            $fecha       = isset($pedido['created_at']) ? date('d/m/Y H:i', strtotime($pedido['created_at'])) : '-';
                            $estadoPago  = $pedido['payment_status']  ?? '-';
                            $estadoEnvio = $pedido['shipping_status'] ?? '-';
                            $items       = $pedido['products'] ?? [];
                            $esPagado    = in_array(strtolower($estadoPago), ['paid', 'authorized']);
                            $idVentaReg  = $yaRegistrados[$tnId] ?? null;
                        ?>
                        <tr>
                            <td><strong>#<?= htmlspecialchars((string)($pedido['number'] ?? $tnId)) ?></strong></td>
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
                            <td style="text-align:center;white-space:nowrap;">
                                <?php if ($idVentaReg): ?>
                                    <span class="tn-badge tn-badge--publicado" title="Venta #<?= $idVentaReg ?>">Registrada</span>
                                <?php elseif ($esPagado): ?>
                                    <button type="button" class="btn-registrar-venta btn-primary"
                                            data-id="<?= $tnId ?>"
                                            style="padding:4px 10px;font-size:12px;">
                                        Registrar venta
                                    </button>
                                <?php else: ?>
                                    <span style="color:#aaa;font-size:12px;">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

</div>

<script>
document.querySelectorAll('.btn-registrar-venta').forEach(btn => {
    btn.addEventListener('click', function () {
        const tnId = parseInt(this.dataset.id);
        if (!confirm(`¿Registrar el pedido TN #${tnId} como venta en el sistema?`)) return;

        this.disabled    = true;
        this.textContent = 'Registrando...';
        const boton      = this;

        fetch('/TYPSISTEMA/app/controllers/tiendanube/registrar_venta.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ tn_order_id: tnId })
        })
        .then(r => r.json())
        .then(d => {
            const msgEl = document.getElementById('tn-venta-msg');
            msgEl.style.display = '';

            if (!d.success) {
                msgEl.className   = 'tn-aviso tn-aviso--error';
                msgEl.textContent = 'Error: ' + d.error;
                boton.disabled    = false;
                boton.textContent = 'Registrar venta';
                return;
            }

            msgEl.className   = 'tn-aviso';
            msgEl.textContent = `✅ Venta #${d.id_venta} registrada correctamente.`;
            if (d.aviso) msgEl.textContent += ' ⚠ ' + d.aviso;

            // Reemplazar botón por badge
            const td       = boton.closest('td');
            td.innerHTML   = `<span class="tn-badge tn-badge--publicado" title="Venta #${d.id_venta}">Registrada</span>`;
        })
        .catch(() => {
            boton.disabled    = false;
            boton.textContent = 'Registrar venta';
        });
    });
});
</script>

<?php
$contenido = ob_get_clean();
require __DIR__ . '/../layouts/main.php';
