<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
auth_require();

// Arrivals list page — shows all guest arrivals registered today, with search and sorting
