<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$titulo = "Dashboard";

$css_extra = '<link rel="stylesheet" href="/TYPSISTEMA/public/css/dashboard.css">';

require_once __DIR__ . '/../../config/seguridad.php';
require_once __DIR__ . '/../../config/database.php';

// Conexión a la base de datos (mysqli)
$db   = new Database();
$conn = $db->getConnection();

// ------------------------------
// Filtros (entrada GET + defaults)
// ------------------------------
$hoy = new DateTime();

$fecha_desde_default = $hoy->format('Y-m-01'); // 1° del mes actual
$fecha_hasta_default = $hoy->format('Y-m-t');  // último día del mes actual

$fecha_desde    = $_GET['fecha_desde']  ?? $fecha_desde_default;
$fecha_hasta    = $_GET['fecha_hasta']  ?? $fecha_hasta_default;
$tipo_venta     = $_GET['tipo_venta']   ?? '';
$metodo_pago    = $_GET['metodo_pago']  ?? '';
$estado_pago    = $_GET['estado_pago']  ?? '';
$cliente_buscar = trim($_GET['cliente'] ?? '');

// Normalizamos valores para evitar cosas raras
$tiposVentaValidos = ['MINORISTA', 'MAYORISTA'];
$metodosValidos    = ['EFECTIVO', 'TARJETA', 'TRANSFERENCIA', 'QR'];
$estadosValidos    = ['PENDIENTE', 'PARCIAL', 'PAGADA', 'ANULADA'];

if (!in_array($tipo_venta, $tiposVentaValidos)) {
    $tipo_venta = '';
}
if (!in_array($metodo_pago, $metodosValidos)) {
    $metodo_pago = '';
}
if (!in_array($estado_pago, $estadosValidos)) {
    $estado_pago = '';
}

// Validamos mínimamente fechas
if (!$fecha_desde) $fecha_desde = $fecha_desde_default;
if (!$fecha_hasta) $fecha_hasta = $fecha_hasta_default;

// Escapamos para usarlas en el SQL
$fecha_desde_sql = $conn->real_escape_string($fecha_desde);
$fecha_hasta_sql = $conn->real_escape_string($fecha_hasta);

// ------------------------------
// Construir WHERE dinámico
// ------------------------------
$where = [];

// rango de fechas (SIEMPRE)
$where[] = "v.fecha_hora BETWEEN '{$fecha_desde_sql} 00:00:00' AND '{$fecha_hasta_sql} 23:59:59'";

if ($tipo_venta !== '') {
    $tipo_venta_sql = $conn->real_escape_string($tipo_venta);
    $where[] = "v.tipo_venta = '{$tipo_venta_sql}'";
}

if ($estado_pago !== '') {
    $estado_pago_sql = $conn->real_escape_string($estado_pago);
    $where[] = "v.estado_pago = '{$estado_pago_sql}'";
}

if ($cliente_buscar !== '') {
    $cliente_sql = $conn->real_escape_string($cliente_buscar);
    $where[] = "c.nombre LIKE '%{$cliente_sql}%'";
}

// Filtro por método de pago (sin duplicar ventas)
if ($metodo_pago !== '') {
    $metodo_pago_sql = $conn->real_escape_string($metodo_pago);
    $where[] = "
        EXISTS (
            SELECT 1
            FROM movimiento_caja mc
            WHERE mc.id_venta = v.id
              AND mc.tipo = 'VENTA'
              AND mc.medio_pago = '{$metodo_pago_sql}'
        )
    ";
}

$sqlWhere = implode(' AND ', $where);

// ------------------------------
// WHERE para totales de las cards
// ------------------------------
// - Si filtro "ANULADA": uso todo normal (solo anuladas)
// - Si NO filtro "ANULADA": excluyo anuladas
if ($estado_pago === 'ANULADA') {
    $sqlWhereTotales = $sqlWhere;
} else {
    $sqlWhereTotales = $sqlWhere . " AND v.estado_pago <> 'ANULADA'";
}

// ------------------------------
// Consulta: resumen
// ------------------------------
$sqlResumen = "
    SELECT
        SUM(v.total) AS total_vendido,
        COUNT(*)     AS cantidad_ventas,
        SUM(CASE WHEN v.tipo_venta = 'MINORISTA' THEN v.total ELSE 0 END) AS total_minorista,
        SUM(CASE WHEN v.tipo_venta = 'MAYORISTA' THEN v.total ELSE 0 END) AS total_mayorista
    FROM ventas v
    LEFT JOIN clientes c ON v.id_cliente = c.id
    WHERE {$sqlWhereTotales}
