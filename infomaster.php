<?php
// Session-Cookie bewusst langlebig setzen (12 Stunden) - vorher lief das Login/die
// Rollen-Freigabe an der PHP-Standardeinstellung (oft nur ~24 Minuten Inaktivitaet oder ein
// reines Browser-Session-Cookie), weshalb Rolle UND Passwort staendig neu abgefragt wurden.
$SESSION_LIFETIME = 12 * 3600;
session_set_cookie_params(['lifetime' => $SESSION_LIFETIME, 'path' => '/']);
ini_set('session.gc_maxlifetime', (string)$SESSION_LIFETIME);
session_start();
// Sliding-Window: jeder Aufruf verlaengert die Session wieder um die vollen 12 Stunden ab
// jetzt, statt fest ab dem urspruenglichen Login abzulaufen - solange man's regelmaessig
// oeffnet, wird man nie herausgeworfen. Nur nach $SESSION_LIFETIME AB DEM LETZTEN Aufruf
// wird erneut nach Rolle/Passwort gefragt.
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $SESSION_LIFETIME) {
    session_unset();
    session_destroy();
    session_start();
}
$_SESSION['last_activity'] = time();

// --- KONFIGURATION ---
$secret_param = "actionhero"; 
$configFile = 'config.json';
$clientsFile = 'clients.json';
$reportsFile = 'error_reports.json';
$uploadBase = 'media/';
$allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'mp4'];

$baseUrl = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://") . $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'];
$serverDirUrl = dirname($baseUrl) . "/";

// Wie lange (in Sekunden) ein Pi nach dem letzten Check-in noch als "online" gilt.
// Der Client meldet sich im Normalbetrieb alle 60 Sekunden (POLL_INTERVAL_OK im Client) -
// der Schwellwert braucht etwas Puffer fuer 1-2 verpasste Zyklen, ohne den Pi bei einer
// kurzen Verzoegerung gleich faelschlich als "Offline" zu zeigen.
const ACTIVE_THRESHOLD = 180;

// Config so früh wie möglich laden (u.a. für $config['required_outputs'], das schon
// der Installer-Script-Generator unten braucht, bevor der alte Ladepunkt weiter unten kam).
$config = [];
if (file_exists($configFile)) {
    $json = json_decode(file_get_contents($configFile), true);
    if ($json) $config = $json;
}
if (!isset($config['required_outputs'])) {
    $config['required_outputs'] = 2; // Erwartete Monitorausgaenge pro Pi (universell einstellbar statt fest im Code)
}

// Master-Passwort bewusst NICHT in config.json und NICHT ueber die Weboberflaeche
// aenderbar: liegt in einer eigenen Datei, die nur von Hand auf dem Server bearbeitet
// wird. Bewusst unauffaellig "vip.txt" genannt statt "master_password.txt", um nicht
// selbst per Dateiname zu verraten, was drinsteht. Damit bleibt der PHP-Code selbst frei
// von Zugangsdaten und koennte z.B. veroeffentlicht werden, ohne ein echtes Passwort
// preiszugeben. Aendern = diese Datei auf dem Server per SFTP/SSH direkt bearbeiten.
$masterPasswordFile = 'vip.txt';
if (!file_exists($masterPasswordFile) && file_exists('master_password.txt')) {
    // Migration: falls schon mit dem vorherigen (zu offensichtlichen) Dateinamen gestartet.
    rename('master_password.txt', $masterPasswordFile);
}
if (!file_exists($masterPasswordFile)) {
    // Einmalig anlegen - entweder mit einem noch aus einer frueheren Version in
    // config.json vorhandenen Passwort (Migration) oder einem Standardwert.
    $bootstrapPassword = $config['admin_password'] ?? "heyholet'sgo2026";
    file_put_contents($masterPasswordFile, $bootstrapPassword);
}
// vip.txt unterstuetzt zwei Formate: entweder die Datei enthaelt (wie bisher) einfach nur
// das Passwort als einzige Zeile, ODER ein einfaches "schluessel=wert"-Format (eine
// Einstellung pro Zeile, "#" leitet einen Kommentar ein) - so bleibt eine bereits bestehende
// reine Passwort-Datei weiterhin gueltig, waehrend zusaetzliche Einstellungen (aktuell:
// ob ein Login-Benutzername auch OHNE ?rolle=-Parameter in der URL eingegeben werden darf)
// moeglich werden, ohne das Format zu brechen.
function loadVipFile($path, $fallbackPassword) {
    $result = ['password' => $fallbackPassword, 'allow_username_login' => false];
    if (!file_exists($path)) return $result;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $hasKeyValue = false;
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') !== false) {
            $hasKeyValue = true;
            list($k, $v) = array_map('trim', explode('=', $line, 2));
            if ($k === 'password' && $v !== '') $result['password'] = $v;
            if ($k === 'allow_username_login') $result['allow_username_login'] = in_array(strtolower($v), ['1', 'true', 'yes', 'ja']);
        }
    }
    if (!$hasKeyValue) {
        // Altes Format: die Datei ist komplett nur das Passwort.
        $result['password'] = trim(implode("\n", $lines));
    }
    return $result;
}
$vip = loadVipFile($masterPasswordFile, "heyholet'sgo2026");
$masterPassword = $vip['password'];
$allowUsernameLogin = $vip['allow_username_login'];
if (isset($config['admin_password'])) {
    unset($config['admin_password']); // nicht mehr in config.json halten
    file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT));
}

// Separate Nutzer (eigener Name + eigenes Passwort, ueber ?rolle=<benutzername> oder das
// Login-Formular identifiziert) - bewusst GETRENNT von vip.txt/dem Master-Passwort, damit
// das Master-Passwort ausschliesslich manuell auf dem Server aenderbar bleibt, waehrend
// diese Nutzer ganz normal ueber die Weboberflaeche (Zugriffs-Popup) verwaltet werden
// koennen. Passwoerter hier werden gehasht gespeichert (password_hash), da diese Datei -
// anders als vip.txt - direkt aus dem Webinterface heraus beschrieben wird.
$usersFile = 'users.json';
$users = file_exists($usersFile) ? (json_decode(file_get_contents($usersFile), true) ?: []) : [];

// Einheitliche Berechnung, ob gerade "global aus" gilt (Ferien/Termine ODER ausserhalb der
// Betriebszeit) - EINE Stelle statt Logik doppelt zu pflegen, damit Ping-Antwort und die
// Status-Anzeige im Bedieninterface niemals auseinanderlaufen koennen. Liefert zusaetzlich
// einen Klartext-Grund, damit im Interface sofort sichtbar ist, WARUM gerade aus/an ist.
// Liefert den aktuellen Zeitstempel, korrigiert um einen manuell im Interface
// eingestellten Stunden-Offset (siehe "time_offset_delta") - noetig, falls die Server-
///Hosting-Zeitzone von der tatsaechlichen lokalen Zeit abweicht.
function getAdjustedNow($config) {
    return time() + (int)round((float)($config['time_offset_hours'] ?? 0) * 3600);
}

function computeGlobalOff($config) {
    $now_ts = getAdjustedNow($config);
    $today = date('Y-m-d', $now_ts);
    $now = date('H:i', $now_ts);
    $dayOfWeek = (string)date('N', $now_ts);
    if (!empty($config['schedule']['holidays'])) {
        foreach ($config['schedule']['holidays'] as $h) {
            if ($today >= ($h['start_date'] ?? '') && $today <= ($h['end_date'] ?? '')) {
                return ['off' => true, 'reason' => 'Ferien/Termin "' . ($h['name'] ?? '?') . '" (' . $h['start_date'] . ' bis ' . $h['end_date'] . ')'];
            }
        }
    }
    $activeDays = $config['schedule']['active_days'] ?? [];
    if (!in_array($dayOfWeek, $activeDays)) {
        return ['off' => true, 'reason' => 'Heutiger Wochentag ist kein aktiver Tag laut Zeitplan'];
    }
    $timeStart = $config['schedule']['time_start'] ?? "00:00";
    $timeEnd = $config['schedule']['time_end'] ?? "23:59";
    if ($now < $timeStart || $now > $timeEnd) {
        return ['off' => true, 'reason' => "Ausserhalb der Betriebszeit ($timeStart - $timeEnd)"];
    }
    return ['off' => false, 'reason' => 'Innerhalb der Betriebszeit, kein Ferien-/Termin-Eintrag aktiv'];
}

// Entfernt Ferien/Termine, deren Bis-Datum in der Vergangenheit liegt - sonst waechst die
// Liste unbegrenzt mit laengst abgelaufenen Eintraegen weiter.
function pruneExpiredHolidays($holidays, $config) {
    $today = date('Y-m-d', getAdjustedNow($config));
    return array_values(array_filter($holidays, function($h) use ($today) {
        return ($h['end_date'] ?? '9999-12-31') >= $today;
    }));
}

// Berechnet die naechsten AN/AUS-Zeitpunkte im Voraus (Zeitplan + Ferien), als konkrete
// Unix-Zeitstempel statt als abstrakte Regel. Wird bei jeder vollen Ping-Antwort mitgeschickt,
// damit ein Pi bei einem Master-Ausfall den Zeitplan trotzdem lokal korrekt weiterfuehren
// kann (Vergleich der eigenen Systemzeit gegen diese Liste, statt raten zu muessen).
function computeUpcomingScheduleChanges($config, $maxCount = 50, $maxDays = 90) {
    $changes = [];
    $activeDays = $config['schedule']['active_days'] ?? [];
    $timeStart = $config['schedule']['time_start'] ?? "00:00";
    $timeEnd = $config['schedule']['time_end'] ?? "23:59";
    $holidays = $config['schedule']['holidays'] ?? [];
    $now = getAdjustedNow($config);
    $dayStart = strtotime(date('Y-m-d 00:00:00', $now));
    $lastState = null; // true = an, false = aus, null = noch unbekannt
    for ($dayOffset = 0; $dayOffset < $maxDays && count($changes) < $maxCount; $dayOffset++) {
        $dayTs = $dayStart + $dayOffset * 86400;
        $dateStr = date('Y-m-d', $dayTs);
        $dow = (string)date('N', $dayTs);
        $isHoliday = false;
        foreach ($holidays as $h) {
            if ($dateStr >= ($h['start_date'] ?? '') && $dateStr <= ($h['end_date'] ?? '')) { $isHoliday = true; break; }
        }
        $isActiveDay = in_array($dow, $activeDays);
        if ($isHoliday || !$isActiveDay) {
            if ($lastState !== false) {
                $changes[] = ['ts' => $dayTs, 'off' => true];
                $lastState = false;
            }
        } else {
            $onTs = strtotime("$dateStr $timeStart");
            $offTs = strtotime("$dateStr $timeEnd");
            if ($lastState !== true) {
                $changes[] = ['ts' => $onTs, 'off' => false];
                $lastState = true;
            }
            $changes[] = ['ts' => $offTs, 'off' => true];
            $lastState = false;
        }
    }
    // Nur zukuenftige (oder gerade eben vergangene, als Referenzpunkt fuer den aktuellen
    // Zustand nuetzliche) Eintraege behalten und auf maxCount begrenzen.
    $changes = array_values(array_filter($changes, function($c) use ($now) { return $c['ts'] >= $now - 3600; }));
    return array_slice($changes, 0, $maxCount);
}

// Ermittelt den aktuellen Anzeige-Status eines Monitors (Screens) ueber alle Pi-Zuordnungen
// hinweg: gruen = wird gerade aktiv angezeigt, gelb = ein zugeordneter Ausgang steckt im
// Wartungsmodus, rot = zugeordnet, aber gerade aus (Zeitplan/Ferien oder Ausgang offline),
// grau = ueberhaupt keinem Ausgang zugeordnet.
function getScreenStatus($screenId, $clients, $config) {
    $assignedAnywhere = false;
    $anyMaintenance = false;
    $anyOnlineNonMaintenance = false;
    $anyProblem = false;
    $now = time();
    foreach ($clients as $data) {
        $isOnline = ((time() - ($data['last_seen'] ?? 0)) < ACTIVE_THRESHOLD);
        foreach (($data['displays'] ?? []) as $out => $mode) {
            if ((string)$mode !== (string)$screenId) continue;
            $assignedAnywhere = true;
            if (!$isOnline) continue;
            if (!empty($data['maintenance'])) { $anyMaintenance = true; continue; }
            $failCount = $data['output_failures'][$out] ?? 0;
            if ($failCount > 0) { $anyProblem = true; continue; }
            $anyOnlineNonMaintenance = true;
        }
    }
    if (!$assignedAnywhere) return ['color' => '#64748b', 'label' => 'Nicht zugeordnet'];
    if ($anyMaintenance) return ['color' => '#eab308', 'label' => 'Wartung'];
    if ($anyProblem) return ['color' => '#eab308', 'label' => 'Problem: Anzeige gestört'];
    if ($anyOnlineNonMaintenance && !computeGlobalOff($config)['off']) return ['color' => '#22c55e', 'label' => 'Aktiv'];
    return ['color' => '#ef4444', 'label' => 'Aus'];
}

// Ist dieser Ordner aktuell als Quelle (Inhalt A oder B) fuer irgendeinen Monitor eingestellt?
function isFolderActive($folderName, $config) {
    foreach (($config['screens'] ?? []) as $s) {
        if (($s['type'] ?? '') === "folder:$folderName") return true;
        if (($s['typeB'] ?? '') === "folder:$folderName") return true;
    }
    return false;
}

// Baut die kleine Info-Zeile mit Monitor-Eigenschaften (Aufloesung/Hz/Groesse/Modell) fuer
// einen Ausgang - liefert einen leeren String, wenn nichts bekannt ist (z.B. alte Client-
// Version, die dieses Feld noch nicht sendet). Bewusst als eigene Funktion statt Inline-
// Logik in renderPiRow, damit dieser Teil isoliert bleibt.
function renderOutputDetailLine($clientData, $outputName) {
    if (!isset($clientData['output_details']) || !is_array($clientData['output_details'])) {
        return '';
    }
    if (!isset($clientData['output_details'][$outputName]) || !is_array($clientData['output_details'][$outputName])) {
        return '';
    }
    $det = $clientData['output_details'][$outputName];
    $bits = [];
    if (isset($det['resolution']) && $det['resolution'] !== '') { $bits[] = htmlspecialchars($det['resolution']); }
    if (isset($det['refresh']) && $det['refresh'] !== '') { $bits[] = htmlspecialchars($det['refresh']); }
    if (isset($det['size']) && $det['size'] !== '') { $bits[] = htmlspecialchars($det['size']); }

    $html = '';
    if (count($bits) > 0) {
        $html .= '<div style="font-size:10px; color:#64748b; margin-bottom:4px;">' . implode(' - ', $bits) . '</div>';
    }
    if (isset($det['model']) && $det['model'] !== '') {
        $modelSafe = htmlspecialchars($det['model']);
        $html .= '<div style="font-size:9px; color:#475569; margin-bottom:4px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="' . $modelSafe . '">' . $modelSafe . '</div>';
    }
    return $html;
}


// Laedt und parst einen ICS-Kalender-Feed. Rueckgabe null = Abruf fehlgeschlagen (z.B. URL
// nicht erreichbar), sonst ein (ggf. leeres) Array frisch geparster Eintraege.
function fetchIcsHolidays($url) {
    $icsData = @file_get_contents($url);
    if (!$icsData) return null;
    $result = [];
    $events = explode("BEGIN:VEVENT", $icsData); array_shift($events);
    foreach ($events as $event) {
        $name = ""; $start = ""; $end = "";
        if (preg_match('/SUMMARY:([^\r\n]+)/', $event, $m)) $name = trim($m[1]);
        if (preg_match('/DTSTART[^:]*:([0-9]{8})/', $event, $m)) $start = substr($m[1], 0, 4) . '-' . substr($m[1], 4, 2) . '-' . substr($m[1], 6, 2);
        if (preg_match('/DTEND[^:]*:([0-9]{8})/', $event, $m)) {
            $endTs = strtotime(substr($m[1], 0, 4) . '-' . substr($m[1], 4, 2) . '-' . substr($m[1], 6, 2)) - 86400;
            $end = date('Y-m-d', $endTs);
        }
        if ($name && $start && $end) {
            $result[] = ['id' => uniqid(), 'name' => $name, 'start_date' => $start, 'end_date' => $end, 'source' => 'ics'];
        }
    }
    return $result;
}

// Beide Endpunkte, die die Pis ansprechen, hier zentral definieren - werden sowohl vom
// Installer (schreibt client.py einmalig) als auch vom Auto-Update-Endpunkt (liefert die
// jeweils aktuelle client.py zum Selbst-Update) gebraucht.
$api_endpoint = $baseUrl . "?api=ping";
$update_endpoint = $baseUrl . "?api=client_script";
$report_endpoint = $baseUrl . "?api=error_report";
$media_list_endpoint = $baseUrl . "?api=media_list";
$media_base_url = $serverDirUrl . "media/";

