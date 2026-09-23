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
- **Für Monitore** - "💾 Auf Server speichern (für Monitor)" legt eine schreibgeschützte
  Kopie unter `media/bento-pronto/monitors/` ab (startet dort automatisch als Endlos-
  Slideshow) und zeigt die fertige URL (inkl. `?autostart=1&loop`) zum Kopieren - diese
  Adresse im Dashboard bei einem Monitor als Inhalt "Webseite (URL)" eintragen.
- **Bearbeitbare Präsentationen (wie bei Moodle-mod_bento)** - "📝 Bearbeitbar auf Server
  speichern" legt eine editierbare Kopie unter `media/bento-pronto/decks/` an. Diese
  Datei trägt einen `bento-host-config`-Meta-Tag; der **native** "Speichern"-Knopf im
  Bento-Editor selbst schreibt beim erneuten Öffnen direkt wieder in dieselbe Datei
  zurück (Dafür nötige Editor-Änderungen liegen als offener PR im
  [bento](https://github.com/lasjoh-toho/bento)-Projekt:
  [lasjoh-toho/bento#1](https://github.com/lasjoh-toho/bento/pull/1) - muss dort erst
  gemerged und über `build.mjs` neu gebaut werden, siehe unten). Das Logo oben links im
  Editor führt bei diesen Dateien als Home-Button zurück zu `infomaster.php`.
  Gespeicherte Präsentationen erscheinen auf der `bento.php`-Startseite als eigene
  "Banner" (Titel, Folienanzahl, Größe, Datum) mit denselben Funktionen wie die
  Deck-Karten in moodle-mod_bentos `manage.php`: ▶ Ansehen (startet direkt die
  Präsentation), ✎ Bearbeiten, ⬇ Herunterladen, Doppelklick auf den Titel zum
  Umbenennen, 🗑 Löschen sowie 🗜 (lädt die Präsentation unten in die Karten-
  Ansicht, für "Medien verkleinern"/"In Teile aufteilen", siehe nächster Punkt).
- **🗜 Medien verkleinern / ✂️ In Teile aufteilen** - wie in moodle-mod_bentos
  Deck-Karten: jede Karte in der Karten-Ansicht (frisch konvertiert, importiert
  oder über 🗜 von einer gespeicherten Präsentation geladen) bekommt einen
  Knopf zum Verkleinern/Neukomprimieren eingebetteter Bilder (mit Duplikat-
  Erkennung) sowie - ab 2 Folien - einen zum Aufteilen in mehrere eigenständige
  Präsentationen (Trennpunkte per Klick zwischen Folien-Thumbnails setzen,
  jeder Teil bekommt nur die von ihm tatsächlich genutzten Bilder/Schriften).
  Ergebnis erscheint als neue, noch ungespeicherte Karte(n) - normal über
  "Auf Server speichern"/"Bearbeitbar auf Server speichern" sichern.
- Erreichbar über den Link "🎬 Präsentationen (Bento-Pronto)" oben im Dashboard.

### `bento.php` neu bauen

`bento.php` ist eine fertig gebaute Datei (Editor-Shell + Demo-Deck sind als Base64 in
`bento-pronto-template.php` eingebettet - dieselbe Vorlage, mit Infomaster-spezifischen
Ergänzungen gegenüber dem Original-Template aus
[bento-pronto](https://github.com/lasjoh-toho/bento-pronto)). Zum Neubauen (z.B. nach
einem Update im [bento](https://github.com/lasjoh-toho/bento)-Editor selbst):

```bash
git clone --depth 1 --branch feature/infomaster-host-save https://github.com/lasjoh-toho/bento
cd bento/slides && npm install && npm run build:single
node ../../dump-starter.mjs src   # erzeugt src/__starter-dump.json (Demo-Deck)
```

Anschließend `bento-pronto-template.php`s vier Platzhalter (`__BENTO_SHELL_B64__`,
`__BENTO_DEMO_B64__`, `__BENTO_VERSION__`, `__BENTO_BUILD_DATE__`) wie in
[bento-pronto](https://github.com/lasjoh-toho/bento-pronto)s eigenem `build.mjs` mit dem
Inhalt aus `dist-single/Bento_Slides.bento.html` bzw. `src/__starter-dump.json` ersetzen
und als `bento.php` speichern.
