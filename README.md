# KLXM Restricted

Zentrales Frontend-Berechtigungsaddon für REDAXO mit Rollenmatrix, Medienpool-Schutz, Login/Profil-Flow, Admin-Imitation, Zugriffsanfragen, Medien-Freigabelinks und One-Time-Pastebin.

## Warum dieses Addon?

Viele Projekte scheitern nicht an fehlenden Rechten, sondern an fehlender Uebersicht:
- Rechte sind verteilt ueber viele Artikel-Metafelder.
- Regeln sind fuer Redakteure kaum nachvollziehbar.
- Medien sind haeufig unabsichtlich oeffentlich.

KLXM Restricted loest genau dieses Problem mit einer zentralen Matrix und klaren Vererbungsregeln.

## Die wichtigsten Vorteile

1. Zentrale Rechteverwaltung statt verstreuter Einzelfelder.
2. Kaskadierende Regeln fuer Kategorien und Unterseiten.
3. Schutz von Artikeln und Medien mit derselben Logik.
4. Sichtbare, reproduzierbare Entscheidungen im Frontend-Flow.
5. Klarer Redaktionsworkflow fuer Zugriffsanfragen.
6. Temporaere, passwortgeschuetzte Medienfreigaben mit ZIP-Option.
7. Einmal abrufbare Geheimtexte (Pastebin) mit Vernichtung nach Abruf.

## Funktionsumfang im Ueberblick

### Rechte-Matrix (Struktur + Medienpool)
- Verwaltung von Rollen auf Kategorie-, Artikel- und Medienkategorie-Ebene.
- Pseudo-Rollen fuer typische Faelle:
  - Oeffentlich
  - Nur angemeldet
  - Nur Gaeste
- Direkte Speicherung per AJAX.

### Vererbungslogik
- Rechte auf Kategorie-Ebene werden an Unterkategorien/Artikel vererbt.
- In Navigationsausgaben werden nicht erlaubte Elemente ueber `ART_IS_PERMITTED` und `CAT_IS_PERMITTED` ausgeblendet.

### Medienpool-/Media-Manager-Schutz
- Zugriff wird in `MEDIA_MANAGER_BEFORE_SEND` geprueft.
- Nur echte Medienpool-Dateien werden eingeschraenkt.
- Nicht-restricted Inhalte bleiben verfuegbar.

### Login, Profil, Registrierung
- Eigener Auth-Flow fuer Restricted-User.
- Theme-faehige Fragmente (`bootstrap`, `uikit3`, `tailwind`).
- Profilverwaltung inkl. Passwortaenderung.

### DB-Sessionverwaltung
- Frontend-Sessions werden serverseitig in der Datenbank gespeichert (`rex_klxm_restricted_session`).
- Inaktivitaet und maximale Laufzeit sind konfigurierbar.
- Sessions werden beim Login angelegt, bei Aktivitaet aktualisiert und bei Logout entfernt.
- Abgelaufene Sessions werden automatisch bereinigt.

### Sessions im Backend
- Eigene Unterseite `Restricted > Sessions`.
- Filter nach Benutzer.
- Sichtbar sind u. a. Session-ID, IP, User-Agent, Startzeit und letzte Aktivitaet.
- Einzelne Sessions koennen aktiv beendet werden.

### Admin-Imitation
- Admins koennen Frontend als gewaehlten Restricted-User testen.
- Sichtbarer Imitationshinweis und sicherer Beenden-Flow.

### Zugriffsanfragen
- Optional pro Kategorie/Artikel aktivierbar (Matrix).
- Besucher koennen Zugriff anfragen.
- Backend-Inbox mit Statusfilter und Aktionen (`approve`, `reject`).

### Medien teilen (Mediapool)
- Eigene Mediapool-Unterseite: `Mediapool > Medien teilen`.
- Redakteure koennen eine Medienpool-Kategorie waehlen und Dateien freigeben.
- Optionen je Freigabe:
  - Ablaufzeit
  - Optionales Passwort
  - Optionales Download-Limit
  - Einzeldatei-Download und optional ZIP-Download
