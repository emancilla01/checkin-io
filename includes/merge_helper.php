<?php
// Shared PDF merge logic.
// Called by merge.php (individual) and merge_masivo.php (bulk).
//
// Requires:
//   $pdo             — PDO connection
//   $staticDocsPath  — from config.php (path to club.pdf, contrato.pdf, etc.)
//   vendor/autoload.php + FPDF class alias already loaded by the caller
//
// Returns on success: redirects (calls header() + exit)
// Returns on failure: string error message

// UPLOAD_DIR defined centrally in includes/db.php

const TIER_FILES = [
    'club'           => 'club.pdf',
    'silver_elite'   => 'silver_elite.pdf',
    'gold_elite'     => 'gold_elite.pdf',
    'platinum_elite' => 'platinum_elite.pdf',
    'diamond_elite'  => 'diamond_elite.pdf',
];

const RECONOCIMIENTO_OPCIONES = [
    ''               => 'Sin reconocimiento',
    'club'           => 'Club',
    'silver_elite'   => 'Silver Elite',
    'gold_elite'     => 'Gold Elite',
    'platinum_elite' => 'Platinum Elite',
    'diamond_elite'  => 'Diamond Elite',
];

/**
 * Perform a group PDF merge: pool unmerged docs from multiple expedientes into one
 * document on the primary expediente, then fully delete the redundant expedientes.
 *
 * @param PDO    $pdo            Active PDO connection
 * @param array  $primary_exp    The surviving expediente row (lowest id wins)
 * @param array  $all_docs       All is_merged=0 docs across every checked expediente,
 *                               ordered expediente_id ASC, created_at ASC
 * @param array  $redundant_ids  IDs of expedientes to delete entirely after merge
 * @param string $nivel          Recognition tier key
 * @param string $static_path    Absolute path to the static-docs directory
 *
 * @return string  Empty on success; error message on failure.
 */
