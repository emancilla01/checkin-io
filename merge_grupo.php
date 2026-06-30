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

// --- Flash ---
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// --- Search (applied in PHP after grouping) ---
$search = trim($_GET['search'] ?? '');

// --- Query: today's unmerged expedientes whose nombre+apellido pair appears more than once ---
// Uses a correlated EXISTS to find duplicates without CTEs (MySQL 5.7 compatible).
$sql = "
    SELECT
        e.id,
        e.apellido,
        e.nombre,
        e.fecha_llegada,
        e.crs_no,
        e.habitacion,
        COUNT(d.id) AS doc_count
    FROM expedientes e
    LEFT JOIN documentos d ON d.expediente_id = e.id AND d.is_merged = 0
    WHERE DATE(e.fecha_llegada) = CURDATE()
      AND NOT EXISTS (
          SELECT 1 FROM documentos dm
          WHERE dm.expediente_id = e.id AND dm.is_merged = 1
      )
      AND EXISTS (
          SELECT 1 FROM expedientes e2
          WHERE DATE(e2.fecha_llegada) = CURDATE()
            AND NOT EXISTS (
                SELECT 1 FROM documentos dm2
                WHERE dm2.expediente_id = e2.id AND dm2.is_merged = 1
            )
            AND LOWER(e2.nombre)   = LOWER(e.nombre)
            AND LOWER(e2.apellido) = LOWER(e.apellido)
            AND e2.id != e.id
      )
    GROUP BY e.id, e.apellido, e.nombre, e.fecha_llegada, e.crs_no, e.habitacion
    ORDER BY LOWER(e.apellido) ASC, LOWER(e.nombre) ASC, e.id ASC
";
$stmt = $pdo->query($sql);
$all_rows = $stmt->fetchAll();

// --- Group rows by normalised nombre+apellido ---
$groups = [];
foreach ($all_rows as $row) {
    $key = mb_strtolower($row['apellido']) . '|' . mb_strtolower($row['nombre']);
    if (!isset($groups[$key])) {
        $groups[$key] = [
            'apellido' => $row['apellido'],
            'nombre'   => $row['nombre'],
            'rows'     => [],
        ];
    }
    $groups[$key]['rows'][] = $row;
}

// --- Apply text search: filter whole groups ---
if ($search !== '') {
    $sl = mb_strtolower($search);
    $groups = array_filter($groups, function ($g) use ($sl) {
        return str_contains(mb_strtolower($g['apellido']), $sl)
            || str_contains(mb_strtolower($g['nombre']), $sl);
    });
}

