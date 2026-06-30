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
- No TypeScript, no Vite
- `setasign/fpdi` + `fpdf/fpdf` via Composer (PDF merge + signature stamping)
- Signature Pad v4.0.0 via CDN (guest signature capture)
- PDF.js 3.11.174 via cdnjs CDN (cross-browser PDF preview — Safari-safe)

---

## 2. Current File Structure

```
checkin-io/
│
├── config.php                  — DB credentials + date_default_timezone_set('America/Matamoros')
│                                 GITIGNORED — exists separately on each machine
│                                 Also defines $staticDocsPath (path to club.pdf, contrato.pdf, etc.)
│
├── schema.sql                  — Full DB schema (see Section 3). Run once per machine.
│
├── index.php                   — Llegadas: today's arrivals, search/sort/paginate, status badges,
│                                 inline Habitacion edit (AJAX → habitacion_update.php),
│                                 Firmar button per row, Ver/Editar/Combinar/Eliminar dropdown
├── base_datos.php              — All records, date-range search, same table/badges
├── registro_nuevo.php          — Create new expediente: OCR prefill step + manual form
├── expediente.php              — View single expediente: header actions, PDF.js doc preview,
│                                 identificacion card (primary inline + additional list)
├── expediente_editar.php       — Edit expediente fields + upload new doc/identificacion
├── expediente_delete.php       — POST-only: delete expediente + all files from disk, cascade DB
├── documento_delete.php        — POST-only: unlink file + DELETE documentos row
├── identificacion_delete.php   — POST-only: unlink file + DELETE documentos row (is_identificacion=1)
├── habitacion_update.php       — AJAX POST-only: UPDATE expedientes SET habitacion
├── firma_guardar.php           — AJAX POST-only: stamp signature PNG onto every page of merged PDF
│                                 via FPDI, overwrite file in place, set documentos.signed_at
├── login.php                   — Login form (username + password)
├── logout.php                  — Destroys session, redirects to login.php
├── cambiar_password.php        — Self-service password change (all roles). Verifies current
│                                 password before allowing change. PRG pattern.
│
├── bootstrap_admin.php         — ONE-TIME CLI script to create first admin user.
│                                 DELETE after use. Do NOT deploy to production.
│
├── carga_masiva.php            — Batch OCR upload (up to 60 PDFs). AJAX one-file-at-a-time.
├── carga_masiva_ocr.php        — AJAX endpoint: receives one PDF, runs OCR, returns JSON
├── carga_masiva_guardar.php    — AJAX endpoint: receives JSON array, saves expedientes + documentos
│
├── merge.php                   — Individual merge page
├── merge_masivo.php            — Bulk merge: today's unmerged arrivals
├── merge_grupo.php             — Group merge: duplicate nombre+apellido pairs, checkbox multi-select
│
├── usuarios.php                — Admin-only user management: table of all users, per-row actions
│                                 (Editar rol, Restablecer contrasena, Eliminar via Bootstrap modals)
├── usuario_crear.php           — POST-only (admin): validate + INSERT new user
├── usuario_rol.php             — POST-only (admin): UPDATE users SET role
├── usuario_password.php        — POST-only (admin): UPDATE users SET password_hash
├── usuario_delete.php          — POST-only (admin): DELETE user, guard against self-deletion
│
├── includes/
│   ├── auth.php                — auth_attempt(), auth_check(), auth_require(),
│   │                             auth_require_role(array), auth_role(),
│   │                             auth_logout(), auth_start_session()
│   ├── db.php                  — PDO connection using config.php variables
│   ├── navbar.php              — Shared navbar. Set $active_nav before including.
│   │                             Valid values: 'llegadas' | 'base-de-datos' | 'registro-nuevo' |
│   │                             'carga-masiva' | 'merge-masivo' | 'usuarios' | 'cambiar-password'
│   │                             "Usuarios" link only rendered for role=admin.
│   │                             "Cambiar contrasena" link rendered for all logged-in users.
│   ├── footer.php              — "Sesion iniciada como: [nombre]"
│   ├── firma_modal.php         — Shared Firmar modal partial. Include once per page before </body>.
│   │                             Loads PDF.js CDN + pdf-viewer.js + Signature Pad CDN.
│   │                             Trigger buttons need: data-firma-expid, data-firma-docpath,
│   │                             data-firma-nombre.
│   ├── merge_helper.php        — Shared PDF merge logic. perform_merge(), perform_group_merge().
│   └── ocr/
│       ├── PdfFirstPageImageConverter.php
│       ├── TesseractOcrService.php
│       └── RegisterCardTextParser.php
│
├── assets/
│   ├── css/app.css             — Brand CSS vars + .io-navbar, .io-card, .io-upload-box,
│   │                             .io-footer, .io-page-header, .btn-io-blue, .btn-io-orange,
│   │                             dropdown unclip fix, active nav link color
│   └── js/
│       ├── upload-boxes.js     — Drag-and-drop upload box wiring for [data-upload-box] elements
│       └── pdf-viewer.js       — initPdfViewer(container, url): PDF.js canvas renderer.
│                                 Replaces <iframe> PDF previews. Renders all pages sequentially
│                                 into stacked <canvas> elements inside a scrollable container.
│                                 Caps dpr at 2 for iPad memory safety. Safari-compatible.
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
    identificacion_path VARCHAR(500)    NULL,   -- legacy column, kept but no longer written
    crs_no              VARCHAR(50)     NULL,
    habitacion          VARCHAR(20)     NULL,
    created_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS documentos (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    expediente_id     BIGINT UNSIGNED NOT NULL,
    path              VARCHAR(500)    NOT NULL,
    original_name     VARCHAR(255)    NULL,
    is_merged         TINYINT(1)      NOT NULL DEFAULT 0,   -- 1 = the one merged PDF
    is_identificacion TINYINT(1)      NOT NULL DEFAULT 0,   -- 1 = ID document (INE, passport, etc.)
    signed_at         TIMESTAMP       NULL,                  -- set when merged PDF is signed
    created_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_documentos_expediente
        FOREIGN KEY (expediente_id) REFERENCES expedientes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**is_identificacion migration** — this column was added after initial deploy. If schema.sql was already applied, run on each machine:
```sql
ALTER TABLE documentos ADD COLUMN is_identificacion TINYINT(1) NOT NULL DEFAULT 0 AFTER is_merged;
```

**Roles:**
- `admin` — full access including user management and deleting any record
- `editor` — daily ops: create/edit/merge/sign; can delete today's arrivals only; cannot manage users
- `viewer` — read-only: index.php, base_datos.php, expediente.php only; no action buttons shown

---

## 4. What's Working and Confirmed

### Auth & Roles
- Login/logout/session fully working. Roles stored in `$_SESSION['role']` and `$_SESSION['user_id']`.
- `auth_require_role(['admin', 'editor'])` enforced at top of every write endpoint and page.
- Admin-only pages: `usuarios.php` and all `usuario_*.php` endpoints.
- Viewer UI: action buttons (Editar, Eliminar, Combinar, Firmar, inline Habitacion input) hidden on all three viewer-accessible pages. Ver button always visible.
- `base_datos.php` Eliminar: admin always, editor only for today's arrivals, viewer never.
- Flash messages: stored as `['type' => 'warning'|'success', 'message' => '...']` array. index.php renders `alert-warning` vs `alert-success` accordingly. Old string-format flash still handled for backwards compatibility.

### Pages & Features
- **index.php / base_datos.php:** search/sort/paginate, status badges, inline Habitacion edit (AJAX), Firmar button per row.
- **registro_nuevo.php:** OCR prefill (Step 1 → Step 2), drag-and-drop, full-width grid layout (Apellido|Nombre / Fecha|CRS / uploads).
- **expediente.php:** PDF.js canvas viewer for merged doc (Safari-compatible). Identificacion card shows first uploaded as inline preview; additional IDs listed with Abrir+Eliminar.
- **expediente_editar.php:** full-width grid layout matching registro_nuevo.
- **firma_guardar.php:** receives base64 PNG, stamps it via FPDI onto **every page** of the merged PDF at `x=70mm, y=(pageHeight−45mm), w=99mm`, overwrites file in place, sets `signed_at`.
- **firma modal (includes/firma_modal.php):** PDF.js viewer (55vh), "He leido y acepto" checkbox gates Signature Pad canvas, Limpiar/Guardar. On success: modal closes, page reloads. Included on index.php and expediente.php.
- **usuarios.php:** user table with role badges (navy=admin, blue=editor, gray=viewer). Editar rol / Restablecer contrasena / Eliminar via Bootstrap modals. Self-delete guarded in both UI (disabled item) and endpoint.
- **cambiar_password.php:** self-service for all roles. Verifies current password before allowing change. PRG redirect on success.
- **Merge pipeline:** individual, bulk, and group merge all working. FPDF class alias pattern established.
- **OCR pipeline:** carga_masiva and registro_nuevo both working.

### PDF Viewer (assets/js/pdf-viewer.js)
- `initPdfViewer(container, url)` renders all pages sequentially into stacked `<canvas>` elements.
- Fixes FPDI Form XObject structure incompatibility with Safari/iOS PDFKit — native `<iframe>` only showed first page on iPad.
- Used in: firma modal (55vh scrollable container) and expediente.php Documento card (400px).
- dpr capped at 2 to prevent OOM on older iPads. `-webkit-overflow-scrolling:touch` for momentum scroll.
- PDF.js + worker both loaded from cdnjs.cloudflare.com at version 3.11.174.

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
- Multiple expedientes. `all_docs` is pre-flattened, ordered `expediente_id ASC, created_at ASC`.
- Same PDF build order as perform_merge.
- DB transaction: DELETE unmerged doc rows → INSERT merged row on primary → DELETE redundant expedientes (CASCADE removes their docs) → commit. After commit: unlink original files.

**FPDF class alias** (required by all merge callers and firma_guardar.php):
```php
if (!class_exists('FPDF')) {
    class_alias(\Fpdf\Fpdf::class, 'FPDF');
}
```

---

## 6. OCR Pipeline

Flow: `registro_nuevo.php` / `carga_masiva_ocr.php` → `PdfFirstPageImageConverter` (pdftoppm) → `TesseractOcrService` (--psm 6, spa+eng) → `RegisterCardTextParser`

**RegisterCardTextParser key facts:**
- `ALL_CARD_LABELS` is used as a stop-word list in `trimAtNextLabel()`.
- Inline regex: `'/(?:^|\s)' . preg_quote($alias, '/') . '\s*(?:[:.]\s*|\s+)(.+)/i'` — handles both delimiter and no-delimiter cases (e.g. "CRS No 62617537").
- `fecha_llegada` aliases: `['llegada', 'arrival', 'arrival date', 'arr. date', 'arr date', 'fecha llegada', 'fecha de llegada']`
- `crs_no` aliases: `['crs no.', 'crs no', 'crs number', 'crs#']`
- `normaliseDate()` handles DD-MM-YY OPERA card format.

**Binary resolution — production server specifics:**
- Tesseract: hardcoded to `C:\Program Files\Tesseract-OCR\tesseract.exe` in `TesseractOcrService::resolveBinary()`. Spanish pack at `tessdata\spa.traineddata` → lang selected as `spa+eng`.
- Poppler/pdftoppm: installed at `C:\poppler-26.02.0\Library\bin\pdftoppm.exe`. This path is hardcoded as a candidate in `PdfFirstPageImageConverter::resolveBinary()`. **Do not rely on PATH for pdftoppm under Apache** — Apache was started before Poppler was added to the system PATH, so its process doesn't inherit it. The hardcoded path is what makes it work.
- If pdftoppm is ever reinstalled to a different path, update the candidates list in `PdfFirstPageImageConverter::resolveBinary()` or set the `PDFTOPPM_BINARY` env var.

**Error visibility:** the OCR catch block in `registro_nuevo.php` calls `error_log('[IO OCR] ...')` so exceptions appear in `C:\xampp\apache\logs\error.log` even though the user sees only the generic "no pudo extraer" message.

---

## 7. Remaining / Known Issues

- **Move uploads/ outside public web root** — planned but not yet implemented. Full audit was done (see below); plan is ready to execute.
- **identificacion_path** column on `expedientes` is a legacy remnant — kept to avoid a schema migration, but nothing writes to it anymore. All ID files now use `documentos` with `is_identificacion=1`.
- **Signature position** on the merged PDF is tunable in `firma_guardar.php`: currently `x=70, y=(pageHeight−45), w=99` mm on every page. May need further fine-tuning per the actual contract layout.

### Planned: move uploads to `C:\io-data\uploads\`

Audit completed. Current state of path storage:

**Database format:** all paths stored as `uploads/<filename>` (relative, no machine prefix). Examples: `uploads/Morales_Chavez_Carlos_Adrian_290626.pdf`, `uploads/cid_ibarra_roberto_daniel_207.pdf`.

**Write sites** (all use `'uploads/' . basename($dest)` pattern):
`registro_nuevo.php`, `expediente_editar.php`, `carga_masiva_guardar.php`, `merge_helper.php` (×2 for individual and group merge). Each defines `UPLOAD_DIR` locally as `__DIR__ . '/uploads/'` except merge_helper which uses `__DIR__ . '/../uploads/'`.

**Read/display sites** (`expediente.php`): DB path used **as-is as a web URL** — `href`, `src`, `data-pdf-url` all emit `uploads/filename` directly. Works because `uploads/` is inside the web root.

**Delete/filesystem sites**: `expediente_delete.php`, `documento_delete.php`, `identificacion_delete.php`, `merge_helper.php`, `firma_guardar.php` all reconstruct the absolute path via `__DIR__ . '/' . ltrim($path, '/\\')`.

**Migration plan (minimum changes):**
1. `config.php`: add `$uploadDir = 'C:\io-data\uploads\\';`
2. Add Apache Alias: `Alias /checkin-io/uploads C:\io-data\uploads` in `httpd.conf` — this keeps the `uploads/filename` URL format working with zero display-code changes.
3. All 5 write sites: replace local `UPLOAD_DIR` define with `$uploadDir` from config.
4. All 5 delete/filesystem sites: replace `__DIR__ . '/' . ltrim($path, '/\\')` with `$uploadDir . basename($path)`.
5. No DB migration needed — stored format stays `uploads/<filename>`.

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
