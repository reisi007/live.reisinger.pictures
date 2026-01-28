<?php
/**
 * Single File PHP PhotoSwipe Gallery (Dynamic, Sorted, Secure, Download-All)
 * * Version: 1.4
 */

// --- Konfiguration (Defaults) ---
$defaultDir = 'gallery';       // Standardordner, falls kein Pfad angegeben wird
$thumbDirName = '_thumbnails'; // Ordnername für Vorschaubilder (innerhalb des Bildordners)
$thumbWidth = 500;             // Breite für Masonry Grid
$thumbQuality = 75;            // JPG Qualität
$cronSecret = 'MySecretKey123'; // WICHTIG: Ändern Sie dies für den Cronjob-Schutz!

// Dateifilter
$ignoredFiles = ['.', '..', 'index.php', $thumbDirName, '.DS_Store', 'Thumbs.db'];
$validExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

// --- GLOBAL HELPER FUNCTION ---
if (!function_exists('createThumbnail')) {
    function createThumbnail($src, $dest, $targetWidth, $quality) {
        if (!file_exists($src)) return false;

        list($width, $height) = getimagesize($src);
        if (!$width || !$height) return false;

        $ratio = $height / $width;
        $targetHeight = floor($targetWidth * $ratio);

        $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));

        switch ($ext) {
            case 'jpg':
            case 'jpeg': $image = @imagecreatefromjpeg($src); break;
            case 'png':  $image = @imagecreatefrompng($src); break;
            case 'gif':  $image = @imagecreatefromgif($src); break;
            case 'webp': $image = @imagecreatefromwebp($src); break;
            default: return false;
        }

        if (!$image) return false;

        $thumbnail = imagecreatetruecolor($targetWidth, $targetHeight);

        // Transparenz erhalten für PNG/WEBP/GIF
        if (in_array($ext, ['png', 'gif', 'webp'])) {
            imagecolortransparent($thumbnail, imagecolorallocatealpha($thumbnail, 0, 0, 0, 127));
            imagealphablending($thumbnail, false);
            imagesavealpha($thumbnail, true);
        }

        imagecopyresampled($thumbnail, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        $result = false;
        switch ($ext) {
            case 'jpg':
            case 'jpeg': $result = imagejpeg($thumbnail, $dest, $quality); break;
            case 'png':  $result = imagepng($thumbnail, $dest, 8); break;
            case 'gif':  $result = imagegif($thumbnail, $dest); break;
            case 'webp': $result = imagewebp($thumbnail, $dest, $quality); break;
        }

        imagedestroy($image);
        imagedestroy($thumbnail);
        return $result;
    }
}

// --- INPUT HANDLING & SECURITY ---

$isCli = (php_sapi_name() === 'cli');
$reqMode = null;
$reqToken = null;
$reqPathInput = null;

if ($isCli) {
    // Parsing CLI arguments
    foreach ($argv as $arg) {
        if (strpos($arg, 'mode=') === 0) $reqMode = substr($arg, 5);
        if (strpos($arg, 'token=') === 0) $reqToken = substr($arg, 6);
        if (strpos($arg, 'path=') === 0) $reqPathInput = substr($arg, 5);
    }
} else {
    // Parsing Web arguments
    $reqMode = isset($_GET['mode']) ? $_GET['mode'] : null;
    $reqToken = isset($_GET['token']) ? $_GET['token'] : null;
    $reqPathInput = isset($_GET['path']) ? $_GET['path'] : null;
}

// Pfad bereinigen
$targetDir = $reqPathInput ? $reqPathInput : $defaultDir;
$targetDir = str_replace(['..', "\0"], '', $targetDir);
$targetDir = trim($targetDir, '/\\');

$baseDir = __DIR__;
$sourcePath = $baseDir . DIRECTORY_SEPARATOR . $targetDir;

// Realpath Check
$realSourcePath = realpath($sourcePath);
$securityError = false;

if ($realSourcePath !== false && strpos($realSourcePath, $baseDir) !== 0) {
    $securityError = "Access denied.";
}
if ($realSourcePath === false && !preg_match('/^[a-zA-Z0-9_\-\/]+$/', $targetDir)) {
    $securityError = "Invalid path characters.";
}

