# IO — Handoff Summary

## 1. Project Overview

**IO** (Hotel Check-In) is a staff-facing web app for a Holiday Inn Express property in Piedras Negras, Coahuila. It manages guest arrivals: creating expedientes, uploading register cards and IDs, tracking document and signature status.

**This is a port/rebuild of an existing Laravel prototype** at:
`https://github.com/emancilla01/Sem8TallerInv2Zenzze` (branch: `test2`)

The rule is: **stay as faithful as possible to the prototype's actual UI and behavior** unless explicitly instructed to change something. Do not redesign or add features not in the prototype or not explicitly requested.

**Stack:**
- Plain PHP + PDO (no Laravel, no Composer autoloader beyond fpdi/fpdf)
- MySQL 8.0
- Bootstrap 5 (CDN, no build step)
- No TypeScript, no Vite — one plain `upload-boxes.js` in `assets/js/`
- `setasign/fpdi` + `fpdf/fpdf` via Composer (for PDF merge/signature, partially built)

---

## 2. Current File Structure

```
D:\projects\io\
│
├── config.php                  — DB credentials + date_default_timezone_set('America/Matamoros')
│                                 GITIGNORED — exists separately on each machine
│                                 Also defines $staticDocsPath (path to club.pdf, contrato.pdf, etc.)
│
├── schema.sql                  — Full DB schema, run once per machine (see Section 3)
│
├── index.php                   — Llegadas page: today's arrivals, search/sort/paginate,
│                                 Ver/Editar/Eliminar/Combinar dropdown, status badges
├── base_datos.php              — All records (no date filter), date-range search, same table/badges
├── registro_nuevo.php          — Create new expediente: OCR prefill step + manual form
├── expediente.php              — View single expediente: header info line, doc preview, ID preview
├── expediente_editar.php       — Edit expediente fields + upload new doc/identificacion
├── expediente_delete.php       — POST-only: delete expediente + files from disk, cascade DB
├── documento_delete.php        — POST-only: unlink file + DELETE documentos row, redirect to expediente
├── identificacion_delete.php   — POST-only: unlink file + NULL identificacion_path, redirect to expediente
├── login.php                   — Login form (username + password, Spanish error message)
├── logout.php                  — Destroys session, redirects to login.php
│
├── bootstrap_admin.php         — ONE-TIME CLI script to create first admin user.
│                                 DELETE after use. Do NOT deploy to production.
│
├── carga_masiva.php            — Batch OCR upload (up to 60 PDFs). AJAX one-file-at-a-time.
│                                 Results table with checkboxes + Guardar buttons.
├── carga_masiva_ocr.php        — AJAX endpoint: receives one PDF, runs OCR, returns JSON
├── carga_masiva_guardar.php    — AJAX endpoint: receives JSON array, saves expedientes + documentos
│
├── merge.php                   — Individual merge page (reached from expediente.php or index/base_datos dropdown)
├── merge_masivo.php            — Bulk merge: today's unmerged arrivals, one Combinar per row
├── merge_grupo.php             — Group merge: today's arrivals with duplicate nombre+apellido pairs,
│                                 checkbox multi-select, merges and deletes redundant expedientes
│
├── includes/
│   ├── auth.php                — auth_attempt(), auth_check(), auth_require(),
│   │                             auth_role(), auth_logout(), auth_start_session()
│   ├── db.php                  — PDO connection using config.php variables
│   ├── navbar.php              — Shared navbar partial; set $active_nav before including
│   │                             Values: 'llegadas' | 'base-de-datos' | 'registro-nuevo' |
│   │                                     'carga-masiva' | 'merge-masivo'
│   │                             (merge_grupo.php sets active_nav = 'merge-masivo')
│   ├── footer.php              — Shared footer: "Sesion iniciada como: [nombre]"
│   ├── merge_helper.php        — Shared PDF merge logic. Defines TIER_FILES, RECONOCIMIENTO_OPCIONES,
│   │                             UPLOAD_DIR. Exports perform_merge() and perform_group_merge().
│   └── ocr/
│       ├── PdfFirstPageImageConverter.php  — Wraps pdftoppm; converts PDF p.1 to PNG
│       ├── TesseractOcrService.php         — Wraps tesseract binary; returns raw text
│       └── RegisterCardTextParser.php      — Parses OCR text → apellido/nombre/fecha_llegada/crs_no
│
├── assets/
│   ├── css/app.css             — Brand CSS vars + .io-navbar, .io-card, .io-upload-box,
│   │                             .io-footer, .io-page-header, .btn-io-blue,
│   │                             .io-card .table-responsive { overflow: visible } (dropdown unclip fix)
│   │                             .io-navbar .nav-link.active { color: var(--io-orange) }
│   └── js/upload-boxes.js      — Drag-and-drop upload box wiring for [data-upload-box] elements
│
├── uploads/                    — Uploaded files (docs, IDs). TODO: move outside web root in prod.
├── private/uploads-temp/       — Temp storage for OCR reg card PDFs before expediente is saved
│
├── vendor/                     — Composer packages (fpdi, fpdf)
├── composer.json / composer.lock
└── dashboard_mockup.html       — Static visual mockup (early reference, not part of app)
```

