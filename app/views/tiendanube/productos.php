<?php
// app/views/tiendanube/productos.php

require_once __DIR__ . '/../../config/seguridad.php';
require_once __DIR__ . '/../../config/database.php';

$titulo    = "Tienda Nube — Productos";
$css_extra = '<link rel="stylesheet" href="' . BASE_URL . 'public/css/tiendanube.css">';


$_esAdmin = ($_SESSION['usuario_rol'] ?? '') === 'ADMIN';
$_dep     = (int)($_SESSION['usuario_deposito'] ?? 0);
if (!$_esAdmin && $_dep !== 1) {
    header('Location: ' . BASE_URL . 'app/views/dashboard/index.php');
    exit;
}

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
        p.sincronizar_tn,
        tp.tn_product_id,
        tp.sincronizado_at,
        COUNT(pv.id) AS total_variantes,
        MAX(CASE WHEN lp.tipo_lista = 'MINORISTA' THEN lp.precio END) AS precio_minorista
    FROM productos p
    LEFT JOIN producto_variante pv ON pv.id_producto = p.id AND pv.activo = 1
    LEFT JOIN lista_precio lp ON lp.id_producto = p.id
    LEFT JOIN tiendanube_producto tp ON tp.id_producto = p.id
    WHERE p.activo = 1
    GROUP BY p.id, p.nombre, p.activo, p.sincronizar_tn, tp.tn_product_id, tp.sincronizado_at
    ORDER BY tp.tn_product_id IS NULL DESC, p.nombre ASC
")->fetch_all(MYSQLI_ASSOC);

$totalPublicados  = count(array_filter($productos, fn($p) => $p['tn_product_id']));
$totalSinPublicar = count(array_filter($productos, fn($p) => !$p['tn_product_id'] && $p['sincronizar_tn']));
$totalExcluidos   = count(array_filter($productos, fn($p) => !$p['sincronizar_tn']));

ob_start();
?>

<div class="tn-container">

    <div class="tn-header">
        <h1 class="tn-titulo">Tienda Nube — Productos</h1>
        <a href="<?= BASE_URL ?>app/views/tiendanube/ventas.php" class="btn-link">Ver pedidos</a>
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
            <?php if ($totalExcluidos > 0): ?>
            <span class="tn-stat tn-stat--excluido"><strong><?= $totalExcluidos ?></strong> excluidos</span>
            <?php endif; ?>
        </div>
        <div style="display:flex;gap:10px;">
            <button type="button" class="btn-primary" id="btn-publicar-todo" <?= !$configurado ? 'disabled title="Configurá la conexión primero"' : '' ?>>
                Productos sin publicar (<?= $totalSinPublicar ?>)
            </button>
        </div>
    </div>

    <!-- PROGRESS BAR (oculta) -->
    <div id="tn-progress" style="display:none;" class="tn-progress-wrap">
        <div class="tn-progress-bar"><div class="tn-progress-fill" id="tn-progress-fill"></div></div>
        <span id="tn-progress-msg">Procesando...</span>
    </div>

    <!-- BUSCADOR -->
    <div style="margin-bottom:12px;">
        <input type="text" id="tn-buscador" placeholder="Buscar producto..." autocomplete="off"
               style="width:100%;max-width:400px;padding:8px 12px;border:1px solid #ccc;border-radius:6px;font-size:14px;">
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
                    <th style="text-align:center;">Sincronizar</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($productos as $p): ?>
                    <tr class="<?= !$p['sincronizar_tn'] ? 'tn-fila-excluida' : '' ?>">
                        <td><?= htmlspecialchars($p['nombre']) ?></td>
                        <td style="text-align:center;"><?= (int)$p['total_variantes'] ?></td>
                        <td class="col-monto"><?= $p['precio_minorista'] ? '$' . number_format((float)$p['precio_minorista'], 2, ',', '.') : '-' ?></td>
                        <td>
                            <?php if (!$p['sincronizar_tn']): ?>
                                <span class="tn-badge tn-badge--excluido">Excluido</span>
                            <?php elseif ($p['tn_product_id']): ?>
                                <span class="tn-badge tn-badge--publicado">Publicado</span>
                            <?php else: ?>
                                <span class="tn-badge tn-badge--pendiente">Sin publicar</span>
                            <?php endif; ?>
                        </td>
                        <td style="color:#888;font-size:13px;">
                            <?= $p['sincronizado_at'] ? date('d/m/Y H:i', strtotime($p['sincronizado_at'])) : '-' ?>
                        </td>
                        <td style="text-align:center;display:flex;gap:8px;align-items:center;justify-content:center;">
                            <label class="tn-toggle" title="<?= $p['sincronizar_tn'] ? 'Desactivar sincronización' : 'Activar sincronización' ?>">
                                <input type="checkbox" class="tn-toggle-input" data-id="<?= $p['id'] ?>" <?= $p['sincronizar_tn'] ? 'checked' : '' ?>>
                                <span class="tn-toggle-slider"></span>
                            </label>
                            <?php if ($p['tn_product_id']): ?>
                            <button type="button" class="btn-republicar" data-id="<?= $p['id'] ?>" title="Borrar de TN y volver a publicar con datos actuales" style="font-size:12px;padding:2px 8px;cursor:pointer;background:#f0f0f0;border:1px solid #ccc;border-radius:4px;white-space:nowrap;">
                                Republicar
                            </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
