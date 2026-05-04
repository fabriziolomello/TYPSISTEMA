<?php
// app/views/tiendanube/productos.php

$titulo    = "Tienda Nube — Productos";
$css_extra = '<link rel="stylesheet" href="/TYPSISTEMA/public/css/tiendanube.css">';

require_once __DIR__ . '/../../config/seguridad.php';
require_once __DIR__ . '/../../config/database.php';

$db   = new Database();
$conn = $db->getConnection();

$esAdmin   = ($_SESSION['usuario_rol'] ?? '') === 'ADMIN';
$depositos = $conn->query("SELECT id, nombre FROM deposito ORDER BY id ASC")->fetch_all(MYSQLI_ASSOC);

// Config actual
$config = $conn->query("SELECT store_id, access_token, id_deposito FROM tiendanube_config LIMIT 1")->fetch_assoc();
$configurado = !empty($config['store_id']) && !empty($config['access_token']);

// Productos del sistema con estado TN
$productos = $conn->query("
    SELECT
        p.id,
        p.nombre,
        p.activo,
        tp.tn_product_id,
        tp.sincronizado_at,
        COUNT(pv.id) AS total_variantes,
        MAX(CASE WHEN lp.tipo_lista = 'MINORISTA' THEN lp.precio END) AS precio_minorista
    FROM productos p
    LEFT JOIN producto_variante pv ON pv.id_producto = p.id AND pv.activo = 1
    LEFT JOIN lista_precio lp ON lp.id_producto = p.id
    LEFT JOIN tiendanube_producto tp ON tp.id_producto = p.id
    WHERE p.activo = 1
    GROUP BY p.id, p.nombre, p.activo, tp.tn_product_id, tp.sincronizado_at
    ORDER BY tp.tn_product_id IS NULL DESC, p.nombre ASC
")->fetch_all(MYSQLI_ASSOC);

$totalPublicados  = count(array_filter($productos, fn($p) => $p['tn_product_id']));
$totalSinPublicar = count($productos) - $totalPublicados;

ob_start();
?>

<div class="tn-container">

    <div class="tn-header">
        <h1 class="tn-titulo">Tienda Nube — Productos</h1>
        <a href="/TYPSISTEMA/app/views/tiendanube/ventas.php" class="btn-link">Ver pedidos</a>
    </div>

    <?php if ($esAdmin): ?>
    <!-- CONFIGURACIÓN -->
    <div class="tn-card" id="tn-config-card">
        <div class="tn-card-header" id="tn-config-toggle" style="cursor:pointer;">
            <strong>⚙ Configuración de conexión</strong>
            <span style="color:#888;font-size:13px;"><?= $configurado ? '✅ Configurado' : '⚠ Sin configurar' ?></span>
        </div>
        <div class="tn-config-body" id="tn-config-body" style="<?= $configurado ? 'display:none' : '' ?>">
            <div class="tn-config-grid">
                <div class="modal-field">
                    <label>Store ID</label>
                    <input type="text" id="tn-store-id" value="<?= htmlspecialchars($config['store_id'] ?? '') ?>" placeholder="Ej: 123456">
                </div>
                <div class="modal-field">
                    <label>Access Token</label>
                    <input type="text" id="tn-access-token" value="<?= htmlspecialchars($config['access_token'] ?? '') ?>" placeholder="tu_access_token">
                </div>
                <div class="modal-field">
                    <label>Depósito para stock</label>
                    <select id="tn-deposito">
                        <?php foreach ($depositos as $d): ?>
                            <option value="<?= $d['id'] ?>" <?= (int)($config['id_deposito'] ?? 1) === (int)$d['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($d['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="display:flex;align-items:flex-end;">
                    <button type="button" class="btn-primary" id="tn-guardar-config">Guardar</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ACCIONES -->
    <div class="tn-acciones">
        <div class="tn-stats">
            <span class="tn-stat"><strong><?= $totalPublicados ?></strong> publicados</span>
            <span class="tn-stat tn-stat--pendiente"><strong><?= $totalSinPublicar ?></strong> sin publicar</span>
        </div>
        <?php if ($esAdmin): ?>
        <div style="display:flex;gap:10px;">
            <button type="button" class="btn-primary" id="btn-publicar-todo" <?= !$configurado ? 'disabled title="Configurá la conexión primero"' : '' ?>>
                Publicar sin publicar (<?= $totalSinPublicar ?>)
            </button>
            <button type="button" class="btn-link" id="btn-sincronizar" <?= !$configurado ? 'disabled title="Configurá la conexión primero"' : '' ?>>
                Sincronizar stock y precios
            </button>
        </div>
        <?php endif; ?>
    </div>

    <!-- PROGRESS BAR (oculta) -->
    <div id="tn-progress" style="display:none;" class="tn-progress-wrap">
        <div class="tn-progress-bar"><div class="tn-progress-fill" id="tn-progress-fill"></div></div>
        <span id="tn-progress-msg">Procesando...</span>
    </div>

    <!-- TABLA -->
    <div class="tn-tabla-wrapper">
        <table class="tn-tabla">
            <thead>
                <tr>
                    <th>Producto</th>
                    <th>Variantes</th>
                    <th>Precio minorista</th>
                    <th>Estado TN</th>
                    <th>Última sincronización</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($productos as $p): ?>
                    <tr>
                        <td><?= htmlspecialchars($p['nombre']) ?></td>
                        <td style="text-align:center;"><?= (int)$p['total_variantes'] ?></td>
                        <td class="col-monto"><?= $p['precio_minorista'] ? '$' . number_format((float)$p['precio_minorista'], 2, ',', '.') : '-' ?></td>
                        <td>
                            <?php if ($p['tn_product_id']): ?>
                                <span class="tn-badge tn-badge--publicado">Publicado</span>
                            <?php else: ?>
                                <span class="tn-badge tn-badge--pendiente">Sin publicar</span>
                            <?php endif; ?>
                        </td>
                        <td style="color:#888;font-size:13px;">
                            <?= $p['sincronizado_at'] ? date('d/m/Y H:i', strtotime($p['sincronizado_at'])) : '-' ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
// Toggle config
document.getElementById('tn-config-toggle')?.addEventListener('click', () => {
    const body = document.getElementById('tn-config-body');
    body.style.display = body.style.display === 'none' ? '' : 'none';
});

// Guardar config
document.getElementById('tn-guardar-config')?.addEventListener('click', () => {
    const store_id     = document.getElementById('tn-store-id').value.trim();
    const access_token = document.getElementById('tn-access-token').value.trim();
    const id_deposito  = document.getElementById('tn-deposito').value;

    if (!store_id || !access_token) { alert('Completá Store ID y Access Token.'); return; }

    fetch('/TYPSISTEMA/app/controllers/tiendanube/guardar_config.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ store_id, access_token, id_deposito })
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) window.location.reload();
        else alert('Error: ' + d.error);
    });
});

