# KLXM Restricted

Zentrales Frontend-Berechtigungsaddon für REDAXO mit Rollenmatrix, Medienpool-Schutz, Login/Profil-Flow, Admin-Imitation, Zugriffsanfragen, Medien-Freigabelinks und One-Time-Pastebin.

## Warum dieses Addon?

Viele Projekte scheitern nicht an fehlenden Rechten, sondern an fehlender Übersicht:
- Rechte sind verteilt über viele Artikel-Metafelder.
- Regeln sind für Redakteure kaum nachvollziehbar.
- Medien sind häufig unabsichtlich öffentlich.

KLXM Restricted löst genau dieses Problem mit einer zentralen Matrix und klaren Vererbungsregeln.

## Wichtige Abgrenzung zu YCom

KLXM Restricted ist kein YCom-Ersatz und soll das auch nicht sein.

Das Addon wurde auf konkreten Kundenwunsch entwickelt mit Fokus auf:
- einfache Einrichtung,
- schnelle Inbetriebnahme mit mitgelieferten Modulen,
- unkomplizierte Verwaltung von Zugriffsrechten im Projektalltag.

Im Ausblick planen wir zusätzlich optionale Social-/Fediverse-Login-Anbindungen (z. B. Google und Apple), ohne den Charakter als schlankes Berechtigungs- und Access-Addon zu verlieren.

## Die wichtigsten Vorteile

1. 🚀 Zentrale Rechteverwaltung statt verstreuter Einzelfelder.
2. 🧭 Kaskadierende Regeln für Kategorien und Unterseiten.
3. 🛡️ Schutz von Artikeln und Medien mit derselben Logik.
4. 👀 Sichtbare, reproduzierbare Entscheidungen im Frontend-Flow.
5. 📬 Klarer Redaktionsworkflow für Zugriffsanfragen.
6. 🔗 Temporäre, passwortgeschützte Medienfreigaben mit ZIP-Option.
7. 🔥 Einmal abrufbare Geheimtexte (Pastebin) mit Vernichtung nach Abruf.

## ⭐ Feature Highlights

### 🌟 Auf einen Blick

| Feature | Was ist daran cool? | Für wen? |
|---|---|---|
| 🧩 Einfache Einrichtung mit mitgelieferten Modulen | Login, Registrierung, Profil und User-Widget sind sofort als Module synchronisierbar | Redaktion, Integratoren |
| 🔐 Rechte-Matrix (Struktur + Medienpool) | Ein Ort für alle Regeln statt Metadaten-Chaos in vielen Artikeln | Admins, Redakteure |
| 🔗 Medien teilen im Mediapool | Share-Links mit Passwort, Ablauf, Limit, ZIP und Copy-Button direkt im Workflow | Redaktion, Projektteams |
| 🔥 One-Time Pastebin | Sensible Inhalte nur einmal sichtbar, danach serverseitig vernichtet | Admins, DevOps, Support |
| 🌗 Moderne Share/Pastebin-Seiten | Light/Dark/Auto + DE/EN Umschaltung ohne Framework-Zwang | Externe Empfänger |
| 🎨 Branding für öffentliche Seiten | Eigener Titel, Untertitel und Akzentfarbe für professionellen Auftritt | Agenturen, Unternehmen |
| 🧠 DB-Sessionmanagement | Bessere Kontrolle laufender Sessions inkl. Backend-Ansicht und Beenden-Funktion | Admins |
| 🧰 URL-Normalizer | Funktioniert robust auch mit HTML-escaped Copy/Paste-Links (`&amp;`, `&#038;`) | Alle Nutzer |

### 💥 Warum das im Alltag hilft

- Keine Rechte-Ratespiele mehr: Entscheidungen sind nachvollziehbar und reproduzierbar.
- Sicheres Teilen ohne Extra-Portal: Mediapool-Link erzeugen, senden, fertig.
- Geheime Daten bleiben nicht liegen: One-Time-Prinzip reduziert Risiken deutlich.
- Bessere User Experience für Empfänger: modern, mobil, mehrsprachig, hell/dunkel.

## Funktionsumfang im Überblick

