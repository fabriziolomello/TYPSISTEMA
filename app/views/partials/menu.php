<?php
$_menuRol     = $_SESSION['usuario_rol']      ?? '';
$_menuDep     = (int)($_SESSION['usuario_deposito'] ?? 0);
$_menuEsAdmin = $_menuRol === 'ADMIN';
$_menuVerTN   = $_menuEsAdmin || $_menuDep === 1;
?>
<aside id="sidebar" class="sidebar">
    <nav>
        <ul>
            <li><a href="<?= BASE_URL ?>app/views/dashboard/index.php">Inicio / Dashboard</a></li>

            <li><a href="<?= BASE_URL ?>app/views/ventas/nueva.php">Nueva venta / Punto POS</a></li>

            <li class="menu-title">Stock</li>
            <li><a href="<?= BASE_URL ?>app/views/stock/stock_consultar/index.php">Consultar stock</a></li>
            <li><a href="<?= BASE_URL ?>app/views/stock/stock_movimientos/index.php">Ingreso / Egreso</a></li>
            <li><a href="<?= BASE_URL ?>app/views/stock/transferencia/index.php">Transferencia</a></li>
            <li><a href="<?= BASE_URL ?>app/views/stock/ajuste/index.php">Ajuste de stock</a></li>

            <li class="menu-title">Caja</li>
            <li><a href="<?= BASE_URL ?>app/views/caja/apertura.php">Apertura de caja</a></li>
            <li><a href="<?= BASE_URL ?>app/views/caja/cierre.php">Cierre de caja</a></li>
            <li><a href="<?= BASE_URL ?>app/views/caja/movimiento.php">Ingreso / Egreso</a></li>
            <li><a href="<?= BASE_URL ?>app/views/caja/historico.php">Consulta (Histórico)</a></li>

            <li class="menu-title">Base de datos</li>
            <li><a href="<?= BASE_URL ?>app/views/base_datos/productos.php">Productos</a></li>
            <li><a href="<?= BASE_URL ?>app/views/base_datos/lista_precios.php">Lista de precios</a></li>
            <li><a href="<?= BASE_URL ?>app/views/base_datos/clientes.php">Clientes</a></li>
            <li><a href="<?= BASE_URL ?>app/views/base_datos/proveedores.php">Proveedores</a></li>

            <?php if ($_menuVerTN): ?>
            <li class="menu-title">Tienda Nube</li>
            <li><a href="<?= BASE_URL ?>app/views/tiendanube/productos.php">Productos</a></li>
            <li><a href="<?= BASE_URL ?>app/views/tiendanube/ventas.php">Pedidos</a></li>
            <?php endif; ?>

            <?php if ($_menuEsAdmin): ?>
            <li class="menu-title">Informes</li>
            <li><a href="<?= BASE_URL ?>app/views/informes/ventas.php">Ventas por período</a></li>
            <li><a href="<?= BASE_URL ?>app/views/informes/ventas_producto.php">Ventas por producto</a></li>
            <li><a href="<?= BASE_URL ?>app/views/informes/stock.php">Stock actual</a></li>
            <li><a href="<?= BASE_URL ?>app/views/informes/movimientos.php">Movimientos de stock</a></li>
            <li><a href="<?= BASE_URL ?>app/views/informes/caja.php">Informe de caja</a></li>
            <?php endif; ?>

            <?php if ($_menuEsAdmin): ?>
            <li class="menu-title">Configuración</li>
            <li><a href="<?= BASE_URL ?>app/views/config/usuarios.php">Usuarios y permisos</a></li>
            <?php endif; ?>
        </ul>
    </nav>
</aside>