function perform_group_merge(
    PDO    $pdo,
    array  $primary_exp,
    array  $all_docs,
    array  $redundant_ids,
    string $nivel,
    string $static_path
): string {
    if (empty($all_docs)) {
        return 'No hay tarjetas de registro cargadas para combinar.';
    }

    $pdfs_to_merge = [];

    // (a) Recognition tier PDF
    if ($nivel !== '' && isset(TIER_FILES[$nivel])) {
        $tier_path = rtrim($static_path, '/\\') . DIRECTORY_SEPARATOR . TIER_FILES[$nivel];
        if (!file_exists($tier_path)) {
            return 'Archivo de reconocimiento no encontrado: ' . htmlspecialchars(basename($tier_path)) . '.';
        }
        $pdfs_to_merge[] = $tier_path;
    }

    // (b) All unmerged docs (primary's first, then redundant expedientes', each in upload order)
    foreach ($all_docs as $doc) {
        $doc_path = upload_absolute_path($doc['path']);
        if (!file_exists($doc_path)) {
            return 'Archivo de documento no encontrado: ' . htmlspecialchars(basename($doc['path'])) . '.';
        }
        $pdfs_to_merge[] = $doc_path;
    }

    // (c) Contrato — always last
    $contrato_path = rtrim($static_path, '/\\') . DIRECTORY_SEPARATOR . 'contrato.pdf';
    if (!file_exists($contrato_path)) {
        return 'Archivo de contrato no encontrado. Verifica que contrato.pdf este en la ruta configurada.';
    }
    $pdfs_to_merge[] = $contrato_path;

    // Output filename from primary expediente
    $apellido_safe = preg_replace('/[^a-z0-9]/i', '_', $primary_exp['apellido']);
    $nombre_safe   = preg_replace('/[^a-z0-9]/i', '_', $primary_exp['nombre']);
    $fecha_parts   = explode('-', $primary_exp['fecha_llegada']);
    $fecha_short   = count($fecha_parts) === 3
        ? $fecha_parts[2] . $fecha_parts[1] . substr($fecha_parts[0], 2)
        : date('dmy');
    $out_filename  = preg_replace('/_+/', '_', $apellido_safe . '_' . $nombre_safe . '_' . $fecha_short . '.pdf');

    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);

    $out_path = UPLOAD_DIR . $out_filename;
    if (file_exists($out_path)) {
        $i    = 2;
        $base = pathinfo($out_filename, PATHINFO_FILENAME);
        while (file_exists(UPLOAD_DIR . $base . '_' . $i . '.pdf')) $i++;
        $out_filename = $base . '_' . $i . '.pdf';
        $out_path     = UPLOAD_DIR . $out_filename;
    }

    try {
        $pdf = new \setasign\Fpdi\Fpdi();
        foreach ($pdfs_to_merge as $src) {
            $page_count = $pdf->setSourceFile($src);
            for ($p = 1; $p <= $page_count; $p++) {
                $tpl  = $pdf->importPage($p);
                $size = $pdf->getTemplateSize($tpl);
                $pdf->AddPage($size['width'] > $size['height'] ? 'L' : 'P', [$size['width'], $size['height']]);
                $pdf->useTemplate($tpl);
            }
        }
        $pdf->Output('F', $out_path);

        if (!file_exists($out_path)) {
            throw new RuntimeException('FPDI no genero el archivo de salida.');
        }

        $rel_path = 'uploads/' . $out_filename;

        $pdo->beginTransaction();

        // Delete all unmerged doc rows across every checked expediente
        $del_ids      = array_column($all_docs, 'id');
        $placeholders = implode(',', array_fill(0, count($del_ids), '?'));
        $pdo->prepare("DELETE FROM documentos WHERE id IN ($placeholders)")->execute($del_ids);

        // Attach merged doc to primary expediente
        $pdo->prepare(
            "INSERT INTO documentos (expediente_id, path, original_name, is_merged) VALUES (?, ?, ?, 1)"
        )->execute([$primary_exp['id'], $rel_path, $out_filename]);

        // Collect identificacion paths of redundant expedientes before deletion
        $redundant_id_paths = [];
        if (!empty($redundant_ids)) {
            $rph     = implode(',', array_fill(0, count($redundant_ids), '?'));
            $id_stmt = $pdo->prepare("SELECT identificacion_path FROM expedientes WHERE id IN ($rph)");
            $id_stmt->execute($redundant_ids);
            $redundant_id_paths = array_column($id_stmt->fetchAll(), 'identificacion_path');

            // Safety net: delete any remaining documentos on redundant expedientes
            $pdo->prepare("DELETE FROM documentos WHERE expediente_id IN ($rph)")->execute($redundant_ids);
            // Delete the redundant expediente rows
            $pdo->prepare("DELETE FROM expedientes WHERE id IN ($rph)")->execute($redundant_ids);
        }

        $pdo->commit();

        // Unlink original doc files from disk
        foreach ($all_docs as $doc) {
            $disk_path = upload_absolute_path($doc['path']);
            if (file_exists($disk_path)) @unlink($disk_path);
        }

        // Unlink redundant expediente identificacion files
        foreach ($redundant_id_paths as $id_path) {
            if (!empty($id_path)) {
                $abs = upload_absolute_path($id_path);
                if (file_exists($abs)) @unlink($abs);
            }
        }

        return '';

    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (file_exists($out_path)) @unlink($out_path);
        return 'Error al combinar el grupo: ' . $e->getMessage();
    }
}

/**
 * Perform the PDF merge for one expediente.
 *
 * @param PDO    $pdo           Active PDO connection
 * @param array  $exp           expedientes row (id, apellido, nombre, fecha_llegada)
 * @param array  $unmerged_docs Array of documentos rows with is_merged = 0
 * @param string $nivel         Selected recognition tier key ('' = none)
 * @param string $static_path   Absolute path to the static-docs directory
 *
 * @return string  Empty string on success (caller should redirect); error message on failure.
 */
