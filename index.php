<?php
/**
 * Single File PHP PhotoSwipe Gallery (Next-Gen Formats: AVIF/WebP + Picture Tag)
 * * Version: 1.7
 */

// --- Konfiguration (Defaults) ---
$defaultDir = 'gallery';       // Standardordner
$thumbDirName = '_thumbnails'; // Ordnername für Vorschaubilder
$thumbWidth = 500;             // Breite für Masonry Grid
$thumbQualityJpg = 75;         // Qualität JPEG (0-100)
$thumbQualityWebp = 75;        // Qualität WebP (0-100)
$thumbQualityAvif = 45;        // Qualität AVIF (0-100, AVIF ist bei niedrigeren Werten effizienter)
$cronSecret = 'MySecretKey123'; 

// Dateifilter
$ignoredFiles = ['.', '..', 'index.php', $thumbDirName, '.DS_Store', 'Thumbs.db'];
$validExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

// --- CAPABILITY CHECK ---
// Wir prüfen einmalig, was der Server kann
$canAvif = function_exists('imageavif');
$canWebp = function_exists('imagewebp');

// --- GLOBAL HELPER FUNCTION ---
if (!function_exists('createThumbnail')) {
    function createThumbnail($src, $dest, $targetWidth, $format, $quality) {
        if (!file_exists($src)) return false;

        $info = @getimagesize($src);
        if (!$info) return false;
        
        $width = $info[0]; 
        $height = $info[1];
        $type = $info[2];

        $ratio = $height / $width;
        $targetHeight = floor($targetWidth * $ratio);

        switch ($type) {
            case IMAGETYPE_JPEG: $image = @imagecreatefromjpeg($src); break;
            case IMAGETYPE_PNG:  $image = @imagecreatefrompng($src); break;
            case IMAGETYPE_GIF:  $image = @imagecreatefromgif($src); break;
            case IMAGETYPE_WEBP: $image = @imagecreatefromwebp($src); break;
            default: return false;
        }

        if (!$image) return false;

        $thumbnail = imagecreatetruecolor($targetWidth, $targetHeight);

        // Transparenz erhalten
        if ($type == IMAGETYPE_PNG || $type == IMAGETYPE_WEBP || $type == IMAGETYPE_GIF) {
            imagecolortransparent($thumbnail, imagecolorallocatealpha($thumbnail, 0, 0, 0, 127));
            imagealphablending($thumbnail, false);
            imagesavealpha($thumbnail, true);
        }

        imagecopyresampled($thumbnail, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        $result = false;
        
        // Zielformat schreiben
        switch ($format) {
            case 'avif':
                // AVIF braucht oft PHP 8.1+ und eine aktuelle GD Lib
                if (function_exists('imageavif')) {
                    $result = imageavif($thumbnail, $dest, $quality); 
                }
                break;
            case 'webp':
                if (function_exists('imagewebp')) {
                    $result = imagewebp($thumbnail, $dest, $quality);
                }
                break;
            case 'jpg':
            default:
                imageinterlace($thumbnail, true); // Progressive JPEG
                $result = imagejpeg($thumbnail, $dest, $quality);
                break;
        }

        imagedestroy($image);
        imagedestroy($thumbnail);
        return $result;
    }
}

// --- INPUT HANDLING ---
$isCli = (php_sapi_name() === 'cli');
$reqMode = null; 
$reqToken = null; 
$reqPathInput = null; 
$reqFile = null;
$reqFmt = 'jpg'; // Default Format Anfrage

if ($isCli) {
    foreach ($argv as $arg) {
        if (strpos($arg, 'mode=') === 0) $reqMode = substr($arg, 5);
        if (strpos($arg, 'token=') === 0) $reqToken = substr($arg, 6);
        if (strpos($arg, 'path=') === 0) $reqPathInput = substr($arg, 5);
    }
} else {
    $reqMode = isset($_GET['mode']) ? $_GET['mode'] : null;
    $reqToken = isset($_GET['token']) ? $_GET['token'] : null;
    $reqPathInput = isset($_GET['path']) ? $_GET['path'] : null;
    $reqFile = isset($_GET['file']) ? $_GET['file'] : null;
    $reqFmt = isset($_GET['fmt']) ? $_GET['fmt'] : 'jpg';
}

// Pfad bereinigen
$targetDir = $reqPathInput ? $reqPathInput : $defaultDir;
$targetDir = str_replace(['..', "\0"], '', $targetDir);
$targetDir = trim($targetDir, '/\\');

$baseDir = __DIR__;
$sourcePath = $baseDir . DIRECTORY_SEPARATOR . $targetDir;

// Security
$realSourcePath = realpath($sourcePath);
$securityError = false;
if ($realSourcePath !== false && strpos($realSourcePath, $baseDir) !== 0) $securityError = "Access denied.";
if ($realSourcePath === false && !preg_match('/^[a-zA-Z0-9_\-\/]+$/', $targetDir)) {
    if (!is_dir($sourcePath)) $securityError = "Gallery path not found.";
}

$thumbPathAbs = $sourcePath . DIRECTORY_SEPARATOR . $thumbDirName;


// --- LOGIK: THUMBNAIL DELIVERY (ON-THE-FLY) ---
if ($reqMode === 'thumb') {
    if ($securityError) { http_response_code(403); die("Access Denied"); }
    if (!$reqFile) { http_response_code(400); die("No file specified"); }

    $fileName = basename($reqFile);
    if ($fileName !== $reqFile) die("Invalid filename");

    $srcFile = $sourcePath . DIRECTORY_SEPARATOR . $fileName;
    
    // Zieldatei hat nun das Format als Endung angehängt (z.B. bild.jpg.avif)
    // Das verhindert Konflikte und macht das Caching einfach
    $destExt = match($reqFmt) {
        'avif' => '.avif',
        'webp' => '.webp',
        default => '.jpg'
    };
    
    $destFile = $thumbPathAbs . DIRECTORY_SEPARATOR . $fileName . $destExt;

    if (!file_exists($srcFile)) { http_response_code(404); die("Image not found"); }

    if (!is_dir($thumbPathAbs)) mkdir($thumbPathAbs, 0755, true);

    $needsGeneration = false;
    if (!file_exists($destFile)) {
        $needsGeneration = true;
    } else {
        if (filemtime($srcFile) > filemtime($destFile)) $needsGeneration = true;
    }

    if ($needsGeneration) {
        // Qualität basierend auf Format wählen
        $q = match($reqFmt) {
            'avif' => $thumbQualityAvif,
            'webp' => $thumbQualityWebp,
            default => $thumbQualityJpg
        };
        
        $success = createThumbnail($srcFile, $destFile, $thumbWidth, $reqFmt, $q);
        
        // Fallback: Wenn AVIF/WebP Generierung fehlschlägt (z.B. fehlende Lib zur Laufzeit), 
        // versuchen wir JPG oder leiten um.
        if (!$success) {
            if ($reqFmt !== 'jpg') {
                // Versuch auf JPG Fallback umzuleiten
                 $fallbackUrl = "?mode=thumb&path=".urlencode($targetDir)."&file=".urlencode($fileName)."&fmt=jpg";
                 header("Location: $fallbackUrl");
                 exit;
            } else {
                header("Location: " . $targetDir . '/' . $fileName);
                exit;
            }
        }
    }

    // Mime Type
    $mime = match($reqFmt) {
        'avif' => 'image/avif',
        'webp' => 'image/webp',
        default => 'image/jpeg'
    };

    $lastMod = filemtime($destFile);
    $etag = md5($destFile . $lastMod);
    
    header("Last-Modified: ".gmdate("D, d M Y H:i:s", $lastMod)." GMT");
    header("Etag: $etag");
    header("Content-Type: $mime");
    header("Content-Length: " . filesize($destFile));
    header("Cache-Control: public, max-age=31536000"); // 1 Jahr Cache, da Hash im Filename oder Etag

    if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) && strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) >= $lastMod) {
        header('HTTP/1.1 304 Not Modified');
        exit;
    }
    
    readfile($destFile);
    exit;
}

