<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/vendor/autoload.php';

// fpdf/fpdf ^1.86 registers as \Fpdf\Fpdf (PSR-4 namespaced).
// setasign/fpdi's FpdfTpl extends \FPDF (global, old-style).
// Bridge the two so FPDI can find its base class.
if (!class_exists('FPDF')) {
    class_alias(\Fpdf\Fpdf::class, 'FPDF');
}

auth_require();
auth_start_session();

// Static docs path comes from config.php (gitignored, set per-machine).
// Files expected inside $staticDocsPath:
//   club.pdf, silver_elite.pdf, gold_elite.pdf, platinum_elite.pdf, diamond_elite.pdf, contrato.pdf

define('UPLOAD_DIR', __DIR__ . '/uploads/');

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

// Load all documentos
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

// Guard direct URL access when already merged
if ($merged_doc !== null) {
    $_SESSION['flash'] = 'Este expediente ya tiene un documento combinado.';
    header('Location: expediente.php?id=' . $id);
    exit;
}

// Recognition tier map: dropdown value → filename inside $staticDocsPath
$tier_files = [
    'club'          => 'club.pdf',
    'silver_elite'  => 'silver_elite.pdf',
    'gold_elite'    => 'gold_elite.pdf',
    'platinum_elite'=> 'platinum_elite.pdf',
    'diamond_elite' => 'diamond_elite.pdf',
];

$reconocimiento_opciones = [
    ''              => 'Sin reconocimiento',
    'club'          => 'Club',
    'silver_elite'  => 'Silver Elite',
    'gold_elite'    => 'Gold Elite',
    'platinum_elite'=> 'Platinum Elite',
    'diamond_elite' => 'Diamond Elite',
];

$error          = '';
$selected_nivel = '';

