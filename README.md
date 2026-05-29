# KLXM Restricted (Modern REDAXO Access AddOn)

Ein modernes, performantes und zentral gesteuertes Berechtigungssystem für das REDAXO CMS. Verwalte den Frontend-Zugriff auf Artikel, Kategorien und den Media-Manager übersichtlich in einer einzigen Rechtematrix – ohne lästige Einzelkonfiguration in jedem Artikel!


## Inhaltsverzeichnis
- [Philosophie](#philosophie)
- [Systemvoraussetzungen](#systemvoraussetzungen)
- [Installation](#installation)
- [Kern-Features](#kern-features)
  - [Die Rollen-Matrix](#1-die-rollen-matrix)
  - [Kaskadierende Rechte](#2-kaskadierende-rechte)
  - [Media Manager Schutz](#3-media-manager-schutz)
  - [Passkeys (WebAuthn)](#4-passkeys-webauthn)
- [Nutzung im Frontend](#nutzung-im-frontend)
- [Entwickler](#entwickler-api)

---

## Philosophie

Wer große Kundenprojekte kennt, kennt das Problem: Der Überblick darüber, *wer* auf *welche* Artikel zugreifen darf, geht in Systemen wie YCOM oft verloren, weil die Rechte in jedem einzelnen Artikel-Metainfo-Feld konfiguriert werden müssen. 

**KLXM Restricted löst das N+1 Query-Problem und das UX-Problem:**
- Es gibt *eine* zentrale Backend-Seite ("Matrix"), in der alle Artikel-, Struktur- und Media-Rechte über einfache Checkboxen (Ajax/Pjax-optimiert) gesteuert werden.
- Die Lese-Performance im Frontend sinkt nie wieder, egal wie viele Objekte gerendert werden, weil die Matrix mit einem Memory-Cache (`PermissionManager::loadMatrix()`) blitzschnell im RAM verbleibt.
- Verwaltung von Benutzern und Rollen läuft über das moderne **YForm 5.0**. 

---

## Systemvoraussetzungen

- **REDAXO >= 5.18.0**
- **PHP >= 8.4**
- **YForm >= 5.0** (für das User- und Rollen-Management via `rex_yform_manager`)
- **Composer** (für die Installation der WebAuthn PSR-7 Server Abhängigkeiten)

---

## Installation

1. Entpacke das AddOn oder klone es in `/redaxo/src/addons/klxm_restricted`.
2. Lade im Verzeichnis des AddOns über Composer die Abhängigkeiten herunter:
   ```bash
   cd redaxo/src/addons/klxm_restricted
   composer install
   ```
3. Gehe im REDAXO Backend auf **AddOns -> Installieren und aktivieren**.
4. Wähle im Backend-Menü unter `Restricted` den Punkt **Einstellungen** und wähle deinen Login-Artikel sowie das gewünschte CSS-Framework (Bootstrap, UIkit 3 oder Tailwind).

---

## Kern-Features

### 1. Die Rollen-Matrix
Das Herzstück des AddOns ist die Rechtematrix. Klicke einfach auf die Checkboxen, um Rollen den entsprechenden Bereich zuzuweisen. AJAX speichert ohne Reload. Die Matrix iteriert automatisch durch deinen kompletten Navigationsbaum sowie den Medienpool (`media_category`).

### 2. Kaskadierende Rechte
Wenn du eine Hauptkategorie schützt, erben *alle* Unterkategorien und deren Artikel automatisch diesen Schutz. Du musst nicht 100 Unterseiten einzeln anklicken. REDAXO's Hooks (`ART_IS_PERMITTED` & `CAT_IS_PERMITTED`) kümmern sich im Hintergrund zudem darum, dass geschützte Inhalte gar nicht erst in den `navigation_array` oder `rex_navigation` auftauchen, wenn der Frontend-User nicht eingeloggt ist. Die Inhalte wandern optisch sofort in den Offline-Status für unbefugte Dritte.

### 3. Media Manager Schutz
Lädt jemand eine Datei über den `Media Manager` und hat den direkten Link? Kein Problem. Der `MEDIA_MANAGER_BEFORE_SEND` Hook blockiert die Datei-Auslieferung hart mit einem `403 Access Denied`, wenn ein Media-Kategorie-Ordner in der Matrix einem Benutzerkonto untersagt wurde.

### 4. Passkeys (WebAuthn)
Nie wieder vergessene Passwörter. Das AddOn bringt Out-Of-The-Box Funktionalität für hardwaregestützte Passkeys (TouchID, FaceID, Windows Hello, Yubikey) via PSR-7 WebAuthn mit. Native Authentikation ohne dritte Cloud-Dienste, direkt auf dem REDAXO-Server gespeichert.

---

## Nutzung im Frontend

Da die Backend-Ausgaben automatisiert per Fragment bereitstehen, kannst du das Anmeldeformular und den Passkey-Manager direkt in deinem Modul einfügen oder anpassen:

```php
// Modul-Ausgabe für das Login-Formular

$fragment = new rex_fragment();
$fragment->setVar('action_url', rex_getUrl(rex_article::getCurrentId()));
$fragment->setVar('passkey_enabled', true); // Zeigt den WebAuthn Button
$fragment->setVar('error', $loginError ?? '');

// Render basierend auf in den Settings gewähltem Framework!
// Zum Beispiel: 'restricted/bootstrap/login.php'
$theme = rex_addon::get('klxm_restricted')->getConfig('theme_framework', 'bootstrap');
echo $fragment->parse('restricted/' . $theme . '/login.php');

// Nicht vergessen, die JS Lib auf der Seite mit dem Formular zu laden (für WebAuthn):
// (Entweder direkt im Modul oder im Head deines Templates einbinden)
echo '<script src="' . rex_url::addonAssets('klxm_restricted', 'passkey.js') . '"></script>';
```

### Registrierung und Profil
Für die Registrierung und Profilverwaltung der angemeldeten User (Erstanmeldung, Namensänderung, Passwort ändern, Passkeys verwalten) nutzt das Addon einen `UserController`. 
Dazu liefert das AddOn passende Formular-Fragmente: `register.php`, `profile.php` und `profile_passkey.php`.

**Beispiel für ein Profil-Modul:**
```php
use KLXM\Restricted\Auth;
use KLXM\Restricted\Frontend\UserController;

$auth = new Auth();
$userController = new UserController();
$theme = rex_addon::get('klxm_restricted')->getConfig('theme_framework', 'bootstrap');

if (!$auth->isLoggedIn()) {
    echo "Bitte einloggen.";
    return;
}

$user = $auth->getUser();
$error = '';
$success = '';

// POST Handle (Profile Update)
if (rex_post('klxm_action', 'string') === 'update_profile') {
    $result = $userController->updateProfile($user, rex_post('email', 'string'), rex_post('firstname', 'string'), rex_post('lastname', 'string'));
    $result['status'] ? $success = $result['message'] : $error = $result['message'];
}

// 1. Profil Maske rendern:
$fragment = new rex_fragment();
$fragment->setVar('action_url', rex_getUrl(rex_article::getCurrentId()));
$fragment->setVar('firstname', rex_post('firstname', 'string', $user->firstname));
$fragment->setVar('lastname', rex_post('lastname', 'string', $user->lastname));
$fragment->setVar('email', rex_post('email', 'string', $user->email));
$fragment->setVar('error', $error);
$fragment->setVar('success', $success);
echo $fragment->parse('restricted/' . $theme . '/profile.php');

// 2. Passkey Manager rendern:
$pkFragment = new rex_fragment();
$pkFragment->setVar('passkeys', $userController->getPasskeys($user));
echo $pkFragment->parse('restricted/' . $theme . '/profile_passkey.php');

// Optional Passkey JS einbinden, wenn nicht global im Template vorhanden:
echo '<script src="' . rex_url::addonAssets('klxm_restricted', 'passkey.js') . '"></script>';
```

---

## Entwickler API

Du möchtest Manuell im Modul abfragen, ob dein Benutzer Rechte hat? Nutze die `Auth` und `PermissionManager` Klasse:

```php
use KLXM\Restricted\Auth;
use KLXM\Restricted\PermissionManager;

$auth = new Auth();

if ($auth->isLoggedIn()) {
    $user = $auth->getUser(); // Returns KLXM\Restricted\User DataObject
    echo "Hallo " . $user->firstname;
    
    // Hat dieser User Zugriff auf speziellen Artikel?
    $pm = new PermissionManager();
    if ($pm->checkArticleAccess($user, 42)) {
         echo "Du darfst das geheime Video sehen.";
    }
} else {
    echo "Bitte einloggen.";
}
```

### Hooks (Extension Points)
- `ART_IS_PERMITTED` (Return Boolean)
- `CAT_IS_PERMITTED` (Return Boolean)
- `MEDIA_MANAGER_BEFORE_SEND` (403 Exit)
- `PACKAGES_INCLUDED` (Redirect bei Unauthorized Page Access)

---
*Built with ❤️ for REDAXO CMS*