// --- POST: perform a group merge ---
$merge_error     = '';
$merge_error_ids = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $checked_ids = array_values(array_unique(array_map('intval', $_POST['checked_ids'] ?? [])));
    $nivel       = $_POST['nivel_reconocimiento'] ?? '';

    if (count($checked_ids) < 2) {
        $merge_error = 'Debes seleccionar al menos 2 expedientes para combinar un grupo. '
                     . 'Para combinar uno solo, usa Combinar (masivo) o la página de expediente.';
    } else {
        $ph = implode(',', array_fill(0, count($checked_ids), '?'));

        // Load expedientes ordered by id ASC (primary = lowest id)
        $exp_stmt = $pdo->prepare("SELECT * FROM expedientes WHERE id IN ($ph) ORDER BY id ASC");
        $exp_stmt->execute($checked_ids);
        $checked_exps = $exp_stmt->fetchAll();

        if (count($checked_exps) !== count($checked_ids)) {
            $merge_error = 'Uno o más expedientes no fueron encontrados.';
        } else {
            // Guard: none may already have a merged doc
            $am_stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM documentos WHERE expediente_id IN ($ph) AND is_merged = 1"
            );
            $am_stmt->execute($checked_ids);
            if ((int)$am_stmt->fetchColumn() > 0) {
                $merge_error = 'Uno de los expedientes seleccionados ya tiene un documento combinado. '
                             . 'Elimina el documento combinado antes de combinar en grupo.';
            } else {
                $primary_exp   = $checked_exps[0];
                $redundant_ids = array_slice(array_column($checked_exps, 'id'), 1);

                // All unmerged docs: primary's first, then redundant, each by created_at ASC
                $docs_stmt = $pdo->prepare(
                    "SELECT * FROM documentos
                     WHERE expediente_id IN ($ph) AND is_merged = 0
                     ORDER BY expediente_id ASC, created_at ASC"
                );
                $docs_stmt->execute($checked_ids);
                $all_docs = $docs_stmt->fetchAll();

                $result = perform_group_merge(
                    $pdo, $primary_exp, $all_docs, $redundant_ids, $nivel, $staticDocsPath
                );

                if ($result === '') {
                    $count = count($checked_ids);
                    $_SESSION['flash'] = 'Grupo combinado: '
                        . $primary_exp['apellido'] . ', ' . $primary_exp['nombre']
                        . " ($count expedientes unificados).";
                    $qs = $search !== '' ? '?' . http_build_query(['search' => $search]) : '';
                    header('Location: merge_grupo.php' . $qs);
                    exit;
                } else {
                    $merge_error     = $result;
                    $merge_error_ids = $checked_ids;
                }
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
  <title>Combinar grupo — IO</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>

<?php $active_nav = 'merge-masivo'; include __DIR__ . '/includes/navbar.php'; ?>

<div class="container-lg py-4">

  <div class="io-page-header">
    <div>
      <h1>Combinar grupo</h1>
      <p class="text-muted mb-0" style="font-size:0.875rem;">
        Nombres duplicados entre las llegadas de hoy sin combinar.
      </p>
    </div>
    <a href="merge_masivo.php" class="btn btn-outline-secondary btn-sm">← Volver a masivo</a>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
      <?= htmlspecialchars($flash) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <?php if ($merge_error !== ''): ?>
    <div class="alert alert-danger">
      <?= htmlspecialchars($merge_error) ?>
    </div>
  <?php endif; ?>

  <!-- Search -->
  <div class="io-card">
    <form method="GET" action="merge_grupo.php" class="d-flex align-items-center gap-2">
      <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
             class="form-control form-control-sm" style="max-width:320px;"
             placeholder="Filtrar por nombre o apellido">
      <button type="submit" class="btn btn-outline-secondary btn-sm">Buscar</button>
      <?php if ($search !== ''): ?>
        <a href="merge_grupo.php" class="btn btn-link btn-sm text-muted">Limpiar</a>
      <?php endif; ?>
    </form>
  </div>

  <?php if (empty($groups)): ?>
    <div class="io-card">
      <p class="mb-0 text-muted">
        <?= $search !== '' ? 'Sin coincidencias para "' . htmlspecialchars($search) . '".' : 'No hay coincidencias de nombres pendientes hoy.' ?>
      </p>
    </div>
  <?php else: ?>

    <?php foreach ($groups as $gkey => $group):
      $g_apellido = $group['apellido'];
      $g_nombre   = $group['nombre'];
      $g_count    = count($group['rows']);
      $g_ids      = array_column($group['rows'], 'id');
      $has_error  = !empty(array_intersect($merge_error_ids, $g_ids));
    ?>
    <div class="io-card mb-3 <?= $has_error ? 'border-danger' : '' ?>">

      <div class="d-flex align-items-baseline gap-2 mb-3">
        <h6 class="fw-semibold mb-0" style="color:var(--io-navy);">
          <?= htmlspecialchars($g_apellido . ', ' . $g_nombre) ?>
        </h6>
        <span class="badge bg-secondary" style="font-size:0.75rem;">
          <?= $g_count ?> coincidencia<?= $g_count !== 1 ? 's' : '' ?>
        </span>
      </div>

      <form method="POST"
            action="merge_grupo.php<?= $search !== '' ? '?' . htmlspecialchars(http_build_query(['search' => $search])) : '' ?>">

        <div class="table-responsive mb-3">
          <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th style="width:2.5rem;"></th>
                <th>CRS No</th>
                <th>Habitacion</th>
                <th>Documento(s)</th>
                <th>Expediente</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($group['rows'] as $row):
                $has_docs  = (int)$row['doc_count'] > 0;
                $doc_label = (int)$row['doc_count'] === 1 ? '1 archivo' : $row['doc_count'] . ' archivos';
                $row_err   = in_array((int)$row['id'], $merge_error_ids);
              ?>
              <tr <?= $row_err ? 'class="table-danger"' : '' ?>>
                <td>
                  <input type="checkbox" name="checked_ids[]" value="<?= (int)$row['id'] ?>"
                         class="form-check-input group-check-<?= htmlspecialchars($gkey) ?>"
                         <?= $has_docs ? 'checked' : '' ?>>
                </td>
                <td class="text-muted"><?= !empty($row['crs_no']) ? htmlspecialchars($row['crs_no']) : '—' ?></td>
                <td class="text-muted"><?= !empty($row['habitacion']) ? htmlspecialchars($row['habitacion']) : '—' ?></td>
                <td>
                  <?php if ($has_docs): ?>
                    <span style="font-size:0.875rem;" class="text-muted"><?= $doc_label ?></span>
                  <?php else: ?>
                    <span class="text-danger" style="font-size:0.8rem;">Sin tarjeta</span>
                  <?php endif; ?>
                </td>
                <td>
                  <a href="expediente.php?id=<?= (int)$row['id'] ?>"
                     class="text-muted" style="font-size:0.8rem;" target="_blank">#<?= (int)$row['id'] ?></a>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="d-flex align-items-center gap-3 flex-wrap">
          <div style="min-width:220px;">
            <label class="form-label mb-1" style="font-size:0.8rem; color:var(--io-navy);">
              Nivel de reconocimiento
            </label>
            <select name="nivel_reconocimiento" class="form-select form-select-sm">
              <?php foreach (RECONOCIMIENTO_OPCIONES as $val => $label): ?>
                <option value="<?= htmlspecialchars($val) ?>"><?= htmlspecialchars($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mt-auto">
            <button type="submit" class="btn btn-io-blue btn-sm">
              Combinar grupo
            </button>
          </div>

          <p class="text-muted mb-0 mt-auto" style="font-size:0.8rem;">
            El expediente con el ID más bajo sobrevive; los demás se eliminan.
          </p>
        </div>

      </form>
    </div>
    <?php endforeach; ?>

  <?php endif; ?>

  <p class="text-muted" style="font-size:0.8rem;">
    <?= array_sum(array_map(fn($g) => count($g['rows']), $groups)) ?> expediente(s) con nombre duplicado hoy.
  </p>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