// Erzeugt den kompletten Python-Quellcode fuer client.py. Zentral an einer Stelle, damit
// Installer-Skript und Auto-Update-Endpunkt garantiert denselben Code ausliefern.
function renderClientPySource($api_endpoint, $update_endpoint, $report_endpoint, $media_list_endpoint, $media_base_url, $requiredOutputs) {
    ob_start();
    ?>
import time, requests, subprocess, socket, os, glob, json, uuid, threading, shutil, sys, re
import http.server
import urllib.parse
from collections import deque

try:
    import evdev
    from evdev import ecodes
    EVDEV_AVAILABLE = True
except ImportError:
    EVDEV_AVAILABLE = False

SERVER_URL = "<?php echo $api_endpoint; ?>"
UPDATE_URL = "<?php echo $update_endpoint; ?>"
REPORT_URL = "<?php echo $report_endpoint; ?>"
MEDIA_LIST_URL = "<?php echo $media_list_endpoint; ?>"
MEDIA_BASE_URL = "<?php echo $media_base_url; ?>"
CLIENT_SCRIPT_PATH = os.path.abspath(__file__)
DEFAULT_OUTPUTS = ["HDMI-A-1", "HDMI-A-2"]
REQUIRED_OUTPUT_COUNT = <?php echo (int)$requiredOutputs; ?>  # aus config.json, im Master einstellbar
CACHE_FILE = "/home/pi/kiosk_system/last_config.json"
FALLBACK_WAIT_SECONDS = 10
MAINTENANCE_FLAG = "/home/pi/kiosk_system/maintenance_mode"
ESC_TRIPLE_WINDOW = 2.0  # Sekunden zwischen 1. und 3. ESC-Druck

# Vom Master zugewiesener Anzeigename: wird lokal gecacht, damit der Pi ihn auch dann noch
# "kennt" (z.B. fuer eigene Logausgaben), wenn der Master gerade nicht erreichbar ist oder
# noch die alte Server-Version ohne dieses Feld laeuft. Ein leerer/fehlender Name vom Master
# loescht NIE einen bereits bekannten lokalen Namen - nur ein neuer, nicht-leerer Name
# ueberschreibt ihn.
ASSIGNED_NAME_FILE = "/home/pi/kiosk_system/assigned_name.txt"
ASSIGNED_NAME = None
try:
    with open(ASSIGNED_NAME_FILE, "r") as f:
        ASSIGNED_NAME = f.read().strip() or None
except Exception:
    pass

# Automatischer Selbst-Neustart bei anhaltendem Fehlschlag: haelt den Zeitpunkt des letzten
# SELBST ausgeloesten Neustarts in einer Datei fest (ueberlebt einen Reboot!), damit hoechstens
# einmal pro Tag automatisch neu gestartet wird - sonst droht bei einem dauerhaften Hardware-
# problem eine endlose Neustart-Schleife.
LAST_SELF_REBOOT_FILE = "/home/pi/kiosk_system/last_self_reboot.txt"
LAST_SELF_REBOOT_REASON_FILE = "/home/pi/kiosk_system/last_self_reboot_reason.txt"
SELF_REBOOT_MIN_INTERVAL = 24 * 3600  # Sekunden
# Nach wie vielen KOMPLETT fehlgeschlagenen Start-Zyklen in Folge (je Ausgang, jeder Zyklus
# bereits inklusive 3 Sofort-Versuchen) ein Neustart in Erwaegung gezogen wird.
MAX_CONSECUTIVE_FAILURES_BEFORE_REBOOT = 5
consecutive_output_failures = {}

def maybe_self_reboot(reason):
    try:
        with open(LAST_SELF_REBOOT_FILE, "r") as f:
            last_ts = float(f.read().strip() or 0)
    except Exception:
        last_ts = 0
    if time.time() - last_ts < SELF_REBOOT_MIN_INTERVAL:
        log(f"HINWEIS: Automatischer Neustart waere jetzt sinnvoll ({reason}), aber es gab bereits "
            f"einen Selbst-Neustart innerhalb der letzten 24 Stunden - wird uebersprungen, um keine "
            f"Neustart-Schleife zu riskieren.")
        return
    log(f"🔁 Automatischer Neustart wird ausgeloest: {reason}")
    send_error_report(f"auto_reboot: {reason}")
    try:
        with open(LAST_SELF_REBOOT_FILE, "w") as f:
            f.write(str(time.time()))
        # In eine EIGENE Datei geschrieben (nicht nur geloggt): das Journal liegt bewusst nur
        # im RAM (Storage=volatile, siehe Installer) und wird durch den gleich folgenden
        # Neustart geloescht - ohne diese Datei waere danach nicht mehr nachvollziehbar,
        # OB und WARUM ueberhaupt automatisch neu gestartet wurde.
        with open(LAST_SELF_REBOOT_REASON_FILE, "w") as f:
            f.write(f"{time.strftime('%Y-%m-%d %H:%M:%S')} - {reason}")
    except Exception as e:
        log(f"[WARNUNG] Zeitpunkt/Grund des Selbst-Neustarts konnte nicht gespeichert werden ({e}).")
    time.sleep(2)  # kurze Pause, damit Log/Fehlerbericht noch rausgehen
    try:
        subprocess.run(["reboot"])
    except Exception as e:
        log(f"[FEHLER] Neustart konnte nicht ausgeloest werden: {e}")

def note_output_failure(out, consecutive_output_failures):
    consecutive_output_failures[out] = consecutive_output_failures.get(out, 0) + 1
    if consecutive_output_failures[out] >= MAX_CONSECUTIVE_FAILURES_BEFORE_REBOOT:
        maybe_self_reboot(
            f"{out} ist seit {consecutive_output_failures[out]} Start-Versuchen in Folge "
            f"nicht funktionsfaehig - die gewuenschte Anzeige ist so nicht moeglich."
        )

def note_output_success(out, consecutive_output_failures):
    consecutive_output_failures[out] = 0

# Ringpuffer der letzten Logzeilen: Grundlage fuer automatische Fehlerberichte an den Master
# (siehe send_error_report weiter unten) - haelt die letzten 40 Zeilen im Speicher vor, ganz
# unabhaengig davon, ob/wie das Journal auf dem Pi konfiguriert ist.
LOG_HISTORY_SIZE = 40
_log_history = deque(maxlen=LOG_HISTORY_SIZE)

# Kandidaten fuer den Chromium-Befehl: der Paketname/Binary-Name unterscheidet sich
# je nach Raspberry Pi OS Version (Bullseye: "chromium-browser", Bookworm/Debian: oft nur "chromium").
# Wird EINMAL beim Start ermittelt statt bei jedem Zyklus neu zu raten.
CHROMIUM_CANDIDATES = ["chromium-browser", "chromium", "chromium-browser-privileged"]
CHROMIUM_BIN = None

def find_chromium_binary():
    for name in CHROMIUM_CANDIDATES:
        if shutil.which(name):
            return name
    return None

# Polling-Frequenz: eine einzige Abfrage pro Zyklus liefert direkt die vollen Daten (kein
# separater Leichtgewichts-Check mehr noetig, da der Master ohnehin bei jeder Anfrage sofort
# antwortet - ob sich was geaendert hat oder nicht). 60 Sekunden im Normalbetrieb sind dafuer
# guenstig genug und sorgen gleichzeitig fuer zuegige Uebernahme von Aenderungen sowie fuer
# schnelle Selbstheilung bei einem abgestuerzten Chromium. Nur wenn der Master gar nicht
# erreichbar ist, wird kurzzeitig noch haeufiger nachgefragt (3x im 30s-Abstand), bevor auf
# einen traegeren 10-Minuten-Rhythmus zurueckgefallen wird, um einen dauerhaft nicht
# erreichbaren Server nicht unnoetig oft anzufragen.
POLL_INTERVAL_OK = 60
BACKOFF_INTERVAL = 30
BACKOFF_SHORT_TRIES = 3
BACKOFF_INTERVAL_LONG = 600
consecutive_failures = 0

def next_poll_interval():
    if consecutive_failures == 0:
        return POLL_INTERVAL_OK
    if consecutive_failures <= BACKOFF_SHORT_TRIES:
        return BACKOFF_INTERVAL
    return BACKOFF_INTERVAL_LONG

# Fuer weniger redundante, aber praezisere Fehlermeldungen: merkt sich die letzte
# Fehlermeldung pro "Quelle" und loggt Wiederholungen nur noch kompakt mit Zaehler,
# statt denselben Fehlerblock jeden Zyklus erneut komplett auszugeben.
_last_error_sig = {}
_last_error_count = {}

def log(msg):
    prefix = f"[{ASSIGNED_NAME}] " if ASSIGNED_NAME else ""
    line = f"[{time.strftime('%H:%M:%S')}] {prefix}{msg}"
    print(line, flush=True)
    _log_history.append(line)

def send_error_report(reason):
    # Best-effort: ein fehlgeschlagener Report darf selbst niemals eine weitere Fehlerkaskade
    # ausloesen (deshalb kein log_error_deduped-Aufruf hier drin, nur ein stiller Fangnetz).
    try:
        requests.post(REPORT_URL, data={
            'client_id': CLIENT_ID if 'CLIENT_ID' in globals() else 'unbekannt',
            'reason': reason,
            'log': "\n".join(_log_history)
        }, timeout=5)
    except Exception:
        pass

def log_error_deduped(source, sig, full_message):
    if _last_error_sig.get(source) == sig:
        _last_error_count[source] = _last_error_count.get(source, 1) + 1
        log(f"[FEHLER] {source}: weiterhin derselbe Fehler (x{_last_error_count[source]}) - {sig}")
    else:
        _last_error_sig[source] = sig
        _last_error_count[source] = 1
        log(full_message)
        # Nur beim ERSTEN Auftreten eines Fehlers einen Bericht senden, nicht bei jeder
        # deduplizierten Wiederholung - sonst waeren die letzten 10 Berichte pro Pi im
        # Handumdrehen mit lauter identischen Meldungen vollgelaufen.
        send_error_report(f"{source}: {sig}")

def update_assigned_name(new_name):
    global ASSIGNED_NAME
    new_name = (new_name or "").strip()
    if not new_name or new_name == ASSIGNED_NAME:
        return  # Kein Name vom Master (alte Server-Version oder noch keiner vergeben) - vorhandenen lokalen Namen NICHT loeschen.
    ASSIGNED_NAME = new_name
    try:
        with open(ASSIGNED_NAME_FILE, "w") as f:
            f.write(new_name)
        log(f"📛 Name vom Master erhalten: '{new_name}'")
    except Exception as e:
        log(f"[WARNUNG] Zugewiesener Name konnte nicht lokal gespeichert werden ({e}).")

def get_client_id():
    # Bevorzugt: /etc/machine-id - stabil ueber Reboots und IP-Wechsel hinweg.
    try:
        with open("/etc/machine-id") as f:
            mid = f.read().strip()
            if mid:
                return mid
    except Exception:
        pass
    # Fallback: eigene ID-Datei mit zufaelliger UUID anlegen.
    id_file = "/home/pi/kiosk_system/client_id"
    try:
        if os.path.exists(id_file):
            with open(id_file) as f:
                return f.read().strip()
        new_id = str(uuid.uuid4())
        with open(id_file, "w") as f:
            f.write(new_id)
        return new_id
    except Exception as e:
        log(f"WARNUNG: Client-ID konnte nicht ermittelt werden ({e}).")
        return None

def get_ip():
    s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    try:
        s.connect(('10.255.255.255', 1))
        IP = s.getsockname()[0]
    except Exception as e:
        log(f"WARNUNG: IP konnte nicht ermittelt werden ({e}). Nutze 127.0.0.1")
        IP = '127.0.0.1'
    finally:
        s.close()
    return IP

def get_wayland_env():
    env = os.environ.copy()
    user_dirs = glob.glob('/run/user/*')
    # NUR echte, interaktive Nutzerkonten beruecksichtigen (UID >= 1000 ist unter Linux die
    # uebliche Grenze fuer normale Benutzerkonten - Systemdienste wie "lightdm", "gdm" etc.
    # liegen darunter). Ohne diesen Filter konnte hier je nach zufaelliger Reihenfolge von
    # glob() auch das Runtime-Verzeichnis eines Dienstkontos erwischt werden - Chromium
    # startete dann faelschlich als z.B. "lightdm" statt als echter Desktop-User und
    # stuerzte sofort ohne brauchbare Fehlermeldung ab (Exitcode 1, "Keine Ausgabe").
    real_user_dirs = []
    for d in user_dirs:
        uid_str = os.path.basename(d)
        if uid_str.isdigit() and int(uid_str) >= 1000:
            real_user_dirs.append(d)
    real_user_dirs.sort(key=lambda d: int(os.path.basename(d)))
    # Bevorzugt ein Verzeichnis mit bereits vorhandenem Wayland-Socket (= aktive Session);
    # ohne Treffer wird trotzdem das erste echte Nutzerverzeichnis versucht.
    chosen = None
    for d in real_user_dirs:
        if glob.glob(os.path.join(d, 'wayland-*')):
            chosen = d
            break
    if chosen is None and real_user_dirs:
        chosen = real_user_dirs[0]
    if chosen is None:
        log("WARNUNG: Kein echtes Nutzer-Runtime-Verzeichnis (/run/user/<UID>=1000) gefunden - Wayland-Session evtl. nicht aktiv!")
    runtime_dir = chosen if chosen else '/run/user/1000'
    env["XDG_RUNTIME_DIR"] = runtime_dir
    env["WAYLAND_DISPLAY"] = "wayland-0" if os.path.exists(os.path.join(runtime_dir, 'wayland-0')) else "wayland-1"
    env["DISPLAY"] = ":0"
    return env, runtime_dir

def get_desktop_user(runtime_dir):
    try:
        import pwd
        return pwd.getpwuid(int(os.path.basename(runtime_dir))).pw_name
    except Exception as e:
        log(f"WARNUNG: Desktop-User konnte nicht ermittelt werden ({e}). Nutze 'pi'.")
        return "pi"

def detect_outputs(env):
    # Ermittelt die tatsaechlich angeschlossenen HDMI-Ausgaenge ueber wlr-randr,
    # damit Boards mit 2 oder 4 Ausgaengen automatisch korrekt erkannt werden.
    #
    # WICHTIG: wlroots erzeugt automatisch einen Platzhalter-Ausgang (typischerweise
    # "NOOP-1", teils auch "HEADLESS-*"/"Virtual-*"), wenn der Compositor beim Start
    # KEINEN echten Bildschirm findet (z.B. weil der Monitor beim Booten noch nicht
    # bereit war). Das ist kein Fehler von wlr-randr, sondern ein bewusster Ersatz-
    # Ausgang von wlroots selbst, damit der Compositor trotzdem hochfaehrt. Chromium
    # kann darauf nicht sinnvoll rendern - ohne Filterung wuerde staendig erfolglos
    # versucht werden, dort ein Fenster zu starten. Deshalb werden solche virtuellen
    # Ausgaenge hier konsequent aussortiert.
    VIRTUAL_OUTPUT_PREFIXES = ("NOOP", "HEADLESS", "VIRTUAL", "X11", "WL-")
    try:
        result = subprocess.run(["wlr-randr"], env=env, capture_output=True, text=True, timeout=5)
        if result.returncode != 0:
            raise RuntimeError(result.stderr.strip())
        outputs = []
        filtered_virtual = []
        for line in result.stdout.splitlines():
            if line and not line[0].isspace():
                name = line.split()[0].strip()
                if not name:
                    continue
                if name.upper().startswith(VIRTUAL_OUTPUT_PREFIXES):
                    if name not in filtered_virtual:
                        filtered_virtual.append(name)
                    continue
                if name not in outputs:
                    outputs.append(name)
        if filtered_virtual:
            log(f"HINWEIS: virtuelle(r) Platzhalter-Ausgang/-Ausgaenge ignoriert: {filtered_virtual} "
                f"(kein echter Monitor - der Compositor fand beim Start keinen; ggf. hilft ein Neustart "
                f"des Pi, NACHDEM der Monitor sicher angeschlossen und eingeschaltet ist).")
        if outputs:
            return outputs
        raise RuntimeError("keine echten Outputs in wlr-randr Ausgabe gefunden")
    except Exception as e:
        log(f"WARNUNG: Output-Erkennung fehlgeschlagen ({e}). Nutze Standard-Outputs {DEFAULT_OUTPUTS}.")
        return DEFAULT_OUTPUTS

def detect_output_details(env):
    # Liefert zusaetzliche, rein informative Monitor-Eigenschaften (Aufloesung, Hz, Zoll,
    # Modell) je Ausgang. Bewusst sehr defensiv geschrieben: jede Zeile einzeln abgesichert,
    # nur einfache String-Werte im Ergebnis - das darf im schlimmsten Fall leer bleiben,
    # aber niemals die Ausgangserkennung selbst stoeren oder eine Ausnahme nach aussen
    # durchlassen.
    details = {}
    try:
        result = subprocess.run(["wlr-randr"], env=env, capture_output=True, text=True, timeout=5)
    except Exception:
        return details
    if result.returncode != 0:
        return details
    current = None
    for raw_line in result.stdout.splitlines():
        try:
            if not raw_line.strip():
                continue
            if not raw_line[0].isspace():
                name = raw_line.split()[0].strip()
                if name.upper().startswith(("NOOP", "HEADLESS", "VIRTUAL", "X11", "WL-")):
                    current = None  # virtueller Platzhalter-Ausgang - keine Details dafuer sammeln
                    continue
                current = name
                details[current] = {}
                if '"' in raw_line:
                    parts = raw_line.split('"')
                    if len(parts) >= 2 and parts[1].strip():
                        details[current]["model"] = parts[1].strip()[:80]
                continue
            if current is None:
                continue
            stripped = raw_line.strip()
            lower = stripped.lower()
            if lower.startswith("physical size:"):
                dims = lower.replace("physical size:", "").replace("mm", "").strip()
                if "x" in dims:
                    w_txt, h_txt = dims.split("x", 1)
                    if w_txt.strip().isdigit() and h_txt.strip().isdigit():
                        w_mm, h_mm = int(w_txt.strip()), int(h_txt.strip())
                        if w_mm > 0 and h_mm > 0:
                            diag_in = ((w_mm * w_mm + h_mm * h_mm) ** 0.5) / 25.4
                            details[current]["size"] = str(round(diag_in)) + " Zoll"
            elif "px" in lower and "current" in lower:
                dims_part = stripped.split("px")[0].strip()
                if "x" in dims_part:
                    w_txt, h_txt = dims_part.split("x", 1)
                    if w_txt.strip().isdigit() and h_txt.strip().isdigit():
                        details[current]["resolution"] = f"{w_txt.strip()}x{h_txt.strip()}"
                if "hz" in lower:
                    hz_part = stripped.lower().split("hz")[0].strip().split(",")[-1].strip()
                    hz_num = "".join(c for c in hz_part if c.isdigit() or c == ".")
                    if hz_num:
                        try:
                            details[current]["refresh"] = str(round(float(hz_num))) + " Hz"
                        except ValueError:
                            pass
        except Exception:
            continue  # eine einzelne kaputte Zeile darf die restliche Auswertung nicht stoppen
    return details

def load_cached_config():
    try:
        with open(CACHE_FILE) as f:
            data = json.load(f)
        # Rueckwaertskompatibel: alte Cache-Dateien enthalten direkt {out: cfg, ...} ohne
        # die Huelle {"outputs": ..., "schedule_changes": ...}.
        if isinstance(data, dict) and "outputs" in data:
            return data
        return {"outputs": data if isinstance(data, dict) else {}, "schedule_changes": []}
    except Exception:
        return None

def save_cached_config(data, schedule_changes=None):
    try:
        with open(CACHE_FILE, "w") as f:
            json.dump({"outputs": data, "schedule_changes": schedule_changes or []}, f)
    except Exception as e:
        log(f"WARNUNG: Konfiguration konnte nicht zwischengespeichert werden ({e}).")

def extract_screens_map(outputs_cfg):
    # Baut aus den pro-Ausgang mitgelieferten Screen-Definitionen eine Zuordnung
    # Screen-ID -> Definition (mehrere Ausgaenge koennen denselben Screen zeigen).
    screens_map = {}
    for cfg in outputs_cfg.values():
        mode = cfg.get("mode")
        screen_def = cfg.get("screen")
        if screen_def and mode not in ("off", "desktop"):
            screens_map[str(mode)] = screen_def
    return screens_map

def compute_local_global_off(schedule_changes):
    # Bestimmt anhand der vorausberechneten Zeitpunkte (siehe computeUpcomingScheduleChanges
    # auf dem Master) rein lokal, ob gerade "aus" gelten sollte - fuer den Fall, dass der
    # Master laengere Zeit nicht erreichbar ist und der Zeitplan trotzdem eingehalten werden
    # soll. Ohne Daten wird NICHT bevormundet (False), da eine falsche Annahme schlimmer
    # waere als einfach den letzten bekannten Zustand beizubehalten.
    if not schedule_changes:
        return False
    now = time.time()
    state = False
    try:
        for change in sorted(schedule_changes, key=lambda c: c.get("ts", 0)):
            if change.get("ts", 0) <= now:
                state = bool(change.get("off"))
            else:
                break
    except Exception:
        return False
    return state

# ============================================================================
# LOKALE WIEDERGABE (Standard) & LOKALER MEDIEN-PROXY: der Pi liefert Inhalte standardmaessig
# ueber seinen eigenen lokalen Webserver aus einem lokal gespiegelten Datei-Cache aus, statt
# jedes Mal live vom Master zu laden - schneller, robuster, und funktioniert auch bei kurzen
# Verbindungsaussetzern nahtlos weiter. Live vom Server ist die Ausnahme (pro Monitor im
# Master umschaltbar) bzw. der Not-Fallback, solange ein Ordner noch nicht synchronisiert ist.
# Webseiten-Inhalte (Typ "url") werden nicht gespiegelt (koennen es auch nicht, sind dynamisch)
# - dort bedeutet "lokal" nur die gleiche Rotations-/Split-Huelle, der Inhalt selbst kommt
# weiterhin direkt von der jeweiligen externen Seite. Nextcloud-Ordner werden aktuell noch
# NICHT gespiegelt (das braeuchte Kenntnis des genauen Freigabe-/WebDAV-Mechanismus) - dafuer
# zeigt der lokale Modus einen klaren Hinweis statt eines Absturzes.
# ============================================================================
FALLBACK_HTTP_PORT = 8091
FALLBACK_SWITCH_THRESHOLD = 3  # so viele Fehlversuche in Folge, bevor "live"-Screens auf lokal umschalten
_fallback_state = {"screens": {}}

MEDIA_CACHE_DIR = "/home/pi/kiosk_system/media_cache"
MEDIA_SYNC_INTERVAL = 600  # Sekunden zwischen zwei Sync-Durchlaeufen
_last_media_sync_time = 0

def get_needed_media_folders():
    # Welche Ordner-Quellen werden gerade von irgendeinem lokal gecachten Screen gebraucht?
    folders = set()
    for screen_def in _fallback_state.get("screens", {}).values():
        for key in ("type", "typeB"):
            t = screen_def.get(key, "") or ""
            if t.startswith("folder:"):
                folders.add(t[len("folder:"):])
    return folders

def sync_media_folder(folder_name):
    if not folder_name:
        return
    try:
        r = requests.get(MEDIA_LIST_URL, params={"folder": folder_name}, timeout=10)
        if r.status_code != 200:
            log(f"WARNUNG: Medien-Liste fuer Ordner '{folder_name}' nicht abrufbar (Status {r.status_code}).")
            return
        remote_files = set(r.json())
    except Exception as e:
        log(f"WARNUNG: Medien-Liste fuer Ordner '{folder_name}' konnte nicht geladen werden ({e}).")
        return
    local_dir = os.path.join(MEDIA_CACHE_DIR, folder_name)
    try:
        os.makedirs(local_dir, exist_ok=True)
    except Exception as e:
        log(f"WARNUNG: Lokaler Medien-Cache-Ordner '{folder_name}' konnte nicht angelegt werden ({e}).")
        return
    try:
        existing_files = set(f for f in os.listdir(local_dir) if not f.startswith("."))
    except Exception:
        existing_files = set()

    new_files = remote_files - existing_files
    for fname in new_files:
        try:
            file_url = MEDIA_BASE_URL + folder_name + "/" + fname
            resp = requests.get(file_url, timeout=25)
            if resp.status_code == 200:
                tmp_path = os.path.join(local_dir, fname + ".part")
                with open(tmp_path, "wb") as f:
                    f.write(resp.content)
                os.replace(tmp_path, os.path.join(local_dir, fname))  # atomar, kein halb geschriebenes Bild sichtbar
            else:
                log(f"WARNUNG: Datei '{fname}' (Ordner '{folder_name}') Status {resp.status_code} beim Laden.")
        except Exception as e:
            log(f"WARNUNG: Datei '{fname}' (Ordner '{folder_name}') konnte nicht geladen werden ({e}).")

    # Lokale Dateien entfernen, die auf dem Server nicht mehr existieren (geloescht/ausgeblendet).
    for fname in existing_files - remote_files:
        try:
            os.remove(os.path.join(local_dir, fname))
        except Exception:
            pass

    if new_files:
        log(f"🗂️  Medien-Sync '{folder_name}': {len(new_files)} neue Datei(en) lokal gespiegelt.")

def sync_all_media():
    for folder in get_needed_media_folders():
        sync_media_folder(folder)

def build_fallback_html(screen_id, screen_def):
    orient = str(screen_def.get("orient", "0"))
    if orient not in ("0", "90", "180", "270"):
        orient = "0"
    split = screen_def.get("split", "none")
    if split not in ("h", "v"):
        split = "none"
    duration = screen_def.get("duration", 10)
    try:
        duration = max(1, int(duration))
    except (TypeError, ValueError):
        duration = 10

    def pane_html(type_, content_, pane_uid):
        content_ = content_ or ""
        if type_ == "url" and content_:
            safe_content = content_.replace('"', "&quot;")
            return f'<iframe src="{safe_content}" scrolling="no" frameborder="0"></iframe>'
        elif type_ and str(type_).startswith("folder:"):
            folder = str(type_)[len("folder:"):]
            local_dir = os.path.join(MEDIA_CACHE_DIR, folder)
            try:
                files = sorted(f for f in os.listdir(local_dir) if not f.startswith("."))
            except Exception:
                files = []
            if not files:
                safe_folder = folder.replace('"', "&quot;")
                return (f'<div style="color:#888;font-family:sans-serif;font-size:14px;'
                        f'padding:20px;text-align:center;">Noch keine lokal gespiegelten '
                        f'Dateien fuer Ordner &quot;{safe_folder}&quot; verfuegbar.</div>')
            urls = [f"/media/{urllib.parse.quote(folder)}/{urllib.parse.quote(f)}" for f in files]
            return (
                f'<div class="pane-inner" id="{pane_uid}"></div>'
                f'<script>(function(){{'
                f'var files={json.dumps(urls)};var idx=0;'
                f'var el=document.getElementById("{pane_uid}");'
                f'function show(){{'
                f'var u=files[idx%files.length];idx++;'
                f'if(u.toLowerCase().endsWith(".pdf")){{'
                f'el.innerHTML=\'<iframe src="\'+u+\'#toolbar=0&navpanes=0&scrollbar=0&view=FitW" '
                f'style="width:100%;height:100%;border:none;"></iframe>\';'
                f'}}else{{'
                f'el.innerHTML=\'<img src="\'+u+\'" style="width:100%;height:100%;object-fit:contain;">\';'
                f'}}'
                f'}}'
                f'show();setInterval(show,{duration}*1000);'
                f'}})();</script>'
            )
        elif type_ == "nextcloud":
            return ('<div style="color:#888;font-family:sans-serif;font-size:14px;'
                     'padding:20px;text-align:center;">Nextcloud-Inhalte werden im lokalen '
                     'Modus aktuell noch nicht gespiegelt.</div>')
        return ""

    pane_a_html = pane_html(screen_def.get("type"), screen_def.get("content"), f"slidesA_{screen_id}")
    pane_b_html = pane_html(screen_def.get("typeB"), screen_def.get("contentB"), f"slidesB_{screen_id}") if split != "none" else ""
    split_class = f"split-{split}" if split != "none" else ""
    second_pane = f'<div class="pane">{pane_b_html}</div>' if split != "none" else ""

    return f"""<!DOCTYPE html>
<html lang="de"><head><meta charset="UTF-8"><title>Offline-Fallback {screen_id}</title>
<style>
body, html {{ margin:0; padding:0; width:100%; height:100%; overflow:hidden; position:fixed; background:#000; }}
#main-container {{ display:flex; overflow:hidden; position:absolute; }}
.split-h {{ flex-direction:column; }}
.split-v {{ flex-direction:row; }}
.pane {{ flex:1; overflow:hidden; position:relative; background:#000; }}
iframe {{ width:100%; height:100%; border:none; display:block; }}
.pane-inner {{ width:100%; height:100%; }}
body.orient-0 #main-container {{ width:100vw; height:100vh; top:0; left:0; transform:none; }}
body.orient-180 #main-container {{ width:100vw; height:100vh; top:0; left:0; transform:rotate(180deg); transform-origin:center center; }}
body.orient-90 #main-container, body.orient-270 #main-container {{
    width:100vh; height:100vw; top:calc(50vh - 50vw); left:calc(50vw - 50vh); transform-origin:center center;
}}
body.orient-90 #main-container {{ transform:rotate(90deg); }}
body.orient-270 #main-container {{ transform:rotate(270deg); }}
.offline-badge {{
    position:fixed; bottom:10px; right:10px; z-index:9999;
    background:rgba(239,68,68,0.85); color:#fff; font-family:sans-serif;
    font-size:11px; padding:4px 10px; border-radius:4px;
}}
</style></head>
<body class="orient-{orient}">
<div class="offline-badge">Offline-Fallback</div>
<div id="main-container" class="{split_class}">
    <div class="pane">{pane_a_html}</div>
    {second_pane}
</div>
</body></html>"""

class _FallbackHTTPHandler(http.server.BaseHTTPRequestHandler):
    def log_message(self, fmt, *args):
        pass  # Kein eigenes Access-Log - das (bewusst kleine, volatile) Journal soll nicht dafuer draufgehen.

    def do_GET(self):
        try:
            parsed = urllib.parse.urlparse(self.path)
            if parsed.path == "/view":
                qs = urllib.parse.parse_qs(parsed.query)
                screen_id = (qs.get("id") or [None])[0]
                screen_def = _fallback_state.get("screens", {}).get(str(screen_id))
                if screen_def is None:
                    body = b"Kein lokal gecachter Monitor mit dieser ID."
                    self.send_response(404)
                    self.send_header("Content-Type", "text/plain; charset=utf-8")
                    self.send_header("Content-Length", str(len(body)))
                    self.end_headers()
                    self.wfile.write(body)
                    return
                body = build_fallback_html(screen_id, screen_def).encode("utf-8")
                self.send_response(200)
                self.send_header("Content-Type", "text/html; charset=utf-8")
                self.send_header("Content-Length", str(len(body)))
                self.end_headers()
                self.wfile.write(body)
            elif parsed.path.startswith("/media/"):
                # Liefert lokal gespiegelte Ordner-Dateien aus (siehe sync_media_folder).
                rel_path = urllib.parse.unquote(parsed.path[len("/media/"):])
                # Verzeichnis-Traversal ("..") explizit unterbinden.
                if ".." in rel_path.split("/"):
                    self.send_response(400)
                    self.end_headers()
                    return
                full_path = os.path.join(MEDIA_CACHE_DIR, rel_path)
                if not os.path.isfile(full_path):
                    self.send_response(404)
                    self.end_headers()
                    return
                ext = os.path.splitext(full_path)[1].lower()
                content_type = {
                    ".jpg": "image/jpeg", ".jpeg": "image/jpeg", ".png": "image/png",
                    ".gif": "image/gif", ".webp": "image/webp", ".pdf": "application/pdf"
                }.get(ext, "application/octet-stream")
                try:
                    with open(full_path, "rb") as f:
                        content = f.read()
                except Exception:
                    self.send_response(500)
                    self.end_headers()
                    return
                self.send_response(200)
                self.send_header("Content-Type", content_type)
                self.send_header("Content-Length", str(len(content)))
                self.end_headers()
                self.wfile.write(content)
            else:
                self.send_response(404)
                self.end_headers()
        except Exception:
            try:
                self.send_response(500)
                self.end_headers()
            except Exception:
                pass

def start_fallback_server():
    try:
        server = http.server.ThreadingHTTPServer(("127.0.0.1", FALLBACK_HTTP_PORT), _FallbackHTTPHandler)
        threading.Thread(target=server.serve_forever, daemon=True).start()
        log(f"🗄️  Lokaler Offline-Fallback-Server bereit (Port {FALLBACK_HTTP_PORT}).")
    except Exception as e:
        log(f"WARNUNG: Lokaler Offline-Fallback-Server konnte nicht gestartet werden ({e}).")


def describe_output(out, cfg):
    mode = cfg.get("mode", "off")
    url = cfg.get("url", "")
    if mode == "off":
        return f"{out} = AUS"
    if mode == "desktop":
        return f"{out} = Desktop"
    return f"{out} = Webseite ({url})" if url else f"{out} = Monitor {mode} (keine URL)"

# --- 3x-ESC WARTUNGSMODUS ---
# Liest Tastendruecke direkt auf Kernel-Ebene (/dev/input) - das funktioniert auch,
# waehrend Chromium im --kiosk-Modus laeuft und die Tastatur eigentlich fuer sich beansprucht.
_esc_timestamps = []
_esc_lock = threading.Lock()

def _toggle_maintenance_mode():
    if os.path.exists(MAINTENANCE_FLAG):
        try:
            os.remove(MAINTENANCE_FLAG)
        except Exception as e:
            log(f"WARNUNG: Wartungsmodus-Flag konnte nicht entfernt werden ({e}).")
        log("⌨️  3x ESC erkannt -> WARTUNGSMODUS BEENDET. Kiosk startet gleich wieder normal.")
    else:
        try:
            open(MAINTENANCE_FLAG, "w").close()
        except Exception as e:
            log(f"WARNUNG: Wartungsmodus-Flag konnte nicht angelegt werden ({e}).")
        log("⌨️  3x ESC erkannt -> WARTUNGSMODUS AKTIVIERT. Browser werden geschlossen, Desktop wird sichtbar.")

def _handle_key_event(code, value):
    if code == ecodes.KEY_ESC and value == 1:  # value 1 = Taste gedrueckt
        now = time.time()
        with _esc_lock:
            _esc_timestamps.append(now)
            _esc_timestamps[:] = [t for t in _esc_timestamps if now - t <= ESC_TRIPLE_WINDOW]
            if len(_esc_timestamps) >= 3:
                _esc_timestamps.clear()
                _toggle_maintenance_mode()

_watched_paths = set()
_watched_lock = threading.Lock()

def _watch_device(path):
    try:
        dev = evdev.InputDevice(path)
        for event in dev.read_loop():
            if event.type == ecodes.EV_KEY:
                _handle_key_event(event.code, event.value)
    except Exception as e:
        log(f"WARNUNG: Tastatur-Ueberwachung fuer {path} beendet ({e}) - wird beim naechsten automatischen Scan erneut versucht.")
    finally:
        # Aus der Watchlist austragen, damit ein spaeteres erneutes Erscheinen desselben
        # Pfads (z.B. nach Ab-/Wiederanstecken) beim naechsten Scan wieder aufgenommen wird.
        with _watched_lock:
            _watched_paths.discard(path)

def _attach_new_devices():
    started_now = 0
    try:
        devices = evdev.list_devices()
    except Exception as e:
        log(f"WARNUNG: Eingabegeraete konnten nicht aufgelistet werden ({e}).")
        return 0
    for path in devices:
        with _watched_lock:
            if path in _watched_paths:
                continue
        try:
            dev = evdev.InputDevice(path)
            caps = dev.capabilities().get(ecodes.EV_KEY, [])
            if ecodes.KEY_ESC in caps:
                with _watched_lock:
                    _watched_paths.add(path)
                threading.Thread(target=_watch_device, args=(path,), daemon=True).start()
                started_now += 1
        except Exception:
            continue
    return started_now

DEVICE_SCAN_INTERVAL = 30  # Sekunden

def _device_watcher_loop():
    # Regelmaessig neu nach Tastaturen mit ESC-Taste suchen, statt nur einmal beim
    # Dienststart. Faengt damit zwei Faelle sauber ab, die vorher zu einer dauerhaft
    # deaktivierten ESC-Ueberwachung fuehren konnten: (1) die USB-Tastatur war beim
    # Dienststart vom Kernel noch nicht erkannt (Boot-Race-Condition), (2) eine Tastatur
    # wird erst spaeter an-/nach einem Wackelkontakt neu angesteckt - beides jetzt ohne
    # Neustart des Dienstes automatisch erkannt.
    first_run = True
    while True:
        found = _attach_new_devices()
        with _watched_lock:
            total = len(_watched_paths)
        if first_run:
            if total:
                log(f"⌨️  ESC-Ueberwachung gestartet ({total} Tastatur-Geraet(e)). 3x ESC = Wartungsmodus an/aus.")
            else:
                log(f"HINWEIS: Noch keine Tastatur gefunden - wird automatisch aktiv, sobald eine angeschlossen wird (Pruefung alle {DEVICE_SCAN_INTERVAL}s).")
            first_run = False
        elif found:
            log(f"⌨️  {found} neue(s) Tastatur-Geraet(e) erkannt und in die ESC-Ueberwachung aufgenommen.")
        time.sleep(DEVICE_SCAN_INTERVAL)

def start_escape_watcher():
    if not EVDEV_AVAILABLE:
        log("HINWEIS: 'python3-evdev' fehlt - 3x-ESC-Wartungsmodus ist deaktiviert.")
        return
    threading.Thread(target=_device_watcher_loop, daemon=True).start()

def is_chromium_responsive(out, debug_ports):
    # "Prozess laeuft noch" (proc.poll()) erkennt nur einen ABSTURZ. Ein HAENGER (Chromium
    # laeuft, reagiert aber nicht mehr - z.B. weil ein blockierender Dialog wie eine
    # Keyring-Abfrage das Rendern verhindert) sieht fuer proc.poll() genauso aus wie ein
    # gesunder, idler Prozess. Ueber die DevTools-Schnittstelle laesst sich das
    # unterscheiden: der HTTP-Endpunkt antwortet nur, wenn Chromium tatsaechlich noch
    # arbeitet, nicht bloss "existiert".
    port = debug_ports.get(out)
    if not port:
        return True  # kein Port bekannt (z.B. alter Prozess vor diesem Feature) - nicht faelschlich neu starten
    try:
        r = requests.get(f"http://127.0.0.1:{port}/json/version", timeout=3)
        return r.status_code == 200
    except requests.exceptions.RequestException:
        return False

def apply_output_state(out, cfg, env, user, runtime_dir, active_processes, last_states, debug_ports, consecutive_output_failures):
    mode = cfg.get("mode", "off")
    url = cfg.get("url", "")
    state_key = f"{out}_{mode}_{url}"

    if last_states.get(out) == state_key:
        # Soll-Zustand unveraendert - trotzdem pruefen, ob Chromium im Hintergrund
        # inzwischen (z.B. Stunden spaeter) unbemerkt abgestuerzt ODER haengengeblieben ist.
        # Ohne diese Kontrolle blieb der Bildschirm bis zur naechsten tatsaechlichen
        # Aenderung schwarz/haengen, da sonst nur auf GEAENDERTE Vorgaben reagiert wurde.
        if mode not in ("off", "desktop") and url:
            proc = active_processes.get(out)
            if proc is not None and proc.poll() is None and is_chromium_responsive(out, debug_ports):
                return  # laeuft noch UND reagiert - alles gut
            reason = "abgestuerzt" if (proc is None or proc.poll() is not None) else "haengt (reagiert nicht mehr auf DevTools)"
            log_error_deduped(
                f"chromium_silent_exit:{out}",
                reason,
                f"   [WARNUNG] {out} sollte laufen, Chromium ist aber ohne Aenderungsauftrag {reason} - wird neu gestartet."
            )
            # Haengender Prozess muss hart beendet werden - ein normales terminate() (SIGTERM)
            # kann von einem blockierenden Dialog ignoriert werden.
            if proc is not None and proc.poll() is None:
                try:
                    proc.kill()
                    proc.wait(timeout=5)
                except Exception:
                    pass
            # Fallthrough: unten wie bei einer echten Aenderung neu anwenden.
        else:
            return
    else:
        log(f"-> AENDERUNG fuer '{out}': Modus='{mode}', URL='{url}'")
    last_states[out] = state_key

    if out in active_processes:
        log(f"   [+] Beende alten Browser-Prozess fuer {out}...")
        try:
            active_processes[out].terminate()
        except Exception as e:
            log(f"   [!] Fehler beim Beenden: {e}")
        del active_processes[out]

    if mode == "off":
        log(f"   [+] Schalte {out} AUS...")
        res_cmd = subprocess.run(["wlr-randr", "--output", out, "--off"], env=env, capture_output=True, text=True)
        if res_cmd.returncode != 0:
            log(f"   [FEHLER] wlr-randr --off fehlgeschlagen fuer {out}: {res_cmd.stderr.strip()}")
        else:
            log(f"   [OK] {out} ausgeschaltet.")
    else:
        log(f"   [+] Schalte {out} EIN...")
        res_cmd = subprocess.run(["wlr-randr", "--output", out, "--on"], env=env, capture_output=True, text=True)
        if res_cmd.returncode != 0:
            log(f"   [FEHLER] wlr-randr --on fehlgeschlagen fuer {out}: {res_cmd.stderr.strip()}")
        if mode not in ("off", "desktop") and url:
            # Kurze Anlaufzeit: direkt nach dem Wiedereinschalten braucht der Wayland-
            # Compositor manchmal einen Moment, bis der Socket wieder neue Client-
            # Verbindungen annimmt. Ohne diese Pause schlaegt Chromium sofort mit
            # "Failed to connect to Wayland display: Connection refused" fehl - eine reine
            # Race-Condition, die sich durch etwas Geduld meist von selbst erledigt.
            time.sleep(1.5)
            global CHROMIUM_BIN
            if CHROMIUM_BIN is None:
                CHROMIUM_BIN = find_chromium_binary()
                if CHROMIUM_BIN:
                    log(f"   [OK] Chromium-Binary gefunden: '{CHROMIUM_BIN}'")
                else:
                    log(f"   [FEHLER] Kein Chromium gefunden (geprueft: {', '.join(CHROMIUM_CANDIDATES)}). "
                        f"Installiere z.B. mit 'sudo apt install chromium-browser' oder 'sudo apt install chromium'.")
                    last_states[out] = None  # naechster Zyklus versucht es erneut (z.B. nach Nachinstallation)
                    return

            log(f"   [+] Starte Chromium ('{CHROMIUM_BIN}') auf {out} -> {url} (User: {user})")

            # Bis zu 3 sofortige Versuche, bevor auf den naechsten regulaeren Zyklus (60s)
            # gewartet wird - deckt kurzlebige Race-Conditions (z.B. Wayland-Socket direkt
            # nach dem Wiedereinschalten noch nicht bereit) viel schneller ab, statt bis zu
            # einer Minute auf den naechsten Versuch zu warten.
            MAX_LAUNCH_ATTEMPTS = 3
            for attempt in range(1, MAX_LAUNCH_ATTEMPTS + 1):
                # WICHTIG: Vorher wurde /tmp/ch_kiosk_{out} verwendet. Da der Service als
                # root laeuft, gehoert dieses Verzeichnis root, sobald es einmal angelegt
                # wurde - Chromium (gestartet als '{user}' per su) darf dann nicht mehr
                # hineinschreiben und beendet sich sofort, ohne sichtbaren Fehler im Log.
                # Daher: Profil-Verzeichnis im Home des Zielusers anlegen und explizit
                # dessen Besitzer setzen.
                profile_dir = f"/home/{user}/.cache/kiosk_chromium_{out}"
                try:
                    os.makedirs(profile_dir, exist_ok=True)
                    shutil.chown(profile_dir, user=user)
                except Exception as e:
                    log(f"   [WARNUNG] Profil-Verzeichnis {profile_dir} konnte nicht vorbereitet werden ({e}).")

                # Verwaiste Singleton-Sperrdateien aus einem vorherigen (abgestuerzten) Lauf
                # entfernen. Chromium legt beim Start SingletonLock/-Socket/-Cookie im Profil an
                # und raeumt sie beim sauberen Beenden wieder auf - stirbt der Prozess aber hart
                # (z.B. per SIGKILL/Absturz), bleiben sie liegen und Chromium versucht beim
                # naechsten Start, die darin verzeichnete (laengst tote) PID zu kontaktieren.
                # Das kann zu genau der Art von fruehem, kryptischem Absturz fuehren, die wir
                # hier beheben wollen - und einen fehlgeschlagenen Session-Restore-Versuch aus
                # einem nicht sauber beendeten Profil gleich mit vermeiden.
                for lockname in ("SingletonLock", "SingletonSocket", "SingletonCookie"):
                    try:
                        lockpath = os.path.join(profile_dir, lockname)
                        if os.path.exists(lockpath) or os.path.islink(lockpath):
                            os.remove(lockpath)
                    except Exception:
                        pass

                # WICHTIG: inner_cmd bleibt EIN einzelnes Listenelement (kein " ".join()+shell=True) -
                # sonst zerlegt die Shell die Argumente und "su" haelt z.B. "--kiosk" faelschlich
                # fuer eine eigene, unbekannte Option statt fuer einen Teil des Chromium-Befehls.
                # Chromium laeuft hier bewusst NICHT als root: der Service selbst laeuft zwar als
                # root (systemd), aber "su - user" wechselt vorher explizit auf den Desktop-User.
                # Ozone-Platform explizit auf Wayland setzen: ohne diesen Flag versucht
                # Chromium standardmaessig den X11-Backend zu nutzen ("Missing X server or
                # $DISPLAY") - auf einer reinen Wayland-Session (wlroots/wlr-randr, kein
                # X-Server) schlaegt der Start dadurch sofort fehl.
                # Eindeutiger DevTools-Port pro Ausgang (aus der Ausgangsnummer abgeleitet, z.B.
                # HDMI-A-1 -> 9221, HDMI-A-2 -> 9222) - wird unten fuer eine echte Reaktions-
                # faehigkeits-Pruefung genutzt: "Prozess laeuft noch" (proc.poll()) erkennt einen
                # ABSTURZ, aber NICHT einen HAENGER (z.B. ein blockierender Keyring-Dialog, der
                # Chromium nie zum eigentlichen Rendern kommen laesst). Ueber DevTools laesst sich
                # das unterscheiden: antwortet der Port nicht, obwohl der Prozess laeuft, ist
                # Chromium haengengeblieben und wird neu gestartet.
                m = re.search(r'(\d+)$', out)
                debug_port = 9220 + (int(m.group(1)) if m else 0)
                debug_ports[out] = debug_port

                inner_cmd = (
                    f"XDG_RUNTIME_DIR={runtime_dir} WAYLAND_DISPLAY={env['WAYLAND_DISPLAY']} "
                    f"{CHROMIUM_BIN} --kiosk --noerrdialogs --disable-infobars "
                    f"--ozone-platform=wayland "
                    # Ohne diesen Flag versucht Chromium, Passwoerter/Cookies ueber den
                    # System-Keyring (GNOME Keyring/KWallet) zu speichern. Fehlt der - wie auf
                    # einer schlanken Kiosk-Installation ohne Desktop-Umgebung ueblich - kann
                    # das zu einer unsichtbaren Passwort-Abfrage per D-Bus fuehren, die den
                    # Start verzoegert oder haengen laesst. "basic" speichert stattdessen lokal
                    # unverschluesselt, was fuer einen reinen Anzeige-Kiosk unkritisch ist.
                    f"--password-store=basic "
                    # Zusaetzlich zu password-store=basic: verhindert JEGLICHEN Zugriff auf einen
                    # echten OS-Schluesselbund (auch fuer die Cookie-/Local-Storage-Verschluesselung,
                    # die password-store allein nicht immer abdeckt) - genau das war vermutlich die
                    # Ursache fuer den haengenden "Keyring entsperren"-Dialog.
                    f"--use-mock-keychain "
                    # Unterdrueckt die "Chrome wurde nicht richtig beendet"-Wiederherstellungs-
                    # Anfrage, die nach einem harten Kill (z.B. terminate() beim Umschalten)
                    # sonst hinter --disable-infobars trotzdem beim naechsten Start aufpoppen kann.
                    f"--hide-crash-restore-bubble --disable-session-crashed-bubble "
                    f"--remote-debugging-port={debug_port} --remote-debugging-address=127.0.0.1 "
                    f"--user-data-dir={profile_dir} --app='{url}'"
                )
                cmd = ["su", "-", user, "-c", inner_cmd]
                # Explizites Arbeitsverzeichnis statt des von su/systemd geerbten: "su -l" setzt
                # zwar das Home-Verzeichnis des Zielusers als CWD, aber wird das ueber
                # subprocess.Popen ohne eigenes cwd gestartet, kann in seltenen Faellen noch ein
                # ungueltiges/nicht lesbares $PWD aus der root-Umgebung des Dienstes durchschlagen.
                # Chromiums Zygote-Host prueft beim Start explizit das aktuelle Arbeitsverzeichnis
                # ("zygote_host_impl_linux.cc: Check failed: . : No such file or directory") und
                # bricht sofort mit Exitcode 134 (SIGABRT) ab, wenn das fehlschlaegt. profile_dir
                # existiert garantiert und gehoert bereits dem Zieluser (siehe oben).
                proc = subprocess.Popen(cmd, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True, cwd=profile_dir)
                active_processes[out] = proc

                # Kurze Ueberpruefung: laeuft der Prozess ueberhaupt noch, oder ist die
                # su-Session (wie im Log an "session opened"/"session closed" in derselben
                # Sekunde erkennbar) sofort wieder beendet worden? Nur DANN wird die
                # eigentliche Fehlermeldung von Chromium/su sichtbar.
                time.sleep(2)
                ret = proc.poll()
                if ret is not None:
                    output = (proc.stdout.read() or "").strip() if proc.stdout else ""
                    detail = output[:500] if output else (
                        "Keine Ausgabe erhalten - moeglich sind fehlende Wayland-Session, "
                        "falsche Berechtigungen auf XDG_RUNTIME_DIR oder ein Chromium-Absturz."
                    )
                    hint = ""
                    if "Missing X server" in detail or "DISPLAY" in detail:
                        hint = " -> Hinweis: Chromium versucht X11 statt Wayland zu nutzen; --ozone-platform=wayland pruefen."
                    elif "command not found" in detail or "No such file or directory" in detail:
                        hint = " -> Hinweis: Chromium-Binary/Pfad pruefen (siehe CHROMIUM_BIN-Erkennung oben im Log)."
                    elif "Connection refused" in detail or "Verbindungsaufbau abgelehnt" in detail:
                        hint = " -> Hinweis: Wayland-Socket war vermutlich noch nicht bereit (Race-Condition nach dem Einschalten)."
                    is_last_attempt = (attempt == MAX_LAUNCH_ATTEMPTS)
                    log_error_deduped(
                        f"chromium:{out}",
                        f"Exitcode {ret} - {detail}",
                        f"   [FEHLER] Chromium auf {out} ist sofort wieder beendet (Exitcode {ret}, Versuch {attempt}/{MAX_LAUNCH_ATTEMPTS}). [FEHLER-DETAIL] {detail}{hint}"
                    )
                    del active_processes[out]
                    if is_last_attempt:
                        last_states[out] = None  # erzwingt erneuten Startversuch im naechsten Zyklus
                        note_output_failure(out, consecutive_output_failures)
                    else:
                        log(f"   [+] Sofort-Retry {attempt + 1}/{MAX_LAUNCH_ATTEMPTS} in 2s...")
                        time.sleep(2)
                        continue
                else:
                    # Prozess lebt - zusaetzlich kurz warten und per DevTools pruefen, ob Chromium
                    # auch wirklich reagiert (z.B. eine Keyring-Abfrage kann den Prozess am Leben
                    # lassen, aber jedes weitere Rendern blockieren). Etwas mehr Zeit als beim
                    # Crash-Check, da der DevTools-HTTP-Server erst kurz nach dem Start bereitsteht.
                    time.sleep(3)
                    if proc.poll() is not None:
                        # Erst in diesem laengeren Fenster abgestuerzt.
                        ret2 = proc.poll()
                        is_last_attempt = (attempt == MAX_LAUNCH_ATTEMPTS)
                        log_error_deduped(
                            f"chromium:{out}",
                            f"Exitcode {ret2} (verzoegert)",
                            f"   [FEHLER] Chromium auf {out} ist kurz nach dem Start abgestuerzt (Exitcode {ret2}, Versuch {attempt}/{MAX_LAUNCH_ATTEMPTS})."
                        )
                        del active_processes[out]
                        if is_last_attempt:
                            last_states[out] = None
                            note_output_failure(out, consecutive_output_failures)
                        else:
                            log(f"   [+] Sofort-Retry {attempt + 1}/{MAX_LAUNCH_ATTEMPTS} in 2s...")
                            time.sleep(2)
                            continue
                    elif not is_chromium_responsive(out, debug_ports):
                        is_last_attempt = (attempt == MAX_LAUNCH_ATTEMPTS)
                        log_error_deduped(
                            f"chromium:{out}",
                            "haengt beim Start (DevTools antwortet nicht)",
                            f"   [FEHLER] Chromium auf {out} haengt direkt nach dem Start (Versuch {attempt}/{MAX_LAUNCH_ATTEMPTS}, reagiert nicht auf DevTools) - "
                            f"vermutlich ein blockierender Systemdialog (z.B. Keyring). Wird hart beendet."
                        )
                        try:
                            proc.kill()
                            proc.wait(timeout=5)
                        except Exception:
                            pass
                        del active_processes[out]
                        if is_last_attempt:
                            last_states[out] = None
                            note_output_failure(out, consecutive_output_failures)
                        else:
                            log(f"   [+] Sofort-Retry {attempt + 1}/{MAX_LAUNCH_ATTEMPTS} in 2s...")
                            time.sleep(2)
                            continue
                    else:
                        log(f"   [OK] Chromium laeuft stabil und reagiert auf {out} (PID: {proc.pid}, Versuch {attempt}/{MAX_LAUNCH_ATTEMPTS}).")
                        _last_error_sig.pop(f"chromium:{out}", None)
                        note_output_success(out, consecutive_output_failures)
                break
        elif mode not in ("off", "desktop") and not url:
            log(f"   [HINWEIS] Modus {mode} aktiv, aber keine URL vom Master erhalten.")

log("=== KIOSK CLIENT GESTARTET ===")

# Zeigt beim Start, falls der letzte Start durch einen automatischen Selbst-Neustart
# ausgeloest wurde - sonst waere das nach einem Reboot nicht mehr nachvollziehbar (siehe
# maybe_self_reboot). Wird bewusst bei JEDEM Start angezeigt, nicht nur "falls kuerzlich",
# da das RAM-Journal beim Neustart ohnehin geleert wird und dies die einzige Spur ist.
try:
    if os.path.exists(LAST_SELF_REBOOT_REASON_FILE):
        with open(LAST_SELF_REBOOT_REASON_FILE, "r") as f:
            reboot_info = f.read().strip()
        if reboot_info:
            log(f"ℹ️  Letzter automatischer Selbst-Neustart: {reboot_info}")
except Exception:
    pass

# Selbst-Update: Bevor der Client richtig loslegt, kurz pruefen, ob der Master eine neuere
# client.py bereithaelt. So koennen Updates zentral ueber den Master ausgerollt werden,
# ohne dass jemand vor Ort an jedem Pi den Installer erneut ausfuehren muss. Wird die Datei
# ersetzt, beendet sich der Client sauber - systemd (Restart=always) startet ihn sofort mit
# dem neuen Code neu.
UPDATE_CHECK_INTERVAL = 3600  # zusaetzlich zum Start alle 60 Minuten, damit Updates auch ohne Reboot ankommen

def check_for_update():
    try:
        r = requests.get(UPDATE_URL, timeout=10)
        if r.status_code != 200 or not r.text.strip():
            return False
        new_source = r.text
        try:
            with open(CLIENT_SCRIPT_PATH, "r") as f:
                current_source = f.read()
        except Exception:
            current_source = None
        if current_source is not None and new_source.strip() == current_source.strip():
            return False  # schon aktuell
        # Sanity-Check: ist das ueberhaupt gueltiger Python-Code, BEVOR die laufende Datei ersetzt wird?
        try:
            compile(new_source, CLIENT_SCRIPT_PATH, "exec")
        except SyntaxError as e:
            log(f"[WARNUNG] Update vom Master sieht nicht wie gueltiger Python-Code aus, ignoriert ({e}).")
            return False
        tmp_path = CLIENT_SCRIPT_PATH + ".new"
        with open(tmp_path, "w") as f:
            f.write(new_source)
        os.replace(tmp_path, CLIENT_SCRIPT_PATH)  # atomar
        log("🔄 Neue Client-Version vom Master geladen - Dienst wird jetzt neu gestartet, um sie zu uebernehmen.")
        return True
    except requests.exceptions.RequestException as e:
        log(f"[WARNUNG] Update-Check fehlgeschlagen ({e}) - bleibe bei aktueller Version.")
        return False
    except Exception as e:
        log(f"[WARNUNG] Update-Check fehlgeschlagen ({e}).")
        return False

if check_for_update():
    sys.exit(0)
last_update_check = time.time()

CLIENT_ID = get_client_id()
CLIENT_IP = get_ip()
log(f"Client-ID: {CLIENT_ID}")
log(f"Client-IP: {CLIENT_IP}")
log(f"Server-URL: {SERVER_URL}")
start_escape_watcher()
start_fallback_server()

active_processes = {}
debug_ports = {}
last_states = {}
have_fresh_data = False
fallback_applied = False
in_maintenance = False
local_fallback_active = False
LOCAL_SCHEDULE_CHANGES = []

# Beim Start bereits einen evtl. vorhandenen Cache in den Fallback-Server laden, damit
# dieser sofort etwas ausliefern kann, falls schon beim allerersten Kontaktversuch keine
# Verbindung zum Master zustande kommt (statt erst nach dem naechsten erfolgreichen Ping).
_startup_cache = load_cached_config()
if _startup_cache:
    _fallback_state["screens"] = extract_screens_map(_startup_cache.get("outputs", {}))
    LOCAL_SCHEDULE_CHANGES = _startup_cache.get("schedule_changes", [])

def apply_local_fallback(env, user, runtime_dir):
    # Wendet fuer jeden zuletzt bekannten, aktiven Ausgang entweder den lokalen Fallback-Link
    # an (Chromium zeigt dann die von diesem Pi selbst servierte Seite) oder schaltet AUS,
    # falls der lokal berechnete Zeitplan das gerade vorsieht - beides unabhaengig vom Master.
    should_be_off = compute_local_global_off(LOCAL_SCHEDULE_CHANGES)
    for out, cfg in list(last_known_outputs_cfg.items()):
        mode = cfg.get("mode")
        if mode in ("off", "desktop"):
            continue
        local_cfg = dict(cfg)
        if should_be_off:
            local_cfg["mode"] = "off"
            local_cfg["url"] = ""
        else:
            local_cfg["url"] = f"http://127.0.0.1:{FALLBACK_HTTP_PORT}/view?id={mode}"
        apply_output_state(out, local_cfg, env, user, runtime_dir, active_processes, last_states, debug_ports, consecutive_output_failures)

def _local_content_ready(screen_def):
    # Ist fuer diesen Screen alles lokal vorhanden, um ihn OHNE Qualitaetsverlust anzuzeigen?
    # "url" ist sofort bereit (dynamischer Inhalt, nichts zu spiegeln). Ein Ordner gilt erst
    # als bereit, wenn mindestens eine Datei tatsaechlich lokal gespiegelt wurde - sonst lieber
    # noch die Live-URL zeigen, statt verfrueht auf einen leeren Platzhalter umzuschalten.
    # Nextcloud wird aktuell gar nicht gespiegelt, gilt also nie als "lokal bereit".
    for key in ("type", "typeB"):
        t = screen_def.get(key, "") or ""
        if t == "url":
            continue
        if t.startswith("folder:"):
            folder = t[len("folder:"):]
            local_dir = os.path.join(MEDIA_CACHE_DIR, folder)
            try:
                if not any(not f.startswith(".") for f in os.listdir(local_dir)):
                    return False
            except Exception:
                return False
        elif t == "nextcloud":
            return False
    return True

def resolve_effective_cfg(cfg):
    # Entscheidet, welche URL Chromium tatsaechlich bekommt: "lokal" (Standard, ueber den
    # eigenen Mini-Webserver) oder "live" direkt vom Master - abhaengig von der pro Monitor
    # im Master einstellbaren playback_source. Ein Monitor, der auf "lokal" steht, dessen
    # Inhalt aber noch gar nicht lokal verfuegbar ist (z.B. frisch angelegter Ordner, noch
    # nicht synchronisiert), nutzt uebergangsweise die Live-URL, statt einen leeren
    # Platzhalter zu zeigen.
    mode = cfg.get("mode")
    screen_def = cfg.get("screen")
    if mode in ("off", "desktop") or not screen_def:
        return cfg
    playback_source = screen_def.get("playback_source", "local")
    effective = dict(cfg)
    if playback_source == "live":
        return effective  # Live ist fuer diesen Monitor bewusst gewaehlt - unveraendert lassen.
    if str(mode) in _fallback_state.get("screens", {}) and _local_content_ready(screen_def):
        effective["url"] = f"http://127.0.0.1:{FALLBACK_HTTP_PORT}/view?id={mode}"
    # sonst: noch nicht (vollstaendig) synchronisiert - vorerst bei der vom Master gelieferten
    # Live-URL bleiben, bis der naechste Medien-Sync das nachtraegt.
    return effective

# "Silent unless changed"-Ping: der Client schickt bei jedem Zyklus einen Hash der zuletzt
# erhaltenen Konfiguration mit. Hat sich serverseitig nichts geaendert, antwortet der Master
# nur mit einer winzigen Bestaetigung statt der vollen Daten - das ist trotzdem eine ECHTE,
# sofortige HTTP-Antwort (kein Haengenlassen/Timeout!), also weiterhin klar von einem
# tatsaechlichen Verbindungsausfall unterscheidbar. Alle 30 Minuten wird zusaetzlich ein
# erzwungener "Heartbeat" gesendet, auf den der Master IMMER voll antwortet - das haelt
# last_seen/Online-Status frisch, auch wenn sich inhaltlich nichts tut.
HEARTBEAT_INTERVAL = 1800  # Sekunden
last_known_hash = ""
last_known_outputs_cfg = {}
last_heartbeat_time = 0

while True:
    # Periodischer Update-Check (nicht waehrend des Wartungsmodus, um eine laufende
    # Wartungssitzung nicht durch einen Neustart zu unterbrechen).
    if not os.path.exists(MAINTENANCE_FLAG) and (time.time() - last_update_check) >= UPDATE_CHECK_INTERVAL:
        last_update_check = time.time()
        if check_for_update():
            sys.exit(0)

    # Periodischer Medien-Sync fuer lokal wiedergegebene Ordner-Quellen (lokale Wiedergabe
    # ist der Standard - dafuer muessen die Dateien vorab da sein, nicht erst im Ernstfall).
    if not os.path.exists(MAINTENANCE_FLAG) and (time.time() - _last_media_sync_time) >= MEDIA_SYNC_INTERVAL:
        _last_media_sync_time = time.time()
        sync_all_media()

    if os.path.exists(MAINTENANCE_FLAG):
        if not in_maintenance:
            log("🔧 WARTUNGSMODUS aktiv - schliesse Kiosk-Browser und schalte Displays ein...")
            for out, proc in list(active_processes.items()):
                try:
                    proc.terminate()
                except Exception as e:
                    log(f"   [!] Fehler beim Beenden von {out}: {e}")
            active_processes.clear()
            last_states.clear()
            try:
                mm_env, _ = get_wayland_env()
                for out in detect_outputs(mm_env):
                    subprocess.run(["wlr-randr", "--output", out, "--on"], env=mm_env, capture_output=True, text=True)
            except Exception as e:
                log(f"WARNUNG: Displays konnten im Wartungsmodus nicht eingeschaltet werden ({e}).")
            in_maintenance = True
            log("🔧 Wartungsmodus aktiv - Desktop sichtbar, Master wird nicht abgefragt. 3x ESC zum Fortsetzen.")
        # Trotzdem einen minimalen Ping senden (ohne die Antwort anzuwenden!), damit der
        # Master den Wartungszustand anzeigen kann (gelb), statt den Pi nach ACTIVE_THRESHOLD
        # faelschlich als komplett offline (rot) darzustellen.
        try:
            requests.post(SERVER_URL, data={
                'ip': CLIENT_IP,
                'client_id': CLIENT_ID,
                'outputs': json.dumps(last_states.get("_outputs_seen", [])),
                'assigned_name': ASSIGNED_NAME or '',
                'maintenance': '1'
            }, timeout=5)
        except Exception:
            pass
        time.sleep(5)
        continue
    elif in_maintenance:
        log("🔧 Wartungsmodus beendet - Kiosk-Betrieb wird fortgesetzt.")
        in_maintenance = False

    try:
        env, runtime_dir = get_wayland_env()
        user = get_desktop_user(runtime_dir)
        outputs_detected = detect_outputs(env)
        output_details = detect_output_details(env)

        # Nur loggen, wenn sich die erkannten Outputs seit dem letzten Zyklus
        # geaendert haben - sonst wuerde das jede 5s identisch wiederholt werden.
        if outputs_detected != last_states.get("_outputs_seen"):
            log(f"Erkannte Outputs: {outputs_detected}")
            last_states["_outputs_seen"] = outputs_detected

        # Dieser Pi soll genau REQUIRED_OUTPUT_COUNT (2) Monitorausgaenge melden.
        # Warnung nur einmal ausgeben, solange sich die Anzahl nicht aendert.
        if len(outputs_detected) != REQUIRED_OUTPUT_COUNT:
            if last_states.get("_output_count_warned") != len(outputs_detected):
                log(f"   [WARNUNG] Erwarte {REQUIRED_OUTPUT_COUNT} Monitorausgaenge, "
                    f"aber {len(outputs_detected)} erkannt ({outputs_detected}). "
                    f"HDMI-Kabel/Monitorstatus pruefen (wlr-randr zeigt nur aktive Outputs an).")
                last_states["_output_count_warned"] = len(outputs_detected)
        else:
            last_states["_output_count_warned"] = None

        is_heartbeat_now = (time.time() - last_heartbeat_time) >= HEARTBEAT_INTERVAL
        res = requests.post(SERVER_URL, data={
            'ip': CLIENT_IP,
            'client_id': CLIENT_ID,
            'outputs': json.dumps(outputs_detected),
            'output_details': json.dumps(output_details),
            'assigned_name': ASSIGNED_NAME or '',
            'known_hash': last_known_hash,
            'heartbeat': '1' if is_heartbeat_now else '',
            'output_failures': json.dumps(consecutive_output_failures)
        }, timeout=5)

        if res.status_code == 200:
            payload = res.json()
            update_assigned_name(payload.get("name"))
            if is_heartbeat_now:
                last_heartbeat_time = time.time()
            if local_fallback_active:
                # Jede erfolgreiche Antwort (auch eine "unchanged"-Kurzantwort!) beweist, dass
                # der Master wieder erreichbar ist - unabhaengig davon, ob sich inhaltlich
                # etwas geaendert hat.
                log("🔀 Verbindung zum Master wiederhergestellt - kehre von lokalem Offline-Fallback zu Live-Betrieb zurueck.")
                local_fallback_active = False

            if payload.get("reboot"):
                # Vom Master ausgeloester Neustart - wird sofort ausgefuehrt, unabhaengig
                # vom taeglichen Limit fuer den automatischen SELBST-ausgeloesten Neustart
                # (das gilt nur fuer maybe_self_reboot, nicht fuer eine explizite Anweisung).
                log("🔁 Neustart auf Anweisung des Masters angefordert.")
                send_error_report("remote_reboot: vom Master ausgeloest")
                time.sleep(2)
                try:
                    subprocess.run(["reboot"])
                except Exception as e:
                    log(f"[FEHLER] Neustart konnte nicht ausgefuehrt werden: {e}")

            if payload.get("unchanged"):
                # Server hat bestaetigt: nichts Neues. Trotzdem lokal pruefen, ob Chromium im
                # Hintergrund abgestuerzt ist - dafuer reicht die zuletzt bekannte Soll-
                # Konfiguration, ein Server-Roundtrip mit vollen Daten ist dafuer nicht noetig.
                last_known_hash = payload.get("hash", last_known_hash)
                for out, cfg in last_known_outputs_cfg.items():
                    apply_output_state(out, resolve_effective_cfg(cfg), env, user, runtime_dir, active_processes, last_states, debug_ports, consecutive_output_failures)
            else:
                last_known_hash = payload.get("hash", "")
                last_known_outputs_cfg = payload.get("outputs", {})
                LOCAL_SCHEDULE_CHANGES = payload.get("schedule_changes", [])
                _fallback_state["screens"] = extract_screens_map(last_known_outputs_cfg)
                # Auch die Master-Antwort nur bei Aenderung vollstaendig loggen.
                payload_str = json.dumps(payload, sort_keys=True)
                if payload_str != last_states.get("_last_payload"):
                    log(f"<- Antwort vom Master geaendert: {json.dumps(payload)}")
                    last_states["_last_payload"] = payload_str
                    if payload.get("global_off"):
                        log("HINWEIS: Globaler Zeitplan/Ferien aktiv - alle Displays werden AUS geschaltet.")
                data = last_known_outputs_cfg

                for out, cfg in data.items():
                    apply_output_state(out, resolve_effective_cfg(cfg), env, user, runtime_dir, active_processes, last_states, debug_ports, consecutive_output_failures)

                save_cached_config(data, LOCAL_SCHEDULE_CHANGES)
            have_fresh_data = True
            fallback_applied = False
            if consecutive_failures > 0:
                log(f"✅ Verbindung zum Master wieder hergestellt (nach {consecutive_failures} Fehlversuch(en)).")
            consecutive_failures = 0
            _last_error_sig.pop("master_http", None)
        else:
            consecutive_failures += 1
            log_error_deduped(
                "master_http",
                f"{res.status_code}",
                f"[FEHLER] Master antwortet mit Statuscode {res.status_code}: {res.text[:200]}"
            )

    except requests.exceptions.RequestException as e:
        consecutive_failures += 1
        log_error_deduped(
            "master_connection",
            f"{type(e).__name__}",
            f"[FEHLER] Keine Verbindung zum Master ({SERVER_URL}): {e}"
        )
        # Bei anhaltender Stoerung (nicht schon beim ersten Fehlversuch) auf den lokalen
        # Offline-Fallback umschalten - Rotation/Inhalt laufen dann unabhaengig vom Master
        # weiter, solange etwas Gecachtes vorhanden ist.
        if consecutive_failures >= FALLBACK_SWITCH_THRESHOLD and last_known_outputs_cfg:
            if not local_fallback_active:
                log(f"🔀 Verbindung seit {consecutive_failures} Versuchen gestoert - schalte auf lokalen Offline-Fallback um.")
                local_fallback_active = True
            fb_env2, fb_runtime_dir2 = get_wayland_env()
            fb_user2 = get_desktop_user(fb_runtime_dir2)
            apply_local_fallback(fb_env2, fb_user2, fb_runtime_dir2)
        if not have_fresh_data and not fallback_applied:
            cached = load_cached_config()
            if cached and cached.get("outputs"):
                cached_outputs = cached.get("outputs", {})
                last_known_outputs_cfg = cached_outputs
                LOCAL_SCHEDULE_CHANGES = cached.get("schedule_changes", [])
                _fallback_state["screens"] = extract_screens_map(cached_outputs)
                log("⚠️  Keine aktuelle Konfiguration verfuegbar.")
                log("Kiosk-Modus wird gleich mit der letzten bekannten Konfiguration gestartet:")
                for out, cfg in cached_outputs.items():
                    log(f"   {describe_output(out, cfg)}")
                for i in range(FALLBACK_WAIT_SECONDS, 0, -1):
                    log(f"   Start in {i}s ... (Strg+C zum Abbrechen)")
                    time.sleep(1)
                fb_env, fb_runtime_dir = get_wayland_env()
                fb_user = get_desktop_user(fb_runtime_dir)
                for out, cfg in cached_outputs.items():
                    apply_output_state(out, cfg, fb_env, fb_user, fb_runtime_dir, active_processes, last_states, debug_ports, consecutive_output_failures)
                fallback_applied = True
                log("Kiosk mit letzter bekannter Konfiguration gestartet. Versuche weiter, den Master zu erreichen...")
            else:
                log("Keine zwischengespeicherte Konfiguration vorhanden - warte auf ersten Kontakt zum Master...")
    except Exception as e:
        consecutive_failures += 1
        log_error_deduped("critical", f"{type(e).__name__}:{e}", f"[KRITISCHER FEHLER] {e}")

    # Bis zum naechsten Zyklus warten.
    time.sleep(next_poll_interval())
<?php
    return ob_get_clean();
}

