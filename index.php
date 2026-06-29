<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
auth_require();
// branched off to first version of the app, so we can test new features without affecting the main version. 
// has everything from v1. still needs signature capture, user management, and other features.
// --- Search ---
$search = trim($_GET['search'] ?? '');

// --- Sort ---
$allowed_sort = ['nombre', 'apellido'];
$sort = in_array($_GET['sort'] ?? '', $allowed_sort) ? $_GET['sort'] : null;
$direction = strtolower($_GET['direction'] ?? '') === 'desc' ? 'DESC' : 'ASC';

$order_clause = $sort
    ? "ORDER BY e.$sort $direction"
    : "ORDER BY e.apellido ASC, e.nombre ASC";

// --- Pagination ---
$per_page = 20;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $per_page;

// --- Base WHERE ---
$where = "WHERE DATE(e.fecha_llegada) = CURDATE()";
$params = [];

if ($search !== '') {
    $where .= " AND (e.nombre LIKE ? OR e.apellido LIKE ? OR e.crs_no LIKE ? OR e.habitacion LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

// --- Count for pagination ---
$count_sql = "SELECT COUNT(*) FROM expedientes e $where";
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($params);
$total = (int)$count_stmt->fetchColumn();
$total_pages = (int)ceil($total / $per_page);

// --- Main query with document status ---
$sql = "
    SELECT
        e.id,
        e.nombre,
        e.apellido,
        e.fecha_llegada,
        e.crs_no,
        e.habitacion,
        e.identificacion_path,
        d.id        AS doc_id,
        d.signed_at AS doc_signed_at
    FROM expedientes e
    LEFT JOIN documentos d ON d.expediente_id = e.id AND d.is_merged = 1
    $where
    $order_clause
    LIMIT $per_page OFFSET $offset
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// --- Flash message ---
auth_start_session();
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// --- Document status helper ---
function doc_status(array $row): string {
    if ($row['doc_id'] === null)         return 'Faltante';
    if ($row['doc_signed_at'] !== null)  return 'Firmado';
    return 'Pendiente';
}

// --- Sort link helper ---
function sort_link(string $col, string $label, ?string $current_sort, string $current_dir, string $search): string {
    $new_dir = ($current_sort === $col && $current_dir === 'ASC') ? 'desc' : 'asc';
    $q = http_build_query(['sort' => $col, 'direction' => $new_dir, 'search' => $search, 'page' => 1]);
    $arrow = '';
    if ($current_sort === $col) {
        $arrow = $current_dir === 'ASC' ? ' ↑' : ' ↓';
    }
    return '<a href="?' . $q . '" class="text-decoration-none text-dark fw-semibold">' . $label . $arrow . '</a>';
}

// --- Pagination query string helper ---
function page_qs(int $p, string $search, ?string $sort, string $direction): string {
    return http_build_query([
        'search'    => $search,
        'sort'      => $sort ?? '',
        'direction' => strtolower($direction),
        'page'      => $p,
    ]);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Llegadas — IO</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>

<?php $active_nav = 'llegadas'; include __DIR__ . '/includes/navbar.php'; ?>

<!-- Page content -->
<div class="container-lg py-4">

  <!-- Flash message -->
  <?php if ($flash): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
      <?= htmlspecialchars($flash) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <!-- Heading row -->
  <div class="io-page-header">
    <h1>Llegadas de hoy (TEST VERSION)</h1>
    <a href="registro_nuevo.php" class="btn btn-io-blue btn-sm">Agregar registro</a>
  </div>

  <!-- Search card -->
  <div class="io-card">
    <form method="GET" action="index.php" class="d-flex align-items-center gap-2">
      <label for="search" class="form-label mb-0 text-nowrap">Buscar por nombre o apellido del huesped</label>
      <input type="text" id="search" name="search" class="form-control form-control-sm"
             value="<?= htmlspecialchars($search) ?>" placeholder="Nombre o apellido...">
      <?php if ($sort):      ?><input type="hidden" name="sort"      value="<?= htmlspecialchars($sort) ?>"><?php endif; ?>
      <?php if ($direction): ?><input type="hidden" name="direction" value="<?= htmlspecialchars(strtolower($direction)) ?>"><?php endif; ?>
      <button type="submit" class="btn btn-io-blue btn-sm text-nowrap">Buscar</button>
      <?php if ($search): ?>
        <a href="index.php" class="btn btn-outline-secondary btn-sm text-nowrap">Limpiar</a>
      <?php endif; ?>
    </form>
  </div>

  <!-- Table card -->
  <div class="io-card p-0">
    <?php if (empty($rows)): ?>
      <p class="p-4 mb-0 text-muted">No hay llegadas registradas hoy.</p>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th><?= sort_link('apellido', 'Apellido', $sort, $direction, $search) ?></th>
            <th><?= sort_link('nombre',   'Nombre',   $sort, $direction, $search) ?></th>
            <th>Fecha de llegada</th>
            <th>CRS No</th>
            <th>Habitacion</th>
            <th>Estado del registro</th>
            <th>Estado de la identificacion</th>
            <th>Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row):
            $status = doc_status($row);
            $id_ok  = !empty($row['identificacion_path']);
          ?>
          <tr>
            <td><?= htmlspecialchars($row['apellido']) ?></td>
            <td><?= htmlspecialchars($row['nombre']) ?></td>
            <td><?= htmlspecialchars($row['fecha_llegada']) ?></td>
            <td class="text-muted"><?= !empty($row['crs_no'])    ? htmlspecialchars($row['crs_no'])    : '-' ?></td>
            <td class="text-muted"><?= !empty($row['habitacion']) ? htmlspecialchars($row['habitacion']) : '-' ?></td>
            <td>
              <?php if ($status === 'Firmado'): ?>
                <span class="badge bg-success">Firmado</span>
              <?php elseif ($status === 'Pendiente'): ?>
                <span class="badge bg-warning text-dark">Pendiente</span>
              <?php else: ?>
                <span class="badge bg-danger">Faltante</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($id_ok): ?>
                <span class="badge bg-success">Ok</span>
              <?php else: ?>
                <span class="badge bg-danger">Faltante</span>
              <?php endif; ?>
            </td>
            <td>
              <div class="d-flex gap-1">
                <a href="expediente.php?id=<?= (int)$row['id'] ?>"
                   class="btn btn-io-blue btn-sm">Ver</a>
                <div class="dropdown">
                  <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button"
                          data-bs-toggle="dropdown" aria-expanded="false">Mas</button>
                  <ul class="dropdown-menu dropdown-menu-end">
                    <li>
                      <a class="dropdown-item" href="expediente_editar.php?id=<?= (int)$row['id'] ?>">Editar</a>
                    </li>
                    <li>
                      <?php if ($row['doc_id'] !== null): ?>
                        <span class="dropdown-item text-muted" style="cursor:default;"
                              title="Este expediente ya tiene un documento combinado.">Combinar</span>
                      <?php else: ?>
                        <a class="dropdown-item" href="merge.php?id=<?= (int)$row['id'] ?>">Combinar</a>
                      <?php endif; ?>
                    </li>
                    <li>
                      <form method="POST" action="expediente_delete.php"
                            onsubmit="return confirm('¿Estas seguro de eliminar este registro?');">
                        <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                        <button type="submit" class="dropdown-item text-danger">Eliminar</button>
                      </form>
                    </li>
                  </ul>
                </div>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div class="d-flex justify-content-center py-3">
      <nav>
        <ul class="pagination pagination-sm mb-0">
          <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
            <a class="page-link" href="?<?= page_qs($page - 1, $search, $sort, $direction) ?>">Anterior</a>
          </li>
          <?php for ($p = 1; $p <= $total_pages; $p++): ?>
          <li class="page-item <?= $p === $page ? 'active' : '' ?>">
            <a class="page-link" href="?<?= page_qs($p, $search, $sort, $direction) ?>"><?= $p ?></a>
          </li>
          <?php endfor; ?>
          <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
            <a class="page-link" href="?<?= page_qs($page + 1, $search, $sort, $direction) ?>">Siguiente</a>
          </li>
        </ul>
      </nav>
    </div>
    <?php endif; ?>

    <?php endif; ?>
  </div><!-- /table card -->

</div><!-- /container -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
