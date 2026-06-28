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
│
├── schema.sql                  — Full DB schema, run once per machine (see Section 3)
│
├── index.php                   — Llegadas page: today's arrivals, search/sort/paginate,
│                                 Ver/Editar/Eliminar dropdown, status badges
├── base_datos.php              — All records (no date filter), date-range search, same table/badges
├── registro_nuevo.php          — Create new expediente: OCR prefill step + manual form
├── expediente.php              — View single expediente: info grid, doc list, ID preview
├── expediente_editar.php       — Edit expediente fields + upload new doc/identificacion
├── expediente_delete.php       — POST-only: delete expediente + files from disk, cascade DB
├── login.php                   — Login form (username + password, Spanish error message)
├── logout.php                  — Destroys session, redirects to login.php
│
├── bootstrap_admin.php         — ONE-TIME CLI script to create first admin user.
│                                 DELETE after use. Do NOT deploy to production.
│
├── includes/
│   ├── auth.php                — auth_attempt(), auth_check(), auth_require(),
│   │                             auth_role(), auth_logout(), auth_start_session()
│   ├── db.php                  — PDO connection using config.php variables
│   ├── navbar.php              — Shared navbar partial; set $active_nav before including
│   ├── footer.php              — Shared footer: "Sesion iniciada como: [nombre]"
│   └── ocr/
│       ├── PdfFirstPageImageConverter.php  — Wraps pdftoppm; converts PDF p.1 to PNG
│       ├── TesseractOcrService.php         — Wraps tesseract binary; returns raw text
│       └── RegisterCardTextParser.php      — Parses OCR text → apellido/nombre/fecha_llegada/crs_no
│                                             (see Section 5 — active bug in crs_no extraction)
│
├── assets/
│   ├── css/app.css             — Brand CSS vars + .io-navbar, .io-card, .io-upload-box,
│   │                             .io-footer, .io-page-header, .btn-io-blue, dropdown z-index fix
│   └── js/upload-boxes.js      — Drag-and-drop upload box wiring for [data-upload-box] elements
│
├── uploads/                    — Uploaded files (docs, IDs). TODO: move outside web root in prod.
├── private/uploads-temp/       — Temp storage for OCR reg card PDFs before expediente is saved
│
├── vendor/                     — Composer packages (fpdi, fpdf)
├── composer.json / composer.lock
├── merge_test.php              — Standalone PDF merge test script (early prototype, not part of app)
└── dashboard_mockup.html       — Static visual mockup (early reference, not part of app)
```

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
- **index.php (Llegadas):** today's arrivals filtered by `CURDATE()`, name search (also searches crs_no/habitacion), sort by apellido/nombre, pagination (20/page), status badges (Faltante/Pendiente/Firmado for doc; Faltante/Ok for ID), dropdown actions (Ver/Editar/Eliminar).
- **base_datos.php:** all records, date-range filter (Fecha desde/hasta), sortable apellido/nombre/fecha_llegada, same badges and dropdown actions.
- **registro_nuevo.php:** OCR prefill (Step 1: upload reg card → Continuar → pipeline → prefill form) + manual form (Step 2: Guardar). Drag-and-drop upload boxes on all three file inputs. Temp PDF attached as first documento on save without re-upload.
- **expediente.php:** two-column view (doc info grid + ID preview). No Firmar button yet (blocked on merge feature).
- **expediente_editar.php:** edit all fields including crs_no/habitacion; upload new doc (adds, doesn't replace); upload new ID (replaces path reference).
- **expediente_delete.php:** deletes files from disk before DB row; FK cascade removes documentos rows.
- **Brand colors:** `--io-navy: #001f4f`, `--io-blue: #1658b8`, `--io-orange: #e35205`, `--io-bg: #f5f6f8`, `--io-surface: #ffffff`

---

## 5. Current Unresolved Bug — crs_no OCR Extraction

**Status as of last session:** apellido, nombre, fecha_llegada extract correctly. crs_no is not extracting correctly.

**History of the bug:**
1. First version: `crs_no` aliases included `'conf'`, which matched `"Conf. #:"` on the same OCR line instead of `"CRS No:"`. Fixed by removing `'conf'` from aliases.
2. After that fix: crs_no now produces no result at all. This regression was the state at end of last session.

**The actual card line (from CONDE_AGUILAR__CESAR_D.pdf):**
```
Conf. #: 3961360    Tipo: KNGN    Adultos: 1    CRS No: 24120824
```

**Expected:** `crs_no = "24120824"`
**Actual:** empty string