// =========================================================================
// FREIGEGEBENE ENDPUNKTE (Ausschluss von Session-Check für Pis)
// =========================================================================

// 1. INSTALLER SCRIPT GENERATOR
if (isset($_GET['install'])) {
    header('Content-Type: text/x-shellscript');

    echo "#!/bin/bash\n";
    echo "echo '🚀 Starte Kiosk-Client Installation...'\n";
    echo "sudo apt update && sudo apt install -y python3 python3-requests python3-evdev wlr-randr curl\n";
    // Paketname fuer Chromium ist je nach Raspberry Pi OS Version unterschiedlich
    // (Bullseye: chromium-browser, Bookworm/Debian: oft nur chromium). Beides versuchen,
    // damit die Installation nicht an einem falschen Paketnamen scheitert.
    echo "if ! sudo apt install -y chromium-browser; then\n";
    echo "  echo '⚠️  Paket chromium-browser nicht gefunden, versuche stattdessen chromium...'\n";
    echo "  sudo apt install -y chromium\n";
    echo "fi\n";
    echo "if command -v chromium-browser > /dev/null || command -v chromium > /dev/null; then\n";
    echo "  echo '✅ Chromium-Binary gefunden: '\$(command -v chromium-browser || command -v chromium)\n";
    echo "else\n";
    echo "  echo '❌ FEHLER: Kein Chromium-Binary nach der Installation gefunden! Kiosk kann so nicht starten.'\n";
    echo "fi\n";
    echo "sudo mkdir -p /home/pi/kiosk_system\n";
    echo "if [ \$? -ne 0 ]; then echo '❌ FEHLER: Verzeichnis /home/pi/kiosk_system konnte nicht angelegt werden (sudo-Rechte?).'; fi\n\n";

    // Eine (bewusste) Neuinstallation soll den Kiosk immer wieder normal starten lassen -
    // eine von einem frueheren Test uebrig gebliebene Wartungsmodus-Markierung wuerde sonst
    // stillschweigend weiter verhindern, dass Chromium hochkommt.
    echo "if [ -f /home/pi/kiosk_system/maintenance_mode ]; then\n";
    echo "  sudo rm -f /home/pi/kiosk_system/maintenance_mode\n";
    echo "  echo '🔧 Alte Wartungsmodus-Markierung von einem frueheren Lauf entfernt.'\n";
    echo "fi\n\n";

    // WICHTIG: Journal erzwungen fluechtig (RAM, tmpfs) + auf 20MB gedeckelt.
    // Dadurch landen die Debug-Logs NIE auf der SD-Karte und wachsen nicht dauerhaft -
    // live mitlesen geht trotzdem per "journalctl -f" (liest aus dem RAM-Ringpuffer).
    echo "sudo mkdir -p /etc/systemd/journald.conf.d\n";
    echo "cat << 'EOF' | sudo tee /etc/systemd/journald.conf.d/kiosk-volatile.conf > /dev/null\n";
    echo "[Journal]\nStorage=volatile\nRuntimeMaxUse=20M\nEOF\n";
    echo "sudo systemctl restart systemd-journald && echo '✅ Journal auf fluechtig (RAM, max 20MB) gesetzt.' || echo '❌ FEHLER: Journal-Konfiguration fehlgeschlagen.'\n\n";

    echo "cat << 'EOF' | sudo tee /home/pi/kiosk_system/client.py > /dev/null\n";
    echo renderClientPySource($api_endpoint, $update_endpoint, $report_endpoint, $media_list_endpoint, $media_base_url, (int)($config['required_outputs'] ?? 2));
    echo "\nEOF\n";
    echo "if [ \$? -eq 0 ]; then echo '✅ client.py erfolgreich geschrieben.'; else echo '❌ FEHLER: client.py konnte NICHT geschrieben werden!'; fi\n";
    echo "sudo chmod +x /home/pi/kiosk_system/client.py\n\n";
    echo "PYTHON_PATH=$(which python3 || echo '/usr/bin/python3')\n";
    echo "echo \"ℹ️  Gefundener Python-Interpreter: \$PYTHON_PATH\"\n";
    echo "cat << EOF | sudo tee /etc/systemd/system/kiosk_client.service > /dev/null\n";
    // WICHTIG: WantedBy=graphical.target wird auf schlanken Kiosk-Setups (Autologin +
    // Wayland-Compositor direkt aus dem Profil gestartet, KEIN Display-Manager) oft NIE
    // erreicht - der Dienst war dann zwar "enabled", startete aber nie automatisch beim
    // Booten. multi-user.target wird dagegen immer erreicht (Voraussetzung fuer
    // graphical.target). StartLimitIntervalSec=0 + RestartSec sorgen zusaetzlich dafuer,
    // dass systemd niemals dauerhaft aufgibt, falls der Dienst in den ersten Sekunden nach
    // dem Boot ein paarmal fehlschlaegt, waehrend er auf die Wayland-Session wartet.
    echo "[Unit]\nDescription=Kiosk Client Service\nAfter=multi-user.target network.target\nStartLimitIntervalSec=0\n\n";
    echo "[Service]\nType=simple\nUser=root\nExecStart=\$PYTHON_PATH -u /home/pi/kiosk_system/client.py\nRestart=always\nRestartSec=3\nStandardOutput=journal+console\nStandardError=journal+console\n\n";
    echo "[Install]\nWantedBy=multi-user.target\nEOF\n";
    echo "if [ \$? -eq 0 ]; then echo '✅ Service-Datei erfolgreich geschrieben.'; else echo '❌ FEHLER: Service-Datei konnte NICHT geschrieben werden!'; fi\n\n";
    // Alte Enable-Symlinks entfernen, bevor neu enabled wird - falls eine fruehere
    // Installation den Dienst noch unter dem alten WantedBy=graphical.target eingehaengt
    // hatte, sonst bleibt ein verwaister Symlink dort liegen.
    echo "sudo systemctl disable kiosk_client.service > /dev/null 2>&1\n";
    echo "sudo systemctl daemon-reload && echo '✅ daemon-reload OK' || echo '❌ daemon-reload FEHLGESCHLAGEN'\n";
    echo "sudo systemctl enable kiosk_client.service && echo '✅ Service aktiviert (enable)' || echo '❌ enable FEHLGESCHLAGEN'\n";
    echo "sudo systemctl restart kiosk_client.service && echo '✅ Service gestartet (restart)' || echo '❌ restart FEHLGESCHLAGEN - Details: sudo systemctl status kiosk_client.service'\n";
    echo "sleep 2\n";
    echo "sudo systemctl is-active --quiet kiosk_client.service && echo '✅ Service laeuft aktuell.' || echo '⚠️  Service laeuft NICHT - siehe Fehler oben bzw. unten.'\n";
    echo "echo ''\n";
    echo "echo '========================================'\n";
    echo "echo '✅ Installation abgeschlossen!'\n";
    echo "echo '📡 Live-Ansicht: Solange dieses Fenster offen ist, siehst du hier jede Aktion/jeden Fehler des Kiosk-Clients (Ein-/Ausschalten der Displays, Chromium-Start, Verbindungsfehler...).'\n";
    echo "echo '   Mit Strg+C beenden - der Dienst laeuft dann im Hintergrund weiter (kein erneuter Neustart noetig).'\n";
    echo "echo '========================================'\n";
    echo "sudo journalctl -u kiosk_client.service -f --no-pager\n";
    exit;
}