$thumbPathAbs = $sourcePath . DIRECTORY_SEPARATOR . $thumbDirName;


// --- LOGIK: CLEANUP MODE (Text Output) ---
if ($reqMode === 'cleanup') {
    header('Content-Type: text/plain');

    if ($reqToken !== $cronSecret) die("Error: Invalid Token.");
    if ($securityError) die("Error: Security Violation.");

    echo "--- Starting Cleanup for: /$targetDir ---\n";

    if (is_dir($thumbPathAbs)) {
        $thumbFiles = scandir($thumbPathAbs);
        $deletedCount = 0;

        foreach ($thumbFiles as $tFile) {
            if ($tFile === '.' || $tFile === '..') continue;

            $tFilePath = $thumbPathAbs . DIRECTORY_SEPARATOR . $tFile;
            $sFilePath = $sourcePath . DIRECTORY_SEPARATOR . $tFile;

            if (is_file($tFilePath) && !file_exists($sFilePath)) {
                if (unlink($tFilePath)) {
                    echo "Deleted orphaned thumbnail: $tFile\n";
                    $deletedCount++;
                }
            }
        }
        echo "--- Cleanup Finished. Deleted: $deletedCount files. ---\n";
    } else {
        echo "Thumbnail directory does not exist ($thumbPathAbs).\n";
    }
    exit;
}

// --- LOGIK: DOWNLOAD ALL (ZIP) ---
if ($reqMode === 'download') {
    if ($securityError) die("Error: " . $securityError);
    if (!is_dir($sourcePath)) die("Error: Gallery not found.");
    if (!class_exists('ZipArchive')) die("Error: PHP ZipArchive extension not installed.");

    set_time_limit(0); // Timeout verhindern bei großen Ordnern

    $zipName = 'gallery-' . preg_replace('/[^a-zA-Z0-9]/', '-', $targetDir) . '.zip';
    $tempZipPath = tempnam(sys_get_temp_dir(), 'zip_gal');

    $zip = new ZipArchive();
    if ($zip->open($tempZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
        die("Error: Could not create temp zip file.");
    }

    $rawFiles = scandir($sourcePath);
    $addedCount = 0;

    foreach ($rawFiles as $file) {
        if (in_array($file, $ignoredFiles)) continue;
        
        $filePath = $sourcePath . DIRECTORY_SEPARATOR . $file;
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        if (is_file($filePath) && in_array($ext, $validExtensions)) {
            $zip->addFile($filePath, $file);
            $addedCount++;
        }
    }

    $zip->close();

    if ($addedCount > 0) {
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $zipName . '"');
        header('Content-Length: ' . filesize($tempZipPath));
        header('Pragma: no-cache'); 
        header('Expires: 0'); 
        readfile($tempZipPath);
    } else {
        echo "Keine herunterladbaren Bilder gefunden.";
    }

    unlink($tempZipPath); // Temp Datei löschen
    exit;
}

// --- LOGIK: NORMALER VIEW ---

if ($securityError) {
    die("Error: " . $securityError);
}

// Verzeichnisse erstellen
if (is_dir($sourcePath)) {
    if (!is_dir($thumbPathAbs)) {
        mkdir($thumbPathAbs, 0755, true);
    }
}

$images = [];

