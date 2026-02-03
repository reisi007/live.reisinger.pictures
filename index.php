<?php
/**
 * Single File PHP PhotoSwipe Gallery (Next-Gen Formats: AVIF/WebP + Picture Tag)
 * * Version: 2.6 (Live Updates + Secure ZIP + Clean URLs)
 */

// --- Configuration (Defaults) ---
$defaultDir = 'gallery';       // Default folder
$thumbDirName = '_thumbnails'; // Folder name for thumbnails
$thumbWidth = 500;             // Width for Grid Thumbnails
$lightboxWidth = 1920;         // Width for Lightbox (Full Screen) View
$thumbQualityJpg = 75;         // JPEG Quality (0-100)
$thumbQualityWebp = 75;        // WebP Quality (0-100)
$thumbQualityAvif = 45;        // AVIF Quality (0-100)

// --- CORRUPTION HANDLING ---
$deleteCorruptedFiles = false; 

// SECURITY: Change this token to something random!
$cronSecret = 'PLEASE_CHANGE_THIS_SECRET_TOKEN';

// File filters
$ignoredFiles = ['.', '..', 'index.php', $thumbDirName, '.DS_Store', 'Thumbs.db'];
$validExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

// --- CAPABILITY CHECK ---
$canAvif = function_exists('imageavif');
$canWebp = function_exists('imagewebp');

// --- GLOBAL HELPER FUNCTIONS ---
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

        // Preserve transparency
        if ($type == IMAGETYPE_PNG || $type == IMAGETYPE_WEBP || $type == IMAGETYPE_GIF) {
            imagecolortransparent($thumbnail, imagecolorallocatealpha($thumbnail, 0, 0, 0, 127));
            imagealphablending($thumbnail, false);
            imagesavealpha($thumbnail, true);
        }

        imagecopyresampled($thumbnail, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        $result = false;
        
        switch ($format) {
            case 'avif':
                if (function_exists('imageavif')) $result = imageavif($thumbnail, $dest, $quality); 
                break;
            case 'webp':
                if (function_exists('imagewebp')) $result = imagewebp($thumbnail, $dest, $quality);
                break;
            case 'jpg':
            default:
                imageinterlace($thumbnail, true); 
                $result = imagejpeg($thumbnail, $dest, $quality);
                break;
        }

        imagedestroy($image);
        imagedestroy($thumbnail);
        return $result;
    }
}

if (!function_exists('isJpegIntegrityOk')) {
    function isJpegIntegrityOk($path) {
        $f = @fopen($path, 'rb');
        if (!$f) return false;
        if (fseek($f, -2, SEEK_END) === -1) { fclose($f); return false; }
        $data = fread($f, 2);
        fclose($f);
        return ($data === "\xFF\xD9");
    }
}

// --- INPUT HANDLING ---
$isCli = (php_sapi_name() === 'cli');
$reqMode = null; 
$reqToken = null; 
$reqPathInput = null; 
$reqFile = null;
$reqFmt = 'auto'; 
$reqWidth = $thumbWidth; 

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
    $reqFmt = isset($_GET['fmt']) ? $_GET['fmt'] : 'auto';
    if (isset($_GET['w'])) {
        $w = intval($_GET['w']);
        if ($w > 0 && $w <= 3000) $reqWidth = $w;
    }
}

// Sanitize path
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