function perform_merge(PDO $pdo, array $exp, array $unmerged_docs, string $nivel, string $static_path): string
{
    if (empty($unmerged_docs)) {
        return 'No hay tarjeta de registro cargada para combinar.';
    }

    $pdfs_to_merge = [];

    // (a) Recognition tier PDF
    if ($nivel !== '' && isset(TIER_FILES[$nivel])) {
        $tier_path = rtrim($static_path, '/\\') . DIRECTORY_SEPARATOR . TIER_FILES[$nivel];
        if (!file_exists($tier_path)) {
            return 'Archivo de reconocimiento no encontrado: ' . htmlspecialchars(basename($tier_path))
                 . '. Verifica que los archivos estaticos esten en la ruta configurada.';
        }
        $pdfs_to_merge[] = $tier_path;
    }

    // (b) Unmerged documentos in upload order
    foreach ($unmerged_docs as $doc) {
        $doc_path = upload_absolute_path($doc['path']);
        if (!file_exists($doc_path)) {
            return 'Archivo de documento no encontrado: ' . htmlspecialchars(basename($doc['path'])) . '.';
        }
        $pdfs_to_merge[] = $doc_path;
    }

    // (c) Contrato — always last
    $contrato_path = rtrim($static_path, '/\\') . DIRECTORY_SEPARATOR . 'contrato.pdf';
    if (!file_exists($contrato_path)) {
        return 'Archivo de contrato no encontrado. Verifica que contrato.pdf este en la ruta configurada.';
    }
    $pdfs_to_merge[] = $contrato_path;

    // Generate output filename: Apellido_Nombre_DDMMYY.pdf
    $apellido_safe = preg_replace('/[^a-z0-9]/i', '_', $exp['apellido']);
    $nombre_safe   = preg_replace('/[^a-z0-9]/i', '_', $exp['nombre']);
    $fecha_parts   = explode('-', $exp['fecha_llegada']); // Y-m-d
    $fecha_short   = count($fecha_parts) === 3
        ? $fecha_parts[2] . $fecha_parts[1] . substr($fecha_parts[0], 2)
        : date('dmy');
    $out_filename  = preg_replace('/_+/', '_', $apellido_safe . '_' . $nombre_safe . '_' . $fecha_short . '.pdf');

    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);

    $out_path = UPLOAD_DIR . $out_filename;
    if (file_exists($out_path)) {
        $i    = 2;
        $base = pathinfo($out_filename, PATHINFO_FILENAME);
        while (file_exists(UPLOAD_DIR . $base . '_' . $i . '.pdf')) $i++;
        $out_filename = $base . '_' . $i . '.pdf';
        $out_path     = UPLOAD_DIR . $out_filename;
    }

    try {
        $pdf = new \setasign\Fpdi\Fpdi();

        foreach ($pdfs_to_merge as $src) {
            $page_count = $pdf->setSourceFile($src);
            for ($p = 1; $p <= $page_count; $p++) {
                $tpl  = $pdf->importPage($p);
                $size = $pdf->getTemplateSize($tpl);
                $pdf->AddPage($size['width'] > $size['height'] ? 'L' : 'P', [$size['width'], $size['height']]);
                $pdf->useTemplate($tpl);
            }
        }

        $pdf->Output('F', $out_path);

        if (!file_exists($out_path)) {
            throw new RuntimeException('FPDI no genero el archivo de salida.');
        }

        $rel_path = 'uploads/' . $out_filename;

        $pdo->beginTransaction();

        $del_ids      = array_column($unmerged_docs, 'id');
        $placeholders = implode(',', array_fill(0, count($del_ids), '?'));
        $pdo->prepare("DELETE FROM documentos WHERE id IN ($placeholders)")->execute($del_ids);

        $pdo->prepare(
            "INSERT INTO documentos (expediente_id, path, original_name, is_merged) VALUES (?, ?, ?, 1)"
        )->execute([$exp['id'], $rel_path, $out_filename]);

        $pdo->commit();

        // Delete originals from disk after successful DB commit
        foreach ($unmerged_docs as $doc) {
            $disk_path = upload_absolute_path($doc['path']);
            if (file_exists($disk_path)) @unlink($disk_path);
        }

        return '';  // success

    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (file_exists($out_path)) @unlink($out_path);
        return 'Error al combinar los documentos: ' . $e->getMessage();
    }
}
