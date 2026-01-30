@echo off
:: ---------------------------------------------------------
:: CONFIGURATION
:: ---------------------------------------------------------

:: The desired version of PhotoSwipe
set "VERSION=5.4"

:: Target folders in your project 
set "JS_ZIEL=.\assets\js\photoswipe"
set "CSS_ZIEL=.\assets\css\photoswipe"

:: Base URL from unpkg
set "BASE_URL=https://unpkg.com/photoswipe@%VERSION%/dist"

:: ---------------------------------------------------------
:: START DOWNLOAD
:: ---------------------------------------------------------
echo.
echo Starting download of PhotoSwipe (Minified) version %VERSION%... 
echo.

:: 1. Create directories if they do not exist
if not exist "%JS_ZIEL%" (
    echo Creating directory: %JS_ZIEL% 
    mkdir "%JS_ZIEL%"
)

if not exist "%CSS_ZIEL%" (
    echo Creating directory: %CSS_ZIEL% 
    mkdir "%CSS_ZIEL%"
)

:: 2. Download JavaScript files (Minified)
echo Downloading minified JavaScript files...

:: Core file (minified)
curl -L -o "%JS_ZIEL%\photoswipe.esm.min.js" "%BASE_URL%/photoswipe.esm.min.js"

:: Lightbox file (minified)
curl -L -o "%JS_ZIEL%\photoswipe-lightbox.esm.min.js" "%BASE_URL%/photoswipe-lightbox.esm.min.js"

:: 3. Download CSS file
echo Downloading CSS file...
curl -L -o "%CSS_ZIEL%\photoswipe.css" "%BASE_URL%/photoswipe.css"

echo.
echo --------------------------------------------------------- 
echo DONE! 
echo The minified files have been downloaded successfully. 
echo You can now include them locally in your PHP/HTML files. 
echo --------------------------------------------------------- 
pause