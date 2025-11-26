<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$titulo = "Nueva venta / Punto POS";
$css_extra = '<link rel="stylesheet" href="/TYPSISTEMA/public/css/pos.css">';

require_once __DIR__ . '/../../config/seguridad.php';
require_once __DIR__ . '/../../config/database.php';

$listaPorDefecto = 'MINORISTA'; // o 'MAYORISTA' si querés arrancar así

// Traer productos activos + precios de lista (minorista / mayorista)
try {
    $db = new Database();
    $mysqli = $db->getConnection(); // conexión mysqli

    $sql = "
        SELECT 
            p.id,
            p.codigo_barras,
            p.nombre,
            p.stock_actual,
            MAX(CASE WHEN lp.tipo_lista = 'MINORISTA' THEN lp.precio END) AS precio_minorista,
            MAX(CASE WHEN lp.tipo_lista = 'MAYORISTA' THEN lp.precio END) AS precio_mayorista
        FROM productos p
        LEFT JOIN lista_precio lp ON lp.id_producto = p.id
        WHERE p.activo = 1
        GROUP BY p.id, p.codigo_barras, p.nombre, p.stock_actual
        ORDER BY p.nombre
    ";

    $result = $mysqli->query($sql);

    if (!$result) {
        throw new Exception('Error en la consulta: ' . $mysqli->error);
    }

    $productos = $result->fetch_all(MYSQLI_ASSOC);
} catch (Throwable $e) {
    die('Error al obtener productos: ' . $e->getMessage());
}

ob_start();
?>

<div class="pos-container">
    <!-- COLUMNA IZQUIERDA: PRODUCTOS (80%) -->
    <section class="pos-products">
        <div class="pos-header">
            <h1 class="pos-title">Nueva venta / Punto POS</h1>
        </div>

        <div class="pos-search-bar">
            <input
                type="text"
                id="pos-search-input"
                class="pos-search-input"
                placeholder="Ingresá el nombre o código del producto..."
                autocomplete="off"
            >
        </div>

<div class="pos-products-grid" id="pos-products-grid">
    <?php if (empty($productos)): ?>
        <p>No hay productos activos cargados.</p>
    <?php else: ?>
     <?php foreach ($productos as $producto): ?>
    <?php
        $id     = (int) $producto['id'];
        $nombre = htmlspecialchars($producto['nombre']);
        $codigo = htmlspecialchars($producto['codigo_barras'] ?? '');
        $stock  = (int) $producto['stock_actual'];

        // precios reales desde lista_precio
        $precioMinorista = isset($producto['precio_minorista']) ? (float) $producto['precio_minorista'] : 0;
        $precioMayorista = isset($producto['precio_mayorista']) ? (float) $producto['precio_mayorista'] : 0;

        // precio mostrado por defecto
        if ($listaPorDefecto === 'MAYORISTA' && $precioMayorista > 0) {
            $precioMostrar = $precioMayorista;
        } else {
            $precioMostrar = $precioMinorista;
        }
    ?>
    <article
        class="pos-product-card"
        data-id="<?= $id ?>"
        data-name="<?= $nombre ?>"
        data-code="<?= $codigo ?>"
        data-price-minorista="<?= $precioMinorista ?>"
        data-price-mayorista="<?= $precioMayorista ?>"
        data-price="<?= $precioMostrar ?>"
        data-stock="<?= $stock ?>"
    >
        <div class="pos-product-name"><?= $nombre ?></div>
        <div class="pos-product-price">
            <?= '$' . number_format($precioMostrar, 2, ',', '.') ?>
        </div>
        <div class="pos-product-stock">Stock: <?= $stock ?></div>
    </article>
<?php endforeach; ?>
    <?php endif; ?>
