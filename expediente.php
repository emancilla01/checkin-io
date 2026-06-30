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

$merged_doc    = null;
$unmerged_docs = [];
$id_docs       = [];
foreach ($documentos as $d) {
    if ($d['is_identificacion']) {
        $id_docs[] = $d;
    } elseif ($d['is_merged']) {
        $merged_doc = $d;
    } else {
        $unmerged_docs[] = $d;
    }
}
$has_merged = $merged_doc !== null;

// Primary identificacion = first uploaded (earliest created_at, already ASC)
$id_primary    = $id_docs[0] ?? null;
$id_additional = array_slice($id_docs, 1);

$id_ext      = $id_primary ? strtolower(pathinfo($id_primary['path'], PATHINFO_EXTENSION)) : '';
$id_is_image = in_array($id_ext, ['jpg','jpeg','png','webp','gif']);
$id_is_pdf   = $id_ext === 'pdf';

// Flash
auth_start_session();
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

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
      <p class="mb-0 mt-1" style="font-size:0.825rem; color:var(--io-navy); flex-wrap:wrap; display:flex; gap:1.25rem;">
        <span><span class="text-muted">Apellido:</span> <?= htmlspecialchars($exp['apellido']) ?></span>
        <span><span class="text-muted">Nombre:</span> <?= htmlspecialchars($exp['nombre']) ?></span>
        <span><span class="text-muted">CRS No:</span> <?= !empty($exp['crs_no']) ? htmlspecialchars($exp['crs_no']) : '<span class="text-muted">—</span>' ?></span>
        <span><span class="text-muted">Habitacion:</span> <?= !empty($exp['habitacion']) ? htmlspecialchars($exp['habitacion']) : '<span class="text-muted">—</span>' ?></span>
      </p>
    </div>
    <div class="d-flex gap-2">
      <?php if (auth_role() !== 'viewer'): ?>
      <a href="expediente_editar.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm">Editar</a>
      <?php if ($has_merged): ?>
        <button class="btn btn-outline-secondary btn-sm" disabled
                title="Este expediente ya tiene un documento combinado.">Combinar</button>
      <?php else: ?>
        <a href="merge.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm">Combinar</a>
      <?php endif; ?>
      <?php if ($merged_doc !== null && $merged_doc['signed_at'] === null): ?>
        <button type="button" class="btn btn-io-orange btn-sm"
                data-bs-toggle="modal" data-bs-target="#firmaModal"
                data-firma-expid="<?= $id ?>"
                data-firma-docpath="<?= htmlspecialchars($merged_doc['path']) ?>"
                data-firma-nombre="<?= htmlspecialchars($exp['apellido'] . ', ' . $exp['nombre']) ?>">
          Firmar
        </button>
      <?php elseif ($merged_doc !== null && $merged_doc['signed_at'] !== null): ?>
        <button type="button" class="btn btn-success btn-sm" disabled>Firmado</button>
      <?php else: ?>
        <button type="button" class="btn btn-io-orange btn-sm" disabled
                title="Primero combina los documentos para poder firmar.">Firmar</button>
      <?php endif; ?>
      <form method="POST" action="expediente_delete.php" class="d-inline"
            onsubmit="return confirm('¿Eliminar este expediente? Esta accion no se puede deshacer.');">
        <input type="hidden" name="id" value="<?= $id ?>">
        <button type="submit" class="btn btn-danger btn-sm">Eliminar</button>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="row g-3">

    <!-- LEFT: Documento -->
    <div class="col-12 col-md-6">
      <div class="io-card h-100">
        <h6 class="fw-semibold mb-3" style="color:var(--io-navy);">Documento</h6>

        <!-- Merged document — inline preview (PDF.js canvas, Safari-safe) -->
        <?php if ($merged_doc !== null): ?>
          <div class="mb-3">
            <div id="docViewer"
                 class="border rounded mb-2"
                 style="height:400px; overflow-y:auto; background:#525659; -webkit-overflow-scrolling:touch;"
                 data-pdf-url="<?= htmlspecialchars($merged_doc['path']) ?>">
            </div>
            <div class="d-flex align-items-center gap-2">
              <a href="<?= htmlspecialchars($merged_doc['path']) ?>" target="_blank"
                 class="btn btn-outline-secondary btn-sm">Abrir</a>
              <?php if (auth_role() !== 'viewer'): ?>
              <form method="POST" action="documento_delete.php" class="d-inline"
                    onsubmit="return confirm('¿Estas seguro de eliminar este documento?');">
                <input type="hidden" name="doc_id" value="<?= (int)$merged_doc['id'] ?>">
                <button type="submit" class="btn btn-outline-danger btn-sm">Eliminar</button>
              </form>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>

        <!-- Additional (unmerged) documents -->
        <?php if (!empty($unmerged_docs)): ?>
          <ul class="list-unstyled mb-0">
            <?php foreach ($unmerged_docs as $doc): ?>
            <li class="d-flex align-items-center justify-content-between py-1 border-bottom">
              <span class="text-truncate me-2" style="font-size:0.875rem; max-width:160px;">
                <?= htmlspecialchars($doc['original_name'] ?? basename($doc['path'])) ?>
              </span>
              <div class="d-flex gap-1 flex-shrink-0">
                <a href="<?= htmlspecialchars($doc['path']) ?>" target="_blank"
                   class="btn btn-outline-secondary btn-sm">Abrir</a>
                <?php if (auth_role() !== 'viewer'): ?>
                <form method="POST" action="documento_delete.php"
                      onsubmit="return confirm('¿Estas seguro de eliminar este documento?');">
                  <input type="hidden" name="doc_id" value="<?= (int)$doc['id'] ?>">
                  <button type="submit" class="btn btn-outline-danger btn-sm">Eliminar</button>
                </form>
                <?php endif; ?>
              </div>
            </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>

        <?php if ($merged_doc === null && empty($unmerged_docs)): ?>
          <p class="text-muted mb-0" style="font-size:0.9rem;">Documento pendiente de carga.</p>
        <?php endif; ?>
      </div>
    </div>

    <!-- RIGHT: Identificacion -->
    <div class="col-12 col-md-6">
      <div class="io-card h-100">
        <h6 class="fw-semibold mb-3" style="color:var(--io-navy);">Identificacion</h6>

        <?php if ($id_primary === null): ?>
          <p class="text-muted mb-0" style="font-size:0.9rem;">Identificacion pendiente de carga.</p>

        <?php else: ?>

          <?php if ($id_is_image): ?>
            <img src="<?= htmlspecialchars($id_primary['path']) ?>" alt="Identificacion"
                 class="img-fluid mb-3 rounded" style="max-height:300px; object-fit:contain;">
          <?php elseif ($id_is_pdf): ?>
            <iframe src="<?= htmlspecialchars($id_primary['path']) ?>" width="100%" height="400"
                    class="border rounded mb-3" style="min-height:400px;"></iframe>
          <?php endif; ?>

          <div class="d-flex gap-2 align-items-center mb-3">
            <a href="<?= htmlspecialchars($id_primary['path']) ?>" target="_blank"
               class="btn btn-outline-secondary btn-sm">Abrir</a>
            <?php if (auth_role() !== 'viewer'): ?>
            <form method="POST" action="identificacion_delete.php" class="d-inline"
                  onsubmit="return confirm('¿Estas seguro de eliminar esta identificacion?');">
              <input type="hidden" name="doc_id" value="<?= (int)$id_primary['id'] ?>">
              <button type="submit" class="btn btn-outline-danger btn-sm">Eliminar</button>
            </form>
            <?php endif; ?>
          </div>

          <?php if (!empty($id_additional)): ?>
            <ul class="list-unstyled mb-0">
              <?php foreach ($id_additional as $id_doc): ?>
              <li class="d-flex align-items-center justify-content-between py-1 border-bottom">
                <span class="text-truncate me-2" style="font-size:0.875rem; max-width:160px;">
                  <?= htmlspecialchars($id_doc['original_name'] ?? basename($id_doc['path'])) ?>
                </span>
                <div class="d-flex gap-1 flex-shrink-0">
                  <a href="<?= htmlspecialchars($id_doc['path']) ?>" target="_blank"
                     class="btn btn-outline-secondary btn-sm">Abrir</a>
                  <?php if (auth_role() !== 'viewer'): ?>
                  <form method="POST" action="identificacion_delete.php"
                        onsubmit="return confirm('¿Estas seguro de eliminar esta identificacion?');">
                    <input type="hidden" name="doc_id" value="<?= (int)$id_doc['id'] ?>">
                    <button type="submit" class="btn btn-outline-danger btn-sm">Eliminar</button>
                  </form>
                  <?php endif; ?>
                </div>
              </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>

        <?php endif; ?>

      </div>
    </div>

  </div><!-- /row -->

  <div class="mt-3">
    <a href="index.php" class="text-muted" style="font-size:0.875rem;">← Volver a Llegadas</a>
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php include __DIR__ . '/includes/firma_modal.php'; ?>
<?php if ($merged_doc !== null): ?>
<script>
(function () {
    var el = document.getElementById('docViewer');
    if (el) initPdfViewer(el, el.dataset.pdfUrl);
}());
</script>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
