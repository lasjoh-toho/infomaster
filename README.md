# Infomaster

[![Release](https://img.shields.io/github/v/release/lasjoh-toho/infomaster)](https://github.com/lasjoh-toho/infomaster/releases/latest)

Selbstgehostetes Master-Hub zur Steuerung von Raspberry-Pi-Infoscreens: Monitor-Layouts,
Zeitplan/Ferien, Medien-Ordner und Pi-Geräteverwaltung über `infomaster.php`. Die Pis
spielen ihre Anzeige über `view.php`, `api.php` ist der Ping-/Update-/Report-Endpunkt für
die Clients, `nextcloud_proxy.php` erlaubt Nextcloud-Freigabeordner als Inhaltsquelle.

## Setup

1. Alle Dateien auf einen PHP-fähigen Webserver legen.
2. `infomaster.php` im Browser öffnen (initial mit `?rolle=actionhero`) - legt beim ersten
   Aufruf automatisch `vip.txt` (Master-Passwort, Default siehe Quelltext) sowie `media/`
   an. `vip.txt` danach direkt auf dem Server bearbeiten, um das Passwort zu ändern
   (bewusst nicht über die Weboberfläche änderbar).
3. Raspberry Pis über den im Dashboard angezeigten Installationsbefehl anbinden.

## AJAX & visuelles Feedback

Alle Formulare (inkl. Login) laufen über eine generische `fetch()`-Schicht
(`renderAjaxBootstrap()` in `infomaster.php`): ein Absenden lädt die Seite nicht mehr neu,
sondern ersetzt nur den `<body>` durch die neu vom Server gerenderte Version und zeigt eine
kurze Erfolgs-/Fehlermeldung (Toast oben rechts) an - z.B. "✓ Gespeichert", "🗑 Gelöscht",
"📤 Datei hochgeladen" oder bei falschem Passwort "❌ Login fehlgeschlagen" (inkl. Shake-
Animation). `confirm()`-Dialoge vor dem Löschen funktionieren unverändert.

## Präsentationen für Monitore (Bento-Pronto)

`bento.php` ist [bento-pronto](https://github.com/lasjoh-toho/bento-pronto) (PPTX→Bento-
Präsentationskonverter), fest in Infomaster eingebaut:

- **Gleicher Login** - `bento.php` nutzt dieselbe PHP-Session wie `infomaster.php` (kein
  eigener Zugang), nicht angemeldete Aufrufe werden zum Infomaster-Login umgeleitet.
- **Direkt für Monitore nutzbar** - nach dem Erzeugen einer Präsentation steht neben dem
  Download-Knopf ein "💾 Auf Server speichern (für Monitor)"-Knopf zur Verfügung. Er legt
  die Präsentation (schreibgeschützt, damit sie am Monitor automatisch als Endlos-
  Slideshow startet) unter `media/bento-pronto/` ab und zeigt die fertige URL
  (inkl. `?autostart=1&loop`) zum Kopieren an - diese Adresse im Infomaster-Dashboard bei
  einem Monitor einfach als Inhalt "Webseite (URL)" eintragen.
- Erreichbar über den Link "🎬 Präsentationen (Bento-Pronto)" oben im Dashboard.

`bento.php` wird aus [bento-pronto](https://github.com/lasjoh-toho/bento-pronto)s
`template.php` + `build.mjs` gebaut (siehe dortiges Projekt für Updates); der Login-
Verbund sowie der "Auf Server speichern"-Knopf sind Infomaster-spezifische Ergänzungen
gegenüber dem Original.