// $config wurde bereits ganz oben geladen (wird auch vom Installer-Generator gebraucht).
$clients = file_exists($clientsFile) ? json_decode(file_get_contents($clientsFile), true) : [];

// 1b. AUTO-UPDATE-ENDPUNKT: liefert die aktuelle client.py roh aus, damit laufende Pis
// sich selbst aktualisieren koennen, ohne dass jemand vor Ort den Installer erneut ausfuehren muss.
if (isset($_GET['api']) && $_GET['api'] === 'client_script') {
    header('Content-Type: text/plain; charset=utf-8');
    echo renderClientPySource($api_endpoint, $update_endpoint, $report_endpoint, $media_list_endpoint, $media_base_url, (int)($config['required_outputs'] ?? 2));
    exit;
}

// 1c. FEHLERBERICHT-ENDPUNKT: nimmt bei einem Fehler auf dem Pi automatisch die letzten 40
// Logzeilen entgegen, damit man sie ueber Infomaster nachschauen kann, ohne sich erst per
// SSH/journalctl auf den Pi verbinden zu muessen. Pro Pi werden nur die letzten 10 behalten.
if (isset($_GET['api']) && $_GET['api'] === 'error_report') {
    header('Content-Type: application/json');
    $clientId = trim($_POST['client_id'] ?? '');
    if ($clientId !== '') {
        $reports = file_exists($reportsFile) ? (json_decode(file_get_contents($reportsFile), true) ?: []) : [];
        if (!isset($reports[$clientId])) $reports[$clientId] = [];
        array_unshift($reports[$clientId], [
            'time' => time(),
            'reason' => substr($_POST['reason'] ?? '', 0, 300),
            'log' => substr($_POST['log'] ?? '', 0, 8000)
        ]);
        $reports[$clientId] = array_slice($reports[$clientId], 0, 10);
        file_put_contents($reportsFile, json_encode($reports, JSON_PRETTY_PRINT));
    }
    echo json_encode(['ok' => true]);
    exit;
}