### Rechte-Matrix (Struktur + Medienpool)
- Verwaltung von Rollen auf Kategorie-, Artikel- und Medienkategorie-Ebene.
- Pseudo-Rollen für typische Fälle:
  - Öffentlich
  - Nur angemeldet
  - Nur Gäste
- Direkte Speicherung per AJAX.

### Vererbungslogik
- Rechte auf Kategorie-Ebene werden an Unterkategorien/Artikel vererbt.
- In Navigationsausgaben werden nicht erlaubte Elemente über `ART_IS_PERMITTED` und `CAT_IS_PERMITTED` ausgeblendet.

### Medienpool-/Media-Manager-Schutz
- Zugriff wird in `MEDIA_MANAGER_BEFORE_SEND` geprüft.
- Nur echte Medienpool-Dateien werden eingeschränkt.
- Nicht-restricted Inhalte bleiben verfügbar.
- Im REDAXO-Backend angemeldete Benutzer werden beim Medienzugriff nicht blockiert (wichtig für Backend-Workflows wie z. B. Focuspoint).

### Login, Profil, Registrierung
- Eigener Auth-Flow für Restricted-User.
- Theme-fähige Fragmente (`bootstrap`, `uikit3`, `tailwind`).
- Profilverwaltung inkl. Passwortänderung.

### DB-Sessionverwaltung
- Frontend-Sessions werden serverseitig in der Datenbank gespeichert (`rex_klxm_restricted_session`).
- Inaktivität und maximale Laufzeit sind konfigurierbar.
- Sessions werden beim Login angelegt, bei Aktivität aktualisiert und bei Logout entfernt.
- Abgelaufene Sessions werden automatisch bereinigt.

### Sessions im Backend
- Eigene Unterseite `Restricted > Sessions`.
- Filter nach Benutzer.
- Sichtbar sind u. a. Session-ID, IP, User-Agent, Startzeit und letzte Aktivität.
- Einzelne Sessions können aktiv beendet werden.

### Admin-Imitation
- Admins können Frontend als gewählten Restricted-User testen.
- Sichtbarer Imitationshinweis und sicherer Beenden-Flow.

### Zugriffsanfragen
- Optional pro Kategorie/Artikel aktivierbar (Matrix).
- Besucher können Zugriff anfragen.
- Backend-Inbox mit Statusfilter und Aktionen (`approve`, `reject`).

### Medien teilen (Mediapool)
- Eigene Mediapool-Unterseite: `Mediapool > Medien teilen`.
- Redakteure können eine Medienpool-Kategorie wählen und Dateien freigeben.
- Optionen je Freigabe:
  - Ablaufzeit (optional, leer = kein Ablauf)
  - Optionales Passwort
  - Optionales Download-Limit
  - Einzeldatei-Download und optional ZIP-Download
- Freigabelink wird als absolute URL mit Domain erzeugt.
- Link ist in der Freigabe-Liste direkt kopierbar.

### One-Time Pastebin (sensible Daten)
- Eigene Addon-Seite: `Restricted > Pastebin`.
- Einsatzzweck: Passwörter, Zertifikate, Geheimtexte mit optionalen Medien-Anhängen.
- Sicherheitsverhalten:
  - Eintrag wird nach dem ersten Abruf serverseitig vernichtet.
  - Optionales Zugriffspasswort.
  - Optionales Ablaufdatum.
  - Optionaler Download von Anhängen (aus Medienpool-Kategorie).

### Moderne öffentliche Seiten (Share + Pastebin)
- Framework-unabhängiges Frontend-Design (kein Bootstrap/UITailwind-Zwang).
- Light / Dark / Auto umschaltbar.
- Deutsch / Englisch umschaltbar.
- Branding über Addon-Einstellungen:
  - Titel
  - Untertitel
  - Akzentfarbe

## Wichtiger Hinweis zu Zugriffsanfragen

Stand heute bedeutet `approved` in der Inbox:
- Statuswechsel der Anfrage.
- Noch keine automatische Gast-Freigabe per Token.

Das ist bewusst als nächster Ausbauschritt geplant (Issue im Projekt vorhanden).

## Voraussetzungen

- REDAXO >= 5.18
- PHP >= 8.4
- YForm >= 5.0
- Mediapool

## Installation

