# Changelog

Alle nennenswerten Änderungen an diesem Addon werden in dieser Datei dokumentiert.

## [Unreleased]

## [2.0.0] - 2026-07-27

### Neu
- Neues Filesharing im Mediapool mit direktem Freigabe-Workflow.
- Neue Seite Dateiablage teilen unter Mediapool.
- Neues Frontend-Modul für Dateifreigaben mit optionaler fester Freigabe-Zuordnung pro Modul.
- Neuer Bereich Freigabe-Anfragen im Backend mit Auswertungen und Export.
- Neue kategorisierte Dateigruppen für Freigaben.
- ZIP-Downloads mit Ordnerstruktur nach Kategorie/Gruppe.
- Unterstützung für mehrfache Gruppenzuordnung im ZIP (Datei kann in mehreren Ordnern erscheinen).
- Neues Redakteurs-Handbuch als eigene Seite im Addon.
- Neues E-Mail-Branding-Feld für Mail-Logo (PNG/JPG/JPEG, clientfreundlich).
- Neue Anfragefeld-Typen `Radio` und `Rating (Sterne)` für das externe Anfrageformular.
- Neues Datei-Kontingent pro Datei (optional) inklusive Mediapool-Picker im Backend.
- Neue Darstellungsoption bei erreichtem Datei-Kontingent: Datei ausblenden oder deaktiviert anzeigen.

### Geändert
- Version auf 2.0.0 erhöht.
- Navigation und Berechtigungen für Share-Workflows überarbeitet.
- Share-URLs robuster aufgebaut, inklusive sauberer Normalisierung von relativen Pfaden.
- Mailvorlage für Freigabe-Anfragen vollständig auf clienttaugliches HTML mit Plaintext-Fallback umgestellt.
- Deutsche Texte in Share- und Mail-Flow auf korrekte Umlaute und ß vereinheitlicht.
- Backend-Maske Dateiablage teilen kompakter aufgebaut (erste Felder mehrspaltig).
- Feld Maximale Downloads (optional) um einen klaren Hinweistext zur Gültigkeit des Limits ergänzt.
- Datei-Kontingent-Editor im Backend als optionaler Repeater umgesetzt (Startzustand leer, Zeilen dynamisch hinzufügen/entfernen).
- Verhalten bei erreichtem Kontingent visuell verbessert (deaktivierte Zeile ausgegraut inkl. Hinweis Kontingent erreicht).

### Korrigiert
- Einzeldownload in nicht seitengebundenen Freigaben korrigiert.
- Fehler bei Dateiname fehlt im Download-Flow behoben.
- Doppelt encodierte Query-Parameter in Share-Links korrigiert.
- ZIP-Statusfehler im Async-Flow behoben.
- Fehlerhafte Ermittlung der Ziel-Freigabe bei mehreren Freigaben pro Artikel abgesichert.
- Mehrere kleinere Stabilitäts- und UX-Korrekturen in Share-, Matrix- und Pastebin-Kontexten.
- Doppelte Checkbox-Labels im Anfrageformular behoben.
- Mediapool-Picker für Datei-Kontingente stabilisiert (korrekte Widget-Signatur/IDs, kein fehlerhaftes Popup-Verhalten).
- Intermittierendes Problem beim Hinzufügen von Kontingent-Zeilen behoben (robustere Event-Bindings).
- JavaScript-Initialisierung für REDAXO-Backend-Lifecycle ergänzt (`rex:ready`) und mehrfaches Binden abgesichert.
- Einzeldownload-bezogenes UX-Feinverhalten korrigiert (Button-Deaktivierung erst nach Klick-Start, ZIP-Verhalten bleibt erhalten).

### Sicherheit
- Formularschutz für Freigabe-Anfragen gehärtet (Honeypot, Nonce/Zeitfenster, Ratelimits).
- Kurzzeit-Sende-Sperre für wiederholte E-Mail-Anfragen ergänzt.

## [1.0.0]

### Added
- Initiale Version mit zentraler Rechte-Matrix.
- Artikel-/Kategorie-/Medien-Schutz inkl. Vererbungslogik.
- Login/Profil-Flow fuer Restricted-User.
- Admin-Imitation.
- Zugriffsanfragen im Backend.