// 1e. MEDIEN-LISTE: liefert die Dateiliste eines Ordners fuer den lokalen Medien-Sync der
// Pis (Baustein "lokaler Datei-Proxy"). Respektiert dieselbe Sichtbarkeits-Einstellung wie
// die normale Wiedergabe (per Checkbox ausgeblendete Dateien tauchen auch hier nicht auf).
if (isset($_GET['api']) && $_GET['api'] === 'media_list') {
    header('Content-Type: application/json');
    $folder = basename($_GET['folder'] ?? '');
    $files = [];
    if ($folder !== '') {
        $path = $uploadBase . $folder;
        if (is_dir($path)) {
            $hiddenFiles = $config['folder_hidden_files'][$folder] ?? [];
            $allowedMediaExt = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];
            foreach (array_diff(scandir($path), ['.', '..']) as $f) {
                if (in_array($f, $hiddenFiles)) continue;
                $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
                if (in_array($ext, $allowedMediaExt)) $files[] = $f;
            }
        }
    }
    echo json_encode($files);
    exit;
}


// 2. API-ENDPUNKT FÜR PING
if (isset($_GET['api']) && $_GET['api'] === 'ping') {
    header('Content-Type: application/json');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $ip = $_POST['ip'] ?? $_SERVER['REMOTE_ADDR'];
        // Persistente Client-ID (z.B. /etc/machine-id) - übersteht einen IP-Wechsel per DHCP.
        // Fallback "ip-<IP>" für alte Clients ohne ID-Unterstützung.
        $clientId = trim($_POST['client_id'] ?? '');
        if ($clientId === '') $clientId = 'ip-' . $ip;

        $outputs = isset($_POST['outputs']) ? json_decode($_POST['outputs'], true) : ['HDMI-A-1', 'HDMI-A-2'];
        if (!is_array($outputs)) $outputs = ['HDMI-A-1', 'HDMI-A-2'];

        $statusResult = computeGlobalOff($config);
        $isGlobalOff = $statusResult['off'];

        // Migration: einen alten, nur per IP bekannten Eintrag auf die neue Client-ID
        // umziehen, damit bestehende Konfiguration nach einem Client-Update erhalten bleibt.
        if (!isset($clients[$clientId])) {
            foreach ($clients as $oldKey => $oldEntry) {
                if (($oldEntry['ip'] ?? null) === $ip && empty($oldEntry['client_id'])) {
                    $clients[$clientId] = $oldEntry;
                    unset($clients[$oldKey]);
                    break;
                }
            }
        }

        if (!isset($clients[$clientId])) { $clients[$clientId] = ['displays' => []]; }
        $clients[$clientId]['client_id'] = $clientId;
        $clients[$clientId]['ip'] = $ip;
        $clients[$clientId]['last_seen'] = time();
        $clients[$clientId]['detected_outputs'] = $outputs;

        // Monitor-Eigenschaften (Aufloesung/Hz/Groesse/Modell) je Ausgang - rein informativ,
        // beeinflusst die Steuerung nicht. Bewusst mit expliziten isset()-Pruefungen statt
        // verketteter Array-Zugriffe, und jeder Wert einzeln als String validiert, bevor er
        // gespeichert wird.
        if (isset($_POST['output_details'])) {
            $decodedDetails = json_decode($_POST['output_details'], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decodedDetails)) {
                $cleanDetails = [];
                foreach ($decodedDetails as $outName => $props) {
                    if (!is_string($outName) || !is_array($props)) continue;
                    $cleanEntry = [];
                    foreach (['resolution', 'refresh', 'size', 'model'] as $key) {
                        if (isset($props[$key]) && is_string($props[$key])) {
                            $cleanEntry[$key] = substr($props[$key], 0, 80);
                        }
                    }
                    $cleanDetails[$outName] = $cleanEntry;
                }
                $clients[$clientId]['output_details'] = $cleanDetails;
            }
        }
        $clients[$clientId]['maintenance'] = !empty($_POST['maintenance']);

        // Aktuelle Fehlversuchs-Zaehler je Ausgang (fuer die "Problem"-Anzeige im Dashboard) -
        // gleiche defensive Validierung wie bei output_details: nur numerische Werte, sonst
        // wird der Eintrag einfach uebersprungen statt die ganze Meldung zu verwerfen.
        if (isset($_POST['output_failures'])) {
            $decodedFailures = json_decode($_POST['output_failures'], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decodedFailures)) {
                $cleanFailures = [];
                foreach ($decodedFailures as $outName => $count) {
                    if (!is_string($outName)) continue;
                    if (is_numeric($count)) {
                        $cleanFailures[$outName] = (int)$count;
                    }
                }
                $clients[$clientId]['output_failures'] = $cleanFailures;
            }
        }
        // Ausstehender Neustart-Wunsch aus dem Dashboard: jetzt auslesen und SOFORT loeschen
        // (nicht erst nach dem Senden), damit ein wiederholter Ping - egal was danach noch
        // schiefgeht - niemals zu einer zweiten Neustart-Anweisung fuehrt.
        $rebootNow = !empty($clients[$clientId]['reboot_requested']);
        if ($rebootNow) {
            unset($clients[$clientId]['reboot_requested']);
        }
        if (empty($clients[$clientId]['name'])) {
            // Selbstheilend: falls der Pi sich noch an einen frueher zugewiesenen Namen
            // erinnert (lokal gecacht), z.B. weil clients.json auf dem Server verloren ging
            // oder der Client neu migriert wurde, diesen uebernehmen statt des generischen
            // IP-Platzhalters. Alte Client-Versionen senden dieses Feld nicht - dann greift
            // einfach wie bisher der IP-Platzhalter.
            $reportedName = trim($_POST['assigned_name'] ?? '');
            $clients[$clientId]['name'] = $reportedName !== '' ? $reportedName : ('Raspberry Pi (' . $ip . ')');
        }

        $responseOutputs = [];
        foreach ($outputs as $out) {
            if (!isset($clients[$clientId]['displays'][$out])) {
                $clients[$clientId]['displays'][$out] = '1';
            }
            $assignedMode = $isGlobalOff ? 'off' : $clients[$clientId]['displays'][$out];

            // Dynamisch statt fest auf "1","2","3" beschränkt: jede vorhandene Screen-ID
            // aus config.json (auch ein 4. o.ä. Monitor) wird automatisch unterstützt.
            $targetUrl = "";
            if ($assignedMode !== 'off' && $assignedMode !== 'desktop' && isset($config['screens'][$assignedMode])) {
                $targetUrl = $serverDirUrl . "view.php?id=" . $assignedMode;
            }

            $responseOutputs[$out] = [
                'mode' => $assignedMode,
                'url' => $targetUrl,
                // Volle Screen-Definition mitschicken (nicht nur Modus+URL), damit der Pi
                // bei einem Master-Ausfall lokal genau dieselbe Rotation/Split/Inhalt-Logik
                // nachbilden kann, statt nur eine nackte URL zu haben.
                'screen' => ($targetUrl !== '' && isset($config['screens'][$assignedMode])) ? $config['screens'][$assignedMode] : null
            ];
        }

        file_put_contents($clientsFile, json_encode($clients, JSON_PRETTY_PRINT));

        // "Silent unless changed": last_seen/Online-Status wird IMMER aktualisiert (oben,
        // file_put_contents), aber die vollen Ausgabe-Daten nur gesendet, wenn sie sich
        // gegenueber dem letzten bekannten Stand des Pi geaendert haben - oder wenn der Pi
        // alle 30 Minuten einen erzwungenen Heartbeat schickt (haelt last_seen zuverlaessig
        // frisch, auch wenn inhaltlich nichts passiert). Eine "unchanged"-Antwort ist immer
        // noch eine echte, sofortige HTTP-Antwort - im Unterschied zu einem Verbindungsfehler
        // bleibt sie fuer den Pi eindeutig von einem echten Ausfall unterscheidbar.
        $fullBody = [
            'global_off' => $isGlobalOff,
            'outputs' => $responseOutputs,
            'name' => $clients[$clientId]['name'] ?? null,
            // Fuer den lokalen Fallback auf dem Pi: die naechsten AN/AUS-Zeitpunkte als
            // konkrete Zeitstempel, damit der Zeitplan auch ohne Master-Verbindung
            // weitergefuehrt werden kann.
            'schedule_changes' => computeUpcomingScheduleChanges($config)
        ];
        $bodyHash = md5(json_encode($fullBody));
        $knownHash = $_POST['known_hash'] ?? '';
        // Ein ausstehender Neustart wird wie ein erzwungener Heartbeat behandelt - die
        // "unchanged"-Kurzantwort darf eine Neustart-Anweisung NIE stillschweigend verschlucken.
        $isHeartbeat = !empty($_POST['heartbeat']) || $rebootNow;

        if (!$isHeartbeat && $knownHash === $bodyHash) {
            echo json_encode(['unchanged' => true, 'hash' => $bodyHash]);
        } else {
            $fullBody['hash'] = $bodyHash;
            if ($rebootNow) {
                $fullBody['reboot'] = true;
            }
            echo json_encode($fullBody);
        }
    }
    exit;
}

// =========================================================================
// ZUGRIFFSBESCHRÄNKTER BEREICH
// =========================================================================

if (isset($_GET['rolle'])) {
    $rolleParam = $_GET['rolle'];
    if ($rolleParam === $secret_param) {
        // Master-Rolle wie bisher. Wechselt die Identitaet gegenueber der Session, muss das
        // Passwort erneut eingegeben werden - sonst koennte man sich ueber einen alten
        // eingeloggten Zustand eines anderen Nutzers in die Master-Rolle "hineinlinken".
        if (array_key_exists('auth_user', $_SESSION) && $_SESSION['auth_user'] !== null) {
            $_SESSION['loggedin'] = false;
        }
        $_SESSION['role_authorized'] = true;
        $_SESSION['auth_user'] = null; // null = Master
        header("Location: infomaster.php"); exit;
    } elseif (isset($users[$rolleParam])) {
        // ?rolle=<benutzername> identifiziert jetzt zusaetzlich einen einzelnen Nutzer.
        if (($_SESSION['auth_user'] ?? null) !== $rolleParam) {
            $_SESSION['loggedin'] = false;
        }
        $_SESSION['role_authorized'] = true;
        $_SESSION['auth_user'] = $rolleParam;
        header("Location: infomaster.php"); exit;
    } else {
        header('HTTP/1.0 403 Forbidden'); die("403 Forbidden");
    }
}
if (!isset($_SESSION['role_authorized']) && !$allowUsernameLogin) {
    // Ohne den geheimen ?rolle=-Parameter geht nichts - AUSSER vip.txt erlaubt
    // ausdruecklich den Login per eingegebenem Benutzernamen (siehe unten); dann muss die
    // Sperre hier durchlaessig sein, sonst kaeme man nie bis zum Login-Formular.
    header('HTTP/1.0 403 Forbidden'); die("403 Forbidden: Missing Role Authorization");
}

// Login per eingegebenem Benutzernamen (nur wenn in vip.txt per allow_username_login=1
// erlaubt) - Master entscheidet also selbst, ob der Zugang OHNE den geheimen ?rolle=-
// URL-Parameter moeglich sein soll.
if ($allowUsernameLogin && isset($_POST['login_user']) && trim($_POST['login_user']) !== '') {
    $loginUser = trim($_POST['login_user']);
    if (isset($users[$loginUser])) {
        if (($_SESSION['auth_user'] ?? null) !== $loginUser) {
            $_SESSION['loggedin'] = false;
        }
        $_SESSION['role_authorized'] = true;
        $_SESSION['auth_user'] = $loginUser;
    }
}

if (isset($_POST['login_pass'])) {
    $authUser = array_key_exists('auth_user', $_SESSION) ? $_SESSION['auth_user'] : null;
    $passwordOk = false;
    if ($authUser === null) {
        $passwordOk = ($_POST['login_pass'] === $masterPassword);
    } elseif (isset($users[$authUser]['password_hash'])) {
        $passwordOk = password_verify($_POST['login_pass'], $users[$authUser]['password_hash']);
    }
    if ($passwordOk) {
        $_SESSION['loggedin'] = true;
        $_SESSION['role_authorized'] = true; // fuer den Fall, dass nur per Benutzername (ohne ?rolle=) eingeloggt wurde
        header("Location: infomaster.php"); exit;
    }
}
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: infomaster.php"); exit;
}