1. Im REDAXO-Backend den Installer öffnen.
2. Nach `KLXM Restricted` suchen und installieren.
3. Das Addon aktivieren.
4. Unter `Restricted > Einstellungen` mindestens konfigurieren:
   - Login-Artikel
   - Redirect nach Login
   - Theme-Framework
  - Session Timeout (Minuten)
  - Maximale Session-Laufzeit (Minuten)

### Optionale manuelle Installation (Entwicklung)

Nur für Entwicklungs-Setups ohne REDAXO-Installer:

1. Addon nach `redaxo/src/addons/klxm_restricted` legen.
2. Abhängigkeiten installieren:

```bash
cd redaxo/src/addons/klxm_restricted
composer install
```
3. Im Backend installieren/aktivieren.

## ⚡ Einfache Einrichtung mit mitgelieferten Modulen

Für den schnellen Start bringt das Addon fertige Frontend-Module mit, die per Klick synchronisiert werden können.

1. Gehe zu `Restricted > Setup`.
2. Klicke auf `Module synchronisieren`.
3. Nutze danach direkt die mitgelieferten Module in Artikeln:
  - `klxm_restricted_login`
  - `klxm_restricted_register`
  - `klxm_restricted_profile`
  - `klxm_restricted_widget`

Damit bekommst du Login, Registrierung, Profil und Nutzerstatus ohne eigene Basis-Implementierung sofort live.

## Berechtigungen (Redakteure)

Neben Admin-Rechten können Features gezielt per Permission freigeschaltet werden:
- `klxm_restricted[share]` für Medien-Freigabelinks im Mediapool
- `klxm_restricted[pastebin]` für One-Time-Pastebin im Addon

## Changelog

Alle Änderungen stehen in [CHANGELOG.md](CHANGELOG.md).

## Empfohlene Erstkonfiguration

1. Rollen anlegen (`Restricted > Rollen`).
2. Matrix füllen (`Restricted > Rechte-Matrix`).
3. Login-Artikel setzen (`Restricted > Einstellungen`).
4. Test als Gast und als angemeldeter User.
5. Optional: Zugriffsanfragen aktivieren (pro Kategorie/Artikel).

## Frontend-Einbindung

Das Login-Modul aus dem Addon kann direkt auf dem Login-Artikel eingebunden werden.

Alternative (direkt im Template/Modul):

```php
<?php
use KLXM\Restricted\Frontend\LoginController;

echo LoginController::processRequest();
```

## API für Entwickler

```php
<?php
use KLXM\Restricted\Auth;
use KLXM\Restricted\PermissionManager;

$auth = new Auth();
$pm = new PermissionManager();

$user = $auth->getUser();
if ($pm->checkArticleAccess($user, 42)) {
    echo 'Erlaubt';
}
```

## Erweiterungspunkte und relevante Hooks

- `PACKAGES_INCLUDED` (Frontend-Zugriffsflow)
- `ART_IS_PERMITTED`
- `CAT_IS_PERMITTED`
- `MEDIA_MANAGER_BEFORE_SEND`
- `MEDIA_IS_PERMITTED`

## Bekannte Hinweise

1. Ohne gesetzten Login-Artikel kann es zu unerwünschtem Verhalten im Redirect-Flow kommen.
2. Bei parallelem Einsatz weiterer Auth-Addons (z. B. YCom Auth) sollten Redirect-Zuständigkeiten klar getrennt sein.
3. Nach strukturellen Änderungen immer Backend-Cache leeren.

## Troubleshooting Login

1. Prüfen, ob der Benutzer in `Restricted > Benutzer` aktiv ist (`status = 1`).
2. E-Mail exakt gegen den gespeicherten Wert prüfen (Vertipper sind eine häufige Ursache).
3. Fehlversuche/Sperre kontrollieren (`failed_logins`, `login_locked_until`).
4. Falls nötig Passwort neu setzen und erneut testen.

## Roadmap (Kurz)

1. Tokenbasierte Gast-Freigabe bei `approved`.
2. Optionaler Mailversand für Anfragen/Freigaben.
3. Ablauf- und Widerrufslogik für Freigaben.

---

KLXM Restricted fokussiert auf das, was in echten Projekten zählt: nachvollziehbare Rechte, sichere Auslieferung und einfache Bedienung für Redakteure.