// --- LOGIC: THUMBNAIL DELIVERY ---
if ($reqMode === 'thumb') {
    if ($securityError) { http_response_code(403); die("Access Denied"); }
    if (!$reqFile) { http_response_code(400); die("No file specified"); }

    $fileName = basename($reqFile);
    if ($fileName !== $reqFile) die("Invalid filename");

    $srcFile = $sourcePath . DIRECTORY_SEPARATOR . $fileName;

    if (!file_exists($srcFile)) { http_response_code(404); die("Image not found"); }

    if ($reqFmt === 'auto') {
        $accept = isset($_SERVER['HTTP_ACCEPT']) ? $_SERVER['HTTP_ACCEPT'] : '';
        if ($canAvif && strpos($accept, 'image/avif') !== false) $reqFmt = 'avif';
        elseif ($canWebp && strpos($accept, 'image/webp') !== false) $reqFmt = 'webp';
        else $reqFmt = 'jpg';
        header("Vary: Accept");
    }

    $mtime = filemtime($srcFile);
    $hash = substr(md5($mtime), 0, 8); 
    
    $destExt = match($reqFmt) { 'avif' => '.avif', 'webp' => '.webp', default => '.jpg' };
    $destFileName = $fileName . '.' . $hash . '.w' . $reqWidth . $destExt;
    $destFile = $thumbPathAbs . DIRECTORY_SEPARATOR . $destFileName;

    if (!is_dir($thumbPathAbs)) mkdir($thumbPathAbs, 0755, true);

    if (!file_exists($destFile)) {
        // Cleanup old versions
        $dirHandle = opendir($thumbPathAbs);
        if ($dirHandle) {
            while (($file = readdir($dirHandle)) !== false) {
                if ($file === '.' || $file === '..') continue;
                if (strpos($file, $fileName . '.') === 0 && strpos($file, '.w' . $reqWidth . '.') !== false) {
                     if ($file !== $destFileName) @unlink($thumbPathAbs . DIRECTORY_SEPARATOR . $file);
                }
            }
            closedir($dirHandle);
        }

        $q = match($reqFmt) { 'avif' => $thumbQualityAvif, 'webp' => $thumbQualityWebp, default => $thumbQualityJpg };
        $success = createThumbnail($srcFile, $destFile, $reqWidth, $reqFmt, $q);
        
        if (!$success) {
            if ($reqFmt !== 'jpg') {
                 $fallbackUrl = "?mode=thumb&file=".urlencode($fileName)."&w=$reqWidth&fmt=jpg&v=".$hash;
                 header("Location: $fallbackUrl");
                 exit;
            } else {
                header("Location: /" . $targetDir . '/' . $fileName);
                exit;
            }
        }
    }

    $mime = match($reqFmt) { 'avif' => 'image/avif', 'webp' => 'image/webp', default => 'image/jpeg' };
    $lastMod = filemtime($destFile);
    $etag = md5($destFile . $lastMod);
    
    header("Last-Modified: ".gmdate("D, d M Y H:i:s", $lastMod)." GMT");
    header("Etag: $etag");
    header("Content-Type: $mime");
    header("Content-Length: " . filesize($destFile));
    header("Cache-Control: public, max-age=31536000, immutable"); 

    if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) && strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) >= $lastMod) {
        header('HTTP/1.1 304 Not Modified');
        exit;
    }
    
    readfile($destFile);
    exit;
}

// --- LOGIC: CLEANUP ---
if ($reqMode === 'cleanup') {
    header('Content-Type: text/plain');
    if ($reqToken !== $cronSecret) die("Error: Invalid Token.");
    if ($securityError) die("Error: Security Violation.");

    echo "--- Starting Recursive Cleanup ---\nRoot: $sourcePath\n";
    $deletedCount = 0;
    $recursiveClean = function($currentDir) use (&$recursiveClean, $thumbDirName, &$deletedCount) {
        $items = @scandir($currentDir);
        if (!$items) return;

        if (in_array($thumbDirName, $items) && is_dir($currentDir . DIRECTORY_SEPARATOR . $thumbDirName)) {
            $tDir = $currentDir . DIRECTORY_SEPARATOR . $thumbDirName;
            $tFiles = scandir($tDir);
            foreach ($tFiles as $tFile) {
                if ($tFile === '.' || $tFile === '..') continue;
                $origName = preg_replace('/(\.[a-f0-9]{8})(\.w\d+)?\.(avif|webp|jpg)$/', '', $tFile);
                if (!file_exists($currentDir . DIRECTORY_SEPARATOR . $origName)) {
                    if (@unlink($tDir . DIRECTORY_SEPARATOR . $tFile)) {
                        echo "Deleted orphaned: $origName\n";
                        $deletedCount++;
                    }
                }
            }
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || $item === $thumbDirName) continue;
            if (is_dir($currentDir . DIRECTORY_SEPARATOR . $item)) $recursiveClean($currentDir . DIRECTORY_SEPARATOR . $item);
        }
    };
    $recursiveClean($sourcePath);
    echo "Deleted: $deletedCount\n";
    exit;
}

// --- LOGIC: DOWNLOAD (Updated for Corruption Check) ---
if ($reqMode === 'download') {
    if ($securityError) die($securityError);
    if (file_exists(__DIR__ . '/vendor/autoload.php')) require_once __DIR__ . '/vendor/autoload.php';
    else die("Error: Vendor missing.");

    try {
        $zip = new \ZipStream\ZipStream(outputName: 'gallery.zip', sendHttpHeaders: true);
        
        foreach (scandir($sourcePath) as $f) {
            if (in_array($f, $ignoredFiles)) continue;
            
            $filePath = $sourcePath . DIRECTORY_SEPARATOR . $f;
            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));

            if (!is_file($filePath)) continue;
            if (!in_array($ext, $validExtensions)) continue;

            // Integrity Check
            $dims = @getimagesize($filePath);
            if (!$dims) continue; 

            if (($ext === 'jpg' || $ext === 'jpeg') && !isJpegIntegrityOk($filePath)) {
                continue; 
            }

            $zip->addFileFromPath($f, $filePath);
        }
        $zip->finish();
    } catch (Exception $e) { error_log("Zip Error: " . $e->getMessage()); }
    exit;
}

