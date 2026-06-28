<?php
// $active_nav should be set by the including page: 'llegadas' | 'base-de-datos' | 'registro-nuevo'
$active_nav = $active_nav ?? '';
?>
<nav class="navbar io-navbar px-3">
  <a class="navbar-brand fw-bold" href="index.php">IO</a>
  <div class="d-flex gap-3">
    <a class="nav-link <?= $active_nav === 'llegadas'       ? 'active' : '' ?>" href="index.php">Llegadas</a>
    <a class="nav-link <?= $active_nav === 'base-de-datos'  ? 'active' : '' ?>" href="#">Base de datos</a>
    <a class="nav-link <?= $active_nav === 'registro-nuevo' ? 'active' : '' ?>" href="registro_nuevo.php">Agregar registro</a>
    <a class="nav-link" href="logout.php">Cerrar sesion</a>
  </div>
</nav>
