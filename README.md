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

## PDF/Bild ablegen

Ganz oben in der Kopfzeile steht "📥 Datei hinzufügen" (Drag&Drop oder Klick zum Auswählen -
PDF, JPG, PNG, GIF und WEBP werden akzeptiert, `.doc`/`.docx` aktuell nicht). Jede abgelegte
Datei wird sofort automatisch einsortiert, ganz ohne Rückfrage:

- Eine **PDF** landet unverändert (kein Rendern zu Bildern), je nach Ausrichtung der ersten
  Seite, in "PDF Hoch" bzw. "PDF Quer".
- Ein **Bild** (JPG/PNG/GIF/WEBP) landet, je nach tatsächlichen Pixel-Maßen, in "Hoch" bzw.
  "Quer".

Mehrere Dateien gemischten Typs lassen sich auf einmal hineinziehen - jede wird einzeln
nacheinander hochgeladen und landet automatisch am richtigen Ort.

"PDF Hoch"/"PDF Quer" erscheinen wie jeder andere Medien-Ordner unter "Globale
Ordner-Verwaltung" (inkl. eigenem Formular zum manuellen Hochladen einzelner Dateien);
anders als Bilder-Ordner haben sie aber keine Ausrichtungs-Zuordnung und keinen
"🗑 Ordner löschen"-Knopf, da ihre Ausrichtung schon über den Ordnernamen feststeht.

Bei jedem Monitor lässt sich im "Inhalt A/B"-Dropdown ein einzelner Eintrag "📄 PDF"
auswählen (statt einer Liste einzelner Dateien) - darunter erscheinen dann, genau wie bei
"Webseite (URL)" das URL-Feld, zwei weitere Auswahlfelder: welche PDF-Datei abgespielt
werden soll, und der Wiedergabe-Modus. Sowohl das Ordner- als auch das PDF-Datei-Dropdown
zeigen dabei nur Einträge, deren Ausrichtung zur Ausrichtung dieses Monitors passt (siehe
"Medien-Ordner: Ausrichtung & Archiv" unten) - Archiv-Ordner erscheinen dort nie. Eine bereits
gespeicherte Auswahl bleibt auch bei einem nachträglichen Ausrichtungs-Mismatch (z.B. nach
Drehen des Monitors) sichtbar, damit ein bloßes erneutes Speichern den Inhalt nicht
stillschweigend ändert.

Zwei Wiedergabe-Modi stehen je Monitor zur Wahl: **Scroll** (die PDF läuft einmal komplett
von oben nach unten durch, Gesamtdauer = die Wechselfrequenz des Monitors, springt danach
wieder an den Anfang - bei einer einseitigen PDF wird die Seite stattdessen ruhig/statisch
angezeigt, da es nichts zu scrollen gibt) oder **Seitenweise** (blättert alle X Sekunden -
die Wechselfrequenz - eine Seite weiter und beginnt nach der letzten Seite wieder bei
Seite 1). Beide Modi nutzen den nativen Browser-PDF-Viewer (kein eigenes Rendering,
funktioniert daher auch ohne Internetzugang auf dem Pi).

Die Umwandlung von PDF-Seiten in einzelne JPGs (aus einer früheren Version dieser Funktion)
ist serverseitig weiterhin vorhanden, aktuell aber in keinem Dropdown/Formular der
Oberfläche mehr aufrufbar.

Die Ausrichtungs-Erkennung sowie die native PDF-Anzeige nutzen dieselbe Engine-Erkennung:
bevorzugt die PHP-Imagick-Extension (kommt ohne `exec()`/`shell_exec()` aus), sonst
ersatzweise die Kommandozeilen-Tools `pdftoppm` (poppler-utils) oder `gs` (Ghostscript) - ist
keines davon verfügbar, erscheint eine klare Fehlermeldung statt eines stillen Fehlschlags.
Der "seitenweise"-Modus (und die Scroll-Erkennung einer einseitigen PDF) ermittelt die
Gesamtseitenzahl zusätzlich über `pdfinfo` (falls vorhanden) oder einen kleinen
`gs`-Seitenzähler.

## Medien-Ordner: Ausrichtung & Archiv

