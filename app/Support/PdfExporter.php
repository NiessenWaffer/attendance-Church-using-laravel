<?php

namespace App\Support;

use Barryvdh\DomPDF\Facade\Pdf;

class PdfExporter
{
    public static function download($view, array $data, $filename, string $orientation = 'landscape')
    {
        return Pdf::loadView($view, $data)
            ->setPaper('a4', $orientation)
            ->download($filename);
    }
}