// Buscador
document.getElementById('tn-buscador').addEventListener('input', function () {
    const q = this.value.toLowerCase().trim();
    document.querySelectorAll('.tn-tabla tbody tr').forEach(tr => {
        const nombre = tr.querySelector('td')?.textContent.toLowerCase() ?? '';
        tr.style.display = nombre.includes(q) ? '' : 'none';
    });
});

// Toggle sincronizar_tn
document.querySelectorAll('.tn-toggle-input').forEach(cb => {
    cb.addEventListener('change', function() {
        const id      = parseInt(this.dataset.id);
        const activo  = this.checked;
        const fila    = this.closest('tr');
        this.disabled = true;

        fetch('<?= BASE_URL ?>app/controllers/tiendanube/toggle_sync.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id, sincronizar_tn: activo })
        })
        .then(r => r.json())
        .then(d => {
            if (!d.success) { alert('Error: ' + d.error); this.checked = !activo; }
            else            { window.location.reload(); }
        })
        .catch(() => { this.checked = !activo; })
        .finally(() => { this.disabled = false; });
    });
});

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

    fetch('<?= BASE_URL ?>app/controllers/tiendanube/guardar_config.php', {
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

// Publicar todo (en lotes automáticos)
document.getElementById('btn-publicar-todo')?.addEventListener('click', async () => {
    if (!confirm('¿Publicar todos los productos sin publicar en Tienda Nube?')) return;

    const progress = document.getElementById('tn-progress');
    const fill     = document.getElementById('tn-progress-fill');
    const msg      = document.getElementById('tn-progress-msg');
    const btnPub   = document.getElementById('btn-publicar-todo');

    progress.style.display = '';
    fill.style.width = '5%';
    msg.textContent  = 'Iniciando publicación...';
    btnPub.disabled  = true;

    const totalInicial = <?= $totalSinPublicar ?>;
    let publicadosTotal = 0;
    let erroresTotal    = [];

    while (true) {
        try {
            const r = await fetch('<?= BASE_URL ?>app/controllers/tiendanube/publicar.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' }
            });
            const d = await r.json();

            if (!d.success) {
                msg.textContent = 'Error: ' + d.error;
                btnPub.disabled = false;
                break;
            }

            publicadosTotal += d.publicados ?? 0;
            if (d.errores) erroresTotal = erroresTotal.concat(d.errores);

            // Si no se publicó nada en este lote pero hay más, evitar loop infinito
            if ((d.publicados ?? 0) === 0 && d.hay_mas) {
                fill.style.width = '100%';
                msg.textContent = `⚠ No se pudo continuar. Quedan ${d.restantes} producto(s) con errores.`;
                if (erroresTotal.length) msg.textContent += ' | ' + erroresTotal.join(' | ');
                btnPub.disabled = false;
                break;
            }

            const restantes = d.restantes ?? 0;
            const progreso  = totalInicial > 0
                ? Math.round(((totalInicial - restantes) / totalInicial) * 100)
                : 100;

            fill.style.width = progreso + '%';
            msg.textContent  = `Publicando... ${publicadosTotal} publicados, ${restantes} restantes`;

            if (!d.hay_mas) {
                fill.style.width = '100%';
                let resumen = `✅ ${publicadosTotal} producto(s) publicados.`;
                if (erroresTotal.length) resumen += ' Con errores: ' + erroresTotal.join(' | ');
                msg.textContent = resumen;
                setTimeout(() => window.location.reload(), 2000);
                break;
            }

        } catch (e) {
            msg.textContent = 'Error de red. Reintentando...';
            await new Promise(r => setTimeout(r, 3000));
        }
    }
});

// Republicar producto individual
document.querySelectorAll('.btn-republicar').forEach(btn => {
    btn.addEventListener('click', async function () {
        if (!confirm('¿Republicar este producto? Se borrará de Tienda Nube y se volverá a publicar con los datos actuales.')) return;
        const id = parseInt(this.dataset.id);
        this.disabled = true;
        this.textContent = '...';
        try {
            const r = await fetch('<?= BASE_URL ?>app/controllers/tiendanube/republicar.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id_producto: id })
            });
            const d = await r.json();
            if (d.success) { window.location.reload(); }
            else { alert('Error: ' + d.error); this.disabled = false; this.textContent = 'Republicar'; }
        } catch (e) {
            alert('Error de red.');
            this.disabled = false;
            this.textContent = 'Republicar';
        }
    });
});

</script>

<?php
$contenido = ob_get_clean();
require __DIR__ . '/../layouts/main.php';