**Static docs** (stored at `$staticDocsPath`, gitignored path configured per machine):
`club.pdf`, `silver_elite.pdf`, `gold_elite.pdf`, `platinum_elite.pdf`, `diamond_elite.pdf`, `contrato.pdf`

---

## 3. Database Schema

Database name: **`io_db`**
Timezone on all machines: **`America/Matamoros`** (Piedras Negras follows US Central Time rules — NOT Mexico City).

```sql
CREATE TABLE IF NOT EXISTS users (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username      VARCHAR(100)    NOT NULL UNIQUE,
    nombre        VARCHAR(255)    NOT NULL,
    password_hash VARCHAR(255)    NOT NULL,
    role          ENUM('admin','editor','viewer') NOT NULL DEFAULT 'viewer',
    created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS expedientes (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre              VARCHAR(255)    NOT NULL,
    apellido            VARCHAR(255)    NOT NULL,
    fecha_llegada       DATE            NOT NULL,
    identificacion_path VARCHAR(500)    NULL,
    crs_no              VARCHAR(50)     NULL,   -- booking/CRS number from register card; no uniqueness constraint
    habitacion          VARCHAR(20)     NULL,   -- filled in manually later once room assigned
    created_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS documentos (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    expediente_id   BIGINT UNSIGNED NOT NULL,
    path            VARCHAR(500)    NOT NULL,
    original_name   VARCHAR(255)    NULL,
    is_merged       TINYINT(1)      NOT NULL DEFAULT 0,  -- 1 = the one merged PDF; 0 = individual uploads
    signed_at       TIMESTAMP       NULL,                 -- set in-place when merged doc is signed
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_documentos_expediente
        FOREIGN KEY (expediente_id) REFERENCES expedientes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Roles:**
- `admin` — full access including user management and deleting historical records
- `editor` — daily ops: create/edit expedientes, upload docs, merge, sign; cannot manage users or delete historical records
- `viewer` — read-only everywhere, no date restriction

---

## 4. What's Working and Confirmed

- **Auth:** login/logout/session working. Roles stored in session (`$_SESSION['role']`). **Role-based permission enforcement is NOT yet built** — any logged-in user can do anything for now.
- **index.php / base_datos.php:** search/sort/paginate, status badges (Faltante/Pendiente/Firmado for doc; Faltante/Ok for ID), dropdown actions including Combinar (disabled with tooltip if already merged).
- **registro_nuevo.php:** OCR prefill (Step 1 → Step 2). Drag-and-drop upload boxes. Temp PDF attached as first documento on save.
- **expediente.php:** page header shows h1 (Apellido, Nombre), fecha de llegada subtitle, and a horizontal detail line (Apellido / Nombre / CRS No / Habitacion) using `display:flex; flex-wrap:wrap; gap`. Documento card and Identificacion card start at the same visual height — no info grid inside the Documento card. Merged PDF shown as inline `<iframe>` with Eliminar button. Unmerged docs listed with Abrir + Eliminar each. Identificacion shown as image or iframe with Abrir + Eliminar.
- **expediente_editar.php / expediente_delete.php:** working.
- **documento_delete.php / identificacion_delete.php:** POST-only, unlink + DB update, flash + redirect.
- **carga_masiva.php:** AJAX one-file-at-a-time, progressive results table, status badges (listo/incompleto/error), Guardar buttons. MutationObserver drives button enable state (not change event).
- **merge.php:** individual merge with tier dropdown. Guards against direct URL access when already merged. Calls `perform_merge()` from merge_helper.php.
- **merge_masivo.php:** today's unmerged arrivals only (`NOT EXISTS` subquery). Per-row `<form id="form-N">` + `<button form="form-N">` pattern. "Combinar grupo" link in page header.
- **merge_grupo.php:** finds today's unmerged expedientes whose exact nombre+apellido appears more than once. Groups visually by name with count badge. Per-group checkbox table + nivel dropdown + Combinar grupo button. Validates ≥ 2 checked. Primary = lowest id (first registered). Redundant expedientes fully deleted (identificacion files unlinked, rows deleted). Calls `perform_group_merge()` from merge_helper.php.
- **Brand colors:** `--io-navy: #001f4f`, `--io-blue: #1658b8`, `--io-orange: #e35205`, `--io-bg: #f5f6f8`, `--io-surface: #ffffff`

---

## 5. merge_helper.php — Key Design

File: `includes/merge_helper.php`. Required by merge.php, merge_masivo.php, merge_grupo.php.