";

$resultResumen = $conn->query($sqlResumen);

if ($resultResumen && $resultResumen->num_rows > 0) {
    $resumen = $resultResumen->fetch_assoc();
} else {
    $resumen = [
        'total_vendido'   => 0,
        'cantidad_ventas' => 0,
        'total_minorista' => 0,
        'total_mayorista' => 0,
    ];
}

// ------------------------------
// Total cobrado (mismo criterio que arriba)
// ------------------------------
$sqlCobrado = "
    SELECT COALESCE(SUM(mc2.monto), 0) AS total_cobrado_periodo
    FROM ventas v
    LEFT JOIN clientes c ON v.id_cliente = c.id
    LEFT JOIN movimiento_caja mc2 
        ON mc2.id_venta = v.id
        AND mc2.tipo = 'VENTA'
    WHERE {$sqlWhereTotales}
";

$resultCobrado = $conn->query($sqlCobrado);
$totalCobradoPeriodo = ($resultCobrado && $resultCobrado->num_rows > 0)
    ? $resultCobrado->fetch_assoc()['total_cobrado_periodo']
    : 0;

// ------------------------------
// Listado de ventas (SE MUESTRAN ANULADAS)
// ------------------------------
$sqlVentas = "
    SELECT
        v.*,
        c.nombre AS nombre_cliente,
        (
            SELECT COALESCE(SUM(mc.monto), 0)
            FROM movimiento_caja mc
            WHERE mc.id_venta = v.id
              AND mc.tipo = 'VENTA'
        ) AS total_cobrado
    FROM ventas v
    LEFT JOIN clientes c ON v.id_cliente = c.id
    WHERE {$sqlWhere}
    ORDER BY v.fecha_hora DESC
";

$resultVentas = $conn->query($sqlVentas);
$ventas = [];

if ($resultVentas && $resultVentas->num_rows > 0) {
    while ($row = $resultVentas->fetch_assoc()) {
        $ventas[] = $row;
    }
}

ob_start();
?>

<!-- ==========================
     Sección 1: Cabecera + filtros
     ========================== -->
<section class="dashboard-header">
  <div class="dashboard-header__top">
    <h1>Dashboard de ventas</h1>

    <!-- Botón POS (nueva venta) -->
    <a href="/TYPSISTEMA/app/views/ventas/nueva.php" class="btn btn-primario">
      Nueva venta
    </a>
  </div>

  <form method="get" class="dashboard-filtros">
    <div class="filtro-grupo">
      <label for="fecha_desde">Fecha desde</label>
      <input
        type="date"
        id="fecha_desde"
        name="fecha_desde"
        value="<?= htmlspecialchars($fecha_desde) ?>">
    </div>

    <div class="filtro-grupo">
      <label for="fecha_hasta">Fecha hasta</label>
      <input
        type="date"
        id="fecha_hasta"
        name="fecha_hasta"
        value="<?= htmlspecialchars($fecha_hasta) ?>">
    </div>

    <div class="filtro-grupo">
      <label for="tipo_venta">Tipo de venta</label>
      <select id="tipo_venta" name="tipo_venta">
        <option value="">Todas</option>
        <option value="MINORISTA" <?= $tipo_venta === 'MINORISTA' ? 'selected' : '' ?>>Minorista</option>
        <option value="MAYORISTA" <?= $tipo_venta === 'MAYORISTA' ? 'selected' : '' ?>>Mayorista</option>
      </select>
    </div>

    <div class="filtro-grupo">
      <label for="metodo_pago">Método de pago</label>
      <select id="metodo_pago" name="metodo_pago">
        <option value="">Todos</option>
        <option value="EFECTIVO"      <?= $metodo_pago === 'EFECTIVO' ? 'selected' : '' ?>>Efectivo</option>
        <option value="TARJETA"       <?= $metodo_pago === 'TARJETA' ? 'selected' : '' ?>>Tarjeta</option>
        <option value="TRANSFERENCIA" <?= $metodo_pago === 'TRANSFERENCIA' ? 'selected' : '' ?>>Transferencia</option>
        <option value="QR"            <?= $metodo_pago === 'QR' ? 'selected' : '' ?>>QR</option>
      </select>
    </div>

    <div class="filtro-grupo">
      <label for="estado_pago">Estado del pago</label>
      <select id="estado_pago" name="estado_pago">
    <option value="">Todos</option>
    <option value="PENDIENTE" <?= $estado_pago === 'PENDIENTE' ? 'selected' : '' ?>>Pendiente</option>
    <option value="PARCIAL"   <?= $estado_pago === 'PARCIAL' ? 'selected' : '' ?>>Parcial</option>
    <option value="PAGADA"    <?= $estado_pago === 'PAGADA' ? 'selected' : '' ?>>Pagada</option>
    <option value="ANULADA"   <?= $estado_pago === 'ANULADA' ? 'selected' : '' ?>>Anulada</option>