// --- LOGIK: CLEANUP / DOWNLOAD (Unverändert, aber der Vollständigkeit halber) ---
if ($reqMode === 'cleanup') {
    // ... (Cleanup muss nun auch .avif und .webp löschen können)
    header('Content-Type: text/plain');
    if ($reqToken !== $cronSecret) die("Error: Invalid Token.");
    if ($securityError) die("Error: Security Violation.");
    echo "--- Cleanup /$targetDir ---\n";
    if (is_dir($thumbPathAbs)) {
        $thumbFiles = scandir($thumbPathAbs);
        foreach ($thumbFiles as $tFile) {
            if ($tFile === '.' || $tFile === '..') continue;
            // Wir müssen die Originalendung extrahieren (z.B. bild.jpg aus bild.jpg.avif)
            // Einfacher Hack: Alles nach dem letzten Punkt entfernen und prüfen
            $origName = preg_replace('/\.(avif|webp|jpg)$/', '', $tFile);
            
            // Wenn der Name immer noch Extensions hat (bild.jpg), ist es ok. 
            // Aber Vorsicht bei bild.jpg (Thumbnail) vs bild.jpg (Original). 
            // Unsere Logik: Original heisst bild.jpg. Thumbnail heisst bild.jpg.jpg
            
            // Bessere Logik: Prüfe ob eine Datei im Source Dir existiert, die zum Thumb passt
            // Da wir einfach .ext anhängen, entfernen wir die letzte ext.
            $potentialSource = $sourcePath . DIRECTORY_SEPARATOR . $origName;
            
            if (!file_exists($potentialSource)) {
                // Könnte auch altes Naming Schema sein? 
                // Um sicher zu gehen: Wenn Original nicht da, weg damit.
                if (unlink($thumbPathAbs . DIRECTORY_SEPARATOR . $tFile)) {
                    echo "Deleted: $tFile\n";
                }
            }
        }
    }
    exit;
}

