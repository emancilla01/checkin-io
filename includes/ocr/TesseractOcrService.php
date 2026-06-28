<?php

class TesseractOcrService
{
    public function recognize(string $imagePath): string
    {
        $binary = $this->resolveBinary();
        $lang   = $this->resolveLang($binary);

        // Write output to a temp txt file
        $outBase = tempnam(sys_get_temp_dir(), 'tess_');
        $outTxt  = $outBase . '.txt';

        $cmd = sprintf(
            '%s %s %s -l %s --psm 6 2>&1',
            escapeshellarg($binary),
            escapeshellarg($imagePath),
            escapeshellarg($outBase),
            escapeshellarg($lang)
        );
        exec($cmd, $output, $code);

        if (!file_exists($outTxt)) {
            throw new RuntimeException("Tesseract failed (exit $code): " . implode("\n", $output));
        }

        $text = file_get_contents($outTxt);
        unlink($outTxt);
        if (file_exists($outBase)) unlink($outBase);

        return $text === false ? '' : $text;
    }

    private function resolveBinary(): string
    {
        $env = getenv('TESSERACT_BINARY');
        if ($env && file_exists($env)) return $env;

        $win = 'C:\Program Files\Tesseract-OCR\tesseract.exe';
        if (file_exists($win)) return $win;

        return 'tesseract';
    }

    private function resolveLang(string $binary): string
    {
        // Prefer spa+eng if Spanish data is available, fall back to eng
        $tessdata = dirname($binary) . DIRECTORY_SEPARATOR . 'tessdata';
        if (is_dir($tessdata) && file_exists($tessdata . DIRECTORY_SEPARATOR . 'spa.traineddata')) {
            return 'spa+eng';
        }
        return 'eng';
    }
}