// =========================================================================
// AJAX-SCHICHT: hebt ALLE POST-Formulare (Login inklusive) auf fetch() statt
// eines vollen Seiten-Neuladens - inklusive sichtbarem Feedback (Toast) bei
// Erfolg/Fehler. Bewusst als generischer Formular-Interceptor statt jedes der
// ~30 Formulare einzeln umzuschreiben: der Server rendert nach jedem POST
// (Redirect wird von fetch() automatisch verfolgt) ohnehin die komplette
// Seite neu - der Interceptor ersetzt nach dem Request nur noch <body> durch
// die neu gerenderte Version, statt dass der Browser komplett neu laedt.
// In EINER Funktion gehalten, damit Login-Seite und Dashboard exakt denselben
// Code laden (gleiches Verhalten ueberall, keine Doppelpflege).
function renderAjaxBootstrap() {
    return <<<'HTML'
<style>
#im-toast-stack{position:fixed;top:16px;right:16px;z-index:9999;display:flex;flex-direction:column;gap:8px;pointer-events:none;max-width:320px;}
.im-toast{background:#16a34a;color:#fff;padding:10px 16px;border-radius:8px;font-size:13px;font-weight:600;box-shadow:0 6px 20px rgba(0,0,0,.35);opacity:0;transform:translateY(-8px);transition:opacity .25s ease,transform .25s ease;}
.im-toast.show{opacity:1;transform:translateY(0);}
.im-toast.warn{background:#d97706;}
.im-toast.error{background:#dc2626;}
@keyframes im-shake{10%,90%{transform:translateX(-1px);}20%,80%{transform:translateX(2px);}30%,50%,70%{transform:translateX(-4px);}40%,60%{transform:translateX(4px);}}
.im-shake{animation:im-shake .5s;}
</style>
<script>
(function(){
  function ensureStack(){
    var s = document.getElementById('im-toast-stack');
    if(!s){ s=document.createElement('div'); s.id='im-toast-stack'; document.body.appendChild(s); }
    return s;
  }
  function showToast(text, kind){
    var stack = ensureStack();
    var el = document.createElement('div');
    el.className = 'im-toast' + (kind==='error'?' error':kind==='warn'?' warn':'');
    el.textContent = text;
    stack.appendChild(el);
    requestAnimationFrame(function(){ el.classList.add('show'); });
    setTimeout(function(){ el.classList.remove('show'); setTimeout(function(){ el.remove(); },300); }, 2600);
  }

  function runScripts(list, appendTo){
    Array.prototype.forEach.call(list, function(old){
      var type = (old.getAttribute('type')||'').toLowerCase();
      if (type && type!=='text/javascript' && type!=='application/javascript' && type!=='module') return;
      var s = document.createElement('script');
      Array.prototype.forEach.call(old.attributes, function(a){ s.setAttribute(a.name, a.value); });
      s.textContent = old.textContent;
      appendTo.appendChild(s);
    });
  }

  function applyNewDocument(newDoc){
    if (newDoc.title) document.title = newDoc.title;
    Array.prototype.forEach.call(Array.prototype.slice.call(document.body.attributes), function(a){ document.body.removeAttribute(a.name); });
    Array.prototype.forEach.call(newDoc.body.attributes, function(a){ document.body.setAttribute(a.name, a.value); });
    document.body.innerHTML = newDoc.body.innerHTML;
    // Kopf-Skripte (Funktionsdefinitionen wie toggleInput/applyPresetIfMatch) IMMER erneut
    // ausfuehren - wichtig z.B. direkt nach dem Login, wo die Dashboard-Funktionen vorher
    // noch nie geladen wurden. Nur Funktionsdeklarationen dort (kein top-level const/let
    // mehr), daher unschaedlich mehrfach auszufuehren; der Submit-Listener selbst schuetzt
    // sich separat gegen Mehrfachregistrierung (siehe __imAjaxBootstrapped unten).
    runScripts(newDoc.head.querySelectorAll('script'), document.head);
    runScripts(document.body.querySelectorAll('script'), document.head);
  }

  function feedbackFor(form, submitter){
    var sName = submitter && submitter.name ? submitter.name : '';
    var isDelete = /^del_/.test(sName) || !!form.querySelector('input[type=hidden][name^="del_"]');
    if (isDelete) return {text:'🗑 Gelöscht', kind:'warn'};
    if (form.querySelector('input[type=file]')) return {text:'📤 Datei hochgeladen', kind:'success'};
    var map = {
      toggle_playback_source: '🔀 Wiedergabequelle umgeschaltet',
      reboot_client: '🔁 Neustart angefordert',
      save_preset_slot: '💾 Preset gespeichert',
      save_pi_clients: '✓ Pi-Konfiguration gespeichert',
      save_schedule: '✓ Zeitplan gespeichert',
      add_holiday: '📅 Termin hinzugefügt',
      del_holidays_bulk: '🗑 Termine gelöscht',
      import_ics: '🔄 Kalender geladen',
      sync_ics: '🔄 Kalender synchronisiert',
      new_folder: '📁 Ordner angelegt',
      save_folder_visibility: '👁 Sichtbarkeit gespeichert',
      screen_id: '✓ Layout gespeichert',
      save_required_outputs: '✓ Gespeichert',
      add_user: '✓ Nutzer angelegt',
      del_user: '🗑 Nutzer gelöscht',
      time_offset_delta: '✓ Zeitkorrektur gespeichert'
    };
    if (sName && map[sName]) return {text: map[sName], kind: 'success'};
    for (var key in map) {
      if (form.querySelector('input[name="'+key+'"]')) {
        return {text: map[key], kind: key.indexOf('del_')===0 ? 'warn' : 'success'};
      }
    }
    return {text:'✓ Gespeichert', kind:'success'};
  }

  async function handleSubmit(e){
    var form = e.target;
    if (!(form instanceof HTMLFormElement)) return;
    if ((form.getAttribute('method')||'get').toLowerCase() !== 'post') return;
    if (form.hasAttribute('data-no-ajax')) return;
    if (e.defaultPrevented) return; // z.B. durch ein onsubmit="return confirm(...)" mit "Abbrechen" bereits verhindert
    e.preventDefault();
    var submitter = e.submitter || null;
    var wasLoginPage = document.body.dataset.imPage === 'login';
    var fb = wasLoginPage ? null : feedbackFor(form, submitter);
    var fd = new FormData(form);
    if (submitter && submitter.name) fd.set(submitter.name, submitter.value);
    var resp, text;
    try {
      resp = await fetch(form.getAttribute('action') || location.href, { method: 'POST', body: fd });
      text = await resp.text();
    } catch (err) {
      console.error(err);
      showToast('⚠️ Verbindungsfehler – bitte erneut versuchen', 'error');
      return;
    }
    var newDoc = new DOMParser().parseFromString(text, 'text/html');
    var modalReopen = resp.url && resp.url.indexOf('#access-modal-reopen') !== -1;
    applyNewDocument(newDoc);
    var nowLoginPage = document.body.dataset.imPage === 'login';
    if (wasLoginPage) {
      if (nowLoginPage) {
        showToast('❌ Login fehlgeschlagen', 'error');
        var form2 = document.querySelector('form');
        if (form2) { form2.classList.add('im-shake'); setTimeout(function(){ form2.classList.remove('im-shake'); }, 550); }
        var pw = document.querySelector('input[name="login_pass"]');
        if (pw) { pw.focus(); pw.select(); }
      } else {
        showToast('✓ Angemeldet', 'success');
      }
    } else if (nowLoginPage) {
      // War eingeloggt, ist es nach diesem Request aber nicht mehr (Sitzung
      // zwischenzeitlich abgelaufen) - die gesendeten Daten wurden dann NICHT
      // gespeichert, das muss auch so kommuniziert werden statt "Gespeichert".
      showToast('⏳ Sitzung abgelaufen – bitte erneut anmelden', 'error');
    } else {
      showToast(fb.text, fb.kind);
      if (modalReopen) {
        var modal = document.getElementById('access-modal');
        if (modal) modal.style.display = 'flex';
      }
    }
  }

  if (!window.__imAjaxBootstrapped) {
    window.__imAjaxBootstrapped = true;
    document.addEventListener('submit', handleSubmit, false);
  }
})();
</script>
HTML;
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    $loginUserField = $allowUsernameLogin
        ? '<input type="text" name="login_user" placeholder="Benutzername (leer = Master)" style="padding:10px;width:200px;display:block;margin:0 auto 10px;">'
        : '';
    die('<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"><title>Login – Infoscreens Master Hub</title>' . renderAjaxBootstrap() . '</head><body data-im-page="login" style="background:#121212;color:white;font-family:sans-serif;display:flex;justify-content:center;align-items:center;height:100vh;margin:0;"><form method="POST" style="background:#1e1e1e;padding:40px;border-radius:12px;border:1px solid #333;text-align:center;"><h2>Login</h2>' . $loginUserField . '<input type="password" name="login_pass" autofocus style="padding:10px;width:200px;"><br><br><button type="submit" style="padding:10px 20px;background:#e91e63;color:white;border:none;border-radius:6px;cursor:pointer;font-weight:bold;">Login</button></form></body></html>');
}

if (!is_dir($uploadBase)) { @mkdir($uploadBase, 0775, true); }

// Fehlerberichte der Pis laden (nur fuer den authentifizierten Admin-Bereich relevant).
$errorReports = file_exists($reportsFile) ? (json_decode(file_get_contents($reportsFile), true) ?: []) : [];

if (!isset($config['screens'])) {
    $config["screens"] = [];
    for($i=1; $i<=3; $i++) {
        $config["screens"][$i] = ["type"=>"url", "content"=>"", "orient"=>"0", "split"=>"none", "typeB"=>"url", "contentB"=>"", "duration"=>"10"];
    }
}
if (!isset($config['schedule'])) {
    $config['schedule'] = ['active_days' => ["1","2","3","4","5","6","7"], 'time_start' => "00:00", 'time_end' => "23:59"];
}
if (isset($_POST['save_required_outputs'])) {
    $config['required_outputs'] = max(1, (int)($_POST['required_outputs'] ?? 2));
    file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT));
    header("Location: infomaster.php"); exit;
}
if (isset($_POST['time_offset_delta'])) {
    // Manuelle Korrektur, falls die Serverzeit (z.B. durch Hosting-Zeitzoneneinstellungen)
    // von der tatsaechlichen lokalen Zeit abweicht - wirkt sich sowohl auf die Anzeige als
    // auch auf die Zeitplan-/Ferien-Auswertung (computeGlobalOff) aus.
    $config['time_offset_hours'] = (float)($config['time_offset_hours'] ?? 0) + (float)$_POST['time_offset_delta'];
    file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT));
    header("Location: infomaster.php"); exit;
}
if (isset($_POST['add_user']) && !empty($_POST['new_username']) && !empty($_POST['new_user_password']) && ($_SESSION['auth_user'] ?? null) === null) {
    $newUsername = trim($_POST['new_username']);
    // Nie "actionhero" (Master-Geheimnis) oder einen bereits vergebenen Namen ueberschreiben.
    if ($newUsername !== $secret_param && $newUsername !== '') {
        $users[$newUsername] = [
            'name' => trim($_POST['new_user_display_name'] ?? '') !== '' ? trim($_POST['new_user_display_name']) : $newUsername,
            'password_hash' => password_hash($_POST['new_user_password'], PASSWORD_DEFAULT)
        ];
        file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT));
    }
    header("Location: infomaster.php#access-modal-reopen"); exit;
}
if (isset($_POST['del_user']) && !empty($_POST['del_username']) && ($_SESSION['auth_user'] ?? null) === null) {
    unset($users[$_POST['del_username']]);
    file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT));
    header("Location: infomaster.php#access-modal-reopen"); exit;
}
if (isset($_POST['reboot_client']) && !empty($_POST['reboot_client']) && isset($clients[$_POST['reboot_client']])) {
    // Wird nicht sofort ausgefuehrt (der Master hat keinen direkten Weg zum Pi) - der Pi
    // fragt selbst regelmaessig nach und erhaelt die Anweisung beim naechsten Ping (bis zu
    // 60s). Das Flag wird dort ausgelesen und sofort wieder geloescht, damit kein
    // Neustart-Loop entsteht.
    $clients[$_POST['reboot_client']]['reboot_requested'] = true;
    file_put_contents($clientsFile, json_encode($clients, JSON_PRETTY_PRINT));
    header("Location: infomaster.php"); exit;
}

// Legt einen neuen Monitor/Screen mit der naechsten freien ID an - zentral hier, damit der
// separate "Hinzufuegen"-Button UND die Option direkt im Zuordnungs-Dropdown der Pi-
// Ausgaenge dieselbe Logik nutzen.
function createNewScreen(&$config) {
    $newId = 1;
    while (isset($config['screens'][$newId])) { $newId++; }
    $config['screens'][$newId] = ["type"=>"url", "content"=>"", "orient"=>"0", "split"=>"none", "typeB"=>"url", "contentB"=>"", "duration"=>"10", "playback_source"=>"local"];
    return $newId;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['save_pi_clients'])) {
        foreach ($_POST['client_display'] as $clientId => $displays) {
            if (isset($clients[$clientId])) {
                foreach ($displays as $out => $mode) {
                    if ($mode === '__new__') {
                        // "+ Neuen Monitor hinzufuegen" direkt aus dem Zuordnungs-Dropdown
                        // gewaehlt: neuen Screen anlegen und diesen Ausgang gleich damit
                        // verknuepfen, statt erst separat einen Monitor anzulegen und dann
                        // nochmal zuzuordnen.
                        $mode = (string)createNewScreen($config);
                    }
                    $clients[$clientId]['displays'][$out] = $mode;
                }
            }
        }
        if (isset($_POST['client_name']) && is_array($_POST['client_name'])) {
            foreach ($_POST['client_name'] as $clientId => $name) {
                if (isset($clients[$clientId])) {
                    $clients[$clientId]['name'] = trim($name);
                }
            }
        }
        if (!empty($_POST['del_client']) && is_array($_POST['del_client'])) {
            foreach ($_POST['del_client'] as $delId) {
                unset($clients[$delId]);
            }
        }
        file_put_contents($clientsFile, json_encode($clients, JSON_PRETTY_PRINT));
        file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT)); // falls ein neuer Screen angelegt wurde
    }

    if (!empty($_POST['new_folder'])) {
        $name = preg_replace("/[^a-zA-Z0-9-_]/", "", $_POST['new_folder']);
        if($name !== "") @mkdir($uploadBase . $name, 0775, true);
    }
    if (!empty($_POST['del_folder'])) {
        $safeFolder = basename($_POST['del_folder']);
        $dir = $uploadBase . $safeFolder;
        if(is_dir($dir) && $safeFolder !== "") { array_map('unlink', glob("$dir/*.*")); @rmdir($dir); }
    }
    if (!empty($_POST['del_file']) && !empty($_POST['from_folder'])) {
        $safeFile = basename($_POST['del_file']);
        $safeFolder = basename($_POST['from_folder']);
        $targetPath = $uploadBase . $safeFolder . "/" . $safeFile;
        if(file_exists($targetPath)) unlink($targetPath);
    }
    if (isset($_POST['save_folder_visibility']) && !empty($_POST['folder_name'])) {
        // Checkbox pro Datei: welche Dateien eines Ordners tatsaechlich in der Wiedergabe
        // (view.php) auftauchen sollen. Server ermittelt den vollstaendigen Dateibestand
        // selbst (nicht aus dem Formular uebernommen), damit nichts geloescht wirkt, nur
        // weil eine Checkbox beim Absenden fehlte.
        $safeFolder = basename($_POST['folder_name']);
        $folderPath = $uploadBase . $safeFolder;
        if (is_dir($folderPath)) {
            $allFiles = array_values(array_diff(scandir($folderPath), ['.', '..']));
            $visible = $_POST['visible_files'] ?? [];
            $hidden = array_values(array_diff($allFiles, $visible));
            if (!isset($config['folder_hidden_files'])) $config['folder_hidden_files'] = [];
            $config['folder_hidden_files'][$safeFolder] = $hidden;
            file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT));
        }
    }
    if (!empty($_POST['target_folder']) && !empty($_FILES['file_up']['name'])) {
        $ext = strtolower(pathinfo($_FILES['file_up']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, $allowedExtensions)) {
            $safeFolder = basename($_POST['target_folder']);
            $cleanName = preg_replace("/[^a-zA-Z0-9._-]/", "", $_FILES['file_up']['name']);
            move_uploaded_file($_FILES['file_up']['tmp_name'], $uploadBase . $safeFolder . "/" . time() . "_" . $cleanName);
        }
    }
    if (isset($_POST['screen_id'])) {
        $id = $_POST['screen_id'];
        // playback_source wird ueber den eigenen Umschalt-Button gesetzt (siehe
        // toggle_playback_source unten), nicht ueber dieses Formular - beim Speichern des
        // Layouts also den bisherigen Wert erhalten, statt ihn stillschweigend zurueckzusetzen.
        $existingPlaybackSource = $config['screens'][$id]['playback_source'] ?? 'local';
        $config['screens'][$id] = [
            // Wechselfrequenz darf nie leer/0 sein - clamp auf mind. 1 Sekunde, auch falls
            // das HTML5 "required"-Attribut im Browser umgangen wird.
            'orient' => $_POST['orient'], 'split' => $_POST['split'], 'duration' => max(1, (int)($_POST['duration'] ?? 10)),
            'type' => $_POST['modeA'], 'content' => $_POST['valA'],
            'typeB' => $_POST['modeB'], 'contentB' => $_POST['valB'],
            'playback_source' => $existingPlaybackSource
        ];
        file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT));
    }
    if (!empty($_POST['toggle_playback_source']) && isset($config['screens'][$_POST['toggle_playback_source']])) {
        // Standard ist "lokal" (der Pi spielt aus seinem eigenen, lokal gespiegelten Cache
        // ab); "live" schaltet fuer diesen einen Monitor bewusst auf direkte Server-Anzeige
        // um (z.B. fuer Inhalte, bei denen Aktualitaet wichtiger ist als Ausfallsicherheit).
        $sid = $_POST['toggle_playback_source'];
        $current = $config['screens'][$sid]['playback_source'] ?? 'local';
        $config['screens'][$sid]['playback_source'] = ($current === 'local') ? 'live' : 'local';
        file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT));
        header("Location: infomaster.php"); exit;
    }
    if (!empty($_POST['save_preset_slot'])) {
        // Ein Preset buendelt Inhaltstyp + Wert (URL/Nextcloud-Ordner) UND die Wechsel-
        // frequenz unter einem selbst vergebenen Namen - das Namensfeld dient gleichzeitig
        // als Dropdown zum spaeteren Laden (siehe <datalist> unten). Getrennt nach
        // Ausrichtung gespeichert (Hochkant vs. Quer), da Inhalte oft je nach Ausrichtung
        // unterschiedlich sind.
        $slot = $_POST['save_preset_slot'] === 'B' ? 'B' : 'A';
        $presetName = trim($_POST['preset_name_' . $slot] ?? '');
        if ($presetName !== '') {
            $presetType = $slot === 'A' ? ($_POST['modeA'] ?? 'url') : ($_POST['modeB'] ?? 'url');
            $presetContent = $slot === 'A' ? ($_POST['valA'] ?? '') : ($_POST['valB'] ?? '');
            $presetDuration = max(1, (int)($_POST['duration'] ?? 10));
            $presetOrientGroup = in_array($_POST['orient'] ?? '0', ['90', '270']) ? 'hochkant' : 'quer';
            if (!isset($config['presets'])) $config['presets'] = [];
            $config['presets'][$presetName] = ['type' => $presetType, 'content' => $presetContent, 'duration' => $presetDuration, 'orientation' => $presetOrientGroup];
            file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT));
        }
    }
    if (!empty($_POST['del_preset_slot'])) {
        $slot = $_POST['del_preset_slot'] === 'B' ? 'B' : 'A';
        $presetName = trim($_POST['preset_name_' . $slot] ?? '');
        if ($presetName !== '' && isset($config['presets'][$presetName])) {
            unset($config['presets'][$presetName]);
            file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT));
        }
    }
    if (isset($_POST['add_screen'])) {
        // Neuen Monitor mit der nächsten freien ID anlegen - nötig, damit z.B. bei 4
        // HDMI-Ausgängen auch 4 unterschiedliche Screens zur Auswahl stehen.
        createNewScreen($config);
        file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT));
    }
    if (isset($_POST['save_schedule'])) {
        $config['schedule'] = [
            'active_days' => $_POST['active_days'] ?? [],
            'time_start' => $_POST['time_start'] ?? "00:00",
            'time_end' => $_POST['time_end'] ?? "23:59",
            'holidays' => $config['schedule']['holidays'] ?? [],
            'ics_url' => $config['schedule']['ics_url'] ?? '',
            'last_ics_sync' => $config['schedule']['last_ics_sync'] ?? null
        ];
        file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT));
    }
    if (isset($_POST['add_holiday']) && !empty($_POST['hol_name'])) {
        if (!isset($config['schedule']['holidays'])) $config['schedule']['holidays'] = [];
        $config['schedule']['holidays'][] = ['id' => uniqid(), 'name' => $_POST['hol_name'], 'start_date' => $_POST['hol_start'], 'end_date' => $_POST['hol_end'], 'source' => 'manual'];
        file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT));
    }
    if (isset($_POST['del_holidays_bulk']) && !empty($_POST['holiday_ids'])) {
        $delIds = (array)$_POST['holiday_ids'];
        $config['schedule']['holidays'] = array_values(array_filter($config['schedule']['holidays'], function($h) use ($delIds) { return !in_array($h['id'] ?? '', $delIds); }));
        file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT));
    }
    // ICS laden (neue URL) UND Sync (bestehende URL erneut abrufen) teilen sich dieselbe
    // Logik: bisherige ICS-Eintraege werden ersetzt statt bei jedem Aufruf dupliziert zu
    // werden - manuell angelegte Ferien/Termine bleiben davon unberuehrt.
    $icsUrlToFetch = null;
    if (isset($_POST['import_ics']) && !empty($_POST['ics_url'])) {
        $config['schedule']['ics_url'] = trim($_POST['ics_url']);
        $icsUrlToFetch = $config['schedule']['ics_url'];
    } elseif (isset($_POST['sync_ics']) && !empty($config['schedule']['ics_url'])) {
        $icsUrlToFetch = $config['schedule']['ics_url'];
    }
    if ($icsUrlToFetch !== null) {
        $newIcsHolidays = fetchIcsHolidays($icsUrlToFetch);
        if ($newIcsHolidays === null) {
            $config['schedule']['last_ics_error'] = 'ICS-Feed am ' . date('d.m.Y H:i') . ' nicht erreichbar/leer.';
        } else {
            $manualHolidays = array_values(array_filter($config['schedule']['holidays'] ?? [], function($h) { return ($h['source'] ?? 'manual') !== 'ics'; }));
            $config['schedule']['holidays'] = array_merge($manualHolidays, $newIcsHolidays);
            $config['schedule']['last_ics_sync'] = time();
            unset($config['schedule']['last_ics_error']);
        }
        file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT));
    }
    header("Location: infomaster.php"); exit;
}

// Abgelaufene Ferien/Termine (Bis-Datum in der Vergangenheit) automatisch entfernen -
// laeuft bei jedem Aufruf der Oberflaeche, nicht bei jedem Pi-Ping (dort unnoetig).
if (!empty($config['schedule']['holidays'])) {
    $prunedHolidays = pruneExpiredHolidays($config['schedule']['holidays'], $config);
    if (count($prunedHolidays) !== count($config['schedule']['holidays'])) {
        $config['schedule']['holidays'] = $prunedHolidays;
        file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT));
    }
}

$allFolders = array_filter(glob($uploadBase . '*'), 'is_dir');
$folderNames = array_map('basename', $allFolders);

$install_command = "wget -qO- \"" . $baseUrl . "?install=1\" | bash";