Jeder Medien-Ordner unter "Globale Ordner-Verwaltung" lässt sich beim Aufklappen einer
Ausrichtung zuordnen (Dropdown "Ausrichtung: – nicht zugeordnet – / ⇕ Hochkant /
⇔ Querformat") - die beiden vom PDF-Import automatisch angelegten Ordner "Hoch"/"Quer"
bekommen ihre Ausrichtung direkt beim Anlegen zugewiesen. Aktive Ordner (gerade einem
Monitor zugewiesen) stehen dabei immer oben. Statt einzelne Dateien endgültig zu
löschen, lassen sich Bilder in normalen Ordnern stattdessen in eines von zwei festen
Archiv-Ordnern verschieben ("Archiv Hoch"/"Archiv Quer", tauchen bewusst nicht in der
Monitor-Inhalts-Auswahl auf). Ist die Ausrichtung eines Ordners bekannt, genügt für das
Verschieben EIN Knopf (📦, führt direkt ins passende Archiv); ohne Zuordnung stehen
sicherheitshalber weiterhin beide Archiv-Knöpfe (⬆/➡) zur Wahl.

Ist einem Ordner eine Ausrichtung zugewiesen, klappt sein Akkordion beim Öffnen zweispaltig
auf: links der gewohnte Ordnerinhalt, rechts der Inhalt des dazu passenden Archiv-Ordners
(Archiv Hoch bzw. Archiv Quer) - so lässt sich direkt zwischen aktuell genutztem Ordner und
Archiv hin- und herschieben, ohne extra zum Archiv-Ordner navigieren zu müssen. Die
Archiv-Seite ist standardmäßig chronologisch sortiert (neueste zuerst), per Dropdown auch
alphabetisch; ein "⬅"-Knopf pro Archiv-Datei verschiebt sie zurück in den gerade
geöffneten Ordner. Ordner ohne zugewiesene Ausrichtung bleiben einspaltig, da es dafür kein
eindeutig passendes Archiv gibt. "Archiv Hoch"/"Archiv Quer" selbst stehen ganz normal
als eigene Ordner in derselben Liste (kein "Ordner löschen"-Knopf, aber wie gewohnt mit
endgültigem Löschen pro Datei) - z.B. zum Aufräumen unabhängig vom Paar-Blick eines
konkreten Ordners. Die von Bento-Pronto verwalteten Ordner (`bentos`, `bento-pronto`)
tauchen hier absichtlich gar nicht auf - Zugang dazu läuft ausschließlich über die eigene
Bento-Pronto-Seite.

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

`bento.php` ist [bento-pronto](https://github.com/lasjoh-toho/bento-pronto) (PPTX/PPT→Bento-
Präsentationskonverter), fest in Infomaster eingebaut:

- **Gleicher Login** - `bento.php` nutzt dieselbe PHP-Session wie `infomaster.php` (kein
  eigener Zugang), nicht angemeldete Aufrufe werden zum Infomaster-Login umgeleitet.
- **Import** - sowohl `.pptx` als auch altes binäres `.ppt` (PowerPoint 97–2003) werden
  direkt im Browser gelesen (Texte inkl. Aufzählungen/Bullet-Vererbung, Formen inkl.
  Freihand-Geometrie, Bilder mit Alt-Text, Farben/Farbschemata, Hintergründe, Tabellen,
  Diagramme, Klick-Animationen, Übergänge, Notizen) - kein Umweg über PowerPoint/LibreOffice
  nötig. Übernommen aus [moodle-mod_bento](https://github.com/lasjoh-toho/moodle-mod_bento)s
  eigenem OLE2/CFB- und MS-PPT/MS-ODRAW-Parser.
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

## EyeCandy Studio (Spotlight-Präsentationseditor)

`eyecandy.html` ist ein zweiter, eigenständiger Präsentationseditor (Spotlight-Effekt,
Grainy Gradients, Glass-Card-Templates, Wegpunkt-gesteuerte Lichtführung) - erreichbar über
den Link "✨ Schnelle Präsentation erstellen" oben im Dashboard (der Bento-Pronto-Link
daneben heißt entsprechend "🎬 PPT importieren"). Er bleibt bewusst eine reine,
serverlose HTML-Datei (kein eigenes PHP, keine eigene Session) und wird nur per Link
integriert:

- **Wegpunkte per Drag verschieben** - im Spotlight-Tab erscheinen die Wegpunkte als
  farbige Marker direkt auf der Folie, per Ziehen frei positionierbar (statt nur über die
  Zahlenfelder), synchron mit der Liste in der Seitenleiste.
- **Weichzeichner-Hintergrund (Blur) abschaltbar** - ein Schalter bei "Layout-Vorlagen"
  wirkt sofort auf die aktuelle Folie und auf neu angewendete Vorlagen; zusätzlich lässt
  sich der Glass-Effekt weiterhin pro Vorlagen-Element einzeln umschalten.
- **Kontrast-/Negativfarbe für Text** - je ein Knopf bei Text- und Vorlagen-Textfarbe wählt
  automatisch Schwarz/Weiß (je nach Helligkeit) bzw. invertiert die aktuelle Farbe.
- **Automatische Schriftgrößen-Skalierung** - ein Schalter pro Textelement verkleinert die
  Schrift automatisch, bis sie in die Breite des Kastens und die verbleibende Höhe bis zum
  Folienrand passt (die eingestellte Größe wirkt dann als Maximum).
- **Eigene Schriftarten per Drag & Drop** - `.ttf`/`.otf`/`.woff`/`.woff2`-Dateien lassen
  sich im Fonts-Tab hineinziehen, werden per `FontFace`-API registriert und beim Export als
  `@font-face` mit eingebetteter Data-URL mitgeliefert (funktioniert offline auf dem Pi).
- **Übergänge zwischen Folien - pro Folie, mit Richtung und Geschwindigkeit** - Gleiten
  seitlich, Gleiten vertikal, Überblenden oder harter Schnitt, plus ein Geschwindigkeits-
  Regler (150–2000 ms), alles einstellbar unter Folien-Einstellungen; gilt jeweils für den
  Übergang BEIM Hineinwechseln in die gerade bearbeitete Folie (jede Folie kann also ihren
  eigenen Übergang samt Richtung und Tempo haben), im Editor, im Vollbild UND im HTML-Export.
- **Farb-Stops in der richtigen Reihenfolge** - die Liste der Verlaufs-Farbstopps zeigt sie
  jetzt nach Position sortiert (0% oben, 100% unten) statt in der Reihenfolge, in der sie
  angelegt wurden.
- **Spotlight ein/aus - pro Folie** - der Schalter "Spotlight aktivieren" im Spotlight-Tab
  gilt für die gerade ausgewählte Folie (Muster/Größe/Farbe/Wegpunkte bleiben weiterhin
  geteilt) - so lässt sich der Effekt gezielt nur auf einzelnen Folien einsetzen.
- **Weicherer Spotlight-Rand** - sowohl das "Loch" in der Abdunkelung als auch der farbige
  Lichtschein darüber laufen jetzt über mehrere, per Easing-Kurve (statt linear)
  überblendete Zwischenstufen aus - vermeidet den sichtbaren harten Ring am Rand. Die
  Lichtfarbe im Zentrum ist zusätzlich in der Deckkraft gedeckelt, damit die Mitte nicht
  überstrahlt wirkt.
- **Ebenen-Liste** - im Elemente-Tab zeigt eine Liste alle Elemente der aktuellen Folie in
  ihrer Stapelreihenfolge (oben = im Vordergrund) - anklicken wählt zum Bearbeiten aus
  (gerade bei großflächigen, überlappenden Vorlagen-Textfeldern zuverlässiger als auf der
  Folie selbst zu klicken), ▲/▼ ändert die Reihenfolge.
- **Maus-Bewegung statt reinem Hover** - Navigations-Hinweis und Mauszeiger in der
  Vollbild-Präsentation (und im HTML-Export) blenden sich nur bei tatsächlicher
  Mausbewegung über der Folie ein und nach kurzer Ruhe automatisch wieder aus.
- **Kopfzeilen-Dropzone nimmt jetzt auch Bilder** - dieselbe Dropzone oben rechts wie für
  native PDFs (siehe oben) akzeptiert zusätzlich JPG/PNG/GIF/WEBP und sortiert sie
  automatisch nach Ausrichtung in "Hoch"/"Quer" ein.
- **Im Infomaster speichern - mit Monitor-Vorschlag** - neben "Herunterladen" erscheint ein
  zweiter Knopf, sobald die Seite über den echten Dashboard-Link (mit gültigem `?token=...`)
  geöffnet wurde; speichert die exportierte Präsentation direkt in `media/bentos/` -
  demselben Ordner, in dem auch bearbeitbare Bento-Präsentationen liegen (über `bento.php`s
  Speicher-Endpunkt, also mit derselben Login-Session UND einem zusätzlichen serverseitigen
  Token-Abgleich). Nach dem Speichern schlägt ein Modal gleich passende Monitore vor (gleiche
  Ausrichtung wie unten beschrieben) - ein Klick auf "Hierher legen" setzt die Präsentation
  direkt als Inhalt A dieses Monitors (genau derselbe Weg wie Bento-Prontos eigenes "🔗 Auf
  Monitor legen").
- **Quer-/Hochkant-Vorschau und -Anlage** - ein Knopf in der Kopfzeile schaltet die Bühne
  (Editor UND Vollbild-Präsentation) zwischen 16:9 (Quer) und 9:16 (Hochkant) um - der
  Rest bleibt als schwarzer Rand ("Letterbox") sichtbar, wie auf einem echten Monitor mit
  anderem Format. Die gewählte Ausrichtung wird beim Speichern mitgeschickt und bestimmt,
  welche Monitore im Speichern-Modal vorgeschlagen werden (siehe oben).
- **Kopfzeile deutlich schlanker** - Logo/Titel und die Editor/Präsentation-Status-Anzeige
  wurden entfernt, damit rechts daneben immer Platz für die Tab-Kopfzeile der Seitenleiste
  bleibt (die beiden überlappten sich vorher bei normalen Fensterbreiten). Übrig bleiben nur
  Folien-Navigation samt Foliennummer, "+ Neu", der Auto-Play-Schalter, die Quer/Hochkant-
  Umschaltung und "Präsentieren"; zurück in den Editor geht weiterhin per ESC.
- **Zuverlässiger in Textfelder klicken** - ein Klick zum Auswählen eines Elements hat bisher
  die komplette Folie neu aufgebaut, wodurch ein Doppelklick zum Reintippen (neuer DOM-Knoten
  zwischen den beiden Klicks) so gut wie nie erkannt wurde. Die Auswahl aktualisiert jetzt nur
  noch die betroffenen Elemente, ohne die Folie anzufassen - Doppelklick zum Bearbeiten
  funktioniert dadurch zuverlässig. Das Verschieben von Elementen hat zusätzlich einen kleinen
  Bewegungs-Schwellwert bekommen, damit ein normaler Klick nicht schon als (minimaler) Drag
  gewertet wird.
