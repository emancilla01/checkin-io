<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/merge_helper.php';

if (!class_exists('FPDF')) {
    class_alias(\Fpdf\Fpdf::class, 'FPDF');
}

auth_require();
auth_start_session();
auth_require_role(['admin', 'editor']);

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

$docs_stmt = $pdo->prepare("SELECT * FROM documentos WHERE expediente_id = ? ORDER BY created_at ASC");
$docs_stmt->execute([$id]);
$all_docs = $docs_stmt->fetchAll();

$merged_doc    = null;
$unmerged_docs = [];
foreach ($all_docs as $doc) {
    if ($doc['is_merged']) {
        $merged_doc = $doc;
    } else {
        $unmerged_docs[] = $doc;
    }
}

if ($merged_doc !== null) {
    $_SESSION['flash'] = 'Este expediente ya tiene un documento combinado.';
    header('Location: expediente.php?id=' . $id);
    exit;
}

$error          = '';
$selected_nivel = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selected_nivel = $_POST['nivel_reconocimiento'] ?? '';
    $error = perform_merge($pdo, $exp, $unmerged_docs, $selected_nivel, $staticDocsPath);

    if ($error === '') {
        $_SESSION['flash'] = 'Documentos combinados correctamente: '
            . htmlspecialchars($exp['apellido'] . ', ' . $exp['nombre']);
        header('Location: expediente.php?id=' . $id);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Combinar — <?= htmlspecialchars($exp['apellido'] . ', ' . $exp['nombre']) ?> — IO</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>

<?php $active_nav = ''; include __DIR__ . '/includes/navbar.php'; ?>

<div class="container-lg py-4">

  <div class="io-page-header">
    <div>
      <h1>Combinar documentos</h1>
      <p class="text-muted mb-0" style="font-size:0.9rem;">
        <?= htmlspecialchars($exp['apellido'] . ', ' . $exp['nombre']) ?>
        &mdash; Llegada: <?= htmlspecialchars($exp['fecha_llegada']) ?>
      </p>
    </div>
  </div>

  <?php if ($error !== ''): ?>
    <div class="alert alert-danger" style="max-width:600px;"><?= $error ?></div>
  <?php endif; ?>

  <div class="io-card" style="max-width:600px;">

    <h6 class="fw-semibold mb-3" style="color:var(--io-navy);">Documentos a combinar</h6>

    <?php if (empty($unmerged_docs)): ?>
      <p class="text-muted mb-4" style="font-size:0.9rem;">No hay tarjeta de registro cargada para combinar.</p>
    <?php else: ?>
      <ul class="list-unstyled mb-4">
        <?php foreach ($unmerged_docs as $doc): ?>
        <li class="py-1 border-bottom" style="font-size:0.875rem;">
          <?= htmlspecialchars($doc['original_name'] ?? basename($doc['path'])) ?>
        </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <form method="POST" action="merge.php?id=<?= $id ?>">

      <div class="mb-3">
        <label for="nivel_reconocimiento" class="form-label">Nivel de reconocimiento (opcional)</label>
        <select id="nivel_reconocimiento" name="nivel_reconocimiento" class="form-select">
          <?php foreach (RECONOCIMIENTO_OPCIONES as $val => $label): ?>
            <option value="<?= htmlspecialchars($val) ?>"
              <?= $selected_nivel === (string)$val ? 'selected' : '' ?>>
              <?= htmlspecialchars($label) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <p class="text-muted mb-4" style="font-size:0.875rem;">El contrato se incluye automaticamente.</p>

      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-io-blue"
          <?= empty($unmerged_docs) ? 'disabled' : '' ?>>Combinar documentos</button>
        <a href="expediente.php?id=<?= $id ?>" class="btn btn-outline-secondary">Cancelar</a>
      </div>

    </form>

  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