// --- LOGIC: SCAN FILES ---
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
            $dims = @getimagesize($filePath);
            if (!$dims) { if ($deleteCorruptedFiles) @unlink($filePath); continue; }
            if (($ext === 'jpg' || $ext === 'jpeg') && !isJpegIntegrityOk($filePath)) {
                if ($deleteCorruptedFiles) @unlink($filePath); continue;
            }
            
            $fileList[] = [
                'name' => $file,
                'path' => $filePath,
                'time' => filemtime($filePath),
                'w'    => $dims[0],
                'h'    => $dims[1]
            ];
        }
    }

    usort($fileList, function($a, $b) { return $b['time'] - $a['time']; });

    foreach ($fileList as $fInfo) {
        $file = $fInfo['name'];
        $w = $fInfo['w'];
        $h = $fInfo['h'];
        $tH = floor($thumbWidth * ($h / $w));
        $fullW = ($w > $lightboxWidth) ? $lightboxWidth : $w;
        $fullH = floor($fullW * ($h / $w));
        $fileHash = substr(md5($fInfo['time']), 0, 8);

        // NOTE: Parameters are relative to current URL (handled by htaccess)
        $urlParams = '?file=' . urlencode($file) . '&v=' . $fileHash;

        $thumbBase = $urlParams . '&mode=thumb&w=' . $thumbWidth;
        $lightboxUrl = $urlParams . '&mode=thumb&w=' . $fullW;

        $images[] = [
            'name' => $file,
            // Absolute path for originals to support Rewrites
            'orig_src' => '/' . $targetDir . '/' . $file, 
            'lightbox_src' => $lightboxUrl,
            'w' => $fullW,
            'h' => $fullH,
            'thumb_w' => $thumbWidth,
            'thumb_h' => $tH,
            'thumb_jpg'  => $thumbBase . '&fmt=jpg',
            'thumb_webp' => $thumbBase . '&fmt=webp',
            'thumb_avif' => $thumbBase . '&fmt=avif'
        ];
    }
}