function renderFileManager($folderName, $uploadBase, $label, $hiddenFiles = []) {
    $path = $uploadBase . $folderName;
    if (!is_dir($path)) return;
    $files = array_diff(scandir($path), ['.','..']);
    echo "<div style='background:#151515; padding:15px; border-radius:8px; margin-top:15px; border:1px solid #444;'>";
    echo "<strong style='font-size:13px; color:#4caf50; display:block; margin-bottom:10px;'>Medien in '$folderName' ($label)</strong>";
    echo "<form method='POST' enctype='multipart/form-data' style='display:flex; gap:5px; margin-bottom:10px;'>
            <input type='hidden' name='target_folder' value='".htmlspecialchars($folderName)."'>
            <input type='file' name='file_up' style='font-size:11px; padding:6px; flex:1;' onchange='this.form.requestSubmit()'>
          </form>";
    if (empty($files)) {
        echo "<span style='color:#777; font-size:12px;'>Ordner ist leer.</span></div>";
        return;
    }
    // Checkbox = Datei wird in der Wiedergabe gezeigt (deaktivierte Dateien bleiben auf dem
    // Server, tauchen aber nicht in der Playlist des Monitors auf) - Loeschen einzelner
    // Dateien bleibt eine getrennte, kleine Form pro Zeile (per form="..."-Attribut mit dem
    // Haupt-Formular verzahnt, da Formulare nicht ineinander verschachtelt werden koennen).
    echo "<form method='POST'>";
    echo "<input type='hidden' name='save_folder_visibility' value='1'>";
    echo "<input type='hidden' name='folder_name' value='".htmlspecialchars($folderName)."'>";
    echo "<div style='max-height:220px; overflow-y:auto; font-size:12px;'>";
    foreach($files as $file) {
        $isVisible = !in_array($file, $hiddenFiles);
        $fid = 'delform_' . md5($folderName . '/' . $file);
        echo "<div style='display:flex; justify-content:space-between; align-items:center; padding:4px 0; border-bottom:1px solid #222; gap:8px;'>
                <label style='display:flex; align-items:center; gap:6px; flex:1; cursor:pointer; ".($isVisible ? '' : 'opacity:0.5;')."' title='Datei in der Wiedergabe anzeigen/ausblenden'>
                    <input type='checkbox' name='visible_files[]' value='".htmlspecialchars($file)."' ".($isVisible ? 'checked' : '').">
                    <span>".htmlspecialchars($file)."</span>
                </label>
                <button type='submit' form='$fid' style='background:#ff5252; border:none; color:#fff; padding:2px 6px; border-radius:3px;'>X</button>
              </div>";
    }
    echo "</div>";
    echo "<button type='submit' style='margin-top:8px; background:#38bdf8; color:#000; width:auto; padding:6px 14px;'>👁 Sichtbarkeit speichern</button>";
    echo "</form>";
    foreach ($files as $file) {
        $fid = 'delform_' . md5($folderName . '/' . $file);
        echo "<form method='POST' id='$fid' onsubmit=\"return confirm('".addslashes($file)." wirklich loeschen?');\">
                <input type='hidden' name='del_file' value='".htmlspecialchars($file)."'>
                <input type='hidden' name='from_folder' value='".htmlspecialchars($folderName)."'>
              </form>";
    }
    echo "</div>";
}