</select>
    </div>

    <div class="filtro-grupo filtro-grupo--cliente">
      <label for="cliente">Buscar por cliente</label>
      <input
        type="text"
        id="cliente"
        name="cliente"
        placeholder="Nombre del cliente"
        value="<?= htmlspecialchars($cliente_buscar) ?>">
    </div>

    <div class="filtro-acciones">
      <button type="submit" class="btn btn-primario">Aplicar filtros</button>
      <button
        type="button"
        class="btn btn-secundario"
        onclick="window.location.href='index.php'"
      >
        Limpiar
      </button>
    </div>
  </form>
</section>

<!-- ==========================
     Sección 2: Cards de resumen
     ========================== -->
<section class="dashboard-resumen">
  <article class="card-resumen">
    <h2>Total vendido</h2>
    <p class="card-resumen__monto">
      $<?= number_format($resumen['total_vendido'] ?? 0, 2, ',', '.') ?>
    </p>
  </article>

  <article class="card-resumen">
    <h2>Cantidad de ventas</h2>
    <p class="card-resumen__monto">
      <?= (int)($resumen['cantidad_ventas'] ?? 0) ?>
    </p>
  </article>

  <article class="card-resumen">
    <h2>Total cobrado</h2>
    <p class="card-resumen__monto">
      $<?= number_format($totalCobradoPeriodo, 2, ',', '.') ?>
    </p>
  </article>

  <article class="card-resumen card-resumen--doble">
    <h2>Total por tipo de venta</h2>
    <div class="card-resumen__detalle">
      <div>
        <span>Minorista</span>
        <strong>$<?= number_format($resumen['total_minorista'] ?? 0, 2, ',', '.') ?></strong>
      </div>
      <div>
        <span>Mayorista</span>
        <strong>$<?= number_format($resumen['total_mayorista'] ?? 0, 2, ',', '.') ?></strong>
      </div>
    </div>
  </article>
</section>

<!-- ==========================
     Sección 3: Tabla de ventas
     ========================== -->
<?php
// URL actual (para volver con los mismos filtros después de las acciones)
$returnUrl = urlencode($_SERVER['REQUEST_URI']);
?>
<section class="dashboard-tabla">
  <table>
    <thead>
      <tr>
        <th>Fecha</th>
        <th>Cliente</th>
        <th>Total</th>
        <th>Tipo de venta</th>
        <th>Estado</th>
        <th>Total cobrado</th>
        <th>Acciones</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($ventas)): ?>
        <tr>
          <td colspan="7">No se encontraron ventas con los filtros seleccionados.</td>
        </tr>
      <?php else: ?>
        <?php foreach ($ventas as $venta): ?>
          <tr>
            <td><?= date('d/m/Y H:i', strtotime($venta['fecha_hora'])) ?></td>
            <td><?= htmlspecialchars($venta['nombre_cliente'] ?? 'CONSUMIDOR FINAL') ?></td>
            <td class="col-monto">
              $<?= number_format($venta['total'], 2, ',', '.') ?>
            </td>
            <td><?= htmlspecialchars($venta['tipo_venta']) ?></td>
            <td class="col-estado col-estado--<?= strtolower($venta['estado_pago']) ?>">
              <?= htmlspecialchars($venta['estado_pago']) ?>
            </td>
            <td class="col-monto">
              $<?= number_format($venta['total_cobrado'], 2, ',', '.') ?>
            </td>
            <td class="col-acciones">
              <!-- Detalle (popup) -->
              <a
                href="#"
                class="btn-accion btn-accion--detalle btn-detalle"
                data-id="<?= (int)$venta['id'] ?>"
              >
                Detalle
              </a>

              <!-- Ticket -->
              <a
  href="/TYPSISTEMA/app/views/ventas/imprimir.php?id=<?= (int)$venta['id'] ?>"
  class="btn-accion btn-accion--ticket"
  target="_blank"
>
  Ticket
