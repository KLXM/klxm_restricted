# KLXM Restricted

Zentrales Addon für Rechte, Zugriffsschutz und sichere Medienfreigaben in REDAXO.

KLXM Restricted ist eine leichte Alternative zu YCOM für typische Freigabe- und Schutzszenarien mit ein paar praxisnahen Extras, während YCOM die mächtigere Gesamtlösung für umfangreiche Community- und Mitgliederanforderungen bleibt.

Der aktuelle Schwerpunkt liegt auf dem Freigabe-Workflow mit Anfrageformular, Anti-Spam-Schutz, Download-Härtung und auswertbarer Statistik.

## Einordnung im Vergleich zu YCOM

KLXM Restricted ist als leichte Alternative für typische Freigabe- und Schutzszenarien gedacht und bringt dafür ein paar praxisnahe Extras mit (z. B. Mediapool-Freigabe-Workflow mit Artikelbezug, abgesicherte Download-Flows und Share-Auswertung).

YCOM bleibt die mächtigere und umfassendere Lösung, insbesondere wenn es um große Community-, Mitglieds- und Authentifizierungsanforderungen geht.

## Betriebsmodi

### Standalone-Modus (ohne YCom)

- Rechte-Matrix für Struktur und Medienpool
- eigener Login-/Profil-Flow
- Rollen, Benutzer, Sessions, Zugriffsanfragen
- Passkey-APIs
- Pastebin
- Medienfreigaben

### YCom-Kompatibilitätsmodus (mit YCom)

- Fokus auf Medienfreigaben
- keine aktive eigene Rollen-/Benutzerlogik im Frontend-Flow
- Matrix bleibt für Schutzregeln verfügbar (insbesondere Medienkategorien)
- Backend-Navigation auf share-relevante Bereiche reduziert

Ziel: YCom bleibt für Identity/Auth zuständig, KLXM Restricted für Sharing und Schutzlogik.

## Hauptfunktionen

### Medienfreigaben im Medienpool

Backend-Seite: Mediapool > Dateiablage teilen (`mediapool/klxm_restricted_file_share`)

Pro Share konfigurierbar:

- Zielartikel für die Freigabeausgabe (artikelgebundener Flow)
- Quellenmodus:
- komplette Medienpool-Kategorie
- manuelle Gruppierung
- kategorisierter Repeater-Share
- optionales Passwort
- optionales Ablaufdatum
- optionales Download-Limit
- ZIP erlaubt / nicht erlaubt
- Anfrageformular aktiv / inaktiv
- Gültigkeit von Anfrage-Links (Tage)
- individuelle Formularfelder
- individueller Intro-Text

### Anfrage-Workflow mit Anti-Spam

Besucher können eine Freigabe anfragen.

- E-Mail-Pflichtfeld
- zusätzliche frei definierbare Felder
- personalisierter Anfrage-Link mit Ablaufdatum
- Versand über `rex_mailer`
- optionaler globaler E-Mail-Abbinder aus den Addon-Einstellungen

Aktuelle Schutzmaßnahmen:

- CSRF-Token-Validierung
- Formular-Guard mit Nonce + Zeitfenster
- Honeypot-Felder (`request_hp_website`, `request_hp_company`)
- kurze globale Sperre für kürzlich verwendete E-Mail-Adressen
- Share/IP-bezogenes Cooldown-Ratelimit

### Download-Härtung

- Einzeldownloads nur per POST
- CSRF-Absicherung für Datei-Downloads
- asynchrone ZIP-Erzeugung mit Status-/Fetch-Endpunkten

Dadurch werden u. a. unbeabsichtigte GET-Auslösungen durch Link-Checker vermieden.

### Statistik und Reporting

Backend-Seite: Restricted > Freigabe-Anfragen (`klxm_restricted/share_requests`)

- Gesamtanfragen
- eindeutige E-Mails
- letzte 30 Tage
- Top-Freigaben nach Datei-Downloads
- Top-Dateien pro Share
- Trend-Ansicht
- CSV-Export (Downloads + Anfragen)
- PDF-Export (wenn PDFOut installiert ist)

### One-Time Pastebin

- optional passwortgeschützt
- optionales Ablaufdatum
- optionale Medienanhänge
- optional selbstzerstörend nach Abruf

## Anwendungshilfe

### Für Administratoren (Einrichtung)

1. Addon installieren und aktivieren.
2. Unter Restricted > Einstellungen Basisoptionen setzen (z. B. Mail-Footer, Standardgültigkeit).
3. Redakteur-Rechte vergeben (siehe Abschnitt Berechtigungen).
4. Optional: Frontend-Modul `klxm_restricted_fileshare` auf Artikeln einsetzen.

