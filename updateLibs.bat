@echo off
:: ---------------------------------------------------------
:: KONFIGURATION
:: ---------------------------------------------------------

:: Die gewünschte Version von PhotoSwipe
set "VERSION=5.4"

:: Zielordner in deinem Projekt (bitte an deine Struktur anpassen)
:: "." bedeutet aktuelles Verzeichnis
set "JS_ZIEL=.\assets\js\photoswipe"
set "CSS_ZIEL=.\assets\css\photoswipe"

:: Basis-URL von unpkg
set "BASE_URL=https://unpkg.com/photoswipe@%VERSION%/dist"

:: ---------------------------------------------------------
:: START DES DOWNLOADS
:: ---------------------------------------------------------
echo.
echo Starte Download von PhotoSwipe Version %VERSION%...
echo.

:: 1. Ordner erstellen, falls sie nicht existieren
if not exist "%JS_ZIEL%" (
    echo Erstelle Verzeichnis: %JS_ZIEL%
    mkdir "%JS_ZIEL%"
)

if not exist "%CSS_ZIEL%" (
    echo Erstelle Verzeichnis: %CSS_ZIEL%
    mkdir "%CSS_ZIEL%"
)

:: 2. JavaScript Dateien herunterladen
echo Lade JavaScript Dateien...

:: Hauptdatei
curl -L -o "%JS_ZIEL%\photoswipe.esm.js" "%BASE_URL%/photoswipe.esm.js"
:: Lightbox Datei (wird meistens auch benötigt)
curl -L -o "%JS_ZIEL%\photoswipe-lightbox.esm.js" "%BASE_URL%/photoswipe-lightbox.esm.js"

:: 3. CSS Datei herunterladen
echo Lade CSS Datei...
curl -L -o "%CSS_ZIEL%\photoswipe.css" "%BASE_URL%/photoswipe.css"

echo.
echo ---------------------------------------------------------
echo FERTIG!
echo Die Dateien wurden erfolgreich heruntergeladen.
echo Du kannst sie nun lokal in deiner PHP-Datei einbinden.
echo ---------------------------------------------------------
pause