</a>

              <!-- Anular (solo si NO está ANULADA) -->
              <?php if ($venta['estado_pago'] !== 'ANULADA'): ?>
                <a
                  href="/TYPSISTEMA/app/controllers/ventas/anular.php?id=<?= (int)$venta['id'] ?>"
                  class="btn-accion btn-accion--anular"
                  onclick="return confirm('¿Seguro que querés anular esta venta?');"
                >
                  Anular
                </a>
              <?php endif; ?>

              <!-- Cambiar tipo (solo si NO está ANULADA) -->
              <?php if ($venta['estado_pago'] !== 'ANULADA'): ?>
                <a
                  href="/TYPSISTEMA/app/controllers/ventas/cambiar_tipo.php?id=<?= (int)$venta['id'] ?>&return=<?= $returnUrl ?>"
                  class="btn-accion btn-accion--detalle"
                >
                  Tipo
                </a>
              <?php endif; ?>

              <!-- Registrar cobro (solo si NO está PAGADA ni ANULADA) -->
              <?php if ($venta['estado_pago'] !== 'PAGADA' && $venta['estado_pago'] !== 'ANULADA'): ?>
                <a
                  href="#"
                  class="btn-accion btn-accion--ticket btn-cobrar"
                  data-id="<?= (int)$venta['id'] ?>"
                >
                  Cobrar
                </a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</section>

<!-- ==========================
     Popups (modales)
     ========================== -->

<!-- Popup Detalle de Venta -->
<div id="modal-detalle" class="modal-detalle">
  <div class="modal-detalle__overlay"></div>
  <div class="modal-detalle__contenido">
    <button type="button" class="modal-detalle__cerrar" id="modal-detalle-cerrar">×</button>
    <iframe id="modal-detalle-iframe" src="" frameborder="0"></iframe>
  </div>
</div>

<!-- Popup Cobrar Venta -->
<div id="modal-cobrar" class="modal-cobrar">
  <div class="modal-cobrar__overlay"></div>
  <div class="modal-cobrar__contenido">
    <button type="button" class="modal-cobrar__cerrar" id="modal-cobrar-cerrar">×</button>
    <iframe id="modal-cobrar-iframe" src="" frameborder="0"></iframe>
  </div>
</div>

<script>
  document.addEventListener('DOMContentLoaded', function () {
    // ===== Modal DETALLE =====
    const modalDetalle      = document.getElementById('modal-detalle');
    const overlayDetalle    = modalDetalle.querySelector('.modal-detalle__overlay');
    const btnCerrarDetalle  = document.getElementById('modal-detalle-cerrar');
    const iframeDetalle     = document.getElementById('modal-detalle-iframe');

    function abrirModalDetalle(idVenta) {
      iframeDetalle.src = '/TYPSISTEMA/app/views/ventas/detalle.php?id=' + encodeURIComponent(idVenta);
      modalDetalle.classList.add('modal-detalle--visible');
    }

    function cerrarModalDetalle() {
      modalDetalle.classList.remove('modal-detalle--visible');
      iframeDetalle.src = '';
    }

    document.querySelectorAll('.btn-detalle').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        const idVenta = this.getAttribute('data-id');
        abrirModalDetalle(idVenta);
      });
    });

    overlayDetalle.addEventListener('click', cerrarModalDetalle);
    btnCerrarDetalle.addEventListener('click', cerrarModalDetalle);

    // ===== Modal COBRAR =====
    const modalCobrar      = document.getElementById('modal-cobrar');
    const overlayCobrar    = modalCobrar.querySelector('.modal-cobrar__overlay');
    const btnCerrarCobrar  = document.getElementById('modal-cobrar-cerrar');
    const iframeCobrar     = document.getElementById('modal-cobrar-iframe');

    function abrirModalCobrar(idVenta) {
      iframeCobrar.src = '/TYPSISTEMA/app/views/ventas/cobrar.php?id=' + encodeURIComponent(idVenta);
      modalCobrar.classList.add('modal-cobrar--visible');
    }

    function cerrarModalCobrar() {
      modalCobrar.classList.remove('modal-cobrar--visible');
      iframeCobrar.src = '';
      // Si más adelante querés, acá podemos recargar el dashboard:
      // window.location.reload();
    }

    document.querySelectorAll('.btn-cobrar').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        const idVenta = this.getAttribute('data-id');
        abrirModalCobrar(idVenta);
      });
    });

    overlayCobrar.addEventListener('click', cerrarModalCobrar);
    btnCerrarCobrar.addEventListener('click', cerrarModalCobrar);
  });
</script>

<?php
$contenido = ob_get_clean();
require __DIR__ . '/../layouts/main.php';