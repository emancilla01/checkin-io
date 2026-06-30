<?php
// $active_nav should be set by the including page: 'llegadas' | 'base-de-datos' | 'registro-nuevo' | 'carga-masiva' | 'merge-masivo'
$active_nav = $active_nav ?? '';
?>
<nav class="navbar io-navbar px-3">
  <a class="navbar-brand fw-bold" href="index.php">IO</a>
  <div class="d-flex gap-3">
    <a class="nav-link <?= $active_nav === 'llegadas'       ? 'active' : '' ?>" href="index.php">Llegadas</a>
    <a class="nav-link <?= $active_nav === 'base-de-datos'  ? 'active' : '' ?>" href="base_datos.php">Base de datos</a>
    <a class="nav-link <?= $active_nav === 'registro-nuevo' ? 'active' : '' ?>" href="registro_nuevo.php">Agregar registro</a>
    <a class="nav-link <?= $active_nav === 'carga-masiva'   ? 'active' : '' ?>" href="carga_masiva.php">Carga masiva</a>
    <a class="nav-link <?= $active_nav === 'merge-masivo'   ? 'active' : '' ?>" href="merge_masivo.php">Combinar (masivo)</a>
    <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
    <a class="nav-link <?= $active_nav === 'usuarios' ? 'active' : '' ?>" href="usuarios.php">Usuarios</a>
    <?php endif; ?>
    <a class="nav-link <?= $active_nav === 'cambiar-password' ? 'active' : '' ?>" href="cambiar_password.php">Cambiar contrasena</a>
    <a class="nav-link" href="logout.php">Cerrar sesion</a>
  </div>
</nav>
