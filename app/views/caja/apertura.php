<?php
// app/views/caja/apertura.php

$titulo   = "Apertura de caja";
$css_extra = '<link rel="stylesheet" href="/TYPSISTEMA/public/css/caja.css">';

require_once __DIR__ . '/../../config/seguridad.php';
require_once __DIR__ . '/../../config/database.php';

$db   = new Database();
$conn = $db->getConnection();

// ¿Hay una caja abierta?
$resAbierta = $conn->query("SELECT id FROM caja WHERE estado = 'ABIERTA' LIMIT 1");
$cajaAbierta = $resAbierta->num_rows > 0;

// Saldo inicial de efectivo = total_real de efectivo de la última caja cerrada
$saldoEfectivo = 0;

if (!$cajaAbierta) {
    $resUltima = $conn->query("
        SELECT ccd.total_real
        FROM caja c
        INNER JOIN caja_cierre_detalle ccd ON ccd.id_caja = c.id
        WHERE c.estado = 'CERRADA'
          AND ccd.medio_pago = 'EFECTIVO'
        ORDER BY c.fecha DESC, c.id DESC
        LIMIT 1
    ");
    if ($resUltima && $resUltima->num_rows > 0) {
        $saldoEfectivo = (float)$resUltima->fetch_assoc()['total_real'];
    }
}

$hoy  = date('Y-m-d');
$BASE = "/TYPSISTEMA";

ob_start();
?>

<div class="caja-container">
    <h1 class="caja-titulo">Apertura de caja</h1>

    <?php if ($cajaAbierta): ?>

        <div class="caja-aviso caja-aviso--alerta">
            Ya hay una caja abierta. Cerrala antes de abrir una nueva.
        </div>
        <div class="caja-acciones">
            <a href="<?= $BASE ?>/app/views/caja/cierre.php" class="btn-primary">Ir al cierre</a>
        </div>

    <?php else: ?>

        <div class="caja-card">
            <p class="caja-subtitulo">Saldo inicial por medio de pago</p>

            <table class="caja-tabla">
                <thead>
                    <tr>
                        <th>Medio de pago</th>
                        <th>Saldo inicial</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Efectivo</td>
                        <td>$<?= number_format($saldoEfectivo, 2, ',', '.') ?></td>
                    </tr>
                    <tr>
                        <td>Tarjeta</td>
                        <td>$0,00</td>
                    </tr>
                    <tr>
                        <td>Transferencia</td>
                        <td>$0,00</td>
                    </tr>
                    <tr>
                        <td>QR</td>
                        <td>$0,00</td>
                    </tr>
                </tbody>
            </table>

            <div class="caja-field">
                <label>Observaciones (opcional)</label>
                <textarea id="caja-obs" rows="2" placeholder="Ej: apertura normal..."></textarea>
            </div>
        </div>

        <div class="caja-acciones">
            <button type="button" class="btn-primary" id="btn-abrir">Confirmar apertura</button>
        </div>

        <script>
        document.getElementById('btn-abrir').addEventListener('click', () => {
            if (!confirm('¿Confirmar apertura de caja?')) return;

            fetch('/TYPSISTEMA/app/controllers/caja/abrir.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    saldo_inicial: <?= $saldoEfectivo ?>,
                    observaciones: document.getElementById('caja-obs').value
                })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    window.location.href = '/TYPSISTEMA/app/views/dashboard/index.php';
                } else {
                    alert('Error: ' + data.error);
                }
            });
        });
        </script>

    <?php endif; ?>
</div>

<?php
$contenido = ob_get_clean();
require __DIR__ . '/../layouts/main.php';
