<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<header class="app-header">

    <!-- Botón menú -->
    <button id="btn-menu" class="hamburger">☰</button>

    <!-- Texto: Usuario (ROL) | Cerrar sesión -->
    <div class="header-right">
        <span class="header-user-label">
            <?= htmlspecialchars($_SESSION['usuario_nombre'] ?? 'Usuario') ?>
            (<?= htmlspecialchars($_SESSION['usuario_rol'] ?? '') ?>)
        </span>

        <span class="header-separator">|</span>

       <a href="/TYPSISTEMA/app/controllers/auth/logout.php" class="btn-logout">
    Cerrar sesión
</a>
    </div>
</header>