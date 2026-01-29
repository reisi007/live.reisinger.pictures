# Next-Gen PHP Photo Gallery

A high-performance, **single-file PHP gallery** engine designed for developers who prioritize speed, modern image formats, and zero-dependency deployments. This script transforms a simple directory of images into a professional, responsive gallery using **AVIF**, **WebP**, and **PhotoSwipe 5**.

---

## Developer Features

### 1. Adaptive Next-Gen Media Pipeline
The script implements a sophisticated image delivery strategy that prioritizes modern compression without breaking compatibility:
* **Multi-Format Negotiation:** It utilizes the HTML5 `<picture>` tag to serve **AVIF** (best compression), then **WebP**, and finally **JPEG** as a universal fallback.
* **On-the-Fly Generation:** Thumbnails are generated dynamically upon first request (`?mode=thumb`).
* **Smart Fallback Logic:** If the server's GD library fails to generate a specific high-efficiency format (e.g., missing AVIF support), the script automatically redirects to a JPEG fallback to ensure the UI never breaks.
* **Server Capability Detection:** It performs real-time checks for `imageavif` and `imagewebp` support to conditionally render the appropriate frontend source tags.

### 2. Performance & Cache Architecture
* **HTTP Optimization:** Fully supports `Last-Modified` and `Etag` headers. It validates requests against `HTTP_IF_MODIFIED_SINCE` to return `304 Not Modified` status codes, significantly reducing server load.
* **Long-Term Caching:** Served thumbnails include a `Cache-Control` header set to one year (`max-age=31536000`).
* **Lazy Loading:** Native `loading="lazy"` attributes are applied to the grid, ensuring fast initial page paint times even for large galleries.

### 3. Frontend Implementation
* **PhotoSwipe 5 Integration:** Leverages the latest ESM (ECMAScript Modules) version of PhotoSwipe, loaded via CDN to eliminate local asset management.
* **Zero-JS Masonry:** Uses a CSS-based `column-count` approach for a responsive Masonry layout, avoiding the performance overhead of JavaScript-based layout engines.
* **Custom UI Components:** Includes a custom-registered SVG download button within the lightbox interface that dynamically updates its source to match the currently viewed image.

### 4. Security & Maintenance Tools
* **Directory Traversal Protection:** Sanitizes all input paths by stripping `..` and null bytes, then validating the result against `realpath` to ensure access remains within the defined base directory.
* **Maintenance CLI/Web Mode:** Includes a `cleanup` mode (protected by a `$cronSecret`) to remove orphaned thumbnails. It cross-references existing thumbnails against their source files to keep storage lean.
* **Instant ZIP Archiving:** Uses the `ZipArchive` class to bundle gallery contents into a downloadable archive on demand.

---

## Technical Requirements
* **PHP 8.1+** (Uses `match` expressions and modern syntax).
* **GD Graphics Library** (with AVIF/WebP support recommended).
* **ZipArchive Extension** (for "Download All" functionality).

## Configuration
All primary settings are located at the head of the file for quick modification:
```php
$defaultDir = 'gallery';       // Source image directory
$thumbWidth = 500;             // Target width for grid images
$thumbQualityAvif = 45;        // Highly efficient AVIF quality setting
$cronSecret = 'MySecretKey';   // Token for maintenance tasks