// Publicar todo
document.getElementById('btn-publicar-todo')?.addEventListener('click', () => {
    if (!confirm('¿Publicar todos los productos sin publicar en Tienda Nube?')) return;
    ejecutarAccion('/TYPSISTEMA/app/controllers/tiendanube/publicar.php', 'Publicando productos...');
});

// Sincronizar
document.getElementById('btn-sincronizar')?.addEventListener('click', () => {
    if (!confirm('¿Actualizar stock y precios en Tienda Nube?')) return;
    ejecutarAccion('/TYPSISTEMA/app/controllers/tiendanube/sincronizar.php', 'Sincronizando...');
});

function ejecutarAccion(url, msgInicio) {
    const progress = document.getElementById('tn-progress');
    const fill     = document.getElementById('tn-progress-fill');
    const msg      = document.getElementById('tn-progress-msg');

    progress.style.display = '';
    fill.style.width = '30%';
    msg.textContent  = msgInicio;

    document.getElementById('btn-publicar-todo').disabled = true;
    document.getElementById('btn-sincronizar').disabled   = true;

    fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' } })
        .then(r => r.json())
        .then(d => {
            fill.style.width = '100%';
            if (!d.success) {
                msg.textContent = 'Error: ' + d.error;
                return;
            }
            const cant = d.publicados ?? d.sincronizados ?? 0;
            msg.textContent = `✅ ${cant} producto(s) procesados.`;
            if (d.errores && d.errores.length) {
                msg.textContent += ' Con errores: ' + d.errores.join(' | ');
            }
            setTimeout(() => window.location.reload(), 2000);
        });
}
</script>

<?php
$contenido = ob_get_clean();
require __DIR__ . '/../layouts/main.php';
