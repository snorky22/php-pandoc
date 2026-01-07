<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Pandoc\Reader\ReaderFactory;
use Pandoc\Writer\LatexWriter;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.html');
    exit;
}

if (!isset($_FILES['doc_file']) || $_FILES['doc_file']['error'] !== UPLOAD_ERR_OK) {
    die("Upload failed with error code: " . ($_FILES['doc_file']['error'] ?? 'No file'));
}

$uploadedFile = $_FILES['doc_file']['tmp_name'];
$originalName = $_FILES['doc_file']['name'];
// Use mb_string to handle UTF-8 filenames properly
$lastSlash = mb_strrpos($originalName, '/');
if ($lastSlash !== false) {
    $originalName = mb_substr($originalName, $lastSlash + 1);
}
$lastBackslash = mb_strrpos($originalName, '\\');
if ($lastBackslash !== false) {
    $originalName = mb_substr($originalName, $lastBackslash + 1);
}

$extension = '';
$baseName = $originalName;
$lastDot = mb_strrpos($originalName, '.');
if ($lastDot !== false) {
    $extension = mb_substr($originalName, $lastDot + 1);
    $baseName = mb_substr($originalName, 0, $lastDot);
}

try {
    $reader = ReaderFactory::createForExtension($extension);

    if (in_array($extension, ['md', 'html', 'htm', 'ipynb'])) {
        $content = file_get_contents($uploadedFile);
        $doc = $reader->read($content);
    } else {
        // For docx, we pass the file path
        $doc = $reader->read($uploadedFile);
    }
} catch (\Exception $e) {
    die("Error initializing reader: " . $e->getMessage());
}

try {
    // 2. Write
    $standalone = ($_POST['standalone'] ?? '1') === '1';
    $writer = new LatexWriter();
    $latex = $writer->write($doc, $standalone);
    $outputFilename = $baseName . '.tex';

    if (!$doc->mediaBag->isEmpty()) {
        // Create a temporary ZIP file
        $zipFile = tempnam(sys_get_temp_dir(), 'pandoc_zip');
        $zip = new ZipArchive();
        $res = $zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($res === true) {
            $zip->addFromString($outputFilename, $latex);
            foreach ($doc->mediaBag->getAll() as $path => $media) {
                $zip->addFromString($path, $media['contents']);
            }
            $zip->close();

            // Provide ZIP download
            $zipDownloadName = $baseName . '.zip';
            $encodedZipName = rawurlencode($zipDownloadName);
            header('Content-Type: application/zip');
            header("Content-Disposition: attachment; filename=\"$zipDownloadName\"; filename*=UTF-8''$encodedZipName");
            header('Content-Length: ' . filesize($zipFile));
            readfile($zipFile);
            unlink($zipFile);
            exit;
        } else {
            // Fallback to error or just log it
            error_log("ZipArchive open failed with code: " . $res);
        }
    }

} catch (\Exception $e) {
    die("An error occurred during conversion: " . $e->getMessage());
}

// Provide download with robust filename handling
header('Content-Type: application/x-latex');
$encodedName = rawurlencode($outputFilename);
// ASCII fallback for the standard 'filename' parameter
$asciiName = @iconv('UTF-8', 'ASCII//TRANSLIT', $outputFilename);
if ($asciiName === false || $asciiName === '') {
    $asciiName = 'document.tex';
}
// Remove double quotes and backslashes from the fallback name
$asciiName = str_replace(['"', '\\'], '', $asciiName);
header("Content-Disposition: attachment; filename=\"$asciiName\"; filename*=UTF-8''$encodedName");
echo $latex;
exit;
