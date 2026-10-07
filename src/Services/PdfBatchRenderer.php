<?php

declare(strict_types=1);

namespace SDS\Services;

use SDS\Core\App;

/**
 * PdfBatchRenderer — render one PDF per language in parallel child processes.
 *
 * Lifted verbatim from PrivateLabelController::generatePdfsInParallel() so
 * the private label publisher (and anything else that needs a per-language
 * PDF batch) shares one copy. Each language is handed to
 * scripts/pdf-worker.php via proc_open; the worker writes a small JSON
 * result file that is read back here.
 */
final class PdfBatchRenderer
{
    /**
     * Render every entry of $langData (lang => SDS data array) to a PDF.
     *
     * Temp files are written to storage/temp as
     *   {prefix}input_{lang}_{rand}.json / {prefix}result_{lang}_{rand}.json
     * and removed before returning.
     *
     * @param  array  $langData    lang => full SDS data array (from SDSGenerator)
     * @param  string $tempPrefix  Prefix for the temp file names (e.g. 'plpdf_')
     * @return array  lang => ['ok' => bool, 'pdf_path' => ?string (absolute), 'error' => ?string]
     */
    public static function render(array $langData, string $tempPrefix = 'pdf'): array
    {
        $basePath     = App::basePath();
        $workerScript = $basePath . '/scripts/pdf-worker.php';
        $tmpDir       = $basePath . '/storage/temp';
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }

        $phpBin    = php_cli_binary();
        $processes = [];
        $tempFiles = [];
        $results   = [];

        foreach ($langData as $lang => $sdsData) {
            $inputFile  = $tmpDir . '/' . $tempPrefix . 'input_' . $lang . '_' . bin2hex(random_bytes(4)) . '.json';
            $resultFile = $tmpDir . '/' . $tempPrefix . 'result_' . $lang . '_' . bin2hex(random_bytes(4)) . '.json';

            file_put_contents($inputFile, json_encode($sdsData, JSON_UNESCAPED_UNICODE));

            $cmd = sprintf(
                '%s %s %s %s',
                escapeshellarg($phpBin),
                escapeshellarg($workerScript),
                escapeshellarg($inputFile),
                escapeshellarg($resultFile)
            );

            $proc = proc_open($cmd, [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes);

            $tempFiles[] = $inputFile;
            $tempFiles[] = $resultFile;

            if (!is_resource($proc)) {
                $results[$lang] = ['ok' => false, 'pdf_path' => null, 'error' => 'Could not start PDF worker process'];
                continue;
            }

            fclose($pipes[0]);

            $processes[$lang] = [
                'proc'       => $proc,
                'pipes'      => $pipes,
                'resultFile' => $resultFile,
            ];
        }

        foreach ($processes as $lang => $info) {
            $stderr = stream_get_contents($info['pipes'][2]);
            fclose($info['pipes'][1]);
            fclose($info['pipes'][2]);
            $exitCode = proc_close($info['proc']);

            if (file_exists($info['resultFile'])) {
                $result = json_decode((string) file_get_contents($info['resultFile']), true);
                if (is_array($result)) {
                    $results[$lang] = [
                        'ok'       => (bool) ($result['ok'] ?? false),
                        'pdf_path' => isset($result['pdf_path']) ? (string) $result['pdf_path'] : null,
                        'error'    => isset($result['error']) ? (string) $result['error'] : null,
                    ];
                } else {
                    $results[$lang] = ['ok' => false, 'pdf_path' => null, 'error' => 'Invalid result from PDF worker'];
                }
            } else {
                $errMsg = trim((string) $stderr) ?: 'PDF worker exited with code ' . $exitCode;
                $results[$lang] = ['ok' => false, 'pdf_path' => null, 'error' => $errMsg];
            }
        }

        foreach ($tempFiles as $f) {
            @unlink($f);
        }

        return $results;
    }
}