- Freigabelink wird als absolute URL mit Domain erzeugt.
- Link ist in der Freigabe-Liste direkt kopierbar.

### One-Time Pastebin (sensible Daten)
- Eigene Addon-Seite: `Restricted > Pastebin`.
- Einsatzzweck: Passwoerter, Zertifikate, Geheimtexte mit optionalen Medien-Anhaengen.
- Sicherheitsverhalten:
  - Eintrag wird nach dem ersten Abruf serverseitig vernichtet.
  - Optionales Zugriffspasswort.
  - Optionales Ablaufdatum.
  - Optionaler Download von Anhaengen (aus Medienpool-Kategorie).

### Moderne oeffentliche Seiten (Share + Pastebin)
- Framework-unabhaengiges Frontend-Design (kein Bootstrap/UITailwind-Zwang).
- Light / Dark / Auto umschaltbar.
- Deutsch / Englisch umschaltbar.
- Branding ueber Addon-Einstellungen:
  - Titel
  - Untertitel
  - Akzentfarbe

## Wichtiger Hinweis zu Zugriffsanfragen

Stand heute bedeutet `approved` in der Inbox:
- Statuswechsel der Anfrage.
- Noch keine automatische Gast-Freigabe per Token.

Das ist bewusst als naechster Ausbauschritt geplant (Issue im Projekt vorhanden).

## Voraussetzungen

- REDAXO >= 5.18
- PHP >= 8.4
- YForm >= 5.0
- Mediapool
- Composer (fuer Addon-Abhaengigkeiten)

## Installation

1. Addon nach `redaxo/src/addons/klxm_restricted` legen.
2. Abhaengigkeiten installieren:

```bash
cd redaxo/src/addons/klxm_restricted
composer install
```

3. Im Backend installieren/aktivieren.
4. Unter `Restricted > Einstellungen` mindestens konfigurieren:
   - Login-Artikel
   - Redirect nach Login
   - Theme-Framework
  - Session Timeout (Minuten)
  - Maximale Session-Laufzeit (Minuten)

## Berechtigungen (Redakteure)

Neben Admin-Rechten koennen Features gezielt per Permission freigeschaltet werden:
- `klxm_restricted[share]` fuer Medien-Freigabelinks im Mediapool
- `klxm_restricted[pastebin]` fuer One-Time-Pastebin im Addon

## Changelog

Alle Aenderungen stehen in [CHANGELOG.md](CHANGELOG.md).

## Empfohlene Erstkonfiguration

1. Rollen anlegen (`Restricted > Rollen`).
2. Matrix fuellen (`Restricted > Rechte-Matrix`).
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

## API fuer Entwickler

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

1. Ohne gesetzten Login-Artikel kann es zu unerwuenschtem Verhalten im Redirect-Flow kommen.
2. Bei parallelem Einsatz weiterer Auth-Addons (z. B. YCom Auth) sollten Redirect-Zustaendigkeiten klar getrennt sein.
3. Nach strukturellen Aenderungen immer Backend-Cache leeren.

## Troubleshooting Login

1. Pruefen, ob der Benutzer in `Restricted > Benutzer` aktiv ist (`status = 1`).
2. E-Mail exakt gegen den gespeicherten Wert pruefen (Vertipper sind eine haeufige Ursache).
3. Fehlversuche/Sperre kontrollieren (`failed_logins`, `login_locked_until`).
4. Falls noetig Passwort neu setzen und erneut testen.

## Roadmap (Kurz)

1. Tokenbasierte Gast-Freigabe bei `approved`.
2. Optionaler Mailversand fuer Anfragen/Freigaben.
3. Ablauf- und Widerrufslogik fuer Freigaben.

---

KLXM Restricted fokussiert auf das, was in echten Projekten zaehlt: nachvollziehbare Rechte, sichere Auslieferung und einfache Bedienung fuer Redakteure.