</div>
    </section>

    <!-- COLUMNA DERECHA: RESUMEN (20%) -->
    <aside class="pos-summary">
        <header class="pos-summary-header">
            <h2 class="pos-summary-title">Resumen de la venta</h2>
            <button type="button" class="pos-config-btn" id="pos-open-config" title="Configuración">
                ⚙
            </button>
        </header>

        <!-- BUSCADOR DE CLIENTE -->
        <div class="pos-client-box">
            <label for="pos-client-input" class="pos-label">Cliente</label>
            <div class="pos-client-input-wrapper">
                <input
                    type="text"
                    id="pos-client-input"
                    class="pos-client-input"
                    placeholder="Consumidor Final"
                >
                <button type="button" class="pos-client-search-btn">
                    Buscar
                </button>
            </div>
        </div>

        <!-- CARRITO -->
        <section class="pos-cart">
            <h3 class="pos-cart-title">Carrito</h3>
            <div class="pos-cart-items" id="pos-cart-items">
                <!-- El JS va a ir agregando acá las líneas del carrito -->
            </div>
        </section>

        <!-- RESUMEN DE TOTALES -->
        <section class="pos-totals">
            <div class="pos-totals-row">
                <span>N productos seleccionados</span>
                <span id="pos-total-items">0</span>
            </div>
            <div class="pos-totals-row">
                <span>Neto</span>
                <span id="pos-neto">$0,00</span>
            </div>
            <div class="pos-totals-row">
                <span>Descuento</span>
                <span id="pos-descuento">$0,00</span>
            </div>
            <div class="pos-totals-row pos-totals-row--strong">
                <span>Subtotal</span>
                <span id="pos-subtotal">$0,00</span>
            </div>
        </section>

        <!-- BOTÓN VENDER -->
        <button
            type="button"
            class="pos-sell-btn"
            id="pos-open-payment"
            disabled
        >
            Vender <span id="pos-sell-total">$0,00</span>
        </button>
    </aside>
</div>

<!-- MODAL CONFIGURACIÓN (TIPO VENTA + LISTA DE PRECIOS) -->
<div class="pos-modal" id="pos-config-modal" aria-hidden="true">
    <div class="pos-modal-backdrop" data-close-config></div>
    <div class="pos-modal-dialog">
        <header class="pos-modal-header">
            <h3>Configuración de la venta</h3>
            <button type="button" class="pos-modal-close" data-close-config>&times;</button>
        </header>

        <div class="pos-modal-body">
            <div class="pos-field">
                <label for="pos-tipo-venta" class="pos-label">Tipo de venta</label>
                <select id="pos-tipo-venta" class="pos-select">
                    <option value="MINORISTA">MINORISTA</option>
                    <option value="MAYORISTA">MAYORISTA</option>
                </select>
            </div>

            <div class="pos-field">
                <label for="pos-lista-precios" class="pos-label">Lista de precios</label>
                <select id="pos-lista-precios" class="pos-select">
                    <option value="MINORISTA">Lista minorista</option>
                    <option value="MAYORISTA">Lista mayorista</option>
                </select>
            </div>
        </div>

        <footer class="pos-modal-footer">
            <button type="button" class="pos-btn-secondary" data-close-config>Cancelar</button>
            <button type="button" class="pos-btn-primary" id="pos-save-config">Guardar cambios</button>
        </footer>
    </div>
</div>

<!-- MODAL PAGO / COBRO -->
<div class="pos-modal" id="pos-payment-modal" aria-hidden="true">
    <div class="pos-modal-backdrop" data-close-payment></div>
    <div class="pos-modal-dialog">
        <header class="pos-modal-header">
            <h3>Cobrar venta</h3>
            <button type="button" class="pos-modal-close" data-close-payment>&times;</button>
        </header>

        <div class="pos-modal-body">
            <div class="pos-totals-resumen">
                <div class="pos-totals-row">
                    <span>Total de la venta</span>
                    <span id="pos-pay-total">$0,00</span>
                </div>
                <div class="pos-totals-row">
                    <span>Total abonado</span>
                    <span id="pos-pay-abonado">$0,00</span>
                </div>
                <div class="pos-totals-row pos-totals-row--strong">
                    <span>Saldo pendiente</span>
                    <span id="pos-pay-saldo">$0,00</span>
                </div>
            </div>

            <section class="pos-payments">
                <h4>Métodos de pago</h4>
                <div id="pos-payments-list">
                    <!-- Filas de pago generadas luego por JS -->
                </div>
                <button type="button" class="pos-add-payment" id="pos-add-payment">
                    + Agregar método de pago
                </button>
            </section>

            <div class="pos-field">
                <label for="pos-pay-observaciones" class="pos-label">
                    Observaciones (opcional)
                </label>
                <textarea
                    id="pos-pay-observaciones"
                    class="pos-textarea"
                    rows="2"
                ></textarea>
            </div>

            <p class="pos-payment-hint">
                Podés dejar el total abonado en 0 para que la venta quede pendiente.
            </p>
        </div>

        <footer class="pos-modal-footer">
            <button type="button" class="pos-btn-secondary" data-close-payment>Cancelar</button>
            <button type="button" class="pos-btn-primary" id="pos-confirm-payment">
                Cobrar
            </button>
        </footer>
    </div>
</div>

<?php
$contenido = ob_get_clean();
$js_extra = '<script src="/TYPSISTEMA/public/js/pos.js"></script>';
require __DIR__ . '/../layouts/main.php';