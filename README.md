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

## PDF ablegen

Im Dashboard unter "📄 PDF ablegen" kann eine (oder mehrere) PDF-Dateien per Drag&Drop
oder Dateiauswahl hochgeladen werden. Jede Seite wird serverseitig einzeln als JPG
gerendert und landet automatisch - abhängig von der erkannten Ausrichtung dieser einen
Seite - in einem von zwei festen, ganz normalen Ordnern ("Hoch" bzw. "Quer", tauchen wie
jeder andere Ordner in der Monitor-Inhalts-Auswahl auf). Mehrseitige PDFs bekommen
fortlaufend nummerierte Dateinamen, damit die Wiedergabereihenfolge (Sortierung wie in
`view.php`) erhalten bleibt, auch wenn sich die Seiten auf beide Ordner verteilen.
Nutzt bevorzugt die PHP-Imagick-Extension (kommt ohne `exec()`/`shell_exec()` aus), sonst
ersatzweise die Kommandozeilen-Tools `pdftoppm` (poppler-utils) oder `gs` (Ghostscript) -
ist keines davon verfügbar, erscheint eine klare Fehlermeldung statt eines stillen
Fehlschlags.

## Medien-Ordner: Ausrichtung & Archiv

Jeder Medien-Ordner unter "Globale Ordner-Verwaltung" (linke Spalte) lässt sich beim
Aufklappen einer Ausrichtung zuordnen (Dropdown "Ausrichtung: – nicht zugeordnet – /
⇕ Hochkant / ⇔ Querformat") - die beiden vom PDF-Import automatisch angelegten Ordner
"Hoch"/"Quer" bekommen ihre Ausrichtung direkt beim Anlegen zugewiesen. Aktive Ordner
(gerade einem Monitor zugewiesen) stehen dabei immer oben. Statt einzelne Dateien
endgültig zu löschen, lassen sich Bilder in normalen Ordnern stattdessen in eines von
zwei festen Archiv-Ordnern verschieben - "Archiv Hoch"/"Archiv Quer" stehen dafür in
einer eigenen zweiten Spalte direkt daneben (statt zwischen den normalen Ordnern
aufzutauchen) und tauchen bewusst nicht in der Monitor-Inhalts-Auswahl auf. Ist die
Ausrichtung eines Ordners bekannt, genügt für das Verschieben EIN Knopf (📦, führt
direkt ins passende Archiv); ohne Zuordnung stehen sicherheitshalber weiterhin beide
Archiv-Knöpfe (⬆/➡) zur Wahl. Innerhalb der beiden Archiv-Ordner selbst funktioniert
Löschen unverändert endgültig. Die von Bento-Pronto verwalteten Ordner (`bentos`,
`bento-pronto`) tauchen hier absichtlich gar nicht auf - Zugang dazu läuft
ausschließlich über die eigene Bento-Pronto-Seite.

## Mehrere Monitore an einem Pi

Wayland-Compositors (labwc, Standard bei Raspberry Pi OS Bookworm) lassen einen Client
NICHT selbst bestimmen, auf welchem Ausgang sein Fenster erscheint - ohne Gegenmaßnahme
landen dadurch mehrere gleichzeitig gestartete Kiosk-Fenster oft alle auf demselben
Monitor. `client.py` bewegt darum den Mauszeiger unmittelbar vor jedem Kiosk-Start per
`wlrctl` auf den jeweiligen Ziel-Ausgang; der Installationsbefehl baut `wlrctl`
best-effort aus dem Quellcode und setzt bei labwc einmalig additiv `policy=cursor` in
`rc.xml` (vorhandene Konfiguration bleibt unangetastet). Fehlen `wlrctl` oder labwc, läuft
alles wie bisher weiter, nur eben ohne gezielte Platzierung je Ausgang - ein Pi mit bereits
laufendem Kiosk-Dienst muss dafür einmal den Installationsbefehl erneut ausführen und den
Dienst danach neu starten.

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
  speichern" legt eine editierbare Kopie unter `media/bentos/` an (bewusst NICHT unter
  `media/bento-pronto/decks/` o.ä. - der Bento-Editor selbst erkennt einen Speichern-
  Host nur, wenn `bentos` als eigenes Pfadsegment in der URL vorkommt, genau wie
  mod_bento das ganz analog über `mod/bento` in der URL löst; siehe `editor/hostsave.ts`
  im bento-Projekt - `moodle.ts` selbst bleibt davon komplett unberührt). Diese Datei
  trägt zusätzlich einen `bento-host-config`-Meta-Tag; der **native** "Speichern"-Knopf
  im Bento-Editor selbst schreibt beim erneuten Öffnen direkt wieder in dieselbe Datei
  zurück (Dafür nötige Editor-Änderungen liegen als offener PR im
  [bento](https://github.com/lasjoh-toho/bento)-Projekt:
  [lasjoh-toho/bento#1](https://github.com/lasjoh-toho/bento/pull/1) - muss dort erst
  gemerged und über `build.mjs` neu gebaut werden, siehe unten) - inklusive desselben
  visuellen Feedbacks wie beim Moodle-Speichern-Knopf (Fortschrittsbalken während des
  Hochladens, ein "✓ Gespeichert"-Häkchen ersetzt den Knopf, solange nichts geändert
  wurde). Das Logo oben links im Editor führt bei diesen Dateien als Home-Button zurück
  zu `infomaster.php`.
  Sobald eine neue Karte entsteht (Konvertierung, Import, Text einfügen, Verbinden, jeder
  Teil eines Aufteilens), erscheint sofort ein kleiner Dialog mit einem Namensfeld
  (Vorschlag aus dem erkannten Titel, editierbar) und drei Knöpfen: 💾 Speichern (legt die
  Karte sofort als bearbeitbare Präsentation auf dem Server an), ✎ Öffnen (speichert
  ebenfalls - nötig, damit der native Speichern-Knopf im vollen Editor funktioniert - und
  öffnet die Präsentation direkt in einem neuen Tab) und ⬇ Herunterladen (lädt die Datei
  direkt herunter, ohne sie auf dem Server abzulegen, die Karte verschwindet danach
  wieder). Es gibt keine eigene Button-Reihe für noch nicht entschiedene Karten mehr -
  über 💾 oder ✎ bekommt eine Karte sofort dieselben Knöpfe wie eine bereits gespeicherte
  Präsentation (▶✎🗜✂️⬇✕🔗, siehe unten). Die Karten-Ansicht steht oben (direkt unter
  den Umwandlungs-Optionen), darunter folgen die gespeicherten Präsentationen als eigene
  "Banner" mit denselben Funktionen wie die Deck-Karten in moodle-mod_bentos
  `manage.php` - nur Icons mit Tooltip, kein Text: ▶ Ansehen (startet die Präsentation als
  Endlos-Loop im 8-Sekunden-Takt), ✎ Bearbeiten, 🗜 Verkleinern-laden / ✂️ Aufteilen-laden
  (laden die Präsentation unten in die Karten-Ansicht, siehe nächster Punkt),
  ⬇ Herunterladen, ✕ Löschen, 🔗 Link erzeugen (kopiert exakt dieselbe Adresse wie
  ▶ Ansehen in die Zwischenablage). Der Name lässt sich per langem Klick (gedrückt
  halten, ohne die Maus zu bewegen) umbenennen - ändert nur den Dateinamen, nie die
  Position in der Liste. Neueste Präsentation immer oben, sonst zuletzt festgelegte
  Reihenfolge (`media/bentos/.order.json`, selbstheilend); zwischen je zwei
  benachbarten Bannern - genau wie im moodle-Plugin, eng an der Kante überlappend statt
  in einer eigenen Zeile mit Trennstrich - ⇅ Position tauschen und ✚ Verbinden (lädt
  beide Dateien unten in die Karten-Ansicht und führt sie wie zwei frisch konvertierte
  Karten zu einer neuen, noch ungespeicherten Karte zusammen; Bestätigungsdialog mit
  Warnhinweis ab rund 20 MB kombinierter Größe). Dieselbe Karten-Ansicht (frisch
  konvertiert, importiert oder von dort geladen) hat ebenfalls nur Icon-Knöpfe mit
  Tooltip statt Textbeschriftung.
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