### Für Redakteure (Tagesgeschäft)

1. Mediapool > Dateiablage teilen öffnen.
2. Share anlegen oder bearbeiten.
3. Quelle, Gültigkeit, Passwort, ZIP-Optionen und Anfrageformular konfigurieren.
4. Freigabelink verwenden oder in Mailings integrieren.
5. Unter Freigabe-Anfragen Anfragen und Download-Statistiken prüfen.

### Typischer Ablauf für externe Nutzer

1. Nutzer öffnet den Share-Link.
2. Bei aktivem Request-Flow wird das Anfrageformular angezeigt.
3. Nach erfolgreicher Anfrage wird ein personalisierter Link per E-Mail versendet.
4. Downloads erfolgen geschützt (POST/CSRF, optional ZIP asynchron).

## API-Dokumentation

### REDAXO rex-api Endpunkte

Alle Endpunkte werden über `index.php?rex-api-call=<name>` aufgerufen.

#### `klxm_restricted_matrix_update`

- Scope: Backend (nicht veröffentlicht)
- Methode: POST
- Parameter:
- `item_type` (string)
- `item_id` (int)
- `role_id` (int)
- `state` (`1`/`0` oder `true`/`false`)
- Antwort: JSON `{status: true|false, error?: string}`

#### `klxm_restricted_passkey_login_options`

- Scope: veröffentlicht
- Methode: GET/POST
- Zweck: WebAuthn-Login-Options erzeugen und in Session ablegen
- Antwort: PublicKeyCredentialRequestOptions als JSON

#### `klxm_restricted_passkey_login_verify`

- Scope: veröffentlicht
- Methode: POST (JSON-Body, WebAuthn Assertion)
- Zweck: Login-Assertion prüfen und Benutzer anmelden
- Antwort: JSON, z. B. `{status: true, message: "...", redirect: "..."}`

#### `klxm_restricted_passkey_register_options`

- Scope: veröffentlicht (nur für eingeloggte Nutzer)
- Methode: GET/POST
- Zweck: WebAuthn-Registrierungsoptionen erzeugen
- Antwort: PublicKeyCredentialCreationOptions als JSON

#### `klxm_restricted_passkey_register_verify`

- Scope: veröffentlicht (nur für eingeloggte Nutzer)
- Methode: POST (JSON-Body, WebAuthn Attestation)
- Zweck: Passkey speichern
- Antwort: JSON `{status: true|false, message|error}`

### Frontend-Share-Endpunkte (Query-basierter Flow)

Die Share-Logik arbeitet über URL-Parameter und Formular-POSTs.

Wichtig: Die Auslieferung erfolgt artikelgebunden über den konfigurierten Zielartikel.

Basisparameter:

- `klxm_board_share=<token>`

Download-/Aktionen über `klxm_board_share_download`:

- `file` (POST, inkl. CSRF)
- `preview` (GET)
- `zip_async_create` (POST)
- `zip_async_status` (GET)
- `zip_async_fetch` (GET)
- `zip_all` (direkt)
- `zip_selected` (POST)

Anfrageformular-POST:

- `klxm_board_share_request=1`
- `_csrf_token`
- `request_form_nonce`
- `request_form_issued_at`
- `request_email`
- optionale dynamische Formularfelder
- Honeypot-Felder werden serverseitig ausgewertet

## Berechtigungen

- `klxm_restricted[share]`
- Zugriff auf Mediapool > Dateiablage teilen (`mediapool/klxm_restricted_file_share`)
- Zugriff auf Restricted > Freigabe-Anfragen (`klxm_restricted/share_requests`)
- `klxm_restricted[pastebin]`
- Zugriff auf Pastebin-Bereich

Administrationsseiten wie Matrix, Benutzer, Rollen, Setup und globale Einstellungen bleiben auf Admin beschränkt.

## Voraussetzungen

- REDAXO >= 5.18
- PHP >= 8.4
- YForm >= 5.0
- Mediapool

## Installation

1. Addon über den Installer installieren.
2. Addon aktivieren.
3. Einstellungen prüfen und Rollenrechte setzen.

### Hinweis zu Reinstall

Der Install-Flow bereinigt vor dem YForm-Tableset-Import gezielt alte KLXM-YForm-Metadaten und leert den YForm-Cache, um inkonsistente Reinstall-Zustände zu vermeiden.

## Deinstallation

Bei Deinstallation werden alle KLXM-Restricted-Tabellen entfernt, inklusive Share-, Session-, Passkey- und Request-Tabellen sowie zugehöriger YForm-Metadaten.

## Changelog

Alle Änderungen stehen in [CHANGELOG.md](CHANGELOG.md).
