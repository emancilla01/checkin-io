<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
auth_require();

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
    $where .= " AND (e.nombre LIKE ? OR e.apellido LIKE ?)";
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

// --- Document status helper ---
function doc_status(array $row): string {
    if ($row['doc_id'] === null)      return 'Faltante';
    if ($row['doc_signed_at'] !== null) return 'Firmado';
    return 'Pendiente';
}

// --- Build sort link helper ---
function sort_link(string $col, string $label, ?string $current_sort, string $current_dir, string $search): string {
    $new_dir = ($current_sort === $col && $current_dir === 'ASC') ? 'desc' : 'asc';
    $q = http_build_query(['sort' => $col, 'direction' => $new_dir, 'search' => $search, 'page' => 1]);
    return "<a href=\"?$q\">$label</a>";
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Llegadas — IO</title>
</head>
<body>

<h1>Llegadas de hoy</h1>

<form method="GET" action="/index.php">
  <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
         placeholder="Buscar por nombre o apellido del huesped">
  <?php if ($sort):      ?><input type="hidden" name="sort"      value="<?= htmlspecialchars($sort) ?>"><?php endif; ?>
  <?php if ($direction): ?><input type="hidden" name="direction" value="<?= htmlspecialchars(strtolower($direction)) ?>"><?php endif; ?>
  <button type="submit">Buscar</button>
  <?php if ($search): ?>
    <a href="/index.php">Limpiar</a>
  <?php endif; ?>
</form>

<?php if (empty($rows)): ?>
  <p>No hay llegadas registradas hoy.</p>
<?php else: ?>

<table border="1" cellpadding="6" cellspacing="0">
  <thead>
    <tr>
      <th><?= sort_link('nombre',   'Nombre',   $sort, $direction, $search) ?></th>
      <th><?= sort_link('apellido', 'Apellido', $sort, $direction, $search) ?></th>
      <th>Fecha de llegada</th>
      <th>Estado del documento</th>
      <th>Estado de la identificacion</th>
      <th>Acciones</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($rows as $row): ?>
    <tr>
      <td><?= htmlspecialchars($row['nombre']) ?></td>
      <td><?= htmlspecialchars($row['apellido']) ?></td>
      <td><?= htmlspecialchars($row['fecha_llegada']) ?></td>
      <td><?= doc_status($row) ?></td>
      <td><?= !empty($row['identificacion_path']) ? 'Ok' : 'Faltante' ?></td>
      <td><a href="/expediente.php?id=<?= (int)$row['id'] ?>">Ver</a></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<?php if ($total_pages > 1): ?>
  <div>
    <?php if ($page > 1): ?>
      <a href="?<?= http_build_query(['search' => $search, 'sort' => $sort, 'direction' => strtolower($direction), 'page' => $page - 1]) ?>">Anterior</a>
    <?php endif; ?>

    <?php for ($p = 1; $p <= $total_pages; $p++): ?>
      <?php if ($p === $page): ?>
        <strong><?= $p ?></strong>
      <?php else: ?>
        <a href="?<?= http_build_query(['search' => $search, 'sort' => $sort, 'direction' => strtolower($direction), 'page' => $p]) ?>"><?= $p ?></a>
      <?php endif; ?>
    <?php endfor; ?>

    <?php if ($page < $total_pages): ?>
      <a href="?<?= http_build_query(['search' => $search, 'sort' => $sort, 'direction' => strtolower($direction), 'page' => $page + 1]) ?>">Siguiente</a>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php endif; ?>

</body>
</html>
