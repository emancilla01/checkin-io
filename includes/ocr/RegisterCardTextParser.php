<?php

class RegisterCardTextParser
{
    // All label strings that appear on OPERA/Holiday Inn Express register cards.
    // Used as stop-words: when a captured value contains one of these (followed
    // by a delimiter), we truncate before it to prevent bleed-over from
    // side-by-side fields that share an OCR line.
    private const ALL_CARD_LABELS = [
        'apellido', 'nombre', 'direccion 2', 'direccion', 'empresa', 'ciudad',
        'pasaporte', 'estado', 'fecha de nacimiento', 'cod. postal', 'cod.postal',
        'marca auto', 'n de placa', 'tel', 'forma pago', 'email', 'n membresia',
        'rfc', 'llegada', 'salida', 'hab', 'noches', 'tarifa', 'grupo',
        'cod. tarifa', 'cod.tarifa', 'conf', 'crs no.', 'crs no', 'tipo', 'adultos',
        'last name', 'first name', 'arrival', 'departure', 'room',
    ];

    // Maps our field names to label variants that identify them on the card.
    // Ordered so longer/more-specific aliases are checked before shorter ones.
    private const LABEL_ALIASES = [
        'apellido'      => ['apellido', 'last name', 'lastname', 'surname'],
        'nombre'        => ['nombre', 'first name', 'firstname', 'given name'],
        'fecha_llegada' => ['llegada', 'arrival date', 'arr. date', 'arr date', 'fecha llegada', 'fecha de llegada'],
        'crs_no'        => ['crs no.', 'crs no', 'crs number', 'crs#'],
    ];

    public function parse(string $text): array
    {
        $result = [
            'apellido'      => '',
            'nombre'        => '',
            'fecha_llegada' => '',
            'crs_no'        => '',
        ];

        // Strategy 1: inline "Label: Value [next label]" on the same OCR line
        $inline = $this->parseInline($text);
        foreach ($result as $field => $_) {
            if ($inline[$field] !== '') {
                $result[$field] = $inline[$field];
            }
        }

        // Strategy 2: sequential label / value on separate lines
        if ($result['apellido'] === '' || $result['nombre'] === '' || $result['fecha_llegada'] === '') {
            $block = $this->parseSequential($text);
            foreach ($result as $field => $_) {
                if ($result[$field] === '' && $block[$field] !== '') {
                    $result[$field] = $block[$field];
                }
            }
        }

        // Normalise fecha_llegada → Y-m-d
        if ($result['fecha_llegada'] !== '') {
            $result['fecha_llegada'] = $this->normaliseDate($result['fecha_llegada']);
        }

        return $result;
    }

    // -----------------------------------------------------------------------
    // Strategy 1: inline extraction with stop-at-next-label trimming
    // -----------------------------------------------------------------------
    private function parseInline(string $text): array
    {
        $result = ['apellido' => '', 'nombre' => '', 'fecha_llegada' => '', 'crs_no' => ''];
        $lines  = preg_split('/\r?\n/', $text);

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;

            foreach (self::LABEL_ALIASES as $field => $aliases) {
                if ($result[$field] !== '') continue; // already found

                foreach ($aliases as $alias) {
                    $pattern = '/(?:^|\s)' . preg_quote($alias, '/') . '\s*[:.][ \t]*(.+)/i';
                    if (preg_match($pattern, $line, $m)) {
                        $val = $this->trimAtNextLabel(trim($m[1]));
                        if ($val !== '') {
                            $result[$field] = $val;
                            break;
                        }
                    }
                }
            }
        }

        return $result;
    }

    // -----------------------------------------------------------------------
    // Strategy 2: label on one line, value on the next
    // -----------------------------------------------------------------------
    private function parseSequential(string $text): array
    {
        $result = ['apellido' => '', 'nombre' => '', 'fecha_llegada' => '', 'crs_no' => ''];
        $lines  = preg_split('/\r?\n/', $text);
        $n      = count($lines);

        for ($i = 0; $i < $n - 1; $i++) {
            $line      = trim($lines[$i]);
            $lineLower = mb_strtolower($line);
            if ($line === '') continue;

            foreach (self::LABEL_ALIASES as $field => $aliases) {
                if ($result[$field] !== '') continue;

                foreach ($aliases as $alias) {
                    if (str_contains($lineLower, $alias)) {
                        $next = trim($lines[$i + 1] ?? '');
                        if ($next !== '' && !$this->looksLikeLabel($next)) {
                            $val = $this->trimAtNextLabel($next);
                            if ($val !== '') {
                                $result[$field] = $val;
                            }
                        }
                        break;
                    }
                }
            }
        }

        return $result;
    }

    // -----------------------------------------------------------------------
    // Trim a captured value at the first occurrence of any known card label
    // followed by a delimiter (: or .) — stops bleed-over from side-by-side fields
    // -----------------------------------------------------------------------
    private function trimAtNextLabel(string $value): string
    {
        // Build a regex that matches any card label followed by : or .
        // Sort by length descending so longer labels match before shorter ones
        $labels = self::ALL_CARD_LABELS;
        usort($labels, fn($a, $b) => strlen($b) - strlen($a));

        $pattern = '/(?:' . implode('|', array_map(fn($l) => preg_quote($l, '/'), $labels)) . ')\s*[:\.]/i';

        if (preg_match($pattern, $value, $m, PREG_OFFSET_CAPTURE)) {
            $pos = $m[0][1];
            // Also consume any preceding whitespace
            $value = rtrim(substr($value, 0, $pos));
        }

        return trim($value);
    }

    private function looksLikeLabel(string $line): bool
    {
        $lower = mb_strtolower($line);
        foreach (self::ALL_CARD_LABELS as $label) {
            if (str_contains($lower, $label)) return true;
        }
        return false;
    }

    private function normaliseDate(string $raw): string
    {
        $raw = trim($raw);

        // Already Y-m-d
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) return $raw;

        // DD-MM-YY or DD-MM-YYYY (the format printed on OPERA cards)
        if (preg_match('#^(\d{1,2})[-/.](\d{1,2})[-/.](\d{2,4})$#', $raw, $m)) {
            $y = strlen($m[3]) === 2 ? '20' . $m[3] : $m[3];
            $ts = mktime(0, 0, 0, (int)$m[2], (int)$m[1], (int)$y);
            if ($ts !== false) return date('Y-m-d', $ts);
        }

        // Let PHP try anything else
        $ts = strtotime($raw);
        if ($ts !== false) return date('Y-m-d', $ts);

        return '';
    }
}