**Likely cause:** `"CRS No"` appears mid-line after other label:value pairs. The inline parser regex is:
```php
'/(?:^|\s)' . preg_quote($alias, '/') . '\s*[:.][ \t]*(.+)/i'
```
The `(?:^|\s)` anchor requires whitespace before `CRS No`, but after OCR of a side-by-side layout, there may be multiple spaces or the match may be failing for another reason. The `trimAtNextLabel()` stop-list also contains `'crs no'` as a stop-word, which could be causing the value to be trimmed to empty if it appears at the very start of what was captured.

**Full current contents of RegisterCardTextParser.php for diagnosis:**

```php
<?php

class RegisterCardTextParser
{
    private const ALL_CARD_LABELS = [
        'apellido', 'nombre', 'direccion 2', 'direccion', 'empresa', 'ciudad',
        'pasaporte', 'estado', 'fecha de nacimiento', 'cod. postal', 'cod.postal',
        'marca auto', 'n de placa', 'tel', 'forma pago', 'email', 'n membresia',
        'rfc', 'llegada', 'salida', 'hab', 'noches', 'tarifa', 'grupo',
        'cod. tarifa', 'cod.tarifa', 'conf', 'crs no.', 'crs no', 'tipo', 'adultos',
        'last name', 'first name', 'arrival', 'departure', 'room',
    ];

    private const LABEL_ALIASES = [
        'apellido'      => ['apellido', 'last name', 'lastname', 'surname'],
        'nombre'        => ['nombre', 'first name', 'firstname', 'given name'],
        'fecha_llegada' => ['llegada', 'arrival date', 'arr. date', 'arr date', 'fecha llegada', 'fecha de llegada'],
        'crs_no'        => ['crs no.', 'crs no', 'crs number', 'crs#'],
    ];

    public function parse(string $text): array { ... }
    private function parseInline(string $text): array { ... }
    private function parseSequential(string $text): array { ... }
    private function trimAtNextLabel(string $value): string { ... }
    private function looksLikeLabel(string $line): bool { ... }
    private function normaliseDate(string $raw): string { ... }
}
```

**The self-defeating issue to investigate first:** `ALL_CARD_LABELS` contains `'crs no'` as a stop-word. When the parser extracts the value after `"CRS No: "`, it captures `"24120824"`, then calls `trimAtNextLabel("24120824")`. But if the regex in `trimAtNextLabel` is matching `'crs no'` somewhere in the surrounding context (e.g. if the capture group grabbed more than just `24120824`), it may be truncating to empty. Add a debug `var_dump` of the raw capture `$m[1]` before trimming to see exactly what's being captured and what `trimAtNextLabel` returns.

---

## 6. Not Yet Built (Priority Order)

1. **Fix crs_no OCR bug** (immediate next step — see Section 5)
2. **Batch upload — `carga_masiva.php`**: multi-PDF OCR upload + review table + save selected. The original prototype had a >7 PDF timeout bug; this port must handle ~60 PDFs/day without timing out. Also needs to extract crs_no (once bug above is fixed).
3. **Document merge feature:**
   - *Individual merge* (from `expediente.php` Ver page): optional recognition-tier PDF dropdown (1 of 5 static files) → reg card(s) already uploaded → contract (1 static file, always auto-included) → single merged PDF, originals discarded after merge
   - *Bulk dedicated merge view*: table of today's unmerged simple cases, one Combinar button per row
   - *Multi-room grouping view*: checkbox multi-select for guests with multiple rooms/bookings (differentiated by CRS No since room numbers not assigned yet); reached deliberately from an individual record
   - `is_merged = 1` on the resulting documento row; `original_name` = generated name (e.g. `Mancilla_Martinez_270626.pdf`)
4. **Firmar (signature) modal/flow**: signs the merged PDF only, in-place (stamps existing file, updates `signed_at`); button disabled until a merged doc exists
5. **User management page** (admin-only): create/edit/delete users, reset passwords, assign roles
6. **Role-based permission enforcement**: viewer = read-only everywhere; editor = no user management, no historical deletes; admin = full access
7. **Self-service password change** (any role)

---

## 7. Environment Details

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

**External binaries needed for OCR:**
- `pdftoppm` (Poppler) — for PDF-to-image conversion
- `tesseract` — for OCR text extraction
- The PHP services resolve binaries via env var → known Windows path → PATH fallback

**Timezone:** `America/Matamoros` — set in `config.php` via `date_default_timezone_set()`. Piedras Negras follows **US Central Time rules** (not Mexico City's).
