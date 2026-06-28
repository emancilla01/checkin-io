<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
auth_require();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: index.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM expedientes WHERE id = ?");
$stmt->execute([$id]);
$exp = $stmt->fetch();

if (!$exp) {
    header('Location: index.php');
    exit;
}

$docs = $pdo->prepare("SELECT * FROM documentos WHERE expediente_id = ? ORDER BY created_at ASC");
$docs->execute([$id]);
$documentos = $docs->fetchAll();

// Identify identificacion type
$id_path = $exp['identificacion_path'] ?? '';
$id_ext  = strtolower(pathinfo($id_path, PATHINFO_EXTENSION));
$id_is_image = in_array($id_ext, ['jpg','jpeg','png','webp','gif']);
$id_is_pdf   = $id_ext === 'pdf';

// Flash
auth_start_session();
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

function dash(string $val): string {
    return $val !== '' ? htmlspecialchars($val) : '<span class="text-muted">—</span>';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($exp['apellido'] . ', ' . $exp['nombre']) ?> — IO</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>

<?php $active_nav = 'llegadas'; include __DIR__ . '/includes/navbar.php'; ?>

<div class="container-lg py-4">

  <?php if ($flash): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
      <?= htmlspecialchars($flash) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <!-- Page header -->
  <div class="io-page-header mb-3">
    <div>
      <h1 class="mb-0"><?= htmlspecialchars($exp['apellido'] . ', ' . $exp['nombre']) ?></h1>
      <p class="text-muted mb-0" style="font-size:0.9rem;">
        Fecha de llegada: <?= htmlspecialchars($exp['fecha_llegada']) ?>
      </p>
    </div>
    <div class="d-flex gap-2">
      <a href="expediente_editar.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm">Editar</a>
      <form method="POST" action="expediente_delete.php" class="d-inline"
            onsubmit="return confirm('¿Eliminar este expediente? Esta accion no se puede deshacer.');">
        <input type="hidden" name="id" value="<?= $id ?>">
        <button type="submit" class="btn btn-danger btn-sm">Eliminar</button>
      </form>
    </div>
  </div>

  <div class="row g-3">

    <!-- LEFT: Documento -->
    <div class="col-12 col-md-6">
      <div class="io-card h-100">
        <h6 class="fw-semibold mb-3" style="color:var(--io-navy);">Documento</h6>

        <!-- Info grid -->
        <dl class="row mb-3" style="font-size:0.9rem;">
          <dt class="col-5 text-muted fw-normal">Apellido</dt>
          <dd class="col-7"><?= dash($exp['apellido']) ?></dd>

          <dt class="col-5 text-muted fw-normal">Nombre</dt>
          <dd class="col-7"><?= dash($exp['nombre']) ?></dd>

          <dt class="col-5 text-muted fw-normal">Fecha de llegada</dt>
          <dd class="col-7"><?= dash($exp['fecha_llegada']) ?></dd>

          <dt class="col-5 text-muted fw-normal">CRS No</dt>
          <dd class="col-7"><?= dash($exp['crs_no'] ?? '') ?></dd>

          <dt class="col-5 text-muted fw-normal">Habitacion</dt>
          <dd class="col-7"><?= dash($exp['habitacion'] ?? '') ?></dd>
        </dl>

        <hr>

        <!-- Document list -->
        <?php if (empty($documentos)): ?>
          <p class="text-muted mb-0" style="font-size:0.9rem;">Documento pendiente de carga.</p>
        <?php else: ?>
          <ul class="list-unstyled mb-0">
            <?php foreach ($documentos as $doc): ?>
            <li class="d-flex align-items-center justify-content-between py-1 border-bottom">
              <span class="text-truncate me-2" style="font-size:0.875rem; max-width:200px;">
                <?= htmlspecialchars($doc['original_name'] ?? basename($doc['path'])) ?>
              </span>
              <a href="<?= htmlspecialchars($doc['path']) ?>" target="_blank"
                 class="btn btn-outline-secondary btn-sm flex-shrink-0">Abrir</a>
            </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>

    <!-- RIGHT: Identificacion -->
    <div class="col-12 col-md-6">
      <div class="io-card h-100">
        <h6 class="fw-semibold mb-3" style="color:var(--io-navy);">Identificacion</h6>

        <?php if (empty($id_path)): ?>
          <p class="text-muted mb-0" style="font-size:0.9rem;">Identificacion pendiente de carga.</p>

        <?php elseif ($id_is_image): ?>
          <img src="<?= htmlspecialchars($id_path) ?>" alt="Identificacion"
               class="img-fluid mb-3 rounded" style="max-height:300px; object-fit:contain;">
          <div>
            <a href="<?= htmlspecialchars($id_path) ?>" target="_blank"
               class="btn btn-outline-secondary btn-sm">Abrir identificacion</a>
          </div>

        <?php elseif ($id_is_pdf): ?>
          <iframe src="<?= htmlspecialchars($id_path) ?>" width="100%" height="400"
                  class="border rounded mb-3" style="min-height:400px;"></iframe>
          <div>
            <a href="<?= htmlspecialchars($id_path) ?>" target="_blank"
               class="btn btn-outline-secondary btn-sm">Abrir identificacion</a>
          </div>

        <?php else: ?>
          <a href="<?= htmlspecialchars($id_path) ?>" target="_blank"
             class="btn btn-outline-secondary btn-sm">Abrir identificacion</a>
        <?php endif; ?>

      </div>
    </div>

  </div><!-- /row -->

  <div class="mt-3">
    <a href="index.php" class="text-muted" style="font-size:0.875rem;">← Volver a Llegadas</a>
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
