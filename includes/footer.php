<?php auth_start_session(); ?>
<footer class="io-footer">
  Sesion iniciada como: <?= htmlspecialchars($_SESSION['nombre'] ?? $_SESSION['username'] ?? '') ?>
</footer>
