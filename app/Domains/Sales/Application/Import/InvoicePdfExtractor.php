<?php

namespace App\Domains\Sales\Application\Import;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\ExecutableFinder;

/** Local extraction only. Temporary files never enter the public or media disks. */
class InvoicePdfExtractor
{
    public function extract(UploadedFile $file): array
    {
        $finder = new ExecutableFinder;
        $textTool = $finder->find('pdftotext');
        $infoTool = $finder->find('pdfinfo');
        abort_unless($textTool && $infoTool, 503, __('invoice_import_tools_unavailable'));
        $directory = storage_path('app/private/invoice-import/'.Str::uuid());
        File::makeDirectory($directory, 0700, true);
        try {
            $source = $file->getRealPath();
            $info = Process::timeout(8)->run([$infoTool, $source]);
            if (! $info->successful() || ! preg_match('/^Pages:\s+(\d+)/m', $info->output(), $match)) {
                $this->invalid('invoice_import_invalid_pdf');
            }
            $pages = (int) $match[1];
            if ($pages < 1 || $pages > config('invoice-import.max_pages', 10)) {
                $this->invalid('invoice_import_too_many_pages');
            }
            $target = $directory.'/text.txt';
            $result = Process::timeout(15)->run([$textTool, '-layout', '-enc', 'UTF-8', $source, $target]);
            if (! $result->successful()) {
                $this->invalid('invoice_import_invalid_pdf');
            }
            $text = $this->read($target);
            $parts = explode("\f", $text);
            $ocr = 0;
            $warnings = [];
            $renderTool = $finder->find('pdftoppm');
            $ocrTool = $finder->find('tesseract');
            foreach (range(0, $pages - 1) as $index) {
                $part = $parts[$index] ?? '';
                if (mb_strlen(preg_replace('/\s/u', '', $part)) >= 40) {
                    continue;
                }
                if (! $renderTool || ! $ocrTool) {
                    $warnings[] = 'ocr_unavailable';

                    continue;
                }
                if ($ocr >= config('invoice-import.ocr_max_pages', 2)) {
                    $warnings[] = 'ocr_page_limit';

                    continue;
                }
                $ocr++;
                $base = $directory.'/page-'.($index + 1);
                try {
                    $render = Process::timeout(10)->run([$renderTool, '-f', (string) ($index + 1), '-l', (string) ($index + 1), '-scale-to', '2200', '-singlefile', '-png', $source, $base]);
                    if (! $render->successful()) {
                        $warnings[] = 'ocr_failed';

                        continue;
                    }
                    $recognition = Process::timeout(15)->env(['OMP_THREAD_LIMIT' => '1'])->run([$ocrTool, $base.'.png', $base, '-l', config('invoice-import.ocr_languages', 'ces+eng'), '--psm', '6', '-c', 'preserve_interword_spaces=1']);
                    if ($recognition->successful()) {
                        $parts[$index] = $this->read($base.'.txt');
                    } else {
                        $warnings[] = 'ocr_failed';
                    }
                } catch (\Throwable) {
                    $warnings[] = 'ocr_failed';
                }
            }
            $text = implode("\n", array_slice($parts, 0, $pages));
            if (strlen($text) > config('invoice-import.max_text_bytes', 1_000_000)) {
                $this->invalid('invoice_import_too_much_text');
            }

            return ['text' => $text, 'pages' => $pages, 'ocr' => $ocr > 0, 'warnings' => array_values(array_unique($warnings))];
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable) {
            $this->invalid('invoice_import_extraction_failed');
        } finally {
            File::deleteDirectory($directory);
        }
    }

    private function read(string $path): string
    {
        if (! is_file($path) || filesize($path) > config('invoice-import.max_text_bytes', 1_000_000)) {
            $this->invalid('invoice_import_too_much_text');
        }

        return mb_scrub(file_get_contents($path), 'UTF-8');
    }

    private function invalid(string $key): never
    {
        throw ValidationException::withMessages(['file' => __($key)]);
    }
}