// --- LOGIC: JSON API ---
if ($reqMode === 'json') {
    header('Content-Type: application/json');
    echo json_encode(['images' => $images, 'capabilities' => ['avif' => $canAvif, 'webp' => $canWebp]]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, follow">
    <title>reisinger.pictures LIVE</title>
    <link rel="icon" href="https://reisinger.pictures/favicon-32x32.png" type="image/png">
    <link rel="stylesheet" href="/assets/css/photoswipe/photoswipe.css">

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
        body { font-family: system-ui, sans-serif; background: var(--color-base-100); color: var(--color-base-content); margin: 0; padding: 20px; }
        header { text-align: center; margin-bottom: 30px; padding-top: 20px; display: flex; flex-direction: column; align-items: center; }
        .site-logo { width: 80px; height: auto; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); margin-bottom: 15px; }
        h1 { font-weight: 300; margin: 0; display: inline-flex; align-items: center; gap: 15px; font-size: 2rem; flex-wrap: wrap; justify-content: center; }
        .live-badge { font-weight: 700; font-size: 0.6em; letter-spacing: 1px; color: var(--color-error); display: flex; align-items: center; gap: 8px; border: 1px solid var(--color-error); padding: 4px 10px; border-radius: 4px; background: oklch(from var(--color-error) l c h / 0.1); }
        .record-dot { width: 10px; height: 10px; background-color: var(--color-error); border-radius: 50%; display: inline-block; animation: pulse-red 2s infinite; }
        @keyframes pulse-red { 0% { transform: scale(0.95); opacity: 1; } 70% { transform: scale(1); opacity: 0.5; } 100% { transform: scale(0.95); opacity: 1; } }
        .btn-download { display: inline-block; margin-top: 10px; text-decoration: underline; color: var(--color-neutral); border: none; padding: 4px 8px; background: transparent; transition: all 0.2s ease; }
        .btn-download:hover { color: var(--color-primary); }
        .gallery-grid { max-width: 1600px; margin: 40px auto 0; column-count: 4; column-gap: 20px; }
        @media (max-width: 1400px) { .gallery-grid { column-count: 3; } }
        @media (max-width: 900px)  { .gallery-grid { column-count: 2; } }
        @media (max-width: 500px)  { .gallery-grid { column-count: 1; } }
        .gallery-item { position: relative; display: inline-block; width: 100%; margin-bottom: 20px; break-inside: avoid; border-radius: 8px; background: var(--color-base-200); transition: transform 0.2s; cursor: pointer; border: 1px solid var(--color-base-300); text-decoration: none; line-height: 0; }
        .gallery-item:hover { transform: scale(1.02); z-index: 2; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1); border-color: var(--color-primary); }
        .gallery-item picture { display: block; width: 100%; height: auto; }
        .gallery-item img { width: 100%; height: auto; display: block; border-radius: 7px; background: #eee; }
        .footer { text-align: center; margin-top: 60px; padding-bottom: 20px; color: var(--color-neutral); font-size: 0.9em; border-top: 1px solid var(--color-base-200); padding-top: 30px; }
        .footer strong { color: var(--color-primary); }
    </style>
</head>
<body>

    <header>
        <img src="https://reisinger.pictures/apple-touch-icon.png" alt="Logo" class="site-logo">
        <h1>
            <span>reisinger.pictures</span>
            <span class="live-badge"><span class="record-dot"></span> LIVE</span>
        </h1>
        <?php if (!empty($images)): ?>
            <a href="?mode=download" class="btn-download">
                &darr; Alle Bilder herunterladen (ZIP)
            </a>
        <?php endif; ?>
    </header>

    <div class="gallery-grid" id="my-gallery">
        <?php foreach ($images as $img): ?>
            <a href="<?php echo htmlspecialchars($img['lightbox_src']); ?>"
               class="gallery-item"
               data-pswp-width="<?php echo $img['w']; ?>"
               data-pswp-height="<?php echo $img['h']; ?>"
               data-original-src="<?php echo htmlspecialchars($img['orig_src']); ?>"
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
        import PhotoSwipeLightbox from '/assets/js/photoswipe/photoswipe-lightbox.esm.min.js';
        import PhotoSwipe from '/assets/js/photoswipe/photoswipe.esm.min.js';

        const pollInterval = 30000; 
        const galleryGrid = document.getElementById('my-gallery');
        
        // Note: No targetPath needed anymore, browser URL handles it via htaccess

        const lightbox = new PhotoSwipeLightbox({
            gallery: '#my-gallery',
            children: 'a',
            pswpModule: PhotoSwipe
        });

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
                        if (currSlide && currSlide.data.element) {
                            const orig = currSlide.data.element.getAttribute('data-original-src');
                            el.href = orig ? orig : currSlide.data.src;
                        } else if (currSlide) {
                            el.href = currSlide.data.src;
                        }
                    });
                }
            });
        });

        lightbox.init();

        function createGalleryItemHTML(img, caps) {
            let sources = '';
            if (caps.avif) sources += `<source srcset="${img.thumb_avif}" type="image/avif">`;
            if (caps.webp) sources += `<source srcset="${img.thumb_webp}" type="image/webp">`;

            return `
               <picture>
                   ${sources}
                   <img src="${img.thumb_jpg}" 
                        alt="${escapeHtml(img.name)}"
                        width="${img.thumb_w}"
                        height="${img.thumb_h}"
                        loading="lazy" />
               </picture>
            `;
        }

        function escapeHtml(text) {
            if (!text) return text;
            return text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
        }

        async function checkForUpdates() {
            try {
                // Browsers sends request to current URL (e.g., /vacation?mode=json).
                // Htaccess rewrites this to index.php?path=vacation&mode=json.
                const response = await fetch('?mode=json');
                
                if (!response.ok) throw new Error('Network response was not ok');
                
                const data = await response.json();
                const serverImages = data.images;
                const capabilities = data.capabilities;

                const existingLinks = galleryGrid.querySelectorAll('a.gallery-item');
                const existingNames = new Set();
                
                existingLinks.forEach(link => {
                    const src = link.getAttribute('data-original-src');
                    if (src) existingNames.add(src.split('/').pop());
                });

                const newItems = serverImages.filter(img => !existingNames.has(img.name));

                if (newItems.length > 0) {
                    console.log(`Found ${newItems.length} new images.`);
                    for (let i = newItems.length - 1; i >= 0; i--) {
                        const img = newItems[i];
                        const a = document.createElement('a');
                        a.href = img.lightbox_src;
                        a.className = 'gallery-item';
                        a.setAttribute('data-pswp-width', img.w);
                        a.setAttribute('data-pswp-height', img.h);
                        a.setAttribute('data-original-src', img.orig_src);
                        a.target = '_blank';
                        a.innerHTML = createGalleryItemHTML(img, capabilities);
                        a.style.opacity = '0';
                        a.style.transition = 'opacity 1s ease';
                        galleryGrid.prepend(a);
                        void a.offsetWidth; 
                        a.style.opacity = '1';
                    }
                    const emptyMsg = galleryGrid.querySelector('p');
                    if (emptyMsg && galleryGrid.children.length > 1) emptyMsg.remove();
                }

            } catch (error) {
                console.warn('Auto-update check failed:', error);
            }
        }

        setInterval(checkForUpdates, pollInterval);
    </script>
</body>
</html>