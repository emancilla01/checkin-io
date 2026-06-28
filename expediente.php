<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
auth_require();

// Single expediente view — shows guest info, documents, ID, and signature for one record
