# Changelog

Alle nennenswerten Aenderungen an diesem Addon werden in dieser Datei dokumentiert.

## [Unreleased]

### Release Highlights
- Medien-Freigabelinks direkt im Mediapool mit Passwort, Ablauf, Limit und ZIP.
- One-Time Pastebin fuer Geheimtexte inkl. optionaler Medien-Anhaenge.
- Burn-after-read fuer Pastebin: Daten werden nach Abruf serverseitig vernichtet.
- Oeffentliche Share/Pastebin-Seiten mit DE/EN + Light/Dark/Auto + Branding.
- DB-Sessionverwaltung mit Monitoring im Backend.

### Added
- Medien-Freigabelinks im Mediapool (`Mediapool > Medien teilen`).
- Optionen fuer Freigabelinks: Ablaufzeit, Passwort, Download-Limit, ZIP-Download.
- Liste bestehender Freigaben mit direkter Link-Kopie.
- Absolute Freigabelinks mit Domain-Ausgabe statt relativer Pfade.
- One-Time Pastebin (`Restricted > Pastebin`) fuer sensible Inhalte.
- Optionale Anhaenge aus dem Medienpool im Pastebin.
- Vernichtung nach Abruf fuer Pastebin-Eintraege (Burn-after-read).
- Branding-Optionen fuer oeffentliche Share/Pastebin-Seiten:
  - Titel
  - Untertitel
  - Akzentfarbe
- Oeffentliche Seiten mit DE/EN Umschaltung und Light/Dark/Auto Theme.
- URL-Normalizer fuer HTML-escaped Parameter (`&amp;`, `&#038;`) inkl. kanonischem Redirect.
- DB-Sessionverwaltung mit Session-Monitoring im Backend.

### Changed
- Auth-Flow auf DB-basierte Sessionpruefung umgestellt.
- Session-Timeout und maximale Session-Laufzeit konfigurierbar gemacht.
- Share-/Pastebin-Frontend auf modernes, framework-unabhaengiges UI umgestellt.

### Security
- Passwoerter fuer Share/Pastebin werden als Hash gespeichert.
- Pastebin-Inhalte werden nach Abruf serverseitig entfernt.

## [1.0.0]

### Added
- Initiale Version mit zentraler Rechte-Matrix.
- Artikel-/Kategorie-/Medien-Schutz inkl. Vererbungslogik.
- Login/Profil-Flow fuer Restricted-User.
- Admin-Imitation.
- Zugriffsanfragen im Backend.
