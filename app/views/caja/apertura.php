<?php
// app/views/caja/apertura.php

$titulo   = "Apertura de caja";
$css_extra = '<link rel="stylesheet" href="' . BASE_URL . 'public/css/caja.css">';

require_once __DIR__ . '/../../config/seguridad.php';
require_once __DIR__ . '/../../config/database.php';

$db   = new Database();
$conn = $db->getConnection();

$idSucursal = (int)($_SESSION['usuario_deposito'] ?? 1);

// ¿Hay una caja abierta para esta sucursal?
$stmtAbierta = $conn->prepare("SELECT id FROM caja WHERE estado = 'ABIERTA' AND id_sucursal = ? LIMIT 1");
$stmtAbierta->bind_param('i', $idSucursal);
$stmtAbierta->execute();
$resAbierta  = $stmtAbierta->get_result();
$cajaAbierta = $resAbierta->num_rows > 0;
$stmtAbierta->close();

// Saldo inicial de efectivo = total_real de efectivo de la última caja cerrada de esta sucursal
$saldoEfectivo = 0;

if (!$cajaAbierta) {
    $stmtUltima = $conn->prepare("
        SELECT ccd.total_real
        FROM caja c
        INNER JOIN caja_cierre_detalle ccd ON ccd.id_caja = c.id
        WHERE c.estado = 'CERRADA'
          AND c.id_sucursal = ?
          AND ccd.medio_pago = 'EFECTIVO'
        ORDER BY c.fecha DESC, c.id DESC
        LIMIT 1
    ");
    $stmtUltima->bind_param('i', $idSucursal);
    $stmtUltima->execute();
    $resUltima = $stmtUltima->get_result();
    $stmtUltima->close();
    if ($resUltima && $resUltima->num_rows > 0) {
        $saldoEfectivo = (float)$resUltima->fetch_assoc()['total_real'];
    }
}

$hoy  = date('Y-m-d');

ob_start();
?>

<div class="caja-container">
    <h1 class="caja-titulo">Apertura de caja</h1>

    <?php if ($cajaAbierta): ?>

        <div class="caja-aviso caja-aviso--alerta">
            Ya hay una caja abierta. Cerrala antes de abrir una nueva.
        </div>
        <div class="caja-acciones">
            <a href="<?= BASE_URL ?>app/views/caja/cierre.php" class="btn-primary">Ir al cierre</a>
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

            fetch('<?= BASE_URL ?>app/controllers/caja/abrir.php', {
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
                    window.location.href = '<?= BASE_URL ?>app/views/dashboard/index.php';
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