// =========================================================
// POST: perform merge
// =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selected_nivel = $_POST['nivel_reconocimiento'] ?? '';

    if (empty($unmerged_docs)) {
        $error = 'No hay tarjeta de registro cargada para combinar.';
    } else {
        // Build ordered list of PDFs to merge
        $pdfs_to_merge = [];

        // (a) Recognition tier PDF, if selected
        if ($selected_nivel !== '' && isset($tier_files[$selected_nivel])) {
            $tier_path = rtrim($staticDocsPath, '/\\') . DIRECTORY_SEPARATOR . $tier_files[$selected_nivel];
            if (!file_exists($tier_path)) {
                $error = 'Archivo de reconocimiento no encontrado: ' . htmlspecialchars(basename($tier_path)) . '. Verifica que los archivos estaticos esten en la ruta configurada.';
            } else {
                $pdfs_to_merge[] = $tier_path;
            }
        }

        // (b) Unmerged documentos (reg cards), in upload order
        if ($error === '') {
            foreach ($unmerged_docs as $doc) {
                $doc_path = __DIR__ . '/' . ltrim($doc['path'], '/\\');
                if (!file_exists($doc_path)) {
                    $error = 'Archivo de documento no encontrado: ' . htmlspecialchars(basename($doc['path'])) . '.';
                    break;
                }
                $pdfs_to_merge[] = $doc_path;
            }
        }

        // (c) Contrato — always last
        if ($error === '') {
            $contrato_path = rtrim($staticDocsPath, '/\\') . DIRECTORY_SEPARATOR . 'contrato.pdf';
            if (!file_exists($contrato_path)) {
                $error = 'Archivo de contrato no encontrado. Verifica que contrato.pdf este en la ruta configurada.';
            } else {
                $pdfs_to_merge[] = $contrato_path;
            }
        }

        // Run the merge
        if ($error === '') {
            try {
                // Generate output filename: Apellido_Nombre_DDMMYY.pdf
                $apellido_safe = preg_replace('/[^a-z0-9]/i', '_', $exp['apellido']);
                $nombre_safe   = preg_replace('/[^a-z0-9]/i', '_', $exp['nombre']);
                $fecha_parts   = explode('-', $exp['fecha_llegada']); // Y-m-d
                $fecha_short   = count($fecha_parts) === 3
                    ? $fecha_parts[2] . $fecha_parts[1] . substr($fecha_parts[0], 2)
                    : date('dmy');
                $out_filename  = $apellido_safe . '_' . $nombre_safe . '_' . $fecha_short . '.pdf';
                $out_filename  = preg_replace('/_+/', '_', $out_filename);

                if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);

                // Collision-safe output path
                $out_path = UPLOAD_DIR . $out_filename;
                if (file_exists($out_path)) {
                    $i = 2;
                    $base = pathinfo($out_filename, PATHINFO_FILENAME);
                    while (file_exists(UPLOAD_DIR . $base . '_' . $i . '.pdf')) $i++;
                    $out_filename = $base . '_' . $i . '.pdf';
                    $out_path = UPLOAD_DIR . $out_filename;
                }

                // FPDI merge
                $pdf = new \setasign\Fpdi\Fpdi();

                foreach ($pdfs_to_merge as $src) {
                    $page_count = $pdf->setSourceFile($src);
                    for ($p = 1; $p <= $page_count; $p++) {
                        $tpl = $pdf->importPage($p);
                        $size = $pdf->getTemplateSize($tpl);
                        $pdf->AddPage($size['width'] > $size['height'] ? 'L' : 'P', [$size['width'], $size['height']]);
                        $pdf->useTemplate($tpl);
                    }
                }

                $pdf->Output('F', $out_path);

                if (!file_exists($out_path)) {
                    throw new RuntimeException('FPDI no genero el archivo de salida.');
                }

                // Success — now update the database and delete originals.
                // Do DB work first; only delete files once DB is committed.
                $rel_path = 'uploads/' . $out_filename;

                $pdo->beginTransaction();

                // Delete unmerged documento rows
                $del_ids = array_column($unmerged_docs, 'id');
                $placeholders = implode(',', array_fill(0, count($del_ids), '?'));
                $pdo->prepare("DELETE FROM documentos WHERE id IN ($placeholders)")
                    ->execute($del_ids);

                // Insert merged documento row
                $pdo->prepare(
                    "INSERT INTO documentos (expediente_id, path, original_name, is_merged) VALUES (?, ?, ?, 1)"
                )->execute([$id, $rel_path, $out_filename]);

                $pdo->commit();

                // Delete original files from disk (after DB commit)
                foreach ($unmerged_docs as $doc) {
                    $disk_path = __DIR__ . '/' . ltrim($doc['path'], '/\\');
                    if (file_exists($disk_path)) {
                        @unlink($disk_path);
                    }
                }

                $_SESSION['flash'] = 'Documentos combinados correctamente: ' . $out_filename;
                header('Location: expediente.php?id=' . $id);
                exit;

            } catch (\Throwable $e) {
                // Roll back DB if transaction was open
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                // Clean up output file if it was partially written
                if (!empty($out_path) && file_exists($out_path)) {
                    @unlink($out_path);
                }
                $error = 'Error al combinar los documentos: ' . $e->getMessage();
            }
        }
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
      <p class="text-muted mb-4" style="font-size:0.9rem;">
        No hay tarjeta de registro cargada para combinar.
      </p>
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
          <?php foreach ($reconocimiento_opciones as $val => $label): ?>
            <option value="<?= htmlspecialchars($val) ?>"
              <?= $selected_nivel === (string)$val ? 'selected' : '' ?>>
              <?= htmlspecialchars($label) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <p class="text-muted mb-4" style="font-size:0.875rem;">
        El contrato se incluye automaticamente.
      </p>

      <div class="d-flex gap-2">
        <?php if (empty($unmerged_docs)): ?>
          <button type="submit" class="btn btn-io-blue" disabled>Combinar documentos</button>
        <?php else: ?>
          <button type="submit" class="btn btn-io-blue">Combinar documentos</button>
        <?php endif; ?>
        <a href="expediente.php?id=<?= $id ?>" class="btn btn-outline-secondary">Cancelar</a>
      </div>

    </form>

  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