**Constants:**
```php
UPLOAD_DIR           // __DIR__ . '/../uploads/'
TIER_FILES           // ['club' => 'club.pdf', 'silver_elite' => ..., ...]
RECONOCIMIENTO_OPCIONES  // ['' => 'Sin reconocimiento', 'club' => 'Club', ...]
```

**`perform_merge(PDO, exp, unmerged_docs, nivel, static_path): string`**
- Single expediente. Returns `''` on success, error string on failure.
- Merge order: (a) tier PDF if selected, (b) unmerged docs in created_at ASC, (c) contrato.pdf always last.
- Output filename: `Apellido_Nombre_DDMMYY.pdf` with `_2`, `_3`... collision numbering.
- DB: `beginTransaction()` → DELETE unmerged rows → INSERT merged row → `commit()` → `@unlink()` originals.
- On failure: `rollBack()` + delete partial output file.

**`perform_group_merge(PDO, primary_exp, all_docs, redundant_ids, nivel, static_path): string`**
- Multiple expedientes. `all_docs` is a pre-flattened array of all is_merged=0 docs across every checked expediente, ordered `expediente_id ASC, created_at ASC` (primary's docs come first because primary has the lowest id).
- Same PDF build order as perform_merge (tier → docs → contrato).
- DB transaction: DELETE all unmerged doc rows → INSERT merged row on primary → fetch redundant identificacion_paths → DELETE remaining documentos on redundant expedientes (safety net) → DELETE redundant expediente rows → commit.
- After commit: unlink original doc files + unlink redundant identificacion files.

**FPDF class alias** (required by all merge callers — FPDI's FpdfTpl extends `\FPDF` global, but fpdf/fpdf ^1.86 is PSR-4 namespaced as `\Fpdf\Fpdf`):
```php
if (!class_exists('FPDF')) {
    class_alias(\Fpdf\Fpdf::class, 'FPDF');
}
```

---

## 6. OCR Pipeline

Flow: `registro_nuevo.php` / `carga_masiva_ocr.php` → `PdfFirstPageImageConverter` (pdftoppm) → `TesseractOcrService` (--psm 6, spa+eng) → `RegisterCardTextParser`

**RegisterCardTextParser key facts:**
- `ALL_CARD_LABELS` is used as a stop-word list in `trimAtNextLabel()` — any recognized label followed by `:` or `.` truncates the captured value.
- Inline regex: `'/(?:^|\s)' . preg_quote($alias, '/') . '\s*(?:[:.]\s*|\s+)(.+)/i'` — delimiter (colon/period) OR plain whitespace, handles "CRS No 62617537" with no delimiter.
- `fecha_llegada` aliases: `['llegada', 'arrival', 'arrival date', 'arr. date', 'arr date', 'fecha llegada', 'fecha de llegada']`
- `crs_no` aliases: `['crs no.', 'crs no', 'crs number', 'crs#']` — no 'conf' or booking-related aliases.
- `normaliseDate()` handles DD-MM-YY OPERA card format.

---

## 7. Not Yet Built (Priority Order)

1. **Firmar (signature) modal/flow**: signs the merged PDF only, in-place (stamps existing file, updates `signed_at`); button disabled until a merged doc exists. The `signed_at` column already exists in `documentos`.
2. **User management page** (admin-only): create/edit/delete users, reset passwords, assign roles
3. **Role-based permission enforcement**: viewer = read-only everywhere; editor = no user management, no historical deletes; admin = full access (currently any logged-in user can do anything)
4. **Self-service password change** (any role)
5. **Move uploads/ outside public web root** in production

---

## 8. Environment Details

| | Laptop (dev) | Production server |
|---|---|---|
| **PHP** | Laravel Herd | XAMPP (`C:\xampp\php\php.exe`) |
| **MySQL** | Standalone MySQL 8.0 (`C:\Program Files\MySQL\MySQL Server 8.0`) | XAMPP bundled MySQL (`C:\xampp\mysql\bin\mysql.exe`) |
| **App URL** | `http://io.test/` | `http://10.156.56.75/checkin-io/` (Ethernet) / `http://192.168.137.1/checkin-io/` (iPad wifi) |
| **App path** | `D:\projects\io\` | `C:\xampp\htdocs\checkin-io\` |
| **MySQL port** | 3306 | 3306 |

**config.php** (gitignored — must be created manually on each machine):
- Laptop: `$db_host = '127.0.0.1'`, password = `Ch33rlos.`
- Server: `$db_host = 'localhost'`, password = blank (default XAMPP)
- Both: `$staticDocsPath` = absolute path to the static-docs directory

**External binaries needed for OCR:**
- `pdftoppm` (Poppler) — for PDF-to-image conversion
- `tesseract` — for OCR text extraction
- The PHP services resolve binaries via env var → known Windows path → PATH fallback

**Timezone:** `America/Matamoros` — set in `config.php` via `date_default_timezone_set()`. Piedras Negras follows **US Central Time rules** (not Mexico City's).
