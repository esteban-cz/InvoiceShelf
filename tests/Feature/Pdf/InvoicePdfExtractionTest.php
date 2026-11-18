<?php

use App\Domains\Sales\Application\Import\InvoicePdfExtractor;
use Dompdf\Dompdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\ExecutableFinder;

beforeEach(function () {
    if (! (new ExecutableFinder)->find('pdftotext')) {
        $this->markTestSkipped('Poppler is available in the Docker image.');
    }
    File::ensureDirectoryExists(storage_path('app/private/invoice-import'));
});

test('local PDF extraction preserves layout and deletes its private working directory', function () {
    $pdf = new Dompdf;
    $pdf->loadHtml('<h1>Invoice No: OLD-2025</h1><p>Invoice date: 2025-09-15</p><p>Due date: 2025-09-29</p><p>Grand total: 100.00 EUR</p>');
    $pdf->render();
    $before = File::directories(storage_path('app/private/invoice-import'));
    $result = app(InvoicePdfExtractor::class)->extract(UploadedFile::fake()->createWithContent('old.pdf', $pdf->output()));
    expect($result['text'])->toContain('OLD-2025', '100.00 EUR')->and($result['pages'])->toBe(1)->and($result['ocr'])->toBeFalse();
    expect(File::directories(storage_path('app/private/invoice-import')))->toBe($before);
});

test('broken PDFs and page limits return validation errors and leave no extracted files', function () {
    $extractor = app(InvoicePdfExtractor::class);
    $before = File::directories(storage_path('app/private/invoice-import'));
    expect(fn () => $extractor->extract(UploadedFile::fake()->createWithContent('broken.pdf', '%PDF-1.4 invalid')))->toThrow(ValidationException::class);
    $pdf = new Dompdf;
    $pdf->loadHtml('<p>Page one</p><p style="page-break-before:always">Page two</p>');
    $pdf->render();
    config(['invoice-import.max_pages' => 1]);
    expect(fn () => $extractor->extract(UploadedFile::fake()->createWithContent('large.pdf', $pdf->output())))->toThrow(ValidationException::class);
    expect(File::directories(storage_path('app/private/invoice-import')))->toBe($before);
});

test('scanned PDF pages use bounded local OCR', function () {
    if (! (new ExecutableFinder)->find('tesseract')) {
        $this->markTestSkipped('Tesseract is available in the Docker image.');
    }
    $image = imagecreatetruecolor(1400, 700);
    imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
    $black = imagecolorallocate($image, 0, 0, 0);
    foreach (['Invoice number: 202512', 'Invoice date: 2025-12-01', 'Grand total: 100.00 EUR'] as $index => $line) {
        imagettftext($image, 32, 0, 65, 100 + 100 * $index, $black, resource_path('static/fonts/NotoSans-Regular.ttf'), $line);
    }
    ob_start();
    imagepng($image);
    $png = ob_get_clean();
    imagedestroy($image);
    $pdf = new Dompdf;
    $pdf->loadHtml('<img style="width:650px" src="data:image/png;base64,'.base64_encode($png).'">');
    $pdf->render();
    $extractor = app(InvoicePdfExtractor::class);
    $result = $extractor->extract(UploadedFile::fake()->createWithContent('scan.pdf', $pdf->output()));
    expect($result['ocr'])->toBeTrue()->and($result['text'])->toContain('202512', '100.00 EUR');
    config(['invoice-import.ocr_max_pages' => 0]);
    $result = $extractor->extract(UploadedFile::fake()->createWithContent('scan.pdf', $pdf->output()));
    expect($result['ocr'])->toBeFalse()->and($result['warnings'])->toContain('ocr_page_limit');
});