if (is_dir($sourcePath)) {
    $rawFiles = scandir($sourcePath);
    $fileList = [];

    // 1. Sammeln
    foreach ($rawFiles as $file) {
        if (in_array($file, $ignoredFiles)) continue;

        $filePath = $sourcePath . DIRECTORY_SEPARATOR . $file;
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        if (is_file($filePath) && in_array($ext, $validExtensions)) {
            $fileList[] = [
                'name' => $file,
                'path' => $filePath,
                'time' => filemtime($filePath)
            ];
        }
    }

    // 2. Sortieren (Neueste zuerst)
    usort($fileList, function($a, $b) {
        return $b['time'] - $a['time'];
    });

    // 3. Verarbeiten
    foreach ($fileList as $fInfo) {
        $file = $fInfo['name'];
        $filePath = $fInfo['path'];
        
        $thumbPath = $thumbPathAbs . DIRECTORY_SEPARATOR . $file;
        $webSourceUrl = $targetDir . '/' . $file;
        $webThumbUrl = $targetDir . '/' . $thumbDirName . '/' . $file;

        $needsGeneration = false;
        if (!file_exists($thumbPath)) $needsGeneration = true;
        elseif (filesize($thumbPath) === 0) $needsGeneration = true;
        elseif ($fInfo['time'] > filemtime($thumbPath)) $needsGeneration = true;

        if ($needsGeneration) {
            createThumbnail($filePath, $thumbPath, $thumbWidth, $thumbQuality);
        }

        if (file_exists($thumbPath)) {
            $dims = @getimagesize($filePath);
            $thumbDims = @getimagesize($thumbPath);

            if ($dims && $thumbDims) {
                $images[] = [
                    'src' => $webSourceUrl,
                    'thumb' => $webThumbUrl,
                    'w' => $dims[0],
                    'h' => $dims[1],
                    'thumb_w' => $thumbDims[0],
                    'thumb_h' => $thumbDims[1],
                    'name' => $file
                ];
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>reisinger.pictures</title>

    <link rel="icon" href="https://reisinger.pictures/favicon-32x32.png" type="image/png">
    <link rel="stylesheet" href="https://unpkg.com/photoswipe@5.4.3/dist/photoswipe.css">

    <style>
        :root {
            color-scheme: normal;
            --color-base-100: oklch(100% 0 0);
            --color-base-200: oklch(98% 0 0);
            --color-base-300: oklch(95% 0 0);
            --color-base-content: oklch(21% .006 285.885);
            --color-primary: #2a9d8f;
            --color-error: oklch(71% .194 13.428);
            --color-neutral: oklch(14% .005 285.823);
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: var(--color-base-100);
            color: var(--color-base-content);
            margin: 0;
            padding: 20px;
        }

        header {
			display: flex;
			flex-direction: column;
            text-align: center;
            margin-bottom: 30px;
            padding-top: 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .site-logo {
            width: 80px;
            height: auto;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            margin-bottom: 15px;
        }

        h1 {
            font-weight: 300;
            margin: 0;
            display: inline-flex;
            align-items: center;
            gap: 15px;
            font-size: 2rem;
            flex-wrap: wrap;
            justify-content: center;
        }

        .live-badge {
            font-weight: 700;
            font-size: 0.6em;
            letter-spacing: 1px;
            color: var(--color-error);
            display: flex;
            align-items: center;
            gap: 8px;
            border: 1px solid var(--color-error);
            padding: 4px 10px;
            border-radius: 4px;
            background: oklch(from var(--color-error) l c h / 0.1);
        }

        .record-dot {
            width: 10px;
            height: 10px;
            background-color: var(--color-error);
            border-radius: 50%;
            display: inline-block;
            animation: pulse-red 2s infinite;
        }
        
        @keyframes pulse-red {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(220, 38, 38, 0.7); opacity: 1; }
            70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(220, 38, 38, 0); opacity: 0.5; }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(220, 38, 38, 0); opacity: 1; }
        }

       .btn-download {
                   display: inline-block;
                   margin-top: 10px;

                   text-decoration: underline;
                   color: var(--color-neutral);

                   /* Remove Button Styling */
                   width: auto;
                   border: none;
                   padding: 4px 8px;
                   background: transparent;
                   transition: all 0.2s ease;
               }

               .btn-download:hover {
                   color: var(--color-primary);
                   text-decoration: underline;
               }

        .gallery-grid {
            max-width: 1600px;
            margin: 0 auto;
            column-count: 4;
            column-gap: 20px;
            margin-top: 40px;
        }

        @media (max-width: 1400px) { .gallery-grid { column-count: 3; } }
        @media (max-width: 900px)  { .gallery-grid { column-count: 2; } }
        @media (max-width: 500px)  { .gallery-grid { column-count: 1; } }

        .gallery-item {
            position: relative;
            display: inline-block;
            width: 100%;
            margin-bottom: 20px;
            break-inside: avoid;
            border-radius: 8px;
            background: var(--color-base-200);
            transition: transform 0.2s, box-shadow 0.2s;
            cursor: pointer;
            border: 1px solid var(--color-base-300);
            vertical-align: middle;
            line-height: 0;
            text-decoration: none;
        }

        .gallery-item:hover {
            transform: scale(1.02);
            z-index: 2;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
            border-color: var(--color-primary);
        }

        .gallery-item img {
            width: 100%;
            height: auto;
            display: block;
            border-radius: 7px;
        }

        .footer {
            text-align: center;
            margin-top: 60px;
            padding-bottom: 20px;
            color: var(--color-neutral);
            font-size: 0.9em;
            border-top: 1px solid var(--color-base-200);
            padding-top: 30px;
        }
        .footer strong { color: var(--color-primary); }
    </style>
</head>
<body>

    <header>
        <img src="https://reisinger.pictures/apple-touch-icon.png" alt="Reisinger Pictures Logo" class="site-logo">
        <h1>
            <span>reisinger.pictures</span>
            <span class="live-badge">
                <span class="record-dot"></span> LIVE
            </span>
        </h1>
        
        <?php if (!empty($images)): ?>
            <a href="?path=<?php echo urlencode($targetDir); ?>&mode=download" class="btn-download">
                &darr; Alle Bilder herunterladen (ZIP)
            </a>
        <?php endif; ?>
    </header>

    <div class="gallery-grid" id="my-gallery">
        <?php foreach ($images as $img): ?>
            <a href="<?php echo htmlspecialchars($img['src']); ?>"
               class="gallery-item"
               data-pswp-width="<?php echo $img['w']; ?>"
               data-pswp-height="<?php echo $img['h']; ?>"
               target="_blank">
                <img src="<?php echo htmlspecialchars($img['thumb']); ?>"
                     alt="<?php echo htmlspecialchars($img['name']); ?>"
                     width="<?php echo $img['thumb_w']; ?>"
                     height="<?php echo $img['thumb_h']; ?>"
                     loading="lazy" />
            </a>
        <?php endforeach; ?>

        <?php if (empty($images)): ?>
            <p style="text-align:center; column-span: all; color: var(--color-neutral); margin-top: 50px;">
                Es wurden keine Bilder zur Galerie <strong>"<?php echo htmlspecialchars($targetDir); ?>"</strong> gefunden.
                <br>
                Entweder es sind noch keine Bilder vorhanden oder die URL ist falsch.
            </p>
        <?php endif; ?>
    </div>

    <div class="footer">
        &copy; <?php echo date("Y"); ?> <a href="https://reisinger.pictures/"><strong>reisinger.pictures</strong></a><br>
        <?php echo count($images); ?> Bilder &bull; © Florian Reisinger<br>
        <a href="https://reisinger.pictures/impressum"><strong>Impressum</strong></a>
    </div>

    <script type="module">
        import PhotoSwipeLightbox from 'https://unpkg.com/photoswipe@5.4/dist/photoswipe-lightbox.esm.js';
        import PhotoSwipe from 'https://unpkg.com/photoswipe@5.4/dist/photoswipe.esm.js';

        const lightbox = new PhotoSwipeLightbox({
            gallery: '#my-gallery',
            children: 'a',
            pswpModule: PhotoSwipe
        });

        lightbox.on('uiRegister', function() {
            lightbox.pswp.ui.registerElement({
                name: 'download-button',
                order: 8,
                isButton: true,
                tagName: 'a',
                html: {
                    isCustomSVG: true,
                    inner: '<path d="M20.5 14.3 17.1 18V10h-2.2v7.9l-3.4-3.6L10 16l6 6.1 6-6.1ZM23 23H9v2h14Z" id="pswp__icn-download"/>',
                    outlineID: 'pswp__icn-download'
                },
                onInit: (el, pswp) => {
                    el.setAttribute('download', '');
                    el.setAttribute('target', '_blank');
                    el.setAttribute('rel', 'noopener');
                    el.title = "Download";
                    pswp.on('change', () => {
                        const currSlide = pswp.currSlide;
                        if (currSlide) {
                            el.href = currSlide.data.src;
                        }
                    });
                }
            });
        });

        lightbox.init();
    </script>
</body>
</html>