if ($reqMode === 'download') {
    if ($securityError) die($securityError);
    if (!class_exists('ZipArchive')) die("ZipArchive missing");
    $zipName = 'gallery.zip';
    $tempZip = tempnam(sys_get_temp_dir(), 'zip');
    $zip = new ZipArchive();
    $zip->open($tempZip, ZipArchive::CREATE);
    foreach (scandir($sourcePath) as $f) {
        if(in_array($f, $ignoredFiles)) continue;
        $fp = $sourcePath.'/'.$f;
        if(is_file($fp)) $zip->addFile($fp, $f);
    }
    $zip->close();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="'.$zipName.'"');
    header('Content-Length: '.filesize($tempZip));
    readfile($tempZip);
    unlink($tempZip);
    exit;
}

// --- LOGIK: VIEW ---
if ($securityError) die($securityError);

$images = [];

if (is_dir($sourcePath)) {
    $rawFiles = scandir($sourcePath);
    $fileList = [];

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

    usort($fileList, function($a, $b) { return $b['time'] - $a['time']; });

    foreach ($fileList as $fInfo) {
        $file = $fInfo['name'];
        $dims = @getimagesize($fInfo['path']);

        if ($dims) {
            $w = $dims[0];
            $h = $dims[1];
            $tH = floor($thumbWidth * ($h / $w));

            // Basis URL für Thumbnails
            $thumbBase = '?mode=thumb&path=' . urlencode($targetDir) . '&file=' . urlencode($file);

            $images[] = [
                'src' => $targetDir . '/' . $file,
                'w' => $w,
                'h' => $h,
                'thumb_w' => $thumbWidth,
                'thumb_h' => $tH,
                'name' => $file,
                // URLs für die verschiedenen Formate
                'thumb_jpg'  => $thumbBase . '&fmt=jpg',
                'thumb_webp' => $thumbBase . '&fmt=webp',
                'thumb_avif' => $thumbBase . '&fmt=avif'
            ];
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
            font-family: system-ui, sans-serif;
            background-color: var(--color-base-100);
            color: var(--color-base-content);
            margin: 0; padding: 20px;
        }
        header {
            text-align: center; margin-bottom: 30px; padding-top: 20px;
            display: flex; flex-direction: column; align-items: center;
        }
        .site-logo {
            width: 80px; height: auto; border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1); margin-bottom: 15px;
        }
        h1 {
            font-weight: 300; margin: 0; display: inline-flex; align-items: center;
            gap: 15px; font-size: 2rem; flex-wrap: wrap; justify-content: center;
        }
        .live-badge {
            font-weight: 700; font-size: 0.6em; letter-spacing: 1px;
            color: var(--color-error); display: flex; align-items: center;
            gap: 8px; border: 1px solid var(--color-error); padding: 4px 10px;
            border-radius: 4px; background: oklch(from var(--color-error) l c h / 0.1);
        }
        .record-dot {
            width: 10px; height: 10px; background-color: var(--color-error);
            border-radius: 50%; display: inline-block; animation: pulse-red 2s infinite;
        }
        @keyframes pulse-red {
            0% { transform: scale(0.95); opacity: 1; }
            70% { transform: scale(1); opacity: 0.5; }
            100% { transform: scale(0.95); opacity: 1; }
        }
        .btn-download {
           display: inline-block; margin-top: 10px; text-decoration: underline;
           color: var(--color-neutral); border: none; padding: 4px 8px;
           background: transparent; transition: all 0.2s ease;
        }
        .btn-download:hover { color: var(--color-primary); }

        /* Grid Optimierungen */
        .gallery-grid {
            max-width: 1600px; margin: 40px auto 0;
            column-count: 4; column-gap: 20px;
        }
        @media (max-width: 1400px) { .gallery-grid { column-count: 3; } }
        @media (max-width: 900px)  { .gallery-grid { column-count: 2; } }
        @media (max-width: 500px)  { .gallery-grid { column-count: 1; } }

        .gallery-item {
            position: relative; display: inline-block; width: 100%;
            margin-bottom: 20px; break-inside: avoid; border-radius: 8px;
            background: var(--color-base-200); transition: transform 0.2s;
            cursor: pointer; border: 1px solid var(--color-base-300);
            text-decoration: none; line-height: 0;
        }
        .gallery-item:hover {
            transform: scale(1.02); z-index: 2;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
            border-color: var(--color-primary);
        }
        
        /* WICHTIG: Picture Tag Styling */
        .gallery-item picture {
            display: block; width: 100%; height: auto;
        }
        .gallery-item img {
            width: 100%; height: auto; display: block;
            border-radius: 7px; background: #eee;
        }

        .footer {
            text-align: center; margin-top: 60px; padding-bottom: 20px;
            color: var(--color-neutral); font-size: 0.9em;
            border-top: 1px solid var(--color-base-200); padding-top: 30px;
        }
        .footer strong { color: var(--color-primary); }
    </style>
</head>
<body>

    <header>
        <img src="https://reisinger.pictures/apple-touch-icon.png" alt="Reisinger Pictures Logo" class="site-logo">
        <h1>
            <span>reisinger.pictures</span>
            <span class="live-badge"><span class="record-dot"></span> LIVE</span>
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
               
               <picture>
                   <?php if ($canAvif): ?>
                       <source srcset="<?php echo $img['thumb_avif']; ?>" type="image/avif">
                   <?php endif; ?>
                   
                   <?php if ($canWebp): ?>
                       <source srcset="<?php echo $img['thumb_webp']; ?>" type="image/webp">
                   <?php endif; ?>
                   
                   <img src="<?php echo $img['thumb_jpg']; ?>" 
                        alt="<?php echo htmlspecialchars($img['name']); ?>"
                        width="<?php echo $img['thumb_w']; ?>"
                        height="<?php echo $img['thumb_h']; ?>"
                        loading="lazy" />
               </picture>

            </a>
        <?php endforeach; ?>

        <?php if (empty($images)): ?>
            <p style="text-align:center; color: var(--color-neutral); margin-top: 50px;">
                Keine Bilder in "<?php echo htmlspecialchars($targetDir); ?>" gefunden.
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
        
        // Download Button (Unverändert)
        lightbox.on('uiRegister', function() {
            lightbox.pswp.ui.registerElement({
                name: 'download-button',
                order: 8, isButton: true, tagName: 'a',
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
                        if (currSlide) el.href = currSlide.data.src;
                    });
                }
            });
        });

        lightbox.init();
    </script>
</body>
</html>