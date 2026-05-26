<?php
require_once __DIR__ . '/../../config/seguridad.php';
require_once __DIR__ . '/../../config/database.php';

if (($_SESSION['usuario_rol'] ?? '') !== 'ADMIN') {
    header('Location: ' . BASE_URL . 'app/views/dashboard/index.php');
    exit;
}

$titulo = "Limpieza de datos";

$db   = new Database();
$conn = $db->getConnection();

$tablas = [
    'ventas'               => 'Ventas',
    'detalle_ventas'       => 'Detalle de ventas',
    'caja'                 => 'Sesiones de caja',
    'caja_cierre_detalle'  => 'Cierres de caja',
    'movimiento_caja'      => 'Movimientos de caja',
    'movimiento_stock'     => 'Movimientos de stock',
    'movimiento_manual'    => 'Movimientos manuales',
    'stock_deposito'       => 'Stock por depósito',
    'producto_variante'    => 'Variantes de productos',
    'lista_precio'         => 'Lista de precios',
    'productos'            => 'Productos',
    'clientes'             => 'Clientes',
    'proveedor'            => 'Proveedores',
    'categoria'            => 'Categorías',
    'subcategoria'         => 'Subcategorías',
    'tiendanube_pedido'    => 'Pedidos TN registrados',
    'tiendanube_variante'  => 'Variantes TN',
    'tiendanube_producto'  => 'Productos TN',
    'tiendanube_config'    => 'Config TiendaNube',
];

$conteos = [];
$total   = 0;
foreach ($tablas as $tabla => $label) {
    $n = (int)$conn->query("SELECT COUNT(*) FROM `$tabla`")->fetch_row()[0];
    $conteos[$tabla] = $n;
    $total += $n;
}

ob_start();
?>

<div style="max-width:680px;margin:0 auto;">

    <h1 style="margin-bottom:4px;">Limpieza de datos de prueba</h1>
    <p style="color:#888;margin-bottom:24px;font-size:14px;">
        Solo se conservan <strong>usuarios</strong> y <strong>depósitos</strong>. Todo lo demás se elimina.
    </p>

    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;margin-bottom:24px;">
        <table style="width:100%;border-collapse:collapse;font-size:14px;">
            <thead>
                <tr style="background:#f8fafc;">
                    <th style="padding:10px 16px;text-align:left;border-bottom:1px solid #e2e8f0;">Tabla</th>
                    <th style="padding:10px 16px;text-align:right;border-bottom:1px solid #e2e8f0;">Registros a borrar</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tablas as $tabla => $label): ?>
                <tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:8px 16px;color:#444;"><?= $label ?></td>
                    <td style="padding:8px 16px;text-align:right;font-variant-numeric:tabular-nums;
                        color:<?= $conteos[$tabla] > 0 ? '#b45309' : '#aaa' ?>;">
                        <?= number_format($conteos[$tabla]) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background:#f8fafc;">
                    <td style="padding:10px 16px;font-weight:700;">Total</td>
                    <td style="padding:10px 16px;text-align:right;font-weight:700;color:<?= $total > 0 ? '#dc2626' : '#aaa' ?>;">
                        <?= number_format($total) ?>
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>

    <?php if ($total === 0): ?>
        <div style="padding:16px;background:#d4edda;border-radius:6px;color:#155724;font-size:14px;">
            ✅ El sistema ya está limpio. No hay datos de prueba.
        </div>
    <?php else: ?>
        <div style="padding:14px 16px;background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;margin-bottom:20px;font-size:14px;color:#7f1d1d;">
            ⚠ Esta acción es <strong>irreversible</strong>. Asegurate de tener un backup antes de continuar.
        </div>

        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:20px;">
            <p style="font-size:14px;margin-bottom:12px;">
                Escribí <strong>CONFIRMAR</strong> para habilitar el botón:
            </p>
            <input type="text" id="input-confirmar" placeholder="CONFIRMAR"
                style="padding:8px 12px;border:1px solid #ccc;border-radius:4px;font-size:14px;width:200px;margin-bottom:16px;">
            <br>
            <button type="button" id="btn-limpiar" disabled
                style="padding:10px 24px;background:#dc2626;color:#fff;border:none;border-radius:4px;font-size:14px;cursor:not-allowed;opacity:0.5;">
                Limpiar sistema
            </button>
            <span id="limpiar-msg" style="margin-left:12px;font-size:13px;"></span>
        </div>
    <?php endif; ?>

</div>

<script>
const input = document.getElementById('input-confirmar');
const btn   = document.getElementById('btn-limpiar');

input?.addEventListener('input', () => {
    const ok = input.value.trim() === 'CONFIRMAR';
    btn.disabled    = !ok;
    btn.style.opacity  = ok ? '1' : '0.5';
    btn.style.cursor   = ok ? 'pointer' : 'not-allowed';
});

btn?.addEventListener('click', () => {
    if (btn.disabled) return;
    btn.disabled    = true;
    btn.textContent = 'Limpiando...';
    document.getElementById('limpiar-msg').textContent = '';

    fetch('<?= BASE_URL ?>app/controllers/config/limpiar.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ confirmar: true })
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            window.location.reload();
        } else {
            document.getElementById('limpiar-msg').textContent = 'Error: ' + d.error;
            btn.disabled    = false;
            btn.textContent = 'Limpiar sistema';
        }
    });
});
</script>

<?php
$contenido = ob_get_clean();
require __DIR__ . '/../layouts/main.php';
