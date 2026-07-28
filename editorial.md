# Handbuch für Redakteure

Dieses Benutzerhandbuch richtet sich an Redakteure mit den passenden Rechten im Addon KLXM Restricted.

## Zweck

Mit diesem Handbuch arbeiten Sie sicher und strukturiert mit:

- Dateiablagen und Freigabelinks
- Kategoriefreigaben im Medienpool
- Anfrageformularen
- Auswertungen und Exporten
- Pastebin-Einträgen

## Funktionsübersicht des Addons

KLXM Restricted umfasst mehr als reine Medienfreigaben. Je nach Betriebsmodus sind unterschiedliche Bereiche sichtbar.

### Standalone-Modus (ohne YCom)

Mögliche Funktionsbereiche:

- Rechte-Matrix (Struktur und Medienpool)
- Benutzer, Rollen, Sessions
- Zugriffsanfragen (Inhaltszugriff)
- Login-/Auth-Flow inkl. Passkey-Bausteine
- Dateiablagen/Freigaben
- Pastebin

### YCom-Modus (mit YCom)

Schwerpunkt und typische Sichtbarkeit:

- Matrix für Medienkategorien (Schutzregeln)
- Dateiablagen/Freigaben
- Freigabe-Anfragen und Auswertungen
- Pastebin
- Handbuch/Hilfe

Dabei gilt in der Regel: Nutzer-/Rollenverwaltung und Auth-Primärlogik liegen bei YCom, KLXM Restricted ergänzt die Freigabe- und Schutzlogik.

## Erforderliche Rechte

Für den Redaktionsalltag sind in der Regel diese Rechte relevant:

- `klxm_restricted[share]`
   - Zugriff auf `Mediapool > Dateiablage teilen`
   - Zugriff auf `KLXM Restricted > Freigabe-Anfragen`
   - Zugriff auf dieses Handbuch
- `klxm_restricted[pastebin]` (optional)
   - Zugriff auf `KLXM Restricted > Pastebin`

Hinweis: Seiten wie Setup, Rollen, Benutzer oder globale Einstellungen können weiterhin auf Admin beschränkt sein.

## 1. Dateiablage teilen

Pfad im Backend:

- `Mediapool > Dateiablage teilen`

Typischer Ablauf:

1. Neue Freigabe anlegen.
2. Zielartikel für die Freigabe festlegen.
3. Quelle wählen:
    - ganze Medienpool-Kategorie
    - manuelle Dateiauswahl
    - kategorisierte Gruppen
4. Schutzoptionen konfigurieren:
    - Passwort
    - Ablaufdatum
    - Download-Limit
    - ZIP erlauben
5. Optional Anfrageformular aktivieren.
6. Speichern und Link testen.

## 2. Kategoriefreigaben und Matrix

Pfad im Backend:

- `KLXM Restricted > Rechte-Matrix`

Im YCom-Modus ist die Struktur-Matrix reduziert, die **Medienpool-Kategorien** bleiben aber für Schutzregeln verfügbar.

### Was wird dort gesteuert?

- Sichtbarkeit/Zugriff auf Medienpool-Kategorien
- Vererbung auf Unterkategorien
- Rollenabhängige Freigabe

### Bedeutung der Rollen in der Matrix

- `Öffentlich (Jeder)`
   - Zugriff für alle
- `Alle Angemeldeten`
   - Zugriff nur für eingeloggte Nutzer
- `Nur Gäste`
   - Zugriff nur ohne Login
- konkrete Rollen (z. B. Team-Rollen)
   - Zugriff nur für diese Rolle

### Vererbungslogik

- Eine Regel auf einer Oberkategorie wirkt auf Unterkategorien.
- Geerbte Haken erscheinen abgeschwächt und sind nicht direkt änderbar.
- Eigene Regel auf der Zielkategorie überschreibt die reine Vererbung.

### Empfehlung für Redakteure

1. Erst auf höherer Ebene entscheiden, was grundsätzlich geschützt ist.
2. Nur dort Ausnahmen setzen, wo es fachlich nötig ist.
3. Nach jeder Änderung mit einem Testkonto prüfen.

## 2a. Weitere Matrix-Funktionen

Zusätzlich zu Medienkategorien kann die Matrix je nach Betriebsmodus auch Strukturregeln enthalten.

- Strukturregeln betreffen Kategorien/Artikel im Seitenbaum.
- Medienregeln betreffen Kategorien im Medienpool.
- Geerbte Regeln sind visuell abgeschwächt.

Wenn Sie im YCom-Modus arbeiten, sehen Sie bewusst primär den für Freigaben wichtigen Teil.

## 3. Anfrageformular sauber einsetzen

Wenn externe Nutzer Zugriff anfragen sollen:

1. Anfrageformular aktivieren.
2. Gültigkeit der Zugriffslinks setzen (z. B. 3 Tage).
3. Pflichtfelder minimieren.
4. Zusätzliche Felder nur bei echtem Bedarf ergänzen (z. B. Firma, Projekt).

Hinterlegte Schutzmechanismen:

- CSRF-Schutz
- Formular-Guard (Nonce + Zeitfenster)
- Honeypot-Felder gegen Bots
- Kurzzeit-Sperren bei wiederholten Anfragen

## 3a. Zugriffsanfragen für Inhalte (separat vom Datei-Share)

Neben Share-Anfragen kann es (abhängig von Konfiguration und Modus) auch klassische Zugriffsanfragen auf geschützte Inhalte geben.

Typische Eigenschaften:

- Bezug auf Artikel/Kategorie
- Statusverwaltung (offen, bearbeitet)
- Bearbeiterhinweise

Ob und wie dieser Bereich für Redakteure sichtbar ist, hängt von der Rechtevergabe und vom Betriebsmodus ab.

## 4. Freigabe-Anfragen und Statistiken

Pfad im Backend:

- `KLXM Restricted > Freigabe-Anfragen`

Wichtige Kennzahlen:

- Gesamtanzahl der Anfragen
- eindeutige E-Mail-Adressen
- Top-Freigaben
- Top-Dateien
- Downloadarten und Zeitverlauf

Exportoptionen:

- CSV für Downloads
- CSV für Anfragen
- PDF (wenn PDFOut installiert ist)

Praxis-Tipp:

- Mit Filtern (`Freigabe`, `Von`, `Bis`) immer nur den relevanten Zeitraum auswerten.

## 5. Pastebin für sensible Inhalte

Pfad im Backend:

- `KLXM Restricted > Pastebin`

Einsatzszenario:

- Einmalabruf für vertrauliche Inhalte
- optional mit Passwort
- optional mit Ablaufdatum
- optional mit Medienanhängen

Wichtig:

- Einträge sind für kurzfristigen, kontrollierten Austausch gedacht.
- Nach Abruf kann ein Eintrag vernichtet werden.

## 5a. Login, Benutzer, Rollen, Sessions

Diese Bereiche sind funktional Teil des Addons, aber häufig admingeführt.

- Benutzer: Verwaltung interner Konten (im Standalone-Betrieb)
- Rollen: Definition von Zugriffsebenen
- Sessions: Nachvollziehen aktiver Sitzungen
- Login/Passkey: Authentifizierungsbausteine für den Standalone-Flow

Für Redakteure wichtig:

- Wenn ein Bereich nicht sichtbar ist, ist das meist eine gewollte Rechtebegrenzung.
- Fachliche Änderungswünsche an Rollen/Benutzer sollten an Admins gehen.

## 5b. Einstellungen und Setup

Weitere Addon-Funktionen liegen in:

- Einstellungen
- Setup

Dort werden technische und organisatorische Defaults gepflegt, z. B. Mailtexte, Branding, Sicherheits-Defaults oder Systemparameter. Diese Bereiche sind meist für Administratoren reserviert.

## 6. Qualitätssicherung vor Veröffentlichung

Checkliste vor Versand eines Freigabelinks:

1. Ist der richtige Zielartikel hinterlegt?
2. Sind nur die richtigen Dateien enthalten?
3. Ist ein Ablaufdatum gesetzt (wenn gewünscht)?
4. Passt das Download-Limit?
5. Funktioniert der Link im ausgeloggten Test?
6. Falls Passwort gesetzt: Test mit korrekt/falsch durchführen.

## 7. Häufige Probleme

### Nutzer sieht keine Dateien

Prüfen:

- Freigabe abgelaufen
- Download-Limit erreicht
- falscher/alter Link
- Passwort erforderlich
- Kategorie durch Matrix-Regel nicht freigegeben

### Anfrage kommt nicht per E-Mail an

Prüfen:

- Mail-Konfiguration (`rex_mailer`)
- Spamfilter beim Empfänger
- Schreibfehler in der E-Mail-Adresse
- Eintrag in `Freigabe-Anfragen` vorhanden

### Matrix-Änderung speichert nicht

Prüfen:

- Seite nach Änderung neu laden
- Browser-Cache leeren
- bei Systemupdates ggf. Backend-Cache/Container neu starten

### Eine Addon-Funktion fehlt im Menü

Mögliche Ursachen:

- fehlendes Recht (`klxm_restricted[share]` oder `klxm_restricted[pastebin]`)
- bewusst reduzierte Ansicht im YCom-Modus
- Seite ist auf Admin eingeschränkt

Prüfen:

1. Benutzerrolle und Berechtigungen.
2. Ob YCom aktiv ist.
3. Ob ein Admin die Seite grundsätzlich sehen kann.

## 8. Empfohlener Redaktionsprozess

1. Kategorie-/Rollenfreigaben in der Matrix prüfen.
2. Dateiablage erstellen und absichern.
3. Anfrageformular nur mit nötigen Feldern aktivieren.
4. Link versenden.
5. Statistiken prüfen und alte Freigaben bereinigen.

## 9. Übergabe an Administratoren

Diese Punkte sollten Redakteure an Admins eskalieren:

- neue Rollenmodelle oder Rechtekonzepte
- Freigabe globaler Systemoptionen
- Login-/Passkey-Grundeinstellungen
- Infrastrukturthemen (Mailzustellung, Cache, Container)

---

Stand: Juli 2026
