# KLXM Restricted

Zentrales Frontend-Berechtigungsaddon für REDAXO mit Rollenmatrix, Medienpool-Schutz, Login/Profil-Flow, Admin-Imitation und Zugriffsanfragen.

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

### Admin-Imitation
- Admins koennen Frontend als gewaehlten Restricted-User testen.
- Sichtbarer Imitationshinweis und sicherer Beenden-Flow.

### Zugriffsanfragen
- Optional pro Kategorie/Artikel aktivierbar (Matrix).
- Besucher koennen Zugriff anfragen.
- Backend-Inbox mit Statusfilter und Aktionen (`approve`, `reject`).

## Wichtiger Hinweis zu Zugriffsanfragen

Stand heute bedeutet `approved` in der Inbox:
- Statuswechsel der Anfrage.
- Noch keine automatische Gast-Freigabe per Token.

Das ist bewusst als naechster Ausbauschritt geplant (Issue im Projekt vorhanden).

## Voraussetzungen

- REDAXO >= 5.18
- PHP >= 8.4
- YForm >= 5.0
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

## Roadmap (Kurz)

1. Tokenbasierte Gast-Freigabe bei `approved`.
2. Optionaler Mailversand fuer Anfragen/Freigaben.
3. Ablauf- und Widerrufslogik fuer Freigaben.

---

KLXM Restricted fokussiert auf das, was in echten Projekten zaehlt: nachvollziehbare Rechte, sichere Auslieferung und einfache Bedienung fuer Redakteure.
