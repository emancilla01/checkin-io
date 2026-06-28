<?php

class PdfFirstPageImageConverter
{
    private string $outputDir;

    public function __construct(string $outputDir)
    {
        $this->outputDir = rtrim($outputDir, '/\\') . DIRECTORY_SEPARATOR;
        if (!is_dir($this->outputDir)) {
            mkdir($this->outputDir, 0755, true);
        }
    }

    public function convert(string $pdfPath): string
    {
        $binary = $this->resolveBinary();
        $stem   = $this->outputDir . 'page';

        // pdftoppm -png -r 200 -f 1 -l 1 input.pdf output_stem
        $cmd = sprintf(
            '%s -png -r 200 -f 1 -l 1 %s %s 2>&1',
            escapeshellarg($binary),
            escapeshellarg($pdfPath),
            escapeshellarg(rtrim($stem, DIRECTORY_SEPARATOR))
        );
        exec($cmd, $output, $code);

        if ($code !== 0) {
            throw new RuntimeException("pdftoppm failed (exit $code): " . implode("\n", $output));
        }

        // pdftoppm names the file: stem-1.png or stem-01.png
        foreach (['page-1.png', 'page-01.png'] as $candidate) {
            $path = $this->outputDir . $candidate;
            if (file_exists($path)) return $path;
        }

        throw new RuntimeException("pdftoppm ran but output PNG not found in {$this->outputDir}");
    }

    private function resolveBinary(): string
    {
        // 1. Explicit env override
        $env = getenv('PDFTOPPM_BINARY');
        if ($env && file_exists($env)) return $env;

        // 2. Common Windows WinGet install path
        $winget = 'C:\Program Files\Poppler\Library\bin\pdftoppm.exe';
        if (file_exists($winget)) return $winget;

        // 3. Assume on PATH
        return 'pdftoppm';
    }
}