function renderPiRow($clientId, $data, $isOnline, $config, $errorReports) {
    $ip = $data['ip'] ?? '?';
    $name = $data['name'] ?? ('Raspberry Pi (' . $ip . ')');
    $requiredOutputs = (int)($config['required_outputs'] ?? 2);
    $outputs = (!empty($data['detected_outputs']) && is_array($data['detected_outputs'])) ? $data['detected_outputs'] : [];
    $missingCount = max(0, $requiredOutputs - count($outputs));
    // Namen fuer noch nicht gemeldete Ausgaenge nach der ueblichen HDMI-A-n-Konvention
    // raten (genau die, die der Client selbst als Standard-Fallback nutzt). Damit lassen
    // sich diese Ausgaenge schon VOR dem ersten Anschluss konfigurieren - die Einstellung
    // liegt dann einfach bereit und greift automatisch, sobald der Pi diesen Output das
    // erste Mal wirklich meldet (siehe Ping-Handler: bestehende Zuordnung wird nicht
    // ueberschrieben). Passt der geratene Name am Ende nicht zum echten, bleibt die
    // Vorkonfiguration einfach ungenutzt liegen - kein Schaden.
    $placeholderOutputs = [];
    for ($i = 1; $i <= $requiredOutputs && count($placeholderOutputs) < $missingCount; $i++) {
        $guess = "HDMI-A-$i";
        if (!in_array($guess, $outputs) && !in_array($guess, $placeholderOutputs)) {
            $placeholderOutputs[] = $guess;
        }
    }
    // Status: gelb = Wartungsmodus (Pi meldet sich, zeigt aber bewusst keinen Inhalt),
    // gruen = online & normaler Kiosk-Betrieb, rot = offline (meldet sich gar nicht mehr).
    $inMaintenance = $isOnline && !empty($data['maintenance']);
    if ($inMaintenance) {
        $statusColor = '#eab308'; $statusLabel = '🔧 Wartung';
    } elseif ($isOnline) {
        $statusColor = '#22c55e'; $statusLabel = '● Online';
    } else {
        $statusColor = '#ef4444'; $statusLabel = '● Offline';
    }
    echo '<div class="pi-row" style="border-top:3px solid ' . $statusColor . ';">';
    echo '<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; gap:10px;">';
    echo '<input type="text" name="client_name[' . htmlspecialchars($clientId) . ']" value="' . htmlspecialchars($name) . '" style="width:auto; flex-grow:1; background:#020617; font-weight:bold; color:#fff;">';
    echo '<span style="font-size:11px; color:#64748b; white-space:nowrap;">IP: ' . htmlspecialchars($ip) . '</span>';
    echo '<span style="font-size:11px; font-weight:bold; white-space:nowrap; color:' . $statusColor . ';">' . $statusLabel . '</span>';
    $reportCount = count($errorReports[$clientId] ?? []);
    if ($reportCount > 0) {
        echo '<a href="?view_reports=' . urlencode($clientId) . '#reports" style="font-size:11px; white-space:nowrap; color:#f59e0b; text-decoration:none; border:1px solid #f59e0b; border-radius:4px; padding:2px 8px;">🚨 ' . $reportCount . ' Fehlerbericht' . ($reportCount > 1 ? 'e' : '') . '</a>';
    }
    if (!empty($data['reboot_requested'])) {
        echo '<span style="font-size:11px; white-space:nowrap; color:#a855f7; border:1px solid #a855f7; border-radius:4px; padding:2px 8px;">🔁 Neustart ausstehend...</span>';
    } else {
        echo '<form method="POST" onsubmit="return confirm(&quot;' . htmlspecialchars(addslashes($name)) . ' wirklich neu starten? Wirkt beim naechsten Kontakt (bis zu 60s).&quot;);" style="margin:0;">';
        echo '<input type="hidden" name="reboot_client" value="' . htmlspecialchars($clientId) . '">';
        echo '<button type="submit" style="width:auto; font-size:11px; padding:2px 10px; background:#1e293b; color:#a855f7; border:1px solid #a855f7;">🔁 Neustart</button>';
        echo '</form>';
    }
    echo '</div>';
    // Flexibles Grid: zeigt automatisch so viele Dropdowns wie Outputs gemeldet wurden (2 oder 4).
    echo '<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap:10px;">';
    foreach ($outputs as $out) {
        $currentMode = $data['displays'][$out] ?? '1';
        echo '<div style="background:#020617; padding:8px; border-radius:4px; border:1px solid #1e293b;">';
        echo '<label style="font-size:11px; color:#38bdf8; font-weight:bold; display:block; margin-bottom:2px;">' . htmlspecialchars($out) . ':</label>';
        echo '<div style="font-size:10px; color:#22c55e; margin-bottom:4px;">✅ Monitor angeschlossen</div>';
        echo renderOutputDetailLine($data, $out);
        echo '<select name="client_display[' . htmlspecialchars($clientId) . '][' . htmlspecialchars($out) . ']" style="font-size:12px; padding:6px;">';
        echo '<option value="off"' . ($currentMode === 'off' ? ' selected' : '') . '>🔴 AUS</option>';
        echo '<option value="desktop"' . ($currentMode === 'desktop' ? ' selected' : '') . '>🖥️ Desktop</option>';
        foreach ($config['screens'] as $sid => $sVal) {
            echo '<option value="' . htmlspecialchars($sid) . '"' . ((string)$currentMode === (string)$sid ? ' selected' : '') . '>📺 Monitor ' . htmlspecialchars($sid) . '</option>';
        }
        echo '<option value="__new__">➕ Neuen Monitor hinzufügen</option>';
        echo '</select>';
        echo '</div>';
    }
    // Noch nicht gemeldete Ausgaenge (z.B. 2. Monitor noch nicht angeschlossen): trotzdem
    // editierbar unter dem geratenen Namen, nur optisch als "wartet auf Anschluss" markiert.
    foreach ($placeholderOutputs as $out) {
        $currentMode = $data['displays'][$out] ?? '1';
        echo '<div style="background:#020617; padding:8px; border-radius:4px; border:1px dashed #334155;">';
        echo '<label style="font-size:11px; color:#64748b; font-weight:bold; display:block; margin-bottom:2px;">' . htmlspecialchars($out) . ':</label>';
        echo '<div style="font-size:10px; color:#eab308; margin-bottom:4px;">⚠️ Kein Monitor angeschlossen</div>';
        echo '<select name="client_display[' . htmlspecialchars($clientId) . '][' . htmlspecialchars($out) . ']" style="font-size:12px; padding:6px;">';
        echo '<option value="off"' . ($currentMode === 'off' ? ' selected' : '') . '>🔴 AUS</option>';
        echo '<option value="desktop"' . ($currentMode === 'desktop' ? ' selected' : '') . '>🖥️ Desktop</option>';
        foreach ($config['screens'] as $sid => $sVal) {
            echo '<option value="' . htmlspecialchars($sid) . '"' . ((string)$currentMode === (string)$sid ? ' selected' : '') . '>📺 Monitor ' . htmlspecialchars($sid) . '</option>';
        }
        echo '<option value="__new__">➕ Neuen Monitor hinzufügen</option>';
        echo '</select>';
        echo '</div>';
    }
    echo '</div>';
    echo '<label style="font-size:10px; color:#475569; display:flex; align-items:center; gap:4px; margin-top:8px;">';
    echo '<input type="checkbox" name="del_client[]" value="' . htmlspecialchars($clientId) . '" style="width:auto;" onclick="return confirm(\'Diesen Pi wirklich dauerhaft entfernen?\');"> Diesen Pi entfernen';
    echo '</label>';
    echo '</div>';
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Infoscreens Master Hub</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #0b0b0b; color: #eee; margin: 0; padding-bottom: 50px; }
        .topbar { background: #1e1e1e; padding: 15px 25px; border-bottom: 3px solid #e91e63; display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .monitors { padding: 0 25px; display: grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap: 20px; }
        .card { background: #1e1e1e; padding: 20px; border-radius: 12px; border: 1px solid #444; margin-bottom: 20px; }
        .card-pi { background: #161e2e; border-color: #38bdf8; }
        input, select, button { width: 100%; padding: 10px; margin: 4px 0; background: #000; color: #fff; border: 1px solid #444; border-radius: 4px; box-sizing: border-box; }
        button { background: #28a745; cursor: pointer; border: none; font-weight: bold; border-radius: 4px; }
        button.btn-primary { background: #38bdf8; color: #000; }
        .url-tag { font-size: 10px; color: #e91e63; background: #000; padding: 6px; display: block; border: 1px solid #222; margin-bottom: 15px; border-radius: 4px; text-align: center; }
        .pi-row { background: #0f172a; border: 1px solid #334155; padding: 15px; border-radius: 6px; margin-bottom: 15px; }
        pre { background: #000; padding: 12px; border-radius: 6px; color: #38bdf8; border: 1px solid #333; overflow-x: auto; font-size: 13px; margin: 0; }
        
        /* Accordion Style */
        .accordion-btn { background: #0f172a; color: #38bdf8; cursor: pointer; padding: 12px 15px; width: 100%; border: 1px solid #334155; text-align: left; outline: none; font-size: 14px; font-weight: bold; border-radius: 6px; display: flex; justify-content: space-between; align-items: center; }
        .accordion-btn:hover { background: #1e293b; }
        .accordion-content { padding: 15px; display: none; background: #090d16; border: 1px solid #334155; border-top: none; border-radius: 0 0 6px 6px; margin-bottom: 15px; }
    </style>
    <script>
        function toggleInput(sel) {
            const urlInput = sel.nextElementSibling;
            if (urlInput) urlInput.style.display = (sel.value === 'url' || sel.value === 'nextcloud') ? 'block' : 'none';
        }
        // Aendert sich die Ausrichtung, bevor gespeichert wird, sollen die Preset-Felder
        // sofort auf die passende Liste (Hochkant/Quer) umschalten, statt erst nach einem
        // Neuladen der Seite.
        function updatePresetListsForOrientation(orientSel) {
            const form = orientSel.closest('form');
            if (!form) return;
            const isPortrait = (orientSel.value === '90' || orientSel.value === '270');
            const listId = isPortrait ? 'presetNamesHochkant' : 'presetNamesQuer';
            form.querySelectorAll('input[name^="preset_name_"]').forEach(function(inp) {
                inp.setAttribute('list', listId);
            });
        }
        // Presets: buendeln Inhaltstyp + Wert (URL/Nextcloud-Ordner) + Wechselfrequenz unter
        // einem Namen. Das Namensfeld dient per <datalist> gleichzeitig als Dropdown zum
        // Laden - wird ein exakt passender, bereits gespeicherter Name eingegeben/ausgewaehlt,
        // fuellt das automatisch die zugehoerigen Felder DERSELBEN Monitor-Karte (per
        // closest('form') abgegrenzt, damit mehrere Monitor-Formulare auf der Seite sich
        // nicht gegenseitig stoeren).
        // Liegt als JSON-"Dateninsel" im <body> (nicht als const hier im <head>), damit die
        // AJAX-Schicht (siehe weiter unten) nach jedem Speichern nur den <body> ersetzen muss,
        // ohne ein "Identifier bereits deklariert"-Problem beim erneuten Ausführen von <head>-
        // Skripten zu riskieren - und PRESETS bleibt dabei automatisch aktuell.
        function currentPresets() {
            const el = document.getElementById('presets-data');
            try { return el ? (JSON.parse(el.textContent) || {}) : {}; } catch (e) { return {}; }
        }
        function applyPresetIfMatch(slot, inputEl) {
            const preset = currentPresets()[inputEl.value];
            if (!preset) return;
            const form = inputEl.closest('form');
            if (!form) return;
            const orientSel = form.querySelector('select[name="orient"]');
            if (orientSel) {
                const isPortrait = (orientSel.value === '90' || orientSel.value === '270');
                const currentGroup = isPortrait ? 'hochkant' : 'quer';
                if (preset.orientation && preset.orientation !== currentGroup) return; // falsche Ausrichtung, nicht anwenden
            }
            const modeSel = form.querySelector('select[name="mode' + slot + '"]');
            const valInput = form.querySelector('input[name="val' + slot + '"]');
            const durInput = form.querySelector('input[name="duration"]');
            if (modeSel) { modeSel.value = preset.type; toggleInput(modeSel); }
            if (valInput) { valInput.value = preset.content; }
            if (durInput) { durInput.value = preset.duration; }
        }
        function toggleAccordion() {
            const content = document.getElementById("accContent");
            const icon = document.getElementById("accIcon");
            if (content.style.display === "block") {
                content.style.display = "none";
                icon.innerText = "➕ Aufklappen";
            } else {
                content.style.display = "block";
                icon.innerText = "➖ Zuklappen";
            }
        }
        function toggleFerienAccordion() {
            const content = document.getElementById("ferienAccContent");
            const icon = document.getElementById("ferienAccIcon");
            if (content.style.display === "block") {
                content.style.display = "none";
                icon.innerText = "➕ Aufklappen";
            } else {
                content.style.display = "block";
                icon.innerText = "➖ Zuklappen";
            }
        }
        function toggleGenericAccordion(contentId, btn) {
            const content = document.getElementById(contentId);
            const icon = btn.querySelector('.acc-icon');
            if (content.style.display === "block") {
                content.style.display = "none";
                if (icon) icon.innerText = "➕ Aufklappen";
            } else {
                content.style.display = "block";
                if (icon) icon.innerText = "➖ Zuklappen";
            }
        }
        function copyInstallCmd() {
            const cmdText = document.getElementById('cmdText').innerText;
            navigator.clipboard.writeText(cmdText).then(() => {
                const btn = document.getElementById('copyBtn');
                btn.innerText = "✓ Kopiert!";
                btn.style.background = "#22c55e";
                setTimeout(() => {
                    btn.innerText = "📋 Befehl Kopieren";
                    btn.style.background = "#38bdf8";
                }, 2000);
            });
        }
    </script>
    <?php echo renderAjaxBootstrap(); ?>
</head>
<body data-im-page="dashboard">
<script type="application/json" id="presets-data"><?php echo json_encode($config['presets'] ?? []); ?></script>

<div class="topbar">
    <h2 style="margin:0;">Infoscreens Master Hub</h2>
    <div style="display:flex; gap:10px; align-items:center;">
        <a href="bento.php" style="color:#38bdf8; text-decoration:none; font-weight:bold; padding:8px 15px; background:#111; border-radius:4px; border:1px solid #333;">🎬 Präsentationen (Bento-Pronto)</a>
        <a href="?logout=1" style="color:#ff5252; text-decoration:none; font-weight:bold; padding:8px 15px; background:#111; border-radius:4px; border:1px solid #333;">Abmelden</a>
    </div>
</div>

<!-- INHALTE & MONITORE -->
<div style="padding: 0 25px 10px;">
    <span style="font-size:12px; color:#64748b;">Neue Monitore lassen sich direkt unten bei der Zuordnung der Pi-Ausgänge anlegen ("+ Neuen Monitor hinzufügen" im Dropdown).</span>
</div>
<datalist id="presetNamesQuer">
    <?php foreach (($config['presets'] ?? []) as $pName => $pData): ?>
        <?php if (($pData['orientation'] ?? 'quer') === 'quer'): ?>
            <option value="<?php echo htmlspecialchars($pName); ?>"></option>
        <?php endif; ?>
    <?php endforeach; ?>
</datalist>
<datalist id="presetNamesHochkant">
    <?php foreach (($config['presets'] ?? []) as $pName => $pData): ?>
        <?php if (($pData['orientation'] ?? 'quer') === 'hochkant'): ?>
            <option value="<?php echo htmlspecialchars($pName); ?>"></option>
        <?php endif; ?>
    <?php endforeach; ?>
</datalist>
<div class="monitors">
    <?php foreach($config['screens'] as $id => $s): $status = getScreenStatus($id, $clients, $config); ?>
    <div class="card" style="border: 1px solid <?php echo $status['color']; ?>; box-shadow: 0 0 12px <?php echo $status['color']; ?>66;">
        <h3 style="margin:0; color:<?php echo $status['color']; ?>; text-align:center;">
            <span style="display:inline-block; width:10px; height:10px; border-radius:50%; background:<?php echo $status['color']; ?>; margin-right:6px; box-shadow:0 0 6px <?php echo $status['color']; ?>;" title="<?php echo htmlspecialchars($status['label']); ?>"></span>
            Monitor <?php echo $id; ?>
            <span style="font-size:10px; font-weight:normal;">(<?php echo htmlspecialchars($status['label']); ?>)</span>
        </h3>
        <code class="url-tag"><?php echo $serverDirUrl; ?>view.php?id=<?php echo $id; ?></code>

        <?php $pbSource = $s['playback_source'] ?? 'local'; ?>
        <!-- Nur das versteckte Formular selbst (kein sichtbarer Button hier) - der eigentliche
             kleine Umschalt-Knopf sitzt unten direkt hinter dem "Inhalt A"-Select und
             submitted per form="..."-Attribut dieses Formular hier, genau wie die einzelnen
             Datei-Löschen-Buttons in renderFileManager(). -->
        <form method="POST" id="pbform-<?php echo $id; ?>" style="display:none;">
            <input type="hidden" name="toggle_playback_source" value="<?php echo $id; ?>">
        </form>

        <?php
        $orient = $s['orient'] ?? '0';
        $isPortrait = ($orient == '90' || $orient == '270');
        $iframeW = $isPortrait ? 720 : 1280;
        $iframeH = $isPortrait ? 1280 : 720;
        $previewH = 220;
        $scale = round($previewH / $iframeH, 4);
        $previewW = (int)($iframeW * $scale);
        ?>
        <div style="width:100%; display:flex; justify-content:center; margin:10px 0;">
            <div style="width:<?php echo $previewW; ?>px; height:<?php echo $previewH; ?>px; background:#000; border-radius:6px; border:1px solid #555; overflow:hidden; position:relative;">
                <iframe src="view.php?id=<?php echo $id; ?>&preview=1" loading="lazy" style="width:<?php echo $iframeW; ?>px; height:<?php echo $iframeH; ?>px; transform: scale(<?php echo $scale; ?>); transform-origin: top left; border: none; pointer-events: none;"></iframe>
            </div>
        </div>

        <form method="POST">
            <input type="hidden" name="screen_id" value="<?php echo $id; ?>">
            <div style="display:flex; gap:5px;">
                <select name="orient" onchange="updatePresetListsForOrientation(this)">
                    <option value="0" <?php if($s['orient']=='0') echo 'selected'; ?>>0° (Quer)</option>
                    <option value="90" <?php if($s['orient']=='90') echo 'selected'; ?>>90° (Rechts)</option>
                    <option value="180" <?php if($s['orient']=='180') echo 'selected'; ?>>180° (Über Kopf)</option>
                    <option value="270" <?php if($s['orient']=='270') echo 'selected'; ?>>270° (Links)</option>
                </select>
                <select name="split">
                    <option value="none" <?php if($s['split']=='none') echo 'selected'; ?>>Voll</option>
                    <option value="h" <?php if($s['split']=='h') echo 'selected'; ?>>H-Split</option>
                    <option value="v" <?php if($s['split']=='v') echo 'selected'; ?>>V-Split</option>
                </select>
                <input type="number" name="duration" value="<?php echo $s['duration']; ?>" min="1" required style="width:70px;" title="Sek. pro Bild (darf nicht leer sein)">
            </div>

            <label style="font-size:11px; color:#aaa;">Inhalt A</label>
            <div style="display:flex; gap:4px; align-items:center;">
                <select name="modeA" onchange="toggleInput(this)" style="flex:1; width:auto;">
                    <option value="url" <?php if($s['type']=='url') echo 'selected'; ?>>Webseite (URL)</option>
                    <option value="nextcloud" <?php if($s['type']=='nextcloud') echo 'selected'; ?>>Nextcloud Ordner (URL)</option>
                    <?php foreach($folderNames as $fn): ?>
                        <option value="folder:<?php echo $fn; ?>" <?php if($s['type']=="folder:$fn") echo 'selected'; ?>>Ordner: <?php echo $fn; ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" form="pbform-<?php echo $id; ?>" style="width:auto; flex:0 0 auto; font-size:10px; padding:6px 8px; background:<?php echo $pbSource === 'local' ? '#166534' : '#1e3a8a'; ?>;" title="<?php echo $pbSource === 'local' ? 'Lokale Wiedergabe (Standard) - klicken für Live vom Server' : 'Live vom Server - klicken für Lokale Wiedergabe'; ?>">
                    <?php echo $pbSource === 'local' ? '🏠' : '🌐'; ?>
                </button>
            </div>
            <input type="text" name="valA" value="<?php echo htmlspecialchars($s['content']); ?>" style="display:<?php echo (in_array($s['type'],['url','nextcloud'])?'block':'none'); ?>">
            <?php if ($s['type'] === 'nextcloud' && !empty($s['content'])): ?>
                <a href="<?php echo htmlspecialchars($s['content']); ?>" target="_blank" rel="noopener" style="font-size:11px; color:#38bdf8; display:inline-block; margin-top:4px;">🔗 Nextcloud-Ordner öffnen</a>
            <?php endif; ?>
            <div style="display:flex; gap:6px; align-items:center; margin-top:4px;">
                <input type="text" name="preset_name_A" list="<?php echo in_array($s['orient'], ['90','270']) ? 'presetNamesHochkant' : 'presetNamesQuer'; ?>" placeholder="Preset-Name (laden oder neu speichern)" style="flex:1; font-size:11px; padding:4px;" oninput="applyPresetIfMatch('A', this)">
                <button type="submit" name="save_preset_slot" value="A" style="width:auto; font-size:11px; padding:4px 10px;" title="Aktuellen Inhalt A + Wechselfrequenz unter dem eingegebenen Namen speichern">💾</button>
                <button type="submit" name="del_preset_slot" value="A" onclick="return confirm('Preset &quot;' + this.form.querySelector('[name=preset_name_A]').value + '&quot; wirklich löschen?');" style="width:auto; font-size:11px; padding:4px 10px; background:#7f1d1d;" title="Preset mit diesem Namen löschen">🗑</button>
            </div>

            <div style="display: <?php echo ($s['split']=='none'?'none':'block'); ?>">
                <label style="font-size:11px; color:#aaa;">Inhalt B (Split)</label>
                <select name="modeB" onchange="toggleInput(this)">
                    <option value="url" <?php if($s['typeB']=='url') echo 'selected'; ?>>Webseite (URL)</option>
                    <option value="nextcloud" <?php if($s['typeB']=='nextcloud') echo 'selected'; ?>>Nextcloud Ordner (URL)</option>
                    <?php foreach($folderNames as $fn): ?>
                        <option value="folder:<?php echo $fn; ?>" <?php if($s['typeB']=="folder:$fn") echo 'selected'; ?>>Ordner: <?php echo $fn; ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="valB" value="<?php echo htmlspecialchars($s['contentB']); ?>" style="display:<?php echo (in_array($s['typeB'],['url','nextcloud'])?'block':'none'); ?>">
                <?php if (($s['typeB'] ?? '') === 'nextcloud' && !empty($s['contentB'])): ?>
                    <a href="<?php echo htmlspecialchars($s['contentB']); ?>" target="_blank" rel="noopener" style="font-size:11px; color:#38bdf8; display:inline-block; margin-top:4px;">🔗 Nextcloud-Ordner öffnen</a>
                <?php endif; ?>
                <div style="display:flex; gap:6px; align-items:center; margin-top:4px;">
                    <input type="text" name="preset_name_B" list="<?php echo in_array($s['orient'], ['90','270']) ? 'presetNamesHochkant' : 'presetNamesQuer'; ?>" placeholder="Preset-Name (laden oder neu speichern)" style="flex:1; font-size:11px; padding:4px;" oninput="applyPresetIfMatch('B', this)">
                    <button type="submit" name="save_preset_slot" value="B" style="width:auto; font-size:11px; padding:4px 10px;" title="Aktuellen Inhalt B + Wechselfrequenz unter dem eingegebenen Namen speichern">💾</button>
                    <button type="submit" name="del_preset_slot" value="B" onclick="return confirm('Preset &quot;' + this.form.querySelector('[name=preset_name_B]').value + '&quot; wirklich löschen?');" style="width:auto; font-size:11px; padding:4px 10px; background:#7f1d1d;" title="Preset mit diesem Namen löschen">🗑</button>
                </div>
            </div>

            <button type="submit" style="margin-top:10px;">Layout Speichern</button>
        </form>

        <?php 
            $folderA = (strpos($s['type'], 'folder:') === 0) ? substr($s['type'], 7) : null;
            $folderB = ($s['split'] !== 'none' && strpos($s['typeB'], 'folder:') === 0) ? substr($s['typeB'], 7) : null;
            if($folderA) renderFileManager($folderA, $uploadBase, "Inhalt A", $config['folder_hidden_files'][$folderA] ?? []);
            if($folderB && $folderA !== $folderB) renderFileManager($folderB, $uploadBase, "Inhalt B", $config['folder_hidden_files'][$folderB] ?? []);
        ?>
    </div>
    <?php endforeach; ?>
</div>

<!-- ZEITPLAN, FERIEN & ORDNERVERWALTUNG -->
<div style="padding: 0 25px;">
    <?php $status = computeGlobalOff($config); $wTag = ["1"=>"Mo","2"=>"Di","3"=>"Mi","4"=>"Do","5"=>"Fr","6"=>"Sa","7"=>"So"][date('N', getAdjustedNow($config))]; ?>
    <?php $statusColorZeitplan = $status['off'] ? '#ef4444' : '#22c55e'; ?>
    <div class="card" style="border: 1px solid <?php echo $statusColorZeitplan; ?>; box-shadow: 0 0 12px <?php echo $statusColorZeitplan; ?>66;">
        <h3 style="margin-top:0;">Zeitplan (Standby-Steuerung)</h3>
        <div style="display:flex; gap:20px; flex-wrap:wrap; font-size:13px; align-items:center; margin-bottom:6px;">
            <div>Serverzeit: <strong><?php echo date('d.m.Y H:i:s', getAdjustedNow($config)); ?></strong> (<?php echo $wTag; ?>)</div>
            <form method="POST" style="display:inline-flex; gap:6px; align-items:center; margin:0;">
                <button type="submit" name="time_offset_delta" value="-1" style="width:auto; padding:2px 12px;" title="Serverzeit-Korrektur um 1 Stunde verringern">−</button>
                <span style="font-size:11px; color:#94a3b8; white-space:nowrap;">Korrektur: <?php $off = (float)($config['time_offset_hours'] ?? 0); echo ($off >= 0 ? '+' : '') . $off; ?>h</span>
                <button type="submit" name="time_offset_delta" value="1" style="width:auto; padding:2px 12px;" title="Serverzeit-Korrektur um 1 Stunde erhöhen">+</button>
            </form>
            <div>Status: <strong style="color:<?php echo $status['off'] ? '#ef4444' : '#22c55e'; ?>;"><?php echo $status['off'] ? '🔴 AUS' : '🟢 AN'; ?></strong></div>
        </div>
        <div style="font-size:11px; color:#94a3b8; margin-bottom:14px;">Grund: <?php echo htmlspecialchars($status['reason']); ?></div>

        <form method="POST">
            <input type="hidden" name="save_schedule" value="1">
            <div style="display:flex; gap:10px; margin-bottom:10px;">
                <?php foreach(["1"=>"Mo","2"=>"Di","3"=>"Mi","4"=>"Do","5"=>"Fr","6"=>"Sa","7"=>"So"] as $dNum => $dName): ?>
                    <label style="font-size:12px;"><input type="checkbox" name="active_days[]" value="<?php echo $dNum; ?>" <?php if(in_array((string)$dNum, $config['schedule']['active_days'])) echo "checked"; ?>> <?php echo $dName; ?></label>
                <?php endforeach; ?>
            </div>
            <div style="display:flex; gap:15px; align-items:center;">
                <input type="time" name="time_start" value="<?php echo htmlspecialchars($config['schedule']['time_start']); ?>">
                <span>bis</span>
                <input type="time" name="time_end" value="<?php echo htmlspecialchars($config['schedule']['time_end']); ?>">
                <button type="submit" style="background:#e91e63;">Zeitplan Speichern</button>
            </div>
        </form>

        <button class="accordion-btn" type="button" onclick="toggleFerienAccordion()" style="margin-top:15px;">
            <span>🏖️ Ausnahmen: Ferien &amp; Feiertage<?php echo !empty($config['schedule']['holidays']) ? ' (' . count($config['schedule']['holidays']) . ')' : ''; ?></span>
            <span id="ferienAccIcon">➕ Aufklappen</span>
        </button>
        <div id="ferienAccContent" class="accordion-content">
        <form method="POST" style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
            <input type="hidden" name="add_holiday" value="1">
            <input type="text" name="hol_name" placeholder="Name" required style="flex:1 1 180px; min-width:140px;">
            <label style="display:flex; flex-direction:column; font-size:10px; color:#94a3b8; gap:2px;">Start
                <input type="date" name="hol_start" required style="flex:0 0 auto;">
            </label>
            <label style="display:flex; flex-direction:column; font-size:10px; color:#94a3b8; gap:2px;">Ende
                <input type="date" name="hol_end" required style="flex:0 0 auto;">
            </label>
            <button type="submit" style="background:#ff9800; color:#000; width:auto; flex:0 0 auto; white-space:nowrap;">Hinzufügen</button>
        </form>
        <form method="POST" style="margin-top:10px; display:flex; gap:10px; flex-wrap:wrap;">
            <input type="hidden" name="import_ics" value="1">
            <input type="url" name="ics_url" placeholder="ICS Webcal Link..." value="<?php echo htmlspecialchars($config['schedule']['ics_url'] ?? ''); ?>" required style="flex:1 1 220px; min-width:180px;">
            <button type="submit" style="background:#ff9800; color:#000; width:auto; flex:0 0 auto; white-space:nowrap;">ICS laden (ersetzt vorige)</button>
        </form>
        <?php if (!empty($config['schedule']['ics_url'])): ?>
        <form method="POST" style="margin-top:10px; display:flex; gap:15px; align-items:center; flex-wrap:wrap;">
            <input type="hidden" name="sync_ics" value="1">
            <span style="font-size:11px; color:#94a3b8;">
                <?php if (!empty($config['schedule']['last_ics_sync'])): ?>
                    Zuletzt synchronisiert: <?php echo date('d.m.Y H:i', $config['schedule']['last_ics_sync']); ?>
                <?php else: ?>
                    Noch nicht synchronisiert.
                <?php endif; ?>
                <?php if (!empty($config['schedule']['last_ics_error'])): ?>
                    <span style="color:#ef4444;"> — ⚠️ <?php echo htmlspecialchars($config['schedule']['last_ics_error']); ?></span>
                <?php endif; ?>
            </span>
            <button type="submit" style="background:#38bdf8; width:auto; padding:6px 16px;">🔄 Jetzt synchronisieren</button>
        </form>
        <?php endif; ?>

        <?php if (!empty($config['schedule']['holidays'])): ?>
        <form method="POST" style="margin-top:15px;">
            <input type="hidden" name="del_holidays_bulk" value="1">
            <table style="width:100%; font-size:12px; border-collapse:collapse;">
                <tr style="text-align:left; color:#94a3b8;"><th></th><th>Name</th><th>Von</th><th>Bis</th><th>Quelle</th></tr>
                <?php foreach ($config['schedule']['holidays'] as $h): ?>
                <tr style="border-top:1px solid #1e293b;">
                    <td><input type="checkbox" name="holiday_ids[]" value="<?php echo htmlspecialchars($h['id'] ?? ''); ?>"></td>
                    <td><?php echo htmlspecialchars($h['name'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($h['start_date'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($h['end_date'] ?? ''); ?></td>
                    <td><?php echo ($h['source'] ?? 'manual') === 'ics' ? '📅 ICS' : '✍️ Manuell'; ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
            <button type="submit" style="background:#ef4444; margin-top:10px; width:auto; padding:6px 16px;">Ausgewählte löschen</button>
        </form>
        <?php else: ?>
        <div style="font-size:12px; color:#64748b; margin-top:10px;">Keine Ferien/Termine eingetragen - Display läuft nach dem normalen Zeitplan oben.</div>
        <?php endif; ?>
        <div style="font-size:11px; color:#475569; margin-top:8px;">Abgelaufene Einträge (Bis-Datum in der Vergangenheit) werden automatisch entfernt.</div>
        </div>
    </div>

    <div class="card">
        <h3 style="margin-top:0;">Globale Ordner-Verwaltung</h3>
        <form method="POST" style="display:flex; gap:10px; margin-bottom:15px;">
            <input type="text" name="new_folder" placeholder="Neuer Ordner Name" required>
            <button type="submit" style="background:#e91e63; width:180px;">Anlegen</button>
        </form>

        <?php
            $existingFolderPaths = array_filter(glob($uploadBase . '*'), 'is_dir');
            natcasesort($existingFolderPaths);
        ?>
        <?php if (empty($existingFolderPaths)): ?>
            <div style="font-size:12px; color:#64748b;">Noch keine Ordner angelegt.</div>
        <?php endif; ?>
        <?php foreach ($existingFolderPaths as $folderPath):
            $folderName = basename($folderPath);
            $active = isFolderActive($folderName, $config);
            $fileCount = count(array_diff(scandir($folderPath), ['.', '..']));
            $safeId = 'folder_' . md5($folderName);
        ?>
        <div style="margin-bottom:10px;">
            <button class="accordion-btn" type="button" onclick="toggleGenericAccordion('<?php echo $safeId; ?>', this)" style="<?php echo $active ? 'border-color:#22c55e; color:#22c55e;' : ''; ?>">
                <span><?php echo $active ? '🟢' : '⚪'; ?> <?php echo htmlspecialchars($folderName); ?> <span style="font-weight:normal; opacity:0.7;">(<?php echo $fileCount; ?> Datei<?php echo $fileCount === 1 ? '' : 'en'; ?><?php echo $active ? ', aktiv verwendet' : ''; ?>)</span></span>
                <span class="acc-icon">➕ Aufklappen</span>
            </button>
            <div id="<?php echo $safeId; ?>" class="accordion-content">
                <?php renderFileManager($folderName, $uploadBase, 'Ordnerinhalt', $config['folder_hidden_files'][$folderName] ?? []); ?>
                <form method="POST" onsubmit="return confirm('Ordner &quot;<?php echo htmlspecialchars(addslashes($folderName)); ?>&quot; inkl. aller Dateien wirklich löschen?');" style="margin-top:10px;">
                    <input type="hidden" name="del_folder" value="<?php echo htmlspecialchars($folderName); ?>">
                    <button type="submit" style="background:#7f1d1d; width:auto; padding:6px 14px;">🗑 Ordner löschen</button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if (isset($_GET['view_reports']) && !empty($errorReports[$_GET['view_reports']])): ?>
    <div class="card" id="reports" style="border-color:#f59e0b;">
        <h3 style="margin-top:0;">🚨 Fehlerberichte: <?php echo htmlspecialchars($clients[$_GET['view_reports']]['name'] ?? $_GET['view_reports']); ?></h3>
        <p style="font-size:11px; color:#94a3b8;">Automatisch vom Pi gesendet, sobald ein neuer (nicht wiederholter) Fehler auftritt - jeweils die letzten 40 Logzeilen zum Zeitpunkt des Fehlers. Die letzten 10 Berichte pro Pi bleiben erhalten.</p>
        <?php foreach ($errorReports[$_GET['view_reports']] as $r): ?>
        <details style="margin-bottom:10px; background:#020617; border:1px solid #1e293b; border-radius:6px; padding:8px 12px;">
            <summary style="cursor:pointer; font-size:12px; color:#f59e0b;">
                <?php echo date('d.m.Y H:i:s', $r['time'] ?? 0); ?> — <?php echo htmlspecialchars($r['reason'] ?? '?'); ?>
            </summary>
            <pre style="white-space:pre-wrap; font-size:11px; color:#cbd5e1; margin-top:8px; max-height:400px; overflow-y:auto;"><?php echo htmlspecialchars($r['log'] ?? ''); ?></pre>
        </details>
        <?php endforeach; ?>
        <a href="infomaster.php" style="font-size:12px; color:#94a3b8;">← Zurück zur Übersicht</a>
    </div>
    <?php endif; ?>

    <!-- RASPBERRY PI CLIENTS (GANZ UNTEN) -->
    <div class="card card-pi">
        <h3 style="margin-top:0; color:#38bdf8;">📡 Raspberry Pi Clients & Display-Zuordnung</h3>

        <form method="POST" style="display:flex; gap:10px; align-items:center; margin-bottom:15px;">
            <input type="hidden" name="save_required_outputs" value="1">
            <label style="font-size:12px; color:#94a3b8; width:auto;">Erwartete Monitorausgänge pro Pi:</label>
            <input type="number" name="required_outputs" min="1" max="8" value="<?php echo (int)($config['required_outputs'] ?? 2); ?>" style="width:70px;">
            <button type="submit" style="width:auto; background:#38bdf8; color:#000;">Speichern</button>
        </form>

        <!-- AKKORDEON FÜR INSTALLATION -->
        <button class="accordion-btn" onclick="toggleAccordion()">
            <span>⚙️ Anleitung & Installation für neue Raspberry Pis</span>
            <span id="accIcon">➕ Aufklappen</span>
        </button>
        <div id="accContent" class="accordion-content">
            <p style="font-size:13px; color:#cbd5e1; margin-top:0;">
                Führe folgenden Befehl auf dem Raspberry Pi im Terminal aus, um ihn zu installieren und den Autostart einzurichten:
            </p>
            <pre><code id="cmdText"><?php echo htmlspecialchars($install_command); ?></code></pre>
            <button id="copyBtn" onclick="copyInstallCmd()" class="btn-primary" style="font-weight:bold; padding:8px 15px; margin-top:10px; width:auto;">📋 Befehl Kopieren</button>
            <p style="font-size:12px; color:#94a3b8; margin-top:10px;">
                Der Installer zeigt jeden Schritt (✅/❌) direkt im Terminal an. Live-Logs danach (nur RAM, max. 20MB, nichts wird dauerhaft auf die SD-Karte geschrieben):
                <code>sudo journalctl -u kiosk_client.service -f</code><br>
                Für reine Terminal-Ausgabe ganz ohne Journal: Service stoppen und manuell starten:
                <code>sudo systemctl stop kiosk_client.service && sudo python3 -u /home/pi/kiosk_system/client.py</code>
            </p>
        </div>

        <form method="POST">
            <input type="hidden" name="save_pi_clients" value="1">
            <?php if (empty($clients)): ?>
                <p style="color: #64748b; font-size: 13px;">Noch kein Raspberry Pi verbunden.</p>
            <?php else: ?>
                <?php
                $onlineClients = [];
                $offlineClients = [];
                foreach ($clients as $clientId => $data) {
                    if ((time() - ($data['last_seen'] ?? 0)) < ACTIVE_THRESHOLD) {
                        $onlineClients[$clientId] = $data;
                    } else {
                        $offlineClients[$clientId] = $data;
                    }
                }
                foreach ($onlineClients as $clientId => $data) {
                    renderPiRow($clientId, $data, true, $config, $errorReports);
                }
                ?>

                <?php if (!empty($offlineClients)): ?>
                    <details style="margin-top:15px;">
                        <summary style="cursor:pointer; color:#94a3b8;">Inaktive Geräte anzeigen (<?php echo count($offlineClients); ?>)</summary>
                        <div style="margin-top:10px;">
                            <?php foreach ($offlineClients as $clientId => $data) { renderPiRow($clientId, $data, false, $config, $errorReports); } ?>
                        </div>
                    </details>
                <?php endif; ?>

                <button type="submit" class="btn-primary" style="font-weight:bold; margin-top:10px;">Pi-Konfiguration Speichern</button>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Kleiner, unauffaelliger Zugriffs-Button + Modal statt einer eigenen Dashboard-Karte -->
<button onclick="document.getElementById('access-modal').style.display='flex'"
        style="position:fixed; bottom:16px; right:16px; width:auto; background:#1e293b; color:#64748b; border:1px solid #334155; border-radius:6px; padding:6px 10px; font-size:16px; cursor:pointer; z-index:500;"
        title="Zugriff">🔐</button>

<div id="access-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); z-index:1000; align-items:center; justify-content:center;">
    <div style="background:#1e293b; border:1px solid #334155; border-radius:10px; padding:25px 30px; max-width:440px; width:90%; max-height:85vh; overflow-y:auto;">
        <h3 style="margin-top:0;">🔐 Zugriff</h3>
        <div style="font-size:12px; color:#94a3b8;">Das Master-Passwort wird aus Sicherheitsgründen nicht über diese Oberfläche geändert, sondern manuell in einer separaten Datei auf dem Server.</div>
        <div style="font-size:11px; color:#475569; margin-top:8px;">Login (Rolle &amp; Passwort) bleibt 12 Stunden ab der letzten Nutzung gültig, statt ständig neu abgefragt zu werden.</div>

        <?php if (($_SESSION['auth_user'] ?? null) === null): ?>
        <hr style="border-color:#334155; margin:16px 0;">
        <h4 style="margin:0 0 10px 0; font-size:13px; color:#e2e8f0;">Nutzer verwalten</h4>
        <?php if (!empty($users)): ?>
        <div style="margin-bottom:12px;">
            <?php foreach ($users as $uname => $udata): ?>
            <form method="POST" style="display:flex; justify-content:space-between; align-items:center; gap:8px; font-size:12px; padding:4px 0; border-bottom:1px solid #0f172a;">
                <span><?php echo htmlspecialchars($udata['name'] ?? $uname); ?> <span style="color:#64748b;">(<?php echo htmlspecialchars($uname); ?>)</span></span>
                <input type="hidden" name="del_user" value="1">
                <input type="hidden" name="del_username" value="<?php echo htmlspecialchars($uname); ?>">
                <button type="submit" style="width:auto; padding:2px 10px; background:#7f1d1d; font-size:11px;">Löschen</button>
            </form>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <form method="POST" style="display:flex; flex-direction:column; gap:8px;">
            <input type="hidden" name="add_user" value="1">
            <input type="text" name="new_username" placeholder="Benutzername (für ?rolle=...)" required style="padding:8px;">
            <input type="text" name="new_user_display_name" placeholder="Anzeigename (optional)" style="padding:8px;">
            <input type="password" name="new_user_password" placeholder="Passwort" required style="padding:8px;">
            <button type="submit" style="background:#38bdf8; color:#000;">Nutzer anlegen</button>
        </form>
        <?php endif; ?>

        <button onclick="document.getElementById('access-modal').style.display='none'" style="margin-top:18px; background:#334155;">Schließen</button>
    </div>
</div>
<script>
document.getElementById('access-modal').addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
});
// Nach dem Anlegen/Loeschen eines Nutzers wird hierher zurueckgeleitet - Modal direkt
// wieder oeffnen, statt dass die Aenderung "verschwindet".
if (window.location.hash === '#access-modal-reopen') {
    document.getElementById('access-modal').style.display = 'flex';
    history.replaceState(null, '', window.location.pathname + window.location.search);
}
</script>

</body>
</html>