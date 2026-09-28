<?php
/**
 * bento.php — bento-pronto (siehe README des bento-pronto-Projekts), fest in
 * infomaster eingebaut: gleiche Login-Session wie infomaster.php (kein
 * eigener Zugang), damit angemeldete Infomaster-Admins hier Präsentationen
 * bauen und direkt als Monitor-Inhalt speichern koennen (siehe
 * "Auf Server speichern (für Monitor)"-Knopf weiter unten im JS-Teil).
 */
$SESSION_LIFETIME = 12 * 3600;
session_set_cookie_params(['lifetime' => $SESSION_LIFETIME, 'path' => '/']);
ini_set('session.gc_maxlifetime', (string)$SESSION_LIFETIME);
session_start();
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $SESSION_LIFETIME) {
    session_unset();
    session_destroy();
    session_start();
}
$_SESSION['last_activity'] = time();
if (empty($_SESSION['loggedin'])) {
    // Nicht (mehr) angemeldet: zurueck zum zentralen Infomaster-Login. Von
    // dort fuehrt der "Präsentationen"-Link direkt wieder hierher.
    header('Location: infomaster.php');
    exit;
}

// Praesentationen serverseitig ablegen statt nur als Browser-Download - in
// zwei getrennten Ordnern, je nach Zweck:
//  - media/bento-pronto/monitors/  -> schreibgeschuetzte Kiosk-Exporte (siehe
//    "Auf Server speichern (für Monitor)"): die URL kommt 1:1 als Monitor-
//    Inhalt (Typ "Webseite/URL") in infomaster.php.
//  - media/bentos/                -> bearbeitbare Praesentationen (siehe
//    "Bearbeitbar auf Server speichern"): tragen den bento-host-config-Meta-
//    Tag (siehe unten). Der Ordnername "bentos" ist absichtlich so gewaehlt,
//    NICHT "bento-pronto/decks" o.ae. - der Bento-Editor selbst erkennt einen
//    Speichern-Host nur, wenn "bentos" als eigenes Pfadsegment in der URL
//    vorkommt (siehe editor/hostsave.ts im bento-Projekt, spiegelt exakt
//    moodle.ts's eigene "mod/bento"-Erkennung, die dabei unangetastet
//    bleibt). Erst WENN das zutrifft, speichert die BENTO-APP SELBST (der
//    native Speichern-Knopf im Editor, genau wie bei Moodle-mod_bento) spaeter
//    direkt wieder in dieselbe Datei zurueck - kein Zwischenschritt hier
//    noetig, das ?api=save_deck weiter unten uebernimmt das.
function bentoReadUploadedHtml(): string {
    if (isset($_FILES['bento_save_html']) && is_uploaded_file($_FILES['bento_save_html']['tmp_name'])) {
        return (string)file_get_contents($_FILES['bento_save_html']['tmp_name']);
    }
    return (string)($_POST['bento_save_html'] ?? '');
}
function bentoSafeBaseName(string $raw): string {
    $name = preg_replace('/[^a-zA-Z0-9-_]/', '_', pathinfo($raw, PATHINFO_FILENAME));
    return $name === '' ? 'praesentation' : $name;
}
function bentoIsHttps(): bool {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    // Hinter einem Reverse-Proxy (nginx/Apache als SSL-Terminierung davor)
    // steht $_SERVER['HTTPS'] oft NICHT - der Proxy terminiert TLS und spricht
    // PHP intern nur per HTTP an. Ohne diesen Fallback wuerde bentoServerUrlFor()
    // dann faelschlich "http://" in eine tatsaechlich https://-geladene Seite
    // einbetten - der Browser blockt den anschliessenden Speichern-Request des
    // Editors dann als "Mixed Content", meist ohne sichtbare Fehlermeldung
    // ausser in der Browser-Konsole. Nur vertrauenswuerdig, wenn der eigene
    // Webserver diesen Header tatsaechlich vom Proxy gesetzt bekommt (Standard
    // bei nginx/Apache-Reverse-Proxy-Konfigurationen).
    $xfProto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    if (strpos($xfProto, 'https') !== false) return true;
    if (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) === 'on') return true;
    return false;
}
function bentoServerUrlFor(string $relPath): string {
    $baseUrl = (bentoIsHttps() ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    return $baseUrl . '/' . $relPath;
}

// Liest Titel + Folienanzahl aus einer gespeicherten Datei, fuer die
// "Deck-Banner"-Liste unten (Gegenstueck zu den Karten in moodle-mod_bento's
// eigener manage.php) - liest dazu NUR das eingebettete #bento-doc-JSON aus,
// nicht die ganze (u.U. mehrere MB grosse) Datei komplett in den Speicher.
function bentoReadDeckMeta(string $path): array {
    $meta = ['title' => null, 'slideCount' => null];
    $fh = @fopen($path, 'rb');
    if (!$fh) return $meta;
    $chunk = fread($fh, 4 * 1024 * 1024); // #bento-doc liegt weit vorne im Shell-HTML
    fclose($fh);
    if ($chunk === false) return $meta;
    if (!preg_match('/<script[^>]*id=["\']bento-doc["\'][^>]*>([\s\S]*?)<\/script>/', $chunk, $m)) return $meta;
    $doc = json_decode(trim($m[1]), true);
    if (!is_array($doc)) return $meta;
    $meta['title'] = isset($doc['title']) ? (string)$doc['title'] : null;
    $meta['slideCount'] = isset($doc['slides']) && is_array($doc['slides']) ? count($doc['slides']) : null;
    return $meta;
}
function bentoFormatBytes(int $bytes): string {
    if ($bytes >= 1024 * 1024) return round($bytes / (1024 * 1024), 1) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024) . ' KB';
    return $bytes . ' B';
}

// ============================================================================
// Reihenfolge der gespeicherten bearbeitbaren Praesentationen: von sich aus gibt
// es dafuer keine Ordnung ausser dem Dateisystem (mtime/Name), das reicht aber
// nicht, sobald manuell per "Position tauschen" umsortiert werden kann. Eine
// kleine ".order.json" im selben Ordner haelt die Reihenfolge explizit fest -
// selbstheilend: fehlt sie oder enthaelt sie geloeschte/unbekannte Dateien,
// baut bentoLoadDeckOrder() sie automatisch wieder aus dem tatsaechlichen
// Dateibestand auf (neu entdeckte Dateien nach mtime absteigend ans Ende).
// ============================================================================

function bentoDeckOrderFile(string $dir): string { return $dir . '/.order.json'; }

function bentoLoadDeckOrder(string $dir): array {
    if (!is_dir($dir)) return [];
    $existing = array_values(array_diff(scandir($dir), ['.', '..', '.order.json']));
    $order = [];
    $orderFile = bentoDeckOrderFile($dir);
    if (is_file($orderFile)) {
        $decoded = json_decode((string)@file_get_contents($orderFile), true);
        if (is_array($decoded)) $order = array_values(array_filter($decoded, 'is_string'));
    }
    $order = array_values(array_intersect($order, $existing)); // nur noch existierende Dateien, gespeicherte Reihenfolge bleibt
    $missing = array_values(array_diff($existing, $order));
    if (!empty($missing)) {
        usort($missing, function ($a, $b) use ($dir) {
            return (@filemtime($dir . '/' . $b) ?: 0) <=> (@filemtime($dir . '/' . $a) ?: 0);
        });
        $order = array_merge($order, $missing);
    }
    bentoSaveDeckOrder($dir, $order);
    return $order;
}

function bentoSaveDeckOrder(string $dir, array $order): void {
    @file_put_contents(bentoDeckOrderFile($dir), json_encode(array_values($order)));
}

function bentoPrependToOrder(string $dir, string $filename): void {
    $order = array_values(array_diff(bentoLoadDeckOrder($dir), [$filename]));
    array_unshift($order, $filename);
    bentoSaveDeckOrder($dir, $order);
}

function bentoRenameInOrder(string $dir, string $oldName, string $newName): void {
    $order = array_map(function ($f) use ($oldName, $newName) { return $f === $oldName ? $newName : $f; }, bentoLoadDeckOrder($dir));
    bentoSaveDeckOrder($dir, $order);
}

function bentoRemoveFromOrder(string $dir, string $filename): void {
    bentoSaveDeckOrder($dir, array_values(array_diff(bentoLoadDeckOrder($dir), [$filename])));
}

function bentoSwapInOrder(string $dir, string $fileA, string $fileB): bool {
    $order = bentoLoadDeckOrder($dir);
    $i = array_search($fileA, $order, true);
    $j = array_search($fileB, $order, true);
    if ($i === false || $j === false) return false;
    [$order[$i], $order[$j]] = [$order[$j], $order[$i]];
    bentoSaveDeckOrder($dir, $order);
    return true;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (isset($_POST['bento_save_html']) || isset($_FILES['bento_save_html']))) {
    header('Content-Type: application/json');
    $uploadBase = 'media/';
    $html = bentoReadUploadedHtml();
    if ($html === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Keine Datei erhalten.']);
        exit;
    }
    if (strlen($html) > 60 * 1024 * 1024) { // 60MB Sicherheitsgrenze - mehr braucht keine Praesentation
        http_response_code(413);
        echo json_encode(['ok' => false, 'error' => 'Datei zu groß (Limit 60MB).']);
        exit;
    }

    $api = $_GET['api'] ?? '';
    if ($api === 'save_deck') {
        // Ueberschreiben einer BEREITS bestehenden bearbeitbaren Praesentation
        // in place - das ist der Endpunkt, den der native "Speichern"-Knopf
        // im Bento-Editor selbst aufruft (siehe hostConfig.saveUrl, in
        // diesen Dateien beim ersten Speichern unten mit eingebettet).
        $dir = $uploadBase . 'bentos';
        $safeFile = basename((string)($_GET['file'] ?? ''));
        $path = $dir . '/' . $safeFile;
        if ($safeFile === '' || !preg_match('/\.html?$/i', $safeFile) || !is_file($path)) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Diese Datei existiert nicht (mehr) auf dem Server.']);
            exit;
        }
        if (file_put_contents($path, $html) === false) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Konnte Datei nicht auf dem Server speichern.']);
            exit;
        }
        echo json_encode(['ok' => true, 'url' => bentoServerUrlFor($dir . '/' . $safeFile), 'filename' => $safeFile]);
        exit;
    }

    // Neuanlage - Ziel (Kiosk-Export fuer Monitore vs. bearbeitbare Praesentation)
    // kommt vom "kind"-Feld, das die beiden Speichern-Knoepfe unterschiedlich setzen.
    $kind = ($_POST['bento_kind'] ?? 'monitor') === 'deck' ? 'deck' : 'monitor';
    // "bentos" fuer bearbeitbare Decks ist absichtlich EIN eigenstaendiges
    // Pfadsegment direkt unter media/ (nicht media/bento-pronto/decks) - siehe
    // Kommentar oben: der Bento-Editor erkennt den Speichern-Host nur daran.
    $dir = $kind === 'deck' ? ($uploadBase . 'bentos') : ($uploadBase . 'bento-pronto/monitors');
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $rawName = bentoSafeBaseName((string)($_POST['bento_filename'] ?? 'praesentation'));
    $filename = $rawName . '_' . date('Ymd_His') . '.html';
    $path = $dir . '/' . $filename;

    if ($kind === 'deck') {
        // bento-host-config VOR dem ersten Speichern in die eigene Kopie
        // einbetten - danach traegt die Datei sich selbst (jeder weitere
        // native Speichern-Vorgang im Editor serialisiert die aktuell
        // geladene Seite samt diesem Meta-Tag automatisch mit, siehe
        // hostsave.ts im bento-Projekt).
        // Absolute URLs - die Datei liegt unter media/bentos/, nicht neben
        // bento.php selbst, also wuerde ein relativer Pfad hier vom FALSCHEN Verzeichnis
        // aus aufgeloest (dem der Datei beim Oeffnen, nicht dem von bento.php).
        $saveUrl = bentoServerUrlFor(basename($_SERVER['SCRIPT_NAME'])) . '?api=save_deck&file=' . rawurlencode($filename);
        $homeUrl = bentoServerUrlFor('infomaster.php');
        $hostConfig = json_encode([
            'saveUrl' => $saveUrl,
            'homeUrl' => $homeUrl,
            'homeLabel' => 'Infomaster',
        ], JSON_HEX_APOS | JSON_HEX_QUOT);
        $metaTag = '<meta name="bento-host-config" content=\'' . $hostConfig . '\'>';
        if (stripos($html, '<head>') !== false) {
            $html = preg_replace('/<head>/i', '<head>' . $metaTag, $html, 1);
        } else {
            $html = $metaTag . $html; // unerwartete Shell-Form - lieber vorne anhaengen als stillschweigend weglassen
        }
    }

    if (!is_dir($dir) || file_put_contents($path, $html) === false) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Konnte Datei nicht auf dem Server speichern.']);
        exit;
    }
    if ($kind === 'deck') {
        // Neueste Praesentation erscheint immer oben - siehe bentoLoadDeckOrder().
        bentoPrependToOrder($dir, $filename);
    }
    echo json_encode(['ok' => true, 'url' => bentoServerUrlFor($dir . '/' . $filename), 'filename' => $filename]);
    exit;
}

// Loeschen einer gespeicherten bearbeitbaren Praesentation aus der Liste unten.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_GET['api'] ?? '') === 'delete_deck') {
    $dir = 'media/bentos';
    $safeFile = basename((string)($_POST['file'] ?? ''));
    $path = $dir . '/' . $safeFile;
    if ($safeFile !== '' && preg_match('/\.html?$/i', $safeFile) && is_file($path)) {
        @unlink($path);
        bentoRemoveFromOrder($dir, $safeFile);
    }
    header('Location: bento.php');
    exit;
}

// Zwei benachbarte Praesentationen in der Liste vertauschen ("Position tauschen"
// zwischen zwei Bannern) - reine Reihenfolgen-Operation, ruehrt die Dateien
// selbst nicht an.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_GET['api'] ?? '') === 'swap_decks') {
    header('Content-Type: application/json');
    $dir = 'media/bentos';
    $fileA = basename((string)($_POST['file_a'] ?? ''));
    $fileB = basename((string)($_POST['file_b'] ?? ''));
    if ($fileA === '' || $fileB === '' || !is_file($dir . '/' . $fileA) || !is_file($dir . '/' . $fileB)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Datei(en) nicht gefunden.']);
        exit;
    }
    $ok = bentoSwapInOrder($dir, $fileA, $fileB);
    echo json_encode(['ok' => $ok]);
    exit;
}

// Umbenennen: Dateiname UND das interne doc.title werden zusammen
// aktualisiert (genau wie bei moodle-mod_bento's eigenem Rename - dort setzt
// derselbe Vorgang sowohl den Deck-"name" als auch doc.title, siehe
// bentoconvert.js buildItemCard()'s titleSpan-Handler). Der Zeitstempel-Teil
// des Dateinamens bleibt erhalten, damit die Datei eindeutig bleibt.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_GET['api'] ?? '') === 'rename_deck') {
    header('Content-Type: application/json');
    $dir = 'media/bentos';
    $safeFile = basename((string)($_POST['file'] ?? ''));
    $path = $dir . '/' . $safeFile;
    if ($safeFile === '' || !is_file($path)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Datei nicht gefunden.']);
        exit;
    }
    if (!preg_match('/^(.*)(_\d{8}_\d{6})(\.html?)$/i', $safeFile, $m)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Unerwartetes Dateinamensformat.']);
        exit;
    }
    $newTitle = trim((string)($_POST['new_name'] ?? ''));
    if ($newTitle === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Name darf nicht leer sein.']);
        exit;
    }
    $newBase = bentoSafeBaseName($newTitle);
    $newFile = $newBase . $m[2] . $m[3];
    $newPath = $dir . '/' . $newFile;
    if ($newFile !== $safeFile && is_file($newPath)) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'Es gibt bereits eine Datei mit diesem Namen.']);
        exit;
    }
    $content = file_get_contents($path);
    if ($content === false) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Konnte Datei nicht lesen.']);
        exit;
    }
    if ($newFile !== $safeFile) {
        // Die Datei traegt ihr eigenes bento-host-config-Meta-Tag mit
        // "file=<alterDateiname>" in der saveUrl (siehe Erzeugung oben) - muss
        // hier mitziehen, sonst zielt der naechste native Speichern-Vorgang
        // im Editor auf die nicht mehr existierende alte Datei.
        $content = str_replace('file=' . rawurlencode($safeFile), 'file=' . rawurlencode($newFile), $content);
    }
    // doc.title im eingebetteten #bento-doc-JSON mit umsetzen, damit der
    // angezeigte Titel (aus genau diesem Feld gelesen, siehe bentoReadDeckMeta)
    // nach dem Umbenennen tatsaechlich den neuen Namen zeigt.
    if (preg_match('/(<script[^>]*id=["\']bento-doc["\'][^>]*>)([\s\S]*?)(<\/script>)/', $content, $dm)) {
        $doc = json_decode(trim($dm[2]), true);
        if (is_array($doc)) {
            $doc['title'] = $newTitle;
            // json_encode escaped Schraegstriche standardmaessig ("<" wird so
            // Teil von "<\/script>" statt "</script>") - schuetzt genau wie
            // die eigene <-Ersetzung im Bento-Projekt selbst (siehe
            // dessen save.ts) davor, dass ein "</script>" im JSON-Text den
            // Script-Block vorzeitig beendet.
            $newJson = json_encode($doc, JSON_UNESCAPED_UNICODE);
            if ($newJson !== false) {
                $content = substr_replace($content, $dm[1] . $newJson . $dm[3], strpos($content, $dm[0]), strlen($dm[0]));
            }
        }
    }
    if (file_put_contents($path, $content) === false) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Konnte Datei nicht speichern.']);
        exit;
    }
    if ($newFile !== $safeFile) {
        if (!rename($path, $newPath)) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Konnte die Datei nicht umbenennen.']);
            exit;
        }
        bentoRenameInOrder($dir, $safeFile, $newFile);
    }
    echo json_encode(['ok' => true, 'filename' => $newFile, 'url' => bentoServerUrlFor($dir . '/' . $newFile)]);
    exit;
}

/**
 * bento-pronto — a self-hostable, single-PHP-file version of the
 * PPTX→Bento converter (bento-moodle-tools' own template.html), with one
 * capability that tool structurally CAN'T have: a built-in image proxy for
 * the "Inhalte auf Folien verteilen" (paste) flow, since GitHub Pages only
 * serves static files. Drop this ONE file on any PHP-capable host and both
 * the converter AND the proxy just work, no extra configuration.
 *
 * Dispatch: a request with ?proxy=<url> is handled ENTIRELY here (fetches
 * the target image server-side — no CORS applies to a server-to-server
 * request, only to a BROWSER's own script-initiated read — and returns the
 * bytes) and exits before any of the page's own HTML is output. Everything
 * else falls through to the converter page itself, further down.
 *
 * Deliberately OPEN, unlike mod_bento's own copy of this same proxy (which
 * gates it behind require_login()) — this tool has no login system of its
 * own to gate anything behind; the safeguards below (SSRF protection,
 * content-type/size checks) are what stands in for that here.
 *
 * @license MIT
 */

if (isset($_GET['proxy'])) {
    // Im Original standardmaessig deaktiviert (kein eigenes Login). Hier in
    // infomaster eingebaut sitzt diese Seite bereits hinter dem gemeinsamen
    // Login (siehe Sitzungspruefung ganz oben in dieser Datei) - daher hier
    // freigegeben, sonst wuerde "Text einfügen" (Bilder aus der Zwischen-
    // ablage) fuer angemeldete Admins nicht funktionieren.
    $proxy = 'yes';
    if ($proxy !== 'yes') {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Image proxy is currently disabled.';
        exit;
    }

    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET');

    $bentoProntoProxyMaxBytes = 15 * 1024 * 1024; // 15 MB — generous for one image, not for abuse
    $bentoProntoProxyTimeoutSeconds = 10;

    $fail = function (int $status, string $message): void {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        echo $message;
        exit;
    };

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') $fail(405, 'Only GET is supported.');

    $proxyUrl = (string) $_GET['proxy'];
    if ($proxyUrl === '') $fail(400, 'Missing proxy target.');

    $parts = parse_url($proxyUrl);
    if ($parts === false || !isset($parts['scheme'], $parts['host'])) $fail(400, 'Malformed URL.');
    if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) $fail(400, 'Only http/https URLs are allowed.');

    /**
     * SSRF guard: resolve the hostname and refuse anything that isn't a
     * plain public address — otherwise this proxy could be pointed at
     * 127.0.0.1, 169.254.169.254 (cloud metadata endpoints), or an
     * internal 10.x/192.168.x service from the outside, using THIS
     * server's own network position to reach things the caller couldn't
     * reach directly. Same logic as mod_bento's own copy of this proxy.
     */
    $resolvesToPublicIp = function (string $host): bool {
        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            $records = @dns_get_record($host, DNS_A + DNS_AAAA);
            if ($records === false || count($records) === 0) return false;
            foreach ($records as $r) {
                if (isset($r['ip'])) $ips[] = $r['ip'];
                if (isset($r['ipv6'])) $ips[] = $r['ipv6'];
            }
        }
        if (count($ips) === 0) return false;
        foreach ($ips as $ip) {
            $public = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
            if ($public === false) return false;
        }
        return true;
    };

    if (!$resolvesToPublicIp($parts['host'])) $fail(403, 'Target host does not resolve to a public address.');

    $hasCurl = function_exists('curl_init');
    if ($hasCurl) {
        $ch = curl_init($proxyUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => $bentoProntoProxyTimeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $bentoProntoProxyTimeoutSeconds,
            CURLOPT_USERAGENT => 'bento-pronto-proxy/1.0',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        curl_setopt($ch, CURLOPT_NOPROGRESS, false);
        curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function ($r, $downloadSize, $downloaded) use ($bentoProntoProxyMaxBytes) {
            return ($downloaded > $bentoProntoProxyMaxBytes) ? 1 : 0;
        });
        $body = curl_exec($ch);
        $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errored = curl_errno($ch) !== 0;
        curl_close($ch);
    } else {
        $context = stream_context_create(['http' => [
            'method' => 'GET', 'timeout' => $bentoProntoProxyTimeoutSeconds, 'follow_location' => 1,
            'max_redirects' => 4, 'user_agent' => 'bento-pronto-proxy/1.0', 'protocol_version' => 1.1, 'ignore_errors' => true,
        ]]);
        $body = @file_get_contents($proxyUrl, false, $context, 0, $bentoProntoProxyMaxBytes + 1);
        $httpCode = 0;
        $contentType = null;
        if (isset($http_response_header)) {
            foreach ($http_response_header as $line) {
                if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) $httpCode = (int) $m[1];
                if (stripos($line, 'Content-Type:') === 0) $contentType = trim(substr($line, 13));
            }
        }
        $finalUrl = null; // file_get_contents doesn't cheaply expose the post-redirect URL — redirects are simply capped above
        $errored = $body === false;
    }

    if ($errored || $body === null || $body === false) $fail(502, 'Could not fetch the target image.');
    if ($httpCode < 200 || $httpCode >= 300) $fail(502, 'Target server returned HTTP ' . $httpCode . '.');
    if ($hasCurl && $finalUrl) {
        $finalHost = parse_url($finalUrl, PHP_URL_HOST);
        if ($finalHost && !$resolvesToPublicIp($finalHost)) $fail(403, 'Redirected to a non-public address.');
    }
    if (!$contentType || stripos($contentType, 'image/') !== 0) $fail(415, 'Target is not an image (Content-Type: ' . ($contentType ?: 'unknown') . ').');
    if (strlen($body) > $bentoProntoProxyMaxBytes) $fail(413, 'Image exceeds the size limit.');

    header('Content-Type: ' . $contentType);
    header('Content-Length: ' . strlen($body));
    header('Cache-Control: public, max-age=3600');
    echo $body;
    exit;
}

// Fuer den "🔗"-Knopf einer gespeicherten Praesentation: statt den Link nur zu
// kopieren, direkt anbieten, ihn als Inhalt A eines der in infomaster.php
// konfigurierten Monitore zu setzen (?screen_id=...&modeA=url&valA=<Link> - siehe
// dortigen POST-Handler). Liest dieselbe config.json wie infomaster.php selbst;
// bleibt einfach leer, wenn die Datei fehlt oder keine Monitore eingerichtet sind
// (eigenstaendiger bento-pronto-Betrieb ohne Infomaster) - das Modal zeigt dann
// nur noch die "Nur Link kopieren"-Option.
$bentoScreensForModal = [];
if (file_exists('config.json')) {
    $bentoCfgForScreens = json_decode(file_get_contents('config.json'), true);
    if (is_array($bentoCfgForScreens) && !empty($bentoCfgForScreens['screens']) && is_array($bentoCfgForScreens['screens'])) {
        foreach ($bentoCfgForScreens['screens'] as $sid => $sVal) {
            $curType = $sVal['type'] ?? 'url';
            if ($curType === 'url') $curLabel = trim((string)($sVal['content'] ?? '')) !== '' ? (string)$sVal['content'] : '(leer)';
            elseif ($curType === 'nextcloud') $curLabel = 'Nextcloud-Ordner';
            elseif (strpos($curType, 'folder:') === 0) $curLabel = 'Ordner: ' . substr($curType, 7);
            else $curLabel = (string)$curType;
            $bentoScreensForModal[] = [
                'id' => (string)$sid,
                'orient' => (string)($sVal['orient'] ?? '0'),
                'split' => (string)($sVal['split'] ?? 'none'),
                'duration' => (string)($sVal['duration'] ?? 10),
                'modeB' => (string)($sVal['typeB'] ?? 'url'),
                'valB' => (string)($sVal['contentB'] ?? ''),
                'currentLabel' => $curLabel,
            ];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<title>PPTX → Bento Konverter</title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<style>
  :root{
    --bg: #0D1B2E;
    --panel: #16273E;
    --panel-2: #1D3049;
    --line: rgba(182, 193, 210, 0.16);
    --ink: #EDEFF3;
    --ink-dim: rgba(182, 193, 210, 0.72);
    --accent: #FF9E8A;
    --accent-dim: #C25A43;
    --steel: #5E7699;
    --steel-soft: #8FA3BF;
    --tile-paper: #F0EBE0;
    --good: #6fce8f;
    --bad: #e2686a;
    --mono: "SF Mono", "JetBrains Mono", Consolas, monospace;
    --sans: "Inter", system-ui, -apple-system, "Segoe UI", sans-serif;
  }
  *{ box-sizing:border-box; }
  html,body{ margin:0; padding:0; }
  body{
    background:
      radial-gradient(1200px 600px at 15% -10%, #2a2130 0%, transparent 60%),
      radial-gradient(1000px 500px at 110% 10%, #172231 0%, transparent 55%),
      var(--bg);
    color:var(--ink);
    font-family:var(--sans);
    min-height:100vh;
    padding:40px 24px 80px;
  }
  .wrap{ max-width:880px; margin:0 auto; }

  header{ margin-bottom:32px; }
  .eyebrow{
    font-family:var(--mono); font-size:12px; letter-spacing:.14em; text-transform:uppercase;
    color:var(--accent); margin:0 0 10px;
  }
  h1{
    font-size:34px; line-height:1.15; margin:0 0 12px; font-weight:700; letter-spacing:-0.01em;
  }
  h1 .box{
    display:inline-block; width:.62em; height:.62em; border-radius:3px;
    background:linear-gradient(135deg, var(--accent), #ffd08a);
    margin-right:2px; vertical-align:-0.05em;
  }
  p.lede{ color:var(--ink-dim); font-size:15.5px; line-height:1.6; max-width:62ch; margin:0; }
  p.lede code{ font-family:var(--mono); background:var(--panel-2); padding:1px 6px; border-radius:5px; font-size:.9em; color:var(--ink); }

  /* ————— three-option grid, laid out like the bento app icon: a tall tile
     on the left, two stacked tiles on the right (same proportions as the
     actual icon SVG — see docs/../site-src/landing.html) ————— */
  .bento-options{
    display:grid;
    grid-template-columns: 35fr 65fr;
    grid-template-rows: 1fr 1fr;
    gap:10px;
    margin:28px 0;
    height:230px;
  }
  .bento-opt{
    border-radius:14px; border:none; cursor:pointer;
    display:flex; flex-direction:column; align-items:flex-start; justify-content:flex-end;
    padding:18px; text-align:left; position:relative; overflow:hidden;
    font-family:var(--sans);
    transition:transform .12s ease, filter .12s ease, box-shadow .12s ease;
  }
  .bento-opt:hover{ filter:brightness(1.08); transform:translateY(-2px); }
  .bento-opt:active{ transform:translateY(0) scale(.98); }
  .bento-opt-icon{ font-size:22px; margin-bottom:8px; opacity:.85; }
  .bento-opt-label{ font-size:15.5px; font-weight:700; line-height:1.3; }
  .bento-opt-sub{ font-size:12px; font-weight:500; opacity:.8; margin-top:3px; }
  .bento-opt-left{
    grid-row:1 / 3;
    display:flex;
    flex-direction:column;
    gap:10px;
    padding:0;
    background:none;
    border:none;
  }
  .bento-opt-demo{
    flex:0 0 32%;
    background:linear-gradient(160deg, var(--steel-soft), var(--steel));
    color:#EEF2F7;
  }
  .bento-opt-paste{
    flex:1;
    background:var(--tile-paper);
    color:#16273E;
    border:2px dashed rgba(22,39,62,.25);
  }
  .bento-opt-paste.drag{ outline:2px dashed #16273E; outline-offset:-2px; }
  .bento-opt-topright{
    grid-row:1;
    background:linear-gradient(160deg, #FFB29B, var(--accent-dim));
    color:#2B120A;
  }
  .bento-opt-botright{
    grid-row:2;
    background:var(--tile-paper);
    color:#16273E;
  }
  .bento-opt-botright input{ display:none; }
  .bento-opt-botright.drag{ outline:2px dashed #16273E; outline-offset:-2px; }

  .credit{
    margin:32px 0 8px; font-size:16px; color:var(--ink-dim); line-height:1.6;
    border-left:2px solid var(--line); padding-left:12px;
  }
  .credit a{ color:var(--accent); text-decoration:none; border-bottom:1px solid var(--accent-dim); }

  #cards{ margin-top:28px; display:flex; flex-direction:column; gap:14px; }
  .items-row{ margin-top:14px; display:flex; flex-direction:column; }
  .card{
    /* Dieselbe schwarze Kachel wie die spaeter gespeicherten Praesentationen
       (.bento-deck-banner) - eine frisch entstehende Karte soll von Anfang an
       genauso aussehen wie das, was sie nach dem Speichern wird. */
    background:#0f0f0f; border:1px solid #2a2a2a; border-radius:8px;
    padding:10px 12px;
  }
  .card.error{ border-color:#5a2f31; background:#221819; }
  .card.merged{ border-color:#38bdf8; }
  .card.dragging{ opacity:.4; }
  .card-top{ display:flex; align-items:flex-start; justify-content:space-between; gap:12px; }
  .card-top-left{ display:flex; align-items:flex-start; gap:10px; min-width:0; }
  .card-grip{ cursor:grab; color:var(--ink-dim); font-size:16px; line-height:1; padding-top:2px; user-select:none; }
  .card-grip:active{ cursor:grabbing; }
  .card-name{ font-weight:600; font-size:15px; word-break:break-all; cursor:text; }
  .card-name-input{ font-weight:600; font-size:15px; padding:2px 6px; width:100%; }
  .card-meta{ font-family:var(--mono); font-size:12px; color:var(--ink-dim); margin-top:4px; }
  .pill{
    font-family:var(--mono); font-size:11px; padding:3px 8px; border-radius:100px;
    background:var(--panel-2); color:var(--ink-dim); white-space:nowrap;
  }
  .pill.ok{ color:var(--good); }
  .pill.err{ color:var(--bad); }
  .card .err-msg{ color:var(--bad); font-size:13px; margin-top:8px; line-height:1.5; }
  .card .warn-list{ margin:10px 0 0; padding-left:18px; color:var(--ink-dim); font-size:12.5px; line-height:1.6; }
  .actions{ display:flex; gap:6px; margin-top:10px; flex-wrap:wrap; }
  .actions-icons button, .actions-icons a{
    /* Gleiche Icon-Knopf-Optik wie bei den gespeicherten Bannern (siehe
       .bento-deck-banner-Buttons weiter unten) - dunkles Quadrat statt der
       bisherigen groesseren, helleren Kreise. Gilt auch fuer <a> (▶/✎/⬇ bei
       einer bereits gespeicherten Karte, wie bei den Bannern selbst). */
    width:28px; height:28px; flex:none; padding:0; font-size:14px; line-height:1;
    display:flex; align-items:center; justify-content:center; position:relative; overflow:hidden;
    background:#1e293b; color:#93c5fd; border:none; border-radius:6px; text-decoration:none;
    box-sizing:border-box;
  }
  .actions-icons button.primary{ background:#1e293b; color:#93c5fd; border-color:transparent; }
  .actions-icons button:hover, .actions-icons a:hover{ filter:brightness(1.2); }
  .actions-icons button.busy{ opacity:.6; cursor:wait; }
  .btn-progress{
    position:absolute; left:0; right:0; bottom:0; height:3px; background:transparent;
  }
  .actions-icons button.busy .btn-progress{
    background:linear-gradient(90deg, transparent, rgba(255,255,255,.9), transparent);
    background-size:60% 100%; animation:btn-progress-sweep 1s linear infinite;
  }
  @keyframes btn-progress-sweep{ from{ background-position:-60% 0; } to{ background-position:160% 0; } }
  .connector{
    /* Sitzt auf der Naht zwischen zwei Panels (Karten wie Banner) - die kleine
       Eigenhoehe (statt 0) gibt etwas Luft zwischen den Panels selbst, die
       Buttons (30px) ragen trotzdem noch gut sichtbar je zur Haelfte in das
       Panel darueber/darunter hinein, statt in einer eigenen Zeile mit
       Trennstrich zu sitzen. */
    display:flex; justify-content:center; align-items:center; gap:8px;
    height:10px; margin:0; position:relative; z-index:3;
  }
  .connector-btn{
    position:relative; width:30px; height:30px; border-radius:999px; padding:0;
    font-size:16px; line-height:1; display:grid; place-items:center;
    background:var(--panel-2); border:1px solid var(--line);
  }
  .connector-btn:hover{ background:var(--accent); color:#241205; border-color:var(--accent); }
  button{
    font-family:var(--sans); font-size:13px; font-weight:600; cursor:pointer;
    border-radius:9px; padding:9px 14px; border:1px solid var(--line);
    background:var(--panel-2); color:var(--ink); transition:transform .1s ease, border-color .1s ease;
  }
  button:hover{ border-color:var(--accent); }
  button:active{ transform:scale(.97); }
  button.primary{ background:var(--accent); color:#241205; border-color:var(--accent); }
  button.primary:hover{ background:#ffb27a; }
  button:disabled{ opacity:.4; cursor:default; }


  .howto{
    margin-top:36px; background:var(--panel); border:1px solid var(--line); border-radius:14px; padding:22px 24px;
  }
  .howto h3{ margin:0 0 12px; font-size:15px; }
  .howto ol{ margin:0; padding-left:20px; color:var(--ink-dim); font-size:13.5px; line-height:1.8; }
  .howto ol b{ color:var(--ink); }
  .howto a{ color:var(--accent); text-decoration:none; border-bottom:1px solid var(--accent-dim); }

  .progress{ font-family:var(--mono); font-size:12px; color:var(--ink-dim); margin-top:10px; min-height:16px; }

  .toast{
    position:fixed; bottom:24px; left:50%; transform:translateX(-50%) translateY(20px);
    background:var(--ink); color:#141414; font-size:13px; font-weight:600;
    padding:10px 18px; border-radius:16px; opacity:0; pointer-events:none;
    transition:opacity .2s ease, transform .2s ease;
    max-width:min(480px, calc(100vw - 48px)); text-align:center; line-height:1.4;
  }
  .toast.show{ opacity:1; transform:translateX(-50%) translateY(0); }

  /* ————— paste-and-split flow ————— */
  .paste-modal{
    position:fixed; inset:0; z-index:200; background:rgba(10,14,20,.55);
    display:none; align-items:center; justify-content:center; padding:24px;
  }
  .paste-modal.show{ display:flex; }
  .paste-modal-inner{
    background:var(--panel); border:1px solid var(--line); border-radius:16px;
    width:min(760px, 100%); max-height:88vh; overflow-y:auto; padding:26px 28px;
  }
  .paste-modal-inner h3{ margin:0 0 6px; font-size:17px; }
  .paste-modal-inner p{ margin:0 0 16px; font-size:13.5px; color:var(--ink-dim); line-height:1.6; }
  .paste-catcher{
    min-height:160px; border:2px dashed var(--line); border-radius:12px; padding:20px;
    font-size:14px; color:var(--ink-dim); display:flex; align-items:center; justify-content:center;
    text-align:center; outline:none; cursor:text;
  }
  .paste-catcher:focus{ border-color:var(--accent); }
  .paste-modal-close{
    position:absolute; top:16px; right:16px; width:32px; height:32px; border-radius:999px;
    border:none; background:var(--tile-paper); cursor:pointer; font-size:14px;
  }
  .paste-modal-inner{ position:relative; }

  .lr-step2-head{ display:flex; align-items:flex-start; justify-content:space-between; gap:16px; }
  .lr-view-toggle{
    flex:none; font-size:12px; font-weight:700; padding:7px 14px; border-radius:999px;
    border:1px solid var(--line); background:#fff; color:var(--ink); cursor:pointer; white-space:nowrap;
  }
  .lr-view-toggle.active{ background:var(--accent); color:#2B120A; border-color:transparent; }

  .lr-doc{
    border:1px solid var(--line); border-radius:12px; padding:20px 24px; min-height:200px;
    max-height:50vh; overflow-y:auto; background:#fff; color:#1a1a1a; font-size:14.5px; line-height:1.65;
    outline:none;
  }
  .lr-doc p{ margin:0 0 1em; }
  .lr-doc p.lr-h1{ font-size:1.7em; font-weight:700; }
  .lr-doc p.lr-h2{ font-size:1.45em; font-weight:700; }
  .lr-doc p.lr-h3{ font-size:1.25em; font-weight:700; }
  .lr-doc p.lr-h4, .lr-doc p.lr-h5, .lr-doc p.lr-h6{ font-size:1.1em; font-weight:700; }
  .lr-doc img{ max-width:100%; border-radius:6px; margin:0 0 1em; display:block; }
  .lr-doc .lr-zusatz{ background:#FFF7E8; padding:2px 6px; border-radius:4px; box-decoration-break:clone; -webkit-box-decoration-break:clone; }
  .lr-marker-break{
    user-select:none; margin:14px 0; padding:5px 0; border-top:2px solid var(--accent-dim);
    font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--accent-dim);
    text-align:center;
  }
  .lr-marker-mode{
    user-select:none; display:inline-block; margin:0 0 0.6em; font-size:11px; font-weight:700;
    color:#B8860B; background:#FFF3D6; padding:2px 8px; border-radius:999px;
  }

  .lr-ctxmenu{
    position:fixed; z-index:300; display:flex; gap:4px; padding:5px; background:#14161c;
    border-radius:8px; box-shadow:0 6px 18px rgba(0,0,0,.35);
  }
  .lr-ctxmenu button{
    border:none; background:rgba(255,255,255,.1); color:#fff; font-size:11.5px; padding:6px 10px;
    border-radius:6px; cursor:pointer; white-space:nowrap;
  }
  .lr-ctxmenu button:hover{ background:rgba(255,255,255,.22); }

  .lr-preview{
    border:1px solid var(--line); border-radius:12px; padding:16px 20px; max-height:50vh; overflow-y:auto;
    background:var(--tile-paper); color:#16273E;
  }
  .lr-preview-slide{
    border:1px solid rgba(22,39,62,.15); border-radius:10px; padding:12px 14px; margin-bottom:12px; background:#fff;
  }
  .lr-preview-slide h4{ margin:0 0 8px; font-size:12px; text-transform:uppercase; letter-spacing:.04em; color:var(--accent-dim); }
  .lr-preview-slide p{ margin:0 0 6px; font-size:13px; }
  .lr-preview-zusatz{ color:#7a6a3d; font-style:italic; }

  .lr-generate-btn{
    display:block; width:100%; margin-top:18px; padding:12px; border:none; border-radius:10px;
    background:var(--accent); color:#2B120A; font-weight:700; font-size:14px; cursor:pointer;
  }

  /* ————— Medien verkleinern / In Teile aufteilen ————— */
  .mb-modal-box{ width:min(640px, 100%); }
  .mb-modal-actions{ display:flex; justify-content:flex-end; gap:10px; margin-top:18px; }
  .mb-split-list{ max-height:44vh; overflow-y:auto; margin-bottom:8px; }
  .mb-split-row{ display:flex; align-items:center; gap:12px; padding:6px 0; }
  .mb-split-label{ font-size:13px; color:var(--ink-dim); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .mb-split-thumb{ position:relative; width:120px; height:67.5px; flex:none; border-radius:6px; overflow:hidden; background:var(--tile-paper); border:1px solid var(--line); }
  .mb-split-thumb-placeholder{ width:100%; height:100%; border:none; background:transparent; cursor:pointer; font-size:16px; color:var(--ink-dim); }
  .mb-split-thumb-spinner{ position:absolute; inset:0; display:flex; align-items:center; justify-content:center; font-size:11px; color:var(--ink-dim); }
  .mb-split-thumb-spinner::after{ content:'…'; }
  .mb-split-thumb-frame{ position:absolute; top:0; left:0; transform-origin:top left; border:none; pointer-events:none; }
  .mb-split-break{
    display:block; width:100%; margin:2px 0; padding:4px 10px; font-size:11px; text-align:left;
    border:1px dashed var(--line); border-radius:6px; background:transparent; color:var(--ink-dim); cursor:pointer;
  }
  .mb-split-break.active{ border-color:var(--accent); color:var(--accent-dim); border-style:solid; font-weight:700; }
  .mb-split-name-row{ display:flex; align-items:center; gap:10px; font-size:12px; margin-bottom:6px; }
  .mb-split-name-row span{ flex:0 0 160px; color:var(--ink-dim); }
  .mb-split-name-row input{ flex:1; padding:6px 8px; }
  .mb-shrink-settings{ display:flex; gap:16px; margin-bottom:10px; }
  .mb-shrink-settings label{ font-size:12px; color:var(--ink-dim); display:flex; flex-direction:column; gap:4px; }
  .mb-shrink-settings input{ width:100px; padding:6px 8px; }
  .mb-shrink-dupes{ display:block; font-size:12px; margin-bottom:10px; }
  .mb-shrink-list{ max-height:44vh; overflow-y:auto; }
  .mb-shrink-row{ display:flex; align-items:center; gap:12px; padding:6px 0; border-bottom:1px solid var(--line); }
  .mb-shrink-thumb{ width:64px; height:64px; object-fit:cover; border-radius:6px; flex:none; }
  .mb-shrink-size{ font-size:12px; color:var(--ink-dim); margin-left:auto; }
</style>
</head>
<body>
<div class="wrap">

  <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:6px;">
    <a href="infomaster.php" style="font-size:13px; color:#94a3b8; text-decoration:none;">← Zurück zum Infomaster-Dashboard</a>
    <a href="infomaster.php?logout=1" style="font-size:13px; color:#ff5252; text-decoration:none;">Abmelden</a>
  </div>
  <div style="background:#12233e; border:1px solid #1d3a63; border-radius:8px; padding:10px 14px; font-size:12.5px; color:#cbd5e1; margin-bottom:18px; line-height:1.5;">
    📺 <strong>Für Monitore:</strong> Präsentation unten erzeugen, dann bei der Karte auf
    „💾 Auf Server speichern (für Monitor)" klicken. Die dabei angezeigte Adresse im
    Infomaster-Dashboard bei einem Monitor als Inhalt „Webseite (URL)" eintragen — die
    gespeicherte Präsentation läuft dort automatisch als Endlos-Slideshow.<br>
    📝 <strong>Zum Weiterbearbeiten:</strong> stattdessen auf „Bearbeitbar auf Server speichern"
    klicken - die Datei erscheint danach unten in der Liste und lässt sich jederzeit wieder
    öffnen; der „Speichern"-Knopf im Editor selbst schreibt dann direkt in diese Datei zurück.
  </div>


  <header>
    <p class="eyebrow">PPTX → bento/slides</p>
    <h1><span class="box"></span>Präsentationen im Browser</h1>
    <p class="lede">
      Diese Seite wandelt <code>.pptx</code>- und <code>.ppt</code>-Präsentationen in HTML-Dateien um, die sich in
      jedem Browser abspielen lassen. Diese können heruntergeladen, gespeichert, im Browser
      geändert und wiederverwendet werden. Präsentationen lassen sich per Drag &amp; Drop
      sortieren und über die <b>✚</b>-Schaltfläche zwischen ihren Karten zu einer gemeinsamen
      Präsentation verbinden.
    </p>
  </header>

  <div class="bento-options">
    <div class="bento-opt-left">
      <button class="bento-opt bento-opt-demo" id="demoBtn">
        <span class="bento-opt-icon">▶</span>
        <span class="bento-opt-label">Demopräsentation zeigen</span>
        <span class="bento-opt-sub">Feature-Tour, 17 Folien</span>
      </button>
      <button class="bento-opt bento-opt-paste" id="pasteTile">
        <span class="bento-opt-icon">📋</span>
        <span class="bento-opt-label">Text einfügen</span>
        <span class="bento-opt-sub">Kopierter Text aus Webseite/PDF — wird auf mehrere Folien aufgeteilt</span>
      </button>
    </div>
    <button class="bento-opt bento-opt-topright" id="blankBtn">
      <span class="bento-opt-icon">✚</span>
      <span class="bento-opt-label">Mit neuer Präsentation starten</span>
    </button>
    <label class="bento-opt bento-opt-botright" id="importTile">
      <span class="bento-opt-icon">⇩</span>
      <span class="bento-opt-label">Dateien importieren</span>
      <input type="file" id="fileInput" accept=".pptx,.ppt,.json,.html,.htm" multiple>
    </label>
  </div>

  <div class="paste-modal" id="pasteModal">
    <div class="paste-modal-inner" id="pasteModalInner">
      <button class="paste-modal-close" id="pasteModalClose">✕</button>
      <div id="pasteStep1">
        <h3>Text einfügen</h3>
        <p>Text aus einer Webseite oder einem PDF kopieren, dann hier mit Strg+V (Cmd+V auf dem Mac) einfügen. Bilder im kopierten Inhalt werden mit übernommen — diese Version läuft auf einem eigenen PHP-Server und holt Bilder über einen eingebauten Proxy, unabhängig von Sicherheitsbeschränkungen (CORS) der jeweiligen Webseite.</p>
        <div class="paste-catcher" id="pasteCatcher" contenteditable="true" data-placeholder="Hier klicken und einfügen…">Hier klicken und einfügen…</div>
      </div>
      <div id="pasteStep2" style="display:none">
        <div class="lr-step2-head">
          <div>
            <h3 style="display:inline">Text bearbeiten</h3>
            <p style="margin-bottom:8px">Cursor irgendwo in den Text setzen — ein kleines Menü über der Schreibmarke lässt eine Folie enden oder zwischen Folieninhalt und Zusatztext wechseln, ab genau dieser Stelle. Nichts wurde automatisch erkannt.</p>
          </div>
          <button class="lr-view-toggle" id="lrViewToggle">Folienansicht</button>
        </div>
        <div class="lr-doc" id="lrDoc" contenteditable="true"></div>
        <div class="lr-preview" id="lrPreview" style="display:none"></div>
        <button class="lr-generate-btn" id="lrGenerateBtn">Folien erzeugen</button>
      </div>
    </div>
  </div>
  <div class="lr-ctxmenu" id="lrCtxMenu" style="display:none">
    <button id="lrCtxEndSlide">▪ Folie endet hier</button>
    <button id="lrCtxToggleMode">↕ Wechsel Folientext/Zusatztext</button>
  </div>

  <div id="cards"></div>
  <div id="items" class="items-row"></div>

  <?php
    $bentoDecksDir = 'media/bentos';
    $bentoDeckFiles = bentoLoadDeckOrder($bentoDecksDir); // neueste oben, sonst zuletzt gespeicherte/manuelle Reihenfolge
  ?>
  <?php if (!empty($bentoDeckFiles)): ?>
  <div style="margin-bottom:18px;">
    <strong style="font-size:13px; color:#e2e8f0; display:block; margin-bottom:10px;">📝 Gespeicherte bearbeitbare Präsentationen</strong>
    <div id="bentoDeckBanners" style="display:flex; flex-direction:column; max-height:420px; overflow-y:auto;">
      <?php foreach ($bentoDeckFiles as $deckIdx => $deckFile):
        $deckPath = $bentoDecksDir . '/' . $deckFile;
        $deckMeta = bentoReadDeckMeta($deckPath);
        $deckTitle = $deckMeta['title'] !== null && $deckMeta['title'] !== '' ? $deckMeta['title'] : $deckFile;
        $deckUrl = $bentoDecksDir . '/' . $deckFile;
        // Autostart + Endlos-Loop + 8-Sekunden-Takt, damit ein einzeln geöffneter Link (▶
        // wie 🔗) sich genau wie eine Monitor-Slideshow verhält, obwohl die Datei selbst
        // weiterhin bearbeitbar (nicht readonly) bleibt - siehe main.ts's #present-Handler
        // im bento-Projekt (liest dieselben ?interval=<Sekunden>&loop-Parameter wie die
        // schreibgeschützten "für Monitor"-Exporte).
        // Absolut (Schema+Host+Pfad), nicht nur relativ zu bento.php - ein per 🔗
        // kopierter Link muss auch AUSSERHALB des Browsers (E-Mail, Infomaster-
        // Monitor-URL-Feld, …) funktionieren, wo es keine "aktuelle Seite" gibt,
        // zu der ein relativer Pfad aufgeloest werden koennte.
        $deckPlayUrl = bentoServerUrlFor($deckUrl) . '?autostart=1&loop&interval=8#present';
        $deckSize = @filesize($deckPath);
        $deckMtime = @filemtime($deckPath);
      ?>
      <div class="bento-deck-banner" data-file="<?php echo htmlspecialchars($deckFile); ?>" data-size="<?php echo $deckSize !== false ? (int)$deckSize : 0; ?>" data-url="<?php echo htmlspecialchars(bentoServerUrlFor($deckUrl)); ?>" data-play-url="<?php echo htmlspecialchars($deckPlayUrl); ?>" style="display:flex; align-items:center; gap:10px; background:#0f0f0f; border:1px solid #2a2a2a; border-radius:8px; padding:10px 12px;">
        <div style="flex:1; min-width:0;">
          <div class="bento-deck-title" tabindex="0" title="Gedrückt halten zum Umbenennen" style="font-size:13px; color:#e2e8f0; font-weight:600; cursor:text; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; user-select:none;"><?php echo htmlspecialchars($deckTitle); ?></div>
          <div style="font-size:11px; color:#8a8a8a; margin-top:2px;">
            <?php if ($deckMeta['slideCount'] !== null): ?><?php echo (int)$deckMeta['slideCount']; ?> Folie<?php echo $deckMeta['slideCount'] === 1 ? '' : 'n'; ?> · <?php endif; ?>
            <?php if ($deckSize !== false): ?><?php echo bentoFormatBytes((int)$deckSize); ?> · <?php endif; ?>
            <?php if ($deckMtime !== false): ?><?php echo date('d.m.Y H:i', $deckMtime); ?><?php endif; ?>
          </div>
        </div>
        <div style="display:flex; gap:6px; flex:0 0 auto; align-items:center;">
          <a href="<?php echo htmlspecialchars($deckPlayUrl); ?>" target="_blank" rel="noopener" title="Präsentation starten (Endlos-Loop, 8s/Folie)" style="width:28px; height:28px; display:flex; align-items:center; justify-content:center; background:#1e293b; color:#93c5fd; border-radius:6px; text-decoration:none;">▶</a>
          <a href="<?php echo htmlspecialchars($deckUrl); ?>" target="_blank" rel="noopener" title="Bearbeiten (im vollen Editor)" style="width:28px; height:28px; display:flex; align-items:center; justify-content:center; background:#1e293b; color:#93c5fd; border-radius:6px; text-decoration:none;">✎</a>
          <button type="button" class="bento-deck-load-btn" data-purpose="shrink" title="Hier unten laden, um Medien zu verkleinern" style="width:28px; height:28px; display:flex; align-items:center; justify-content:center; background:#1e293b; color:#93c5fd; border-radius:6px; border:none; cursor:pointer; font-size:14px;">🗜</button>
          <?php if ($deckMeta['slideCount'] === null || $deckMeta['slideCount'] > 1): ?>
          <button type="button" class="bento-deck-load-btn" data-purpose="split" title="Hier unten laden, um in Teile aufzuteilen" style="width:28px; height:28px; display:flex; align-items:center; justify-content:center; background:#1e293b; color:#93c5fd; border-radius:6px; border:none; cursor:pointer; font-size:14px;">✂️</button>
          <?php endif; ?>
          <a href="<?php echo htmlspecialchars($deckUrl); ?>" download title="Als .bento.html herunterladen" style="width:28px; height:28px; display:flex; align-items:center; justify-content:center; background:#1e293b; color:#93c5fd; border-radius:6px; text-decoration:none;">⬇</a>
          <form method="POST" action="?api=delete_deck" onsubmit="return confirm('&quot;<?php echo htmlspecialchars(addslashes($deckTitle)); ?>&quot; wirklich löschen?');" style="margin:0;">
            <input type="hidden" name="file" value="<?php echo htmlspecialchars($deckFile); ?>">
            <button type="submit" title="Löschen" style="width:28px; height:28px; display:flex; align-items:center; justify-content:center; background:#7f1d1d; color:#fff; border:none; border-radius:6px; cursor:pointer;">✕</button>
          </form>
          <button type="button" class="bento-deck-link-btn" title="Auf Monitor legen / Link kopieren" style="width:28px; height:28px; display:flex; align-items:center; justify-content:center; background:#1e293b; color:#93c5fd; border-radius:6px; border:none; cursor:pointer; font-size:14px;">🔗</button>
        </div>
      </div>
      <?php if ($deckIdx < count($bentoDeckFiles) - 1):
        $nextFile = $bentoDeckFiles[$deckIdx + 1];
      ?>
      <div class="connector bento-deck-connector" data-file-a="<?php echo htmlspecialchars($deckFile); ?>" data-file-b="<?php echo htmlspecialchars($nextFile); ?>">
        <button type="button" class="connector-btn bento-deck-swap-btn" title="Position tauschen">⇅</button>
        <button type="button" class="connector-btn bento-deck-connect-btn" title="Verbinden">✚</button>
      </div>
      <?php endif; ?>
      <?php endforeach; ?>
    </div>
  </div>
  <script>
  // Umbenennen wie bei moodle-mod_bento's eigenen Deck-Bannern, aber per langem
  // Klick statt Doppelklick (auf Wunsch): Maustaste ~550ms gedrueckt halten,
  // ohne den Zeiger zu bewegen -> Inline-Eingabefeld -> Enter/Blur speichert
  // per ?api=rename_deck, Escape bricht ab. Aendert nur den Dateinamen (siehe
  // PHP-Endpunkt weiter oben); der interne Titel im Dokument selbst bleibt
  // unangetastet.
  document.querySelectorAll('.bento-deck-title').forEach(function(titleEl){
    var pressTimer = null;
    var startX = 0, startY = 0;
    function cancelPress(){ if (pressTimer) { clearTimeout(pressTimer); pressTimer = null; } }
    titleEl.addEventListener('mousedown', function(ev){
      startX = ev.clientX; startY = ev.clientY;
      pressTimer = setTimeout(function(){ pressTimer = null; startRename(); }, 550);
    });
    titleEl.addEventListener('mousemove', function(ev){
      if (pressTimer && (Math.abs(ev.clientX - startX) > 6 || Math.abs(ev.clientY - startY) > 6)) cancelPress();
    });
    titleEl.addEventListener('mouseup', cancelPress);
    titleEl.addEventListener('mouseleave', cancelPress);
    function startRename(){
      var banner = titleEl.closest('.bento-deck-banner');
      var file = banner.getAttribute('data-file');
      var original = titleEl.textContent;
      var input = document.createElement('input');
      input.type = 'text';
      input.value = original;
      input.style.cssText = 'font-size:13px; padding:3px 6px; width:100%; background:#000; color:#fff; border:1px solid #38bdf8; border-radius:4px;';
      titleEl.replaceWith(input);
      input.focus();
      input.select();
      var done = false;
      function commit(save){
        if (done) return; done = true;
        var next = input.value.trim();
        if (!save || !next || next === original) { input.replaceWith(titleEl); return; }
        input.disabled = true;
        var fd = new FormData();
        fd.append('file', file);
        fd.append('new_name', next);
        fetch('?api=rename_deck', { method: 'POST', body: fd })
          .then(function(r){ return r.json(); })
          .then(function(data){
            if (!data.ok) { alert('Konnte nicht umbenennen: ' + (data.error || '?')); input.replaceWith(titleEl); return; }
            location.reload(); // Dateiname im data-file-Attribut + Buttons muessen neu aufgebaut werden
          })
          .catch(function(e){ alert('Konnte nicht umbenennen: ' + e); input.replaceWith(titleEl); });
      }
      input.addEventListener('keydown', function(ev){
        if (ev.key === 'Enter') { ev.preventDefault(); commit(true); }
        else if (ev.key === 'Escape') { ev.preventDefault(); commit(false); }
      });
      input.addEventListener('blur', function(){ commit(true); });
    }
  });

  // Laedt eine bereits gespeicherte Praesentation unten in die Kartenansicht ein
  // (derselbe Import-Weg wie ein per Drag&Drop abgelegtes .bento.html - handleFiles,
  // weiter unten definiert, ist zum Zeitpunkt eines tatsaechlichen Klicks laengst
  // vorhanden) - von dort aus stehen dieselben Karten-Aktionen (🗜 Medien
  // verkleinern, ✂️ In Teile aufteilen, erneut speichern, …) zur Verfuegung wie
  // fuer eine frisch konvertierte. "shrink" und "split" fuehren beide hierher,
  // nur mit unterschiedlichem Icon/Tooltip als Eintrittspunkt.
  async function bentoLoadDeckFileIntoCards(file){
    var resp = await fetch('media/bentos/' + encodeURIComponent(file));
    if (!resp.ok) throw new Error('HTTP ' + resp.status);
    var text = await resp.text();
    var blob = new Blob([text], { type: 'text/html' });
    return new File([blob], file, { type: 'text/html' });
  }
  document.querySelectorAll('.bento-deck-load-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
      var banner = btn.closest('.bento-deck-banner');
      var file = banner.getAttribute('data-file');
      var url = banner.getAttribute('data-url');
      var original = btn.textContent;
      btn.disabled = true;
      btn.textContent = '…';
      bentoLoadDeckFileIntoCards(file)
        .then(function(syntheticFile){
          // queueDecision:false - diese Datei liegt bereits auf dem Server,
          // kein Speichern/Betrachten/Herunterladen-Dialog noetig; savedFile/
          // savedUrl direkt setzen, damit die Karte sofort dieselben Knoepfe
          // wie der Banner zeigt (▶✎🗜✂️⬇✕🔗), statt kurz als "unsaved" aufzublitzen.
          return handleFiles([syntheticFile], { queueDecision: false }).then(function(){
            var newItem = items[items.length - 1];
            if (newItem) { newItem.savedFile = file; newItem.savedUrl = url; renderItems(); }
            document.querySelector('#items')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
          });
        })
        .catch(function(e){ alert('Konnte die Datei nicht laden: ' + (e.message || e)); })
        .finally(function(){ btn.disabled = false; btn.textContent = original; });
    });
  });

  document.querySelectorAll('.bento-deck-link-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
      var banner = btn.closest('.bento-deck-banner');
      var url = banner.getAttribute('data-play-url');
      openMonitorAssignModal(url);
    });
  });

  // ⇅ Position tauschen: vertauscht zwei benachbarte Banner in der Liste
  // (persistiert ueber ?api=swap_decks, siehe bentoSwapInOrder() in PHP).
  document.querySelectorAll('.bento-deck-swap-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
      var connector = btn.closest('.bento-deck-connector');
      var fileA = connector.getAttribute('data-file-a');
      var fileB = connector.getAttribute('data-file-b');
      btn.disabled = true;
      var fd = new FormData();
      fd.append('file_a', fileA);
      fd.append('file_b', fileB);
      fetch('?api=swap_decks', { method: 'POST', body: fd })
        .then(function(r){ return r.json(); })
        .then(function(data){
          if (!data.ok) { alert('Konnte Reihenfolge nicht ändern: ' + (data.error || '?')); btn.disabled = false; return; }
          location.reload();
        })
        .catch(function(e){ alert('Konnte Reihenfolge nicht ändern: ' + e); btn.disabled = false; });
    });
  });

  // ✚ Verbinden (zwischen zwei bereits gespeicherten Praesentationen, wie im
  // moodle-Plugin zwischen den Deck-Bannern): beide Dateien unten in die
  // Kartenansicht laden und dort per derselben mergeItems()-Logik verbinden
  // wie bei frisch konvertierten Karten - Ergebnis ist eine neue, noch
  // UNGESPEICHERTE Karte, die erst per "Bearbeitbar speichern" persistiert
  // wird (kein automatisches Ueberschreiben der beiden Originaldateien).
  document.querySelectorAll('.bento-deck-connect-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
      var connector = btn.closest('.bento-deck-connector');
      var fileA = connector.getAttribute('data-file-a');
      var fileB = connector.getAttribute('data-file-b');
      var bannerA = document.querySelector('.bento-deck-banner[data-file="' + CSS.escape(fileA) + '"]');
      var bannerB = document.querySelector('.bento-deck-banner[data-file="' + CSS.escape(fileB) + '"]');
      var sizeA = parseInt((bannerA && bannerA.getAttribute('data-size')) || '0', 10);
      var sizeB = parseInt((bannerB && bannerB.getAttribute('data-size')) || '0', 10);
      var totalMb = (sizeA + sizeB) / (1024 * 1024);
      var msg = '"' + fileA + '" und "' + fileB + '" zu einer gemeinsamen Präsentation verbinden?';
      if (totalMb >= 20) {
        msg += '\n\nHinweis: Die verbundene Präsentation wird voraussichtlich rund ' + totalMb.toFixed(1) + ' MB groß - das kann je nach Verbindung länger dauern und im Browser spürbar mehr Arbeitsspeicher brauchen.';
      }
      if (!confirm(msg)) return;
      btn.disabled = true;
      Promise.all([bentoLoadDeckFileIntoCards(fileA), bentoLoadDeckFileIntoCards(fileB)])
        .then(function(files){
          var startLen = items.length;
          // queueDecision:false - erst der VERBUNDENE Zwischenstand ist neu und
          // bekommt (in mergeItems()) den Speichern/Betrachten/Herunterladen-
          // Dialog, nicht schon die beiden einzeln nachgeladenen Originale.
          return handleFiles(files, { queueDecision: false }).then(function(){
            if (items.length === startLen + 2) {
              mergeItems(startLen, startLen + 1);
              document.querySelector('#items')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
            } else {
              toast('Verbinden fehlgeschlagen: mindestens eine Datei konnte nicht geladen werden.');
            }
          });
        })
        .catch(function(e){ alert('Konnte nicht verbinden: ' + (e.message || e)); })
        .finally(function(){ btn.disabled = false; });
    });
  });
  </script>
  <?php endif; ?>

  <div class="credit">
    → Die Grundlage dieses Projekts ist <a href="https://github.com/nyblnet/bento" target="_blank" rel="noopener">bento.page</a> —
    please star the project.
  </div>

</div>

<div class="toast" id="toast">Kopiert</div>

<script type="text/plain" id="bento-demo-b64" data-source="bento starterdeck.ts">__BENTO_DEMO_B64__</script>
<script type="text/plain" id="bento-shell-b64" data-bento-version="__BENTO_VERSION__" data-bundled="__BENTO_BUILD_DATE__">__BENTO_SHELL_B64__</script>
<script type="application/json" id="bento-screens-json"><?php echo json_encode($bentoScreensForModal, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?></script>
<script>
const EMU_PER_PX = 9525; // 96 dpi
const emuToPx = v => Math.round((v||0) / EMU_PER_PX);
const ptToPx = pt => Math.round(pt * (96/72));
const rotToDeg = rot => rot ? Math.round(parseInt(rot,10) / 60000) : 0;

function esc(s){
  return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
function uuid(){
  if (crypto.randomUUID) return crypto.randomUUID();
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
    const r = Math.random()*16|0, v = c==='x'?r:(r&0x3|0x8);
    return v.toString(16);
  });
}

// ---- XML helpers (prefixed tag names work fine via getElementsByTagName in browsers) ----
function first(el, tag){ const l = el.getElementsByTagName(tag); return l.length ? l[0] : null; }
function all(el, tag){ return Array.from(el.getElementsByTagName(tag)); }

// Direct children only. first()/all() search the WHOLE subtree, which is
// wrong for anything with nested look-alikes: "the first a:solidFill under
// p:spPr" is the OUTLINE colour when the shape itself has no fill of its own
// (a:ln/a:solidFill is a descendant of spPr too) — that exact mix-up painted
// outlined-but-unfilled shapes in their line colour.
function kid(el, tag){
  if (!el) return null;
  for (const c of el.children) if (c.tagName === tag) return c;
  return null;
}
function kids(el, tag){ return el ? Array.from(el.children).filter(c => !tag || c.tagName === tag) : []; }
function intAttr(el, name, dflt){
  const v = el ? el.getAttribute(name) : null;
  const n = v == null ? NaN : parseInt(v, 10);
  return Number.isFinite(n) ? n : dflt;
}
function parseXml(text){ return new DOMParser().parseFromString(text, 'application/xml'); }
const clamp01 = v => Math.max(0, Math.min(1, v));

function parseThemeColors(themeXmlDoc){
  const map = {};
  if (!themeXmlDoc) return map;
  const scheme = first(themeXmlDoc, 'a:clrScheme');
  if (!scheme) return map;
  const slots = ['dk1','lt1','dk2','lt2','accent1','accent2','accent3','accent4','accent5','accent6','hlink','folHlink'];
  for (const slot of slots){
    const node = first(scheme, 'a:'+slot);
    if (!node) continue;
    const srgb = first(node, 'a:srgbClr');
    const sys = first(node, 'a:sysClr');
    if (srgb) map[slot] = '#'+srgb.getAttribute('val');
    else if (sys) map[slot] = '#'+(sys.getAttribute('lastClr') || (sys.getAttribute('val') === 'window' ? 'FFFFFF' : '000000'));
  }
  return map;
}

// A run's own <a:latin typeface="…"/> very often isn't a real font name at
// all — it's a theme PLACEHOLDER ("+mj-lt"/"+mn-lt", "major"/"minor" latin),
// meaning "whatever this theme's own major/minor font is". Most real-world
// text never sets an explicit typeface per run at all — it just inherits
// the theme's own default, which is exactly this placeholder path.
function parseThemeFonts(themeXmlDoc){
  const map = { major: null, minor: null };
  if (!themeXmlDoc) return map;
  const scheme = first(themeXmlDoc, 'a:fontScheme');
  if (!scheme) return map;
  const majorFont = first(scheme, 'a:majorFont');
  const minorFont = first(scheme, 'a:minorFont');
  const majorLatin = majorFont ? first(majorFont, 'a:latin') : null;
  const minorLatin = minorFont ? first(minorFont, 'a:latin') : null;
  if (majorLatin && majorLatin.getAttribute('typeface')) map.major = majorLatin.getAttribute('typeface');
  if (minorLatin && minorLatin.getAttribute('typeface')) map.minor = minorLatin.getAttribute('typeface');
  return map;
}

// The theme's format scheme: the fill/line/background style lists that
// p:style's fillRef/lnRef and p:bgRef point INTO by index. Without them a
// "shape style" shape could only ever take the ref's bare colour, never the
// gradient or tint the style actually paints.
function parseTheme(themeXmlDoc){
  const fmt = themeXmlDoc ? first(themeXmlDoc, 'a:fmtScheme') : null;
  const lst = name => { const l = fmt ? kid(fmt, name) : null; return l ? kids(l) : []; };
  return {
    colors: parseThemeColors(themeXmlDoc),
    fonts: parseThemeFonts(themeXmlDoc),
    fillStyles: lst('a:fillStyleLst'),
    lnStyles: lst('a:lnStyleLst'),
    bgFillStyles: lst('a:bgFillStyleLst'),
  };
}

// Resolves a raw typeface value against the theme's own major/minor fonts.
// Returns null (not the generic fallback) when nothing usable was found,
// so the CALLER decides what a sensible final fallback looks like.
function resolveFontFamily(raw, themeFonts){
  if (!raw) return null;
  if (/^\+mj-/.test(raw)) return themeFonts.major || null;
  if (/^\+mn-/.test(raw)) return themeFonts.minor || null;
  return raw;
}
// A lone family name falls back to the BROWSER default when it is missing
// (usually a serif Times) — a generic tail keeps the look close.
function fontStack(name){
  if (!name) return 'system-ui, sans-serif';
  if (/,/.test(name)) return name;
  if (!/^[a-zA-Z0-9 '"-]+$/.test(name)) return 'system-ui, sans-serif';
  const serif = /(times|georgia|garamond|cambria|palatino|book|serif|minion|baskerville)/i.test(name) && !/sans/i.test(name);
  return name + (serif ? ', serif' : ', sans-serif');
}

function rgbToHsl(r, g, b){
  r/=255; g/=255; b/=255;
  const max = Math.max(r,g,b), min = Math.min(r,g,b);
  let h=0, s=0; const l=(max+min)/2;
  const d = max-min;
  if (d !== 0){
    s = l > 0.5 ? d/(2-max-min) : d/(max+min);
    if (max===r) h=((g-b)/d + (g<b?6:0));
    else if (max===g) h=(b-r)/d + 2;
    else h=(r-g)/d + 4;
    h/=6;
  }
  return [h,s,l];
}
function hslToRgb(h,s,l){
  if (s===0){ const v=Math.round(l*255); return [v,v,v]; }
  const hue2rgb=(p,q,t)=>{ if(t<0)t+=1; if(t>1)t-=1; if(t<1/6)return p+(q-p)*6*t; if(t<1/2)return q; if(t<2/3)return p+(q-p)*(2/3-t)*6; return p; };
  const q = l < 0.5 ? l*(1+s) : l+s-l*s;
  const p = 2*l-q;
  return [Math.round(hue2rgb(p,q,h+1/3)*255), Math.round(hue2rgb(p,q,h)*255), Math.round(hue2rgb(p,q,h-1/3)*255)];
}
function hexToRgb(hex){
  const h = hex.replace('#','');
  return [parseInt(h.substr(0,2),16), parseInt(h.substr(2,2),16), parseInt(h.substr(4,2),16)];
}
function rgbToHex(r,g,b){
  const c = v => Math.max(0,Math.min(255,Math.round(v))).toString(16).padStart(2,'0');
  return ('#'+c(r)+c(g)+c(b)).toUpperCase();
}
const srgbToLin = c => { c /= 255; return c <= 0.04045 ? c/12.92 : Math.pow((c+0.055)/1.055, 2.4); };
const linToSrgb = v => { v = clamp01(v); return 255 * (v <= 0.0031308 ? v*12.92 : 1.055*Math.pow(v, 1/2.4) - 0.055); };

const PRESET_COLORS = {
  black:'000000', white:'FFFFFF', red:'FF0000', green:'008000', blue:'0000FF', yellow:'FFFF00',
  cyan:'00FFFF', magenta:'FF00FF', gray:'808080', grey:'808080', dkGray:'A9A9A9', darkGray:'A9A9A9',
  ltGray:'D3D3D3', lightGray:'D3D3D3', silver:'C0C0C0', orange:'FFA500', purple:'800080',
  navy:'000080', maroon:'800000', olive:'808000', teal:'008080', lime:'00FF00', brown:'A52A2A',
  pink:'FFC0CB', gold:'FFD700', dkBlue:'00008B', dkRed:'8B0000', dkGreen:'006400', ltBlue:'ADD8E6',
  ltGreen:'90EE90', ltYellow:'FFFFE0',
};
const COLOR_TAGS = new Set(['a:srgbClr','a:schemeClr','a:sysClr','a:prstClr','a:scrgbClr','a:hslClr']);
function colorChild(container){
  if (!container) return null;
  for (const c of container.children) if (COLOR_TAGS.has(c.tagName)) return c;
  return null;
}

// The slide's colour map (p:clrMap on the master, optionally overridden by
// the layout's / slide's p:clrMapOvr) is what turns the ROLE names text and
// backgrounds use — tx1/bg1/tx2/bg2 — into actual theme slots. A dark
// template maps tx1→lt1 and bg1→dk1; the old hard-wired tx1→dk1 painted
// every inherited text colour of such a deck dark-on-dark.
const DEFAULT_CLR_MAP = { bg1:'lt1', tx1:'dk1', bg2:'lt2', tx2:'dk2' };
function clrMapFrom(el, base){
  const m = Object.assign({}, base || DEFAULT_CLR_MAP);
  if (el) for (const a of Array.from(el.attributes)) if (!a.name.includes(':')) m[a.name] = a.value;
  return m;
}
function clrMapOverride(root, base){
  const ovr = root ? first(root, 'p:clrMapOvr') : null;
  const o = ovr ? kid(ovr, 'a:overrideClrMapping') : null;
  return o ? clrMapFrom(o, base) : base;
}

// One colour node → { rgb:[r,g,b], a } (or null when it doesn't resolve).
// Transforms apply IN DOCUMENT ORDER, as PowerPoint does: shade/tint in
// linear light (the sRGB version came out visibly too dark/too washed),
// lumMod/lumOff/satMod in HSL. `phClr` is the placeholder colour a theme
// style (fillRef/lnRef/bgRef) was invoked with.
function resolveClr(node, pal, phClr){
  if (!node) return null;
  let rgb = null, a = 1;
  const val = node.getAttribute('val');
  switch (node.tagName){
    case 'a:srgbClr': if (/^[0-9a-f]{6}$/i.test(val || '')) rgb = hexToRgb(val); break;
    case 'a:sysClr': {
      const lc = node.getAttribute('lastClr');
      rgb = hexToRgb(lc && /^[0-9a-f]{6}$/i.test(lc) ? lc : (/^(window|highlightText|btnHighlight)$/.test(val || '') ? 'FFFFFF' : '000000'));
      break;
    }
    case 'a:prstClr': if (PRESET_COLORS[val]) rgb = hexToRgb(PRESET_COLORS[val]); break;
    case 'a:scrgbClr': rgb = ['r','g','b'].map(k => linToSrgb(intAttr(node, k, 0) / 100000)); break;
    case 'a:hslClr': rgb = hslToRgb(intAttr(node, 'hue', 0) / 21600000, clamp01(intAttr(node, 'sat', 0) / 100000), clamp01(intAttr(node, 'lum', 0) / 100000)); break;
    case 'a:schemeClr': {
      if (val === 'phClr'){ if (phClr){ rgb = phClr.rgb.slice(); a = phClr.a; } break; }
      const key = (pal.map && pal.map[val]) || val;
      const hex = pal.colors[key];
      if (hex) rgb = hexToRgb(hex);
      break;
    }
  }
  if (!rgb) return null;
  let [r, g, b] = rgb;
  for (const m of node.children){
    const f = intAttr(m, 'val', 0) / 100000;
    switch (m.tagName){
      case 'a:alpha': a = f; break;
      case 'a:alphaMod': a *= f; break;
      case 'a:alphaOff': a += f; break;
      case 'a:shade': [r, g, b] = [r, g, b].map(c => linToSrgb(srgbToLin(c) * f)); break;
      case 'a:tint': [r, g, b] = [r, g, b].map(c => linToSrgb(srgbToLin(c) * f + (1 - f))); break;
      case 'a:inv': [r, g, b] = [255 - r, 255 - g, 255 - b]; break;
      case 'a:gray': { const y = 0.2126*r + 0.7152*g + 0.0722*b; r = g = b = y; break; }
      case 'a:lumMod': case 'a:lumOff': case 'a:satMod': case 'a:satOff': case 'a:hueOff': case 'a:hueMod': {
        let [h, s, l] = rgbToHsl(r, g, b);
        if (m.tagName === 'a:lumMod') l *= f;
        else if (m.tagName === 'a:lumOff') l += f;
        else if (m.tagName === 'a:satMod') s *= f;
        else if (m.tagName === 'a:satOff') s += f;
        else if (m.tagName === 'a:hueOff') h += intAttr(m, 'val', 0) / 21600000;
        else h *= f;
        [r, g, b] = hslToRgb(((h % 1) + 1) % 1, clamp01(s), clamp01(l));
        break;
      }
    }
  }
  return { rgb: [r, g, b], a: clamp01(a) };
}
function clrCss(c){
  if (!c) return null;
  if (c.a >= 0.995) return rgbToHex(c.rgb[0], c.rgb[1], c.rgb[2]);
  const [r, g, b] = c.rgb.map(v => Math.max(0, Math.min(255, Math.round(v))));
  return 'rgba(' + r + ',' + g + ',' + b + ',' + (Math.round(c.a * 1000) / 1000) + ')';
}
// A colour ROLE (tx1, bg1, accent2…) through the slide's colour map.
function roleColor(pal, role){
  const hex = pal.colors[(pal.map && pal.map[role]) || role];
  return hex ? rgbToHex(...hexToRgb(hex)) : null;
}
function colorIn(container, pal, phClr){ return resolveClr(colorChild(container), pal, phClr); }

// A fill declaration → null (this level says nothing) | {kind:'none'} |
// {kind:'solid', color} | {kind:'grad', color, grad} | {kind:'blip', el} |
// {kind:'grp'}. `color` on a gradient is its first stop — the solid
// fallback bento keeps beside every gradient.
function fillNode(c, pal, phClr){
  switch (c.tagName){
    case 'a:noFill': return { kind: 'none' };
    case 'a:solidFill': { const col = colorIn(c, pal, phClr); return col ? { kind: 'solid', color: col } : null; }
    case 'a:gradFill': { const g = gradOf(c, pal, phClr); return g ? { kind: 'grad', color: g.first, grad: g.grad } : null; }
    case 'a:pattFill': { const col = colorIn(kid(c, 'a:fgClr'), pal, phClr) || colorIn(kid(c, 'a:bgClr'), pal, phClr); return col ? { kind: 'solid', color: col } : null; }
    case 'a:blipFill': return { kind: 'blip', el: c };
    case 'a:grpFill': return { kind: 'grp' };
  }
  return undefined;
}
function fillOf(parent, pal, phClr){
  if (!parent) return null;
  for (const c of parent.children){
    const f = fillNode(c, pal, phClr);
    if (f !== undefined) return f;
  }
  return null;
}
function gradOf(gradFill, pal, phClr){
  const lst = kid(gradFill, 'a:gsLst');
  if (!lst) return null;
  const stops = kids(lst, 'a:gs')
    .map(gs => ({ at: clamp01(intAttr(gs, 'pos', 0) / 100000), c: colorIn(gs, pal, phClr) }))
    .filter(s => s.c)
    .sort((x, y) => x.at - y.at);
  if (!stops.length) return null;
  // DrawingML a:lin ang: 0 = left→right, clockwise, in 60000ths of a degree.
  // CSS: 90deg = left→right. Radial/path gradients have no bento counterpart
  // and read closest as top→bottom.
  const lin = kid(gradFill, 'a:lin');
  const angle = lin ? Math.round(((intAttr(lin, 'ang', 0) / 60000) + 90) % 360) : 180;
  return { first: stops[0].c, grad: { angle, stops: stops.map(s => ({ at: Math.round(s.at * 1000) / 1000, color: clrCss(s.c) })) } };
}
// p:style's fillRef / p:bgRef: an index into the theme's style lists,
// invoked with the ref's own colour as phClr. idx 0 = no fill; 1..999 =
// fillStyleLst; 1001+ = bgFillStyleLst.
function themeFillRef(ref, theme, pal){
  if (!ref) return null;
  const idx = intAttr(ref, 'idx', 0);
  if (!idx) return { kind: 'none' };
  const phClr = colorIn(ref, pal, null);
  const styleEl = idx >= 1001 ? theme.bgFillStyles[idx - 1001] : theme.fillStyles[idx - 1];
  const f = styleEl ? fillNode(styleEl, pal, phClr) : null;
  if (f && f.kind !== 'blip' && f.kind !== 'grp') return f;
  return phClr ? { kind: 'solid', color: phClr } : null;
}
function styleRef(node, name){
  const st = kid(node, 'p:style');
  return st ? kid(st, name) : null;
}
// Outline: the shape's own a:ln speaks first (per aspect), p:style's lnRef
// (theme lnStyleLst entry) fills in what it leaves out.
function lineOf(spPr, node, theme, pal){
  const ln = kid(spPr, 'a:ln');
  const ref = styleRef(node, 'a:lnRef');
  const refIdx = ref ? intAttr(ref, 'idx', 0) : 0;
  const refLn = refIdx > 0 ? (theme.lnStyles[refIdx - 1] || null) : null;
  const phClr = ref ? colorIn(ref, pal, null) : null;
  let f = ln ? fillOf(ln, pal, phClr) : null;
  if (!f && refIdx > 0) f = (refLn && fillOf(refLn, pal, phClr)) || (phClr ? { kind: 'solid', color: phClr } : null);
  if (!f || f.kind === 'none' || !f.color) return null;
  const wEmu = (ln && ln.getAttribute('w')) ? intAttr(ln, 'w', 12700) : intAttr(refLn, 'w', 12700);
  const dashEl = (ln && kid(ln, 'a:prstDash')) || (refLn && kid(refLn, 'a:prstDash'));
  const dv = dashEl ? (dashEl.getAttribute('val') || 'solid') : 'solid';
  const dash = dv === 'solid' ? null : (/dot/i.test(dv) && !/dash/i.test(dv) ? 'dotted' : 'dashed');
  const end = tag => {
    const e = ln ? kid(ln, tag) : null;
    const t = e ? e.getAttribute('type') : null;
    return !t || t === 'none' ? null : (t === 'oval' ? 'dot' : 'arrow');
  };
  return { color: clrCss(f.color), width: Math.max(1, Math.round(wEmu / EMU_PER_PX * 10) / 10), dash, head: end('a:headEnd'), tail: end('a:tailEnd') };
}

// PowerPoint's own group-to-child coordinate mapping (off/ext = the
// group's own slide-absolute position; chOff/chExt = the coordinate
// space its children's own off/ext values are expressed in) — see
// ECMA-376 Part 1, §20.1.7.6 (xfrm).
function extractGroupXfrm(grpSpEl){
  const grpSpPr = kid(grpSpEl, 'p:grpSpPr');
  const xfrm = grpSpPr ? kid(grpSpPr, 'a:xfrm') : null;
  if (!xfrm) return null;
  const off = kid(xfrm, 'a:off'), ext = kid(xfrm, 'a:ext');
  const chOff = kid(xfrm, 'a:chOff'), chExt = kid(xfrm, 'a:chExt');
  if (!off || !ext || !chOff || !chExt) return null;
  return {
    offX: intAttr(off, 'x', 0), offY: intAttr(off, 'y', 0),
    extW: intAttr(ext, 'cx', 0), extH: intAttr(ext, 'cy', 0),
    chOffX: intAttr(chOff, 'x', 0), chOffY: intAttr(chOff, 'y', 0),
    chExtW: intAttr(chExt, 'cx', 0), chExtH: intAttr(chExt, 'cy', 0),
    rot: intAttr(xfrm, 'rot', 0),
  };
}

// Rewrites ONE descendant's own xfrm from group-relative EMU coordinates to
// slide-absolute EMU coordinates, in place — after this, extractFrame()
// reads exactly the same attributes it always has, just already carrying
// the group's own transform baked in.
function xfrmElOf(el){
  const spPr = kid(el, 'p:spPr') || kid(el, 'p:grpSpPr');
  return spPr ? kid(spPr, 'a:xfrm') : kid(el, 'p:xfrm');
}
function applyGroupXfrmToChild(childEl, g){ applyGroupToXfrm(xfrmElOf(childEl), g); }
function applyGroupToXfrm(xfrm, g){
  if (!xfrm) return;
  const off = kid(xfrm, 'a:off'), ext = kid(xfrm, 'a:ext');
  if (!off || !ext) return;
  const scaleX = g.chExtW ? g.extW / g.chExtW : 1;
  const scaleY = g.chExtH ? g.extH / g.chExtH : 1;
  const childX = intAttr(off, 'x', 0), childY = intAttr(off, 'y', 0);
  const childW = intAttr(ext, 'cx', 0), childH = intAttr(ext, 'cy', 0);
  off.setAttribute('x', Math.round(g.offX + (childX - g.chOffX) * scaleX));
  off.setAttribute('y', Math.round(g.offY + (childY - g.chOffY) * scaleY));
  ext.setAttribute('cx', Math.round(childW * scaleX));
  ext.setAttribute('cy', Math.round(childH * scaleY));
  if (g.rot) xfrm.setAttribute('rot', String(intAttr(xfrm, 'rot', 0) + g.rot));
}

const SHAPE_TAGS = ['p:sp','p:pic','p:graphicFrame','p:cxnSp'];
function cNvPrOf(node){
  for (const c of node.children){ const cnv = kid(c, 'p:cNvPr'); if (cnv) return cnv; }
  return null;
}
// Recursively flattens any nesting depth of p:grpSp into one flat,
// document-ordered list — each descendant comes out with slide-absolute
// coordinates already baked in. Every leaf remembers the ids of the groups
// around it (`__grpIds`: an animation can target a whole group) and its
// nearest group's properties (`__grpSpPr`, for a:grpFill). mc:AlternateContent
// (newer-feature wrappers) takes its Fallback — the flat rendering every
// consumer is guaranteed to understand.
function flattenGroupedShapes(nodes, grpIds, grpSpPr){
  grpIds = grpIds || [];
  const out = [];
  for (const node of nodes){
    if (node.nodeType !== 1) continue;
    if (node.tagName === 'mc:AlternateContent'){
      const alt = kid(node, 'mc:Fallback') || kid(node, 'mc:Choice');
      if (alt) out.push(...flattenGroupedShapes(Array.from(alt.children), grpIds, grpSpPr));
      continue;
    }
    if (node.tagName === 'p:grpSp'){
      const cnv = cNvPrOf(node);
      if (cnv && cnv.getAttribute('hidden') === '1') continue;
      const g = extractGroupXfrm(node);
      const children = Array.from(node.children).filter(n =>
        [...SHAPE_TAGS, 'p:grpSp', 'mc:AlternateContent'].includes(n.tagName));
      // layout/master XML is parsed once and reused for every slide — bake
      // the group transform in only the first time, or it compounds
      if (g && !node.__baked) children.forEach(child => applyGroupXfrmToChild(child, g));
      node.__baked = true;
      const gid = cnv ? cnv.getAttribute('id') : null;
      out.push(...flattenGroupedShapes(children, gid ? [...grpIds, gid] : grpIds, kid(node, 'p:grpSpPr') || grpSpPr));
      continue;
    }
    if (SHAPE_TAGS.includes(node.tagName)){
      node.__grpIds = grpIds;
      node.__grpSpPr = grpSpPr || null;
      out.push(node);
    }
  }
  return out;
}

function extractFrame(spEl){ return frameOfXfrm(xfrmElOf(spEl)); }
function frameOfXfrm(xfrm){
  if (!xfrm) return null;
  const off = kid(xfrm, 'a:off');
  const ext = kid(xfrm, 'a:ext');
  if (!off || !ext) return null;
  return {
    x: emuToPx(intAttr(off, 'x', 0)),
    y: emuToPx(intAttr(off, 'y', 0)),
    w: emuToPx(intAttr(ext, 'cx', 0)),
    h: emuToPx(intAttr(ext, 'cy', 0)),
    rotation: rotToDeg(xfrm.getAttribute('rot')),
    flipH: xfrm.getAttribute('flipH') === '1',
    flipV: xfrm.getAttribute('flipV') === '1',
  };
}

function fallbackFrame(phType, slideW, slideH){
  const margin = 64;
  if (phType === 'title' || phType === 'ctrTitle'){
    return { x: margin, y: 48, w: slideW - margin*2, h: 140, rotation: 0 };
  }
  if (phType === 'subTitle'){
    return { x: margin, y: 200, w: slideW - margin*2, h: 80, rotation: 0 };
  }
  return { x: margin, y: 200, w: slideW - margin*2, h: Math.max(120, slideH - 260), rotation: 0 };
}

// ---- placeholder inheritance: slide shape → layout placeholder → master placeholder ----
function phOf(node){
  for (const c of node.children){
    const nv = kid(c, 'p:nvPr');
    if (nv) return kid(nv, 'p:ph');
  }
  return null;
}
const normPhType = t => (!t || t === 'body' || t === 'obj') ? 'body' : (t === 'ctrTitle' ? 'title' : t);
function phCandidates(root){
  if (!root) return [];
  if (!root.__phc){
    root.__phc = Array.from(root.getElementsByTagName('*'))
      .filter(e => e.tagName === 'p:sp' || e.tagName === 'p:pic' || e.tagName === 'p:graphicFrame')
      .map(el => ({ el, ph: phOf(el) }))
      .filter(c => c.ph);
  }
  return root.__phc;
}
function matchPh(root, want){
  const cands = phCandidates(root);
  const idx = want.getAttribute('idx') || '0';
  for (const c of cands) if ((c.ph.getAttribute('idx') || '0') === idx) return c;
  const t = normPhType(want.getAttribute('type'));
  for (const c of cands) if (normPhType(c.ph.getAttribute('type')) === t) return c;
  return null;
}
// The shape itself, then the layout's matching placeholder, then the
// master's (matched against the LAYOUT's ph when found — that is where the
// type actually lives).
function chainFor(node, ctx){
  if (node.__chain) return node.__chain;
  const own = phOf(node);
  const out = [{ el: node, ph: own }];
  if (own && ctx.layer === 'slide'){
    let want = own;
    const l = ctx.layoutRoot ? matchPh(ctx.layoutRoot, want) : null;
    if (l){ out.push(l); want = l.ph; }
    const m = ctx.masterRoot ? matchPh(ctx.masterRoot, want) : null;
    if (m) out.push(m);
  }
  node.__chain = out;
  return out;
}
function effectivePhType(node, ctx){
  const chain = chainFor(node, ctx);
  if (!chain[0].ph) return '';
  for (const c of chain){ const t = c.ph && c.ph.getAttribute('type'); if (t) return t; }
  return 'body';
}
function frameFromChain(node, ctx){
  for (const c of chainFor(node, ctx)){ const f = extractFrame(c.el); if (f && (f.w || f.h)) return f; }
  return null;
}

// Where a paragraph's defaults come from, nearest first: the shape's own
// a:lstStyle, its p:style fontRef colour, the layout's and master's
// matching placeholder lstStyles, then the master's p:txStyles
// (titleStyle / bodyStyle / otherStyle) — or, for ordinary text boxes, the
// presentation's defaultTextStyle. Each property takes the FIRST source
// that speaks; levels never borrow from other levels.
function textSources(node, ctx, lvl, phType){
  const name = 'a:lvl' + (Math.min(Math.max(lvl, 0), 8) + 1) + 'pPr';
  const out = [];
  const lvlOf = root => { const p = root ? kid(root, name) : null; if (p) out.push({ pPr: p }); };
  chainFor(node, ctx).forEach((c, i) => {
    const tb = kid(c.el, 'p:txBody');
    lvlOf(tb ? kid(tb, 'a:lstStyle') : null);
    if (i === 0){
      const fr = styleRef(node, 'a:fontRef');
      const col = fr ? colorIn(fr, ctx.pal, null) : null;
      if (col) out.push({ color: col });
      if (fr && fr.getAttribute('idx')) out.push({ fontIdx: fr.getAttribute('idx') });
    }
  });
  const styles = ctx.masterRoot ? first(ctx.masterRoot, 'p:txStyles') : null;
  const table = phType === 'title' || phType === 'ctrTitle' ? 'p:titleStyle'
    : (!phType || ['dt','ftr','sldNum','hdr'].includes(phType)) ? 'p:otherStyle' : 'p:bodyStyle';
  if (!phType) lvlOf(ctx.defaultTextStyle);
  lvlOf(styles ? kid(styles, table) : null);
  return out;
}

const WINGDINGS = { 'l':'●', 'n':'■', 'q':'❑', 'u':'◆', 'v':'❖', 'w':'⬥', 'Ø':'➢', 'ü':'✔', '§':'▪', 'Ÿ':'•', 'o':'○', 'p':'□', 'à':'➔', 'è':'➜', 'ð':'⇨', 'ß':'⇦', 'F':'☞', 'J':'☺', 'L':'☹', '¨':'◻', 'm':'❍', 'r':'❒', 's':'⬧', 't':'⧫', 'x':'⌧' };
// Symbol-font code points (legacy decks type ≥, ±, →, Greek … through the
// Symbol font, often as private-use U+F0xx) → real Unicode.
const SYMBOL_FONT = {
  0x22:'∀', 0x24:'∃', 0x27:'∋', 0x2A:'∗', 0x2D:'−', 0x40:'≅', 0x5E:'⊥', 0x60:'‾',
  0xA1:'ϒ', 0xA2:'′', 0xA3:'≤', 0xA4:'⁄', 0xA5:'∞', 0xA6:'ƒ', 0xA7:'♣', 0xA8:'♦', 0xA9:'♥', 0xAA:'♠',
  0xAB:'↔', 0xAC:'←', 0xAD:'↑', 0xAE:'→', 0xAF:'↓', 0xB0:'°', 0xB1:'±', 0xB2:'″', 0xB3:'≥', 0xB4:'×',
  0xB5:'∝', 0xB6:'∂', 0xB7:'•', 0xB8:'÷', 0xB9:'≠', 0xBA:'≡', 0xBB:'≈', 0xBC:'…', 0xC4:'⊗', 0xC5:'⊕',
  0xC6:'∅', 0xC7:'∩', 0xC8:'∪', 0xC9:'⊃', 0xCA:'⊇', 0xCB:'⊄', 0xCC:'⊂', 0xCD:'⊆', 0xCE:'∈', 0xCF:'∉',
  0xD0:'∠', 0xD1:'∇', 0xD5:'∏', 0xD6:'√', 0xD7:'⋅', 0xD8:'¬', 0xD9:'∧', 0xDA:'∨', 0xDB:'⇔', 0xDC:'⇐',
  0xDD:'⇑', 0xDE:'⇒', 0xDF:'⇓', 0xE0:'◊', 0xE5:'∑', 0xF2:'∫',
};
const GREEK = 'ΑΒΧΔΕΦΓΗΙϑΚΛΜΝΟΠΘΡΣΤΥςΩΞΨΖ', greek = 'αβχδεφγηιϕκλμνοπθρστυϖωξψζ';
function symbolChar(ch, font){
  const code = ch.charCodeAt(0);
  const pua = code >= 0xF020 && code <= 0xF0FF;
  if (!pua && !/^symbol$|wingdings/i.test(font || '')) return ch;
  const c = pua ? code - 0xF000 : code;
  if (/wingdings/i.test(font || '')) return WINGDINGS[String.fromCharCode(c)] || (pua ? '•' : ch);
  if (SYMBOL_FONT[c]) return SYMBOL_FONT[c];
  if (c >= 0x41 && c <= 0x5A) return GREEK[c - 0x41];
  if (c >= 0x61 && c <= 0x7A) return greek[c - 0x61];
  return String.fromCharCode(c);
}
function bulletText(bu, counter){
  if (!bu || bu.kind === 'none') return '';
  if (bu.kind === 'char'){
    const ch = bu.char || '•';
    return (/wingdings|symbol/i.test(bu.font || '') ? (WINGDINGS[ch] || '•') : ch);
  }
  const n = counter;
  const scheme = bu.scheme || 'arabicPeriod';
  const alpha = k => { let s = ''; k--; do { s = String.fromCharCode(97 + (k % 26)) + s; k = Math.floor(k / 26) - 1; } while (k >= 0); return s; };
  const roman = k => { const t = [[1000,'m'],[900,'cm'],[500,'d'],[400,'cd'],[100,'c'],[90,'xc'],[50,'l'],[40,'xl'],[10,'x'],[9,'ix'],[5,'v'],[4,'iv'],[1,'i']]; let s = ''; for (const [v, r] of t) while (k >= v){ s += r; k -= v; } return s; };
  let core = /^alphaLc/.test(scheme) ? alpha(n) : /^alphaUc/.test(scheme) ? alpha(n).toUpperCase()
    : /^romanLc/.test(scheme) ? roman(n) : /^romanUc/.test(scheme) ? roman(n).toUpperCase() : String(n);
  if (/ParenBoth$/.test(scheme)) return '(' + core + ')';
  if (/ParenR$/.test(scheme)) return core + ')';
  if (/Plain$/.test(scheme)) return core;
  return core + '.';
}
function bulletOf(pPr){
  if (!pPr) return null;
  if (kid(pPr, 'a:buNone')) return { kind: 'none' };
  const auto = kid(pPr, 'a:buAutoNum');
  if (auto) return { kind: 'auto', scheme: auto.getAttribute('type') || 'arabicPeriod', start: intAttr(auto, 'startAt', 1) };
  const ch = kid(pPr, 'a:buChar');
  if (ch){ const bf = kid(pPr, 'a:buFont'); return { kind: 'char', char: ch.getAttribute('char') || '•', font: bf ? bf.getAttribute('typeface') : '' }; }
  return null;
}

// Text body → paragraphs of inline html + the box's dominant style. bento
// carries ONE style per text element, so the style that covers the most
// characters becomes the box's; runs that deviate keep their own colour /
// size / font as an inline <span style> (the editor's own partial-selection
// formatting, so it round-trips through its sanitizer). Bullets become
// literal characters (the text model has no list type).
//   opts: { sources(lvl) → [{pPr}|{color}], fallbackColor, fonts, pal, rels, scale }
function extractTextBody(txBody, opts){
  const paras = kids(txBody, 'a:p');
  if (!paras.length) return null;
  const bodyPr = kid(txBody, 'a:bodyPr');
  const autofit = bodyPr ? kid(bodyPr, 'a:normAutofit') : null;
  const scale = (autofit ? intAttr(autofit, 'fontScale', 100000) / 100000 : 1) * (opts.scale || 1);
  const counters = [];
  const out = [];
  const allRuns = [];
  let algnFirst = null, lnFirst = null, any = false;

  for (const p of paras){
    const pPr = kid(p, 'a:pPr');
    const lvl = intAttr(pPr, 'lvl', 0);
    const srcs = opts.sources(lvl);
    const fromSrc = get => { for (const s of srcs) if (s.pPr){ const v = get(s.pPr); if (v != null) return v; } return null; };
    const defR = pp => kid(pp, 'a:defRPr');
    const algnRaw = (pPr && pPr.getAttribute('algn')) || fromSrc(pp => pp.getAttribute('algn'));
    const lnSpcEl = (pPr && kid(pPr, 'a:lnSpc')) || fromSrc(pp => kid(pp, 'a:lnSpc'));
    const pct = lnSpcEl ? kid(lnSpcEl, 'a:spcPct') : null;

    const runs = [];
    for (const r of p.children){
      if (r.tagName === 'a:br'){ runs.push({ br: true }); continue; }
      if (r.tagName !== 'a:r' && r.tagName !== 'a:fld') continue;
      const tEl = kid(r, 'a:t');
      let text = tEl ? tEl.textContent : '';
      if (!text) continue;
      const rPr = kid(r, 'a:rPr');
      const attrOf = name => (rPr && rPr.getAttribute(name)) || fromSrc(pp => { const d = defR(pp); return d ? d.getAttribute(name) : null; });
      const sz = parseInt(attrOf('sz') || '1800', 10);
      const hl = rPr ? kid(rPr, 'a:hlinkClick') : null;
      let color = rPr ? colorIn(kid(rPr, 'a:solidFill'), opts.pal, null) : null;
      if (!color && hl && opts.pal.colors.hlink) color = { rgb: hexToRgb(opts.pal.colors.hlink), a: 1 };
      if (!color) for (const s of srcs){
        if (s.color){ color = s.color; break; }
        const d = s.pPr ? defR(s.pPr) : null;
        const c = d ? colorIn(kid(d, 'a:solidFill'), opts.pal, null) : null;
        if (c){ color = c; break; }
      }
      const latin = (rPr && kid(rPr, 'a:latin')) || fromSrc(pp => { const d = defR(pp); return d ? kid(d, 'a:latin') : null; });
      const fontIdx = srcs.find(s => s.fontIdx);
      const rawFace = latin ? latin.getAttribute('typeface') : (fontIdx && fontIdx.fontIdx === 'major' ? '+mj-lt' : null);
      const font = resolveFontFamily(rawFace, opts.fonts) || opts.fonts.minor || null;
      const fldType = r.tagName === 'a:fld' ? (r.getAttribute('type') || '') : '';
      if (fldType === 'slidenum') text = '{{page}}';
      else if (/^datetime/.test(fldType)) text = '{{date}}';
      let href = null;
      if (hl && opts.rels){ const tgt = opts.rels[hl.getAttribute('r:id')]; if (tgt && /^https?:\/\//i.test(tgt)) href = tgt; }
      const run = {
        text, href,
        size: Math.max(1, ptToPx(sz / 100 * scale)),
        color: clrCss(color) || opts.fallbackColor,
        font,
        bold: attrOf('b') === '1' || attrOf('b') === 'true',
        italic: attrOf('i') === '1' || attrOf('i') === 'true',
        underline: (attrOf('u') || 'none') !== 'none',
        strike: (attrOf('strike') || 'noStrike') !== 'noStrike',
      };
      runs.push(run);
      allRuns.push(run);
    }
    const plain = runs.map(r => r.br ? '\n' : r.text).join('');
    let bullet = '';
    if (plain.trim()){
      any = true;
      if (algnFirst === null) algnFirst = algnRaw || 'l';
      if (lnFirst === null && pct) lnFirst = intAttr(pct, 'val', 100000) / 100000;
      const bu = bulletOf(pPr) || fromSrc(bulletOf);
      counters.length = lvl + 1;
      if (bu && bu.kind === 'auto'){ counters[lvl] = (counters[lvl] || (bu.start - 1)) + 1; }
      else counters[lvl] = 0;
      bullet = bulletText(bu, counters[lvl]);
    }
    out.push({ runs, plain, bullet, lvl });
  }
  if (!any) return null;
  return assembleTextInfo(out, allRuns, algnFirst, lnFirst);
}

// Paragraphs of runs → the box's dominant style + inline html. Shared by
// the PPTX (DrawingML) and the legacy .ppt text readers.
//   out: [{ runs:[{text,href,size,color,font,bold,italic,underline,strike}|{br}], plain, bullet, lvl }]
//   algnFirst: DrawingML alignment key ('l'|'ctr'|'r'|'just') · lnFirst: line spacing factor (1 = single)
function assembleTextInfo(out, allRuns, algnFirst, lnFirst){
  // dominant style = the one covering the most characters
  const weight = new Map();
  for (const r of allRuns){
    const k = [r.size, r.color, r.font, r.bold ? 1 : 0].join('|');
    weight.set(k, (weight.get(k) || 0) + r.text.length);
  }
  let best = null, bestW = -1;
  for (const [k, w] of weight) if (w > bestW){ best = k; bestW = w; }
  const [dSize, dColor, dFont, dBold] = best.split('|');
  const style = {
    fontSize: parseInt(dSize, 10), color: dColor, fontFamily: dFont || null, bold: dBold === '1',
    align: algnFirst === 'ctr' ? 'center' : algnFirst === 'r' ? 'right' : algnFirst === 'just' || algnFirst === 'dist' ? 'justify' : 'left',
    lineHeight: lnFirst ? Math.round(Math.max(0.8, Math.min(3, lnFirst * 1.2)) * 100) / 100 : 1.2,
  };
  const safeFont = f => /^[a-zA-Z0-9 ,'"-]+$/.test(f || '');
  const htmlParas = out.map(p => {
    let line = '';
    for (const r of p.runs){
      if (r.br){ line += '<br>'; continue; }
      let seg = esc(r.text);
      if (r.bold && !style.bold) seg = '<b>' + seg + '</b>';
      if (r.italic) seg = '<i>' + seg + '</i>';
      if (r.underline) seg = '<u>' + seg + '</u>';
      if (r.strike) seg = '<s>' + seg + '</s>';
      const css = [];
      if (r.color && r.color !== style.color) css.push('color:' + r.color);
      if (r.size !== style.fontSize) css.push('font-size:' + r.size + 'px');
      if (r.font && r.font !== style.fontFamily && safeFont(r.font)) css.push('font-family:' + r.font);
      if (!r.bold && style.bold) css.push('font-weight:normal');
      if (css.length) seg = '<span style="' + css.join(';') + '">' + seg + '</span>';
      if (r.href) seg = '<a href="' + esc(r.href).replace(/"/g, '&quot;') + '">' + seg + '</a>';
      line += seg;
    }
    if (p.bullet) line = ' '.repeat(p.lvl * 4) + esc(p.bullet) + ' ' + line;
    return { html: line, plain: p.plain, bullet: p.bullet };
  });
  // Leading/trailing EMPTY paragraphs are spacing PowerPoint sizes by their
  // end-of-paragraph mark (often tiny); at the box's full size they push the
  // real text out of its frame. The paragraph list itself stays complete —
  // animation paragraph indices count them.
  let a = 0, b = htmlParas.length - 1;
  while (a < b && !htmlParas[a].plain.trim()) a++;
  while (b > a && !htmlParas[b].plain.trim()) b--;
  // Same for line breaks typed before/after the text (a spacing hack).
  const html = htmlParas.slice(a, b + 1).map(p => p.html).join('<br>').replace(/^(<br>)+|(<br>)+$/g, '');
  return { paras: htmlParas, html, style };
}

// bodyPr insets (per attribute, walked up the chain) and vertical anchor.
// Defaults per OOXML: 91440 EMU left/right, 45720 top/bottom — ignoring them
// makes every box ~19px wider than PowerPoint's, which moves every wrap point.
function bodyLayout(node, ctx){
  const bodies = chainFor(node, ctx).map(c => { const tb = kid(c.el, 'p:txBody'); return tb ? kid(tb, 'a:bodyPr') : null; }).filter(Boolean);
  const pick = (name, dflt) => { for (const b of bodies){ const v = b.getAttribute(name); if (v != null) return v; } return dflt; };
  const anchor = pick('anchor', 't');
  return {
    l: emuToPx(parseInt(pick('lIns', '91440'), 10)), r: emuToPx(parseInt(pick('rIns', '91440'), 10)),
    t: emuToPx(parseInt(pick('tIns', '45720'), 10)), b: emuToPx(parseInt(pick('bIns', '45720'), 10)),
    valign: anchor === 'ctr' ? 'middle' : anchor === 'b' ? 'bottom' : 'top',
  };
}

const GEOM_MAP = {
  ellipse: 'ellipse', flowChartConnector: 'ellipse',
  triangle: 'triangle',
  roundRect: 'rect', rect: 'rect', flowChartProcess: 'rect', flowChartAlternateProcess: 'rect',
  snip1Rect: 'rect', round1Rect: 'rect', round2SameRect: 'rect',
  rightArrow: 'arrow', leftArrow: 'arrow', upArrow: 'arrow', downArrow: 'arrow',
  chevron: 'arrow', homePlate: 'arrow',
  diamond: 'polygon', flowChartDecision: 'polygon', pentagon: 'polygon', hexagon: 'polygon',
  heptagon: 'polygon', octagon: 'polygon', decagon: 'polygon', dodecagon: 'polygon',
};
const POLYGON_SIDES = { diamond: 4, flowChartDecision: 4, pentagon: 5, hexagon: 6, heptagon: 7, octagon: 8, decagon: 10, dodecagon: 12 };
const LINE_GEOMS = /^(line|straightConnector1|bentConnector[2-5]|curvedConnector[2-5])$/;
// Extra rotation (degrees, added to the shape's own rotation) and whether
// w/h need swapping first: bento's arrow always points right.
const ARROW_ORIENTATION = {
  rightArrow: { extraRotation: 0, swapWH: false },
  leftArrow: { extraRotation: 180, swapWH: false },
  upArrow: { extraRotation: 270, swapWH: true },
  downArrow: { extraRotation: 90, swapWH: true },
  chevron: { extraRotation: 0, swapWH: false },
  homePlate: { extraRotation: 0, swapWH: false },
  // bento's polygons start at a top vertex; PowerPoint's hexagon has flat
  // top and bottom edges
  hexagon: { extraRotation: 90, swapWH: true },
};

function resolvePartPath(dir, target){
  if (!target) return null;
  if (target.charAt(0) === '/') return target.slice(1);
  const stack = [];
  for (const part of (dir + '/' + target).split('/')){
    if (part === '..') stack.pop();
    else if (part === '.' || part === '') continue;
    else stack.push(part);
  }
  return stack.join('/');
}

async function loadImageAsset(zip, embedId, relsMap, slideDir, assets, assetCounter, pathToKey){
  const target = relsMap[embedId];
  if (!target || /^https?:/i.test(target)) return null;
  const path = resolvePartPath(slideDir, target);
  // Same underlying file already loaded once (a logo/background reused
  // across several slides is common) — reuse the existing key.
  if (pathToKey.has(path)) return pathToKey.get(path);
  const file = zip.file(path);
  if (!file) return null;
  const ext = (path.split('.').pop()||'png').toLowerCase();
  const mimeMap = { png:'image/png', jpg:'image/jpeg', jpeg:'image/jpeg', gif:'image/gif', bmp:'image/bmp', svg:'image/svg+xml', webp:'image/webp', tif:'image/tiff', tiff:'image/tiff' };
  const mime = mimeMap[ext];
  if (!mime) return null; // WMF/EMF and other legacy vector formats — skip embedding
  const base64 = await file.async('base64');
  const key = 'img' + (assetCounter.n++);
  assets[key] = 'data:'+mime+';base64,'+base64;
  pathToKey.set(path, key);
  return key;
}

function parseRels(relsXmlText){
  const map = {};
  if (!relsXmlText) return map;
  const doc = parseXml(relsXmlText);
  for (const r of Array.from(doc.getElementsByTagName('Relationship'))){
    map[r.getAttribute('Id')] = r.getAttribute('Target');
  }
  // Non-enumerable, so callers iterating the plain id→target map never see it.
  Object.defineProperty(map, '__types', { value: Object.fromEntries(Array.from(doc.getElementsByTagName('Relationship')).map(r => [r.getAttribute('Id'), r.getAttribute('Type') || ''])) });
  return map;
}
function relOfType(rels, suffix){
  const types = rels.__types || {};
  for (const id of Object.keys(types)) if (types[id].endsWith(suffix)) return rels[id];
  return null;
}

// ---- charts (ppt/charts/chartN.xml → bento chart option) ----
// The chart part carries a CACHE of its data beside every reference into the
// embedded workbook (c:strCache / c:numCache) — exactly what PowerPoint
// itself draws from, so the workbook never needs opening.
function chartCache(el){
  if (!el) return null;
  for (const tag of ['c:strCache','c:numCache','c:strLit','c:numLit','c:multiLvlStrCache']){
    const c = first(el, tag);
    if (!c) continue;
    const src = tag === 'c:multiLvlStrCache' ? (kid(c, 'c:lvl') || c) : c;
    const count = intAttr(kid(c, 'c:ptCount'), 'val', 0);
    const pts = kids(src, 'c:pt');
    const n = Math.max(count, ...pts.map(p => intAttr(p, 'idx', 0) + 1), 0);
    const vals = new Array(n).fill(null);
    for (const p of pts){ const v = kid(p, 'c:v'); vals[intAttr(p, 'idx', 0)] = v ? v.textContent : null; }
    const fc = kid(c, 'c:formatCode');
    return { vals, format: fc ? fc.textContent : '' };
  }
  const v = kid(el, 'c:v');
  return v ? { vals: [v.textContent], format: '' } : null;
}
function richText(el){
  if (!el) return '';
  return all(el, 'a:p').map(p => all(p, 'a:t').map(t => t.textContent).join('')).filter(Boolean).join(' ').trim();
}
const CHART_GROUPS = {
  'c:barChart': 'bar', 'c:bar3DChart': 'bar', 'c:lineChart': 'line', 'c:line3DChart': 'line',
  'c:areaChart': 'area', 'c:area3DChart': 'area', 'c:radarChart': 'line', 'c:stockChart': 'line',
  'c:pieChart': 'pie', 'c:pie3DChart': 'pie', 'c:doughnutChart': 'doughnut', 'c:ofPieChart': 'pie',
  'c:scatterChart': 'scatter', 'c:bubbleChart': 'scatter',
};
function convertChartXml(chartDoc, pal, warn){
  const chart = first(chartDoc, 'c:chart');
  const plot = chart ? kid(chart, 'c:plotArea') : null;
  if (!plot) return null;
  const accents = ['accent1','accent2','accent3','accent4','accent5','accent6'].map(k => pal.colors[k]).filter(Boolean);
  const groups = kids(plot).filter(g => CHART_GROUPS[g.tagName]);
  if (!groups.length) return null;
  const valAxes = kids(plot, 'c:valAx');
  const primaryAx = groups[0] ? kids(groups[0], 'c:axId').map(a => a.getAttribute('val')) : [];
  let categories = null, pct = false, isPie = false, pieSeries = null, pieColors = null, scatter = false;
  const series = [];
  let si = 0;
  for (const g of groups){
    const kind = CHART_GROUPS[g.tagName];
    const barDir = kid(g, 'c:barDir');
    if (barDir && barDir.getAttribute('val') === 'bar') warn('Liegende Balkendiagramme werden als Säulen dargestellt.');
    const grouping = kid(g, 'c:grouping');
    if (grouping && /stacked/i.test(grouping.getAttribute('val') || '')) warn('Gestapelte Diagramme werden nebeneinander dargestellt (Stapeln kennt Bento nicht).');
    const secondary = groups.length > 1 && g !== groups[0] && valAxes.length > 1
      && kids(g, 'c:axId').some(a => !primaryAx.includes(a.getAttribute('val')));
    for (const ser of kids(g, 'c:ser')){
      const nameCache = chartCache(kid(ser, 'c:tx'));
      const name = (nameCache && nameCache.vals.filter(Boolean).join(' ')) || ('Reihe ' + (si + 1));
      const valEl = kid(ser, 'c:val') || kid(ser, 'c:yVal');
      const valCache = chartCache(valEl);
      if (!valCache) continue;
      if (/%/.test(valCache.format)) pct = true;
      const nums = valCache.vals.map(v => { const n = parseFloat(v); return Number.isFinite(n) ? n : 0; });
      const catCache = chartCache(kid(ser, 'c:cat') || kid(ser, 'c:xVal'));
      if (!categories && catCache && kind !== 'scatter') categories = catCache.vals.map(v => v == null ? '' : String(v));
      const spPr = kid(ser, 'c:spPr');
      const isLine = kind === 'line' || kind === 'scatter';
      const f = isLine ? (fillOf(kid(spPr, 'a:ln'), pal, null) || fillOf(spPr, pal, null)) : (fillOf(spPr, pal, null) || fillOf(kid(spPr, 'a:ln'), pal, null));
      const color = (f && f.color) ? clrCss(f.color) : accents[si % Math.max(1, accents.length)] || null;
      if (kind === 'pie' || kind === 'doughnut'){
        if (!pieSeries){
          isPie = true;
          const labels = (catCache ? catCache.vals : nums.map((_, j) => String(j + 1))).map(v => v == null ? '' : String(v));
          pieColors = nums.map((_, j) => accents[j % Math.max(1, accents.length)]);
          for (const dPt of kids(ser, 'c:dPt')){
            const pf = fillOf(kid(dPt, 'c:spPr'), pal, null);
            if (pf && pf.color) pieColors[intAttr(kid(dPt, 'c:idx'), 'val', 0)] = clrCss(pf.color);
          }
          const hole = intAttr(kid(g, 'c:holeSize'), 'val', 50);
          pieSeries = {
            type: 'pie', name,
            radius: kind === 'doughnut' ? [Math.round(hole * 0.7) + '%', '70%'] : '70%',
            label: { formatter: '{b}: {d}%' },
            data: labels.map((l, j) => ({ name: l, value: Math.max(0, nums[j] || 0) })),
          };
        }
        si++;
        continue;
      }
      if (kind === 'scatter'){
        scatter = true;
        const xs = catCache ? catCache.vals.map(v => parseFloat(v) || 0) : nums.map((_, j) => j + 1);
        series.push({ type: 'scatter', name, symbolSize: 10, itemStyle: color ? { color } : undefined, data: nums.map((y, j) => [xs[j] || 0, y]) });
        si++;
        continue;
      }
      const s = { type: kind === 'area' ? 'line' : kind, name, data: nums };
      if (kind === 'bar'){ if (color) s.itemStyle = { color }; }
      else {
        if (color){ s.lineStyle = { color }; s.itemStyle = { color }; }
        if (kind === 'area') s.areaStyle = {};
        const sm = kid(ser, 'c:smooth');
        if (sm && sm.getAttribute('val') !== '0') s.smooth = true;
      }
      if (secondary) s.yAxisIndex = 1;
      series.push(s);
      si++;
    }
  }
  if (pct){
    const up = v => Math.round(v * 100 * 10000) / 10000;
    series.forEach(s => { s.data = s.data.map(v => Array.isArray(v) ? [v[0], up(v[1])] : up(v)); });
    if (pieSeries) pieSeries.data.forEach(d => { d.value = up(d.value); });
  }
  const legendEl = kid(chart, 'c:legend');
  const legendPosEl = legendEl ? kid(legendEl, 'c:legendPos') : null;
  const legendPos = legendPosEl ? legendPosEl.getAttribute('val') : 'r';
  const legend = legendEl ? (legendPos === 't' ? { top: 0 } : { bottom: 0 }) : null;
  const titleEl = kid(chart, 'c:title');
  const autoDel = kid(chart, 'c:autoTitleDeleted');
  let title = titleEl ? richText(kid(titleEl, 'c:tx')) : '';
  if (!title && titleEl && !(autoDel && autoDel.getAttribute('val') === '1')){
    const only = isPie ? pieSeries : (series.length === 1 ? series[0] : null);
    if (only) title = only.name;
  }

  let option;
  if (isPie){
    option = { tooltip: { trigger: 'item' }, color: pieColors, series: [pieSeries] };
    if (legend) option.legend = legend;
  } else {
    if (!series.length) return null;
    const axisOf = ax => {
      const a = { type: 'value' };
      const sc = ax ? kid(ax, 'c:scaling') : null;
      const mn = sc ? kid(sc, 'c:min') : null, mx = sc ? kid(sc, 'c:max') : null;
      const k = pct ? 100 : 1;
      if (mn) a.min = parseFloat(mn.getAttribute('val')) * k;
      if (mx) a.max = parseFloat(mx.getAttribute('val')) * k;
      if (pct) a.axisLabel = { formatter: '{value}%' };
      return a;
    };
    const twoAxes = series.some(s => s.yAxisIndex === 1);
    const primaryVal = valAxes.find(a => primaryAx.includes(intAttr(kid(a, 'c:axId'), 'val', -1) + '')) || valAxes[0];
    const secondVal = valAxes.find(a => a !== primaryVal);
    option = {
      tooltip: { trigger: scatter ? 'item' : 'axis' },
      grid: { left: 56, right: twoAxes ? 56 : 20, top: 24, bottom: legend && !legend.top ? 56 : 40 },
      xAxis: scatter ? { type: 'value' } : { type: 'category', data: categories || series[0].data.map((_, j) => String(j + 1)) },
      yAxis: twoAxes ? [axisOf(primaryVal), axisOf(secondVal)] : axisOf(primaryVal),
      color: series.map((s, j) => (s.itemStyle && s.itemStyle.color) || accents[j % Math.max(1, accents.length)]).filter(Boolean),
      series: series.map(s => { if (!s.itemStyle) delete s.itemStyle; return s; }),
    };
    if (legend) option.legend = legend;
  }
  if (!option.color || !option.color.length) delete option.color;
  const hasTable = !!kid(plot, 'c:dTable') && !isPie && !scatter;
  return {
    option, title, preset: isPie ? 'pie' : scatter ? 'scatter' : (series[0].type === 'line' ? 'line' : 'bar'),
    table: hasTable ? { categories: option.xAxis.data, series: series.map(s => ({ name: s.name, data: s.data })) } : null,
  };
}

// ---- tables (a:tbl → bento table) ----
function modHex(hex, mods){
  let c = { rgb: hexToRgb(hex), a: 1 };
  for (const [kind, f] of mods){
    if (kind === 'tint') c.rgb = c.rgb.map(v => linToSrgb(srgbToLin(v) * f + (1 - f)));
  }
  return clrCss(c);
}
// Table style part (wholeTbl / firstRow / band1H / …) → { fill, color, bold }.
// Styles PowerPoint only references by GUID without writing them into
// tableStyles.xml fall back to "Medium Style 2 – Accent 1", the default
// every new PowerPoint table gets (accent header, tinted bands, white rules).
function tableStylePart(styleEl, name, ctx){
  if (styleEl){
    const part = kid(styleEl, 'a:' + name);
    if (!part) return null;
    const tcStyle = kid(part, 'a:tcStyle');
    const fillWrap = tcStyle ? kid(tcStyle, 'a:fill') : null;
    let fill = fillWrap ? fillOf(fillWrap, ctx.pal, null) : null;
    if (!fill && tcStyle && kid(tcStyle, 'a:fillRef')) fill = themeFillRef(kid(tcStyle, 'a:fillRef'), ctx.theme, ctx.pal);
    const tx = kid(part, 'a:tcTxStyle');
    const col = tx ? colorIn(tx, ctx.pal, null) : null;
    return {
      fill: fill && fill.kind !== 'none' && fill.color ? clrCss(fill.color) : (fill && fill.kind === 'none' ? 'transparent' : null),
      color: col ? clrCss(col) : null,
      bold: tx ? tx.getAttribute('b') === 'on' : false,
    };
  }
  const acc = ctx.pal.colors.accent1 || '#4472C4';
  const lt = ctx.pal.colors[ctx.pal.map.bg1 || 'lt1'] || '#FFFFFF';
  const dk = ctx.pal.colors[ctx.pal.map.tx1 || 'dk1'] || '#000000';
  if (name === 'wholeTbl') return { fill: modHex(acc, [['tint', 0.2]]), color: dk, bold: false };
  if (name === 'band1H') return { fill: modHex(acc, [['tint', 0.4]]), color: null, bold: false };
  if (name === 'firstRow' || name === 'lastRow') return { fill: acc, color: lt, bold: true };
  if (name === 'firstCol' || name === 'lastCol') return { fill: acc, color: lt, bold: true };
  return null;
}
// The style's inner rules (wholeTbl → tcBdr → insideH, else an outer edge).
function styleBorder(styleEl, ctx){
  const whole = styleEl ? kid(styleEl, 'a:wholeTbl') : null;
  const tcStyle = whole ? kid(whole, 'a:tcStyle') : null;
  const bdr = tcStyle ? kid(tcStyle, 'a:tcBdr') : null;
  if (!bdr) return null;
  for (const edge of ['a:insideH', 'a:bottom', 'a:top', 'a:left']){
    const e = kid(bdr, edge);
    const ln = e ? kid(e, 'a:ln') : null;
    const f = ln ? fillOf(ln, ctx.pal, null) : null;
    if (f && f.color) return { color: clrCss(f.color), width: Math.max(1, emuToPx(intAttr(ln, 'w', 12700))) };
  }
  return null;
}
function convertTableXml(tbl, frame, ctx, elId){
  const tblPr = kid(tbl, 'a:tblPr');
  const flag = n => !!tblPr && (tblPr.getAttribute(n) === '1' || tblPr.getAttribute(n) === 'true');
  const styleIdEl = tblPr ? kid(tblPr, 'a:tableStyleId') : null;
  const styleId = styleIdEl ? styleIdEl.textContent.trim() : '';
  const styleEl = styleId ? (ctx.tableStyles[styleId] || null) : null;
  const useBuiltin = !!styleId && !styleEl;
  const part = name => (styleEl || useBuiltin) ? tableStylePart(styleEl, name, ctx) : null;
  const whole = part('wholeTbl'), headP = flag('firstRow') ? part('firstRow') : null;
  const band = flag('bandRow') ? part('band1H') : null;
  const lastP = flag('lastRow') ? part('lastRow') : null;
  const firstColP = flag('firstCol') ? part('firstCol') : null;
  const ink = roleColor(ctx.pal, 'tx1') || '#000000';

  const gridCols = kids(kid(tbl, 'a:tblGrid'), 'a:gridCol').map(g => intAttr(g, 'w', 1));
  const total = gridCols.reduce((a, b) => a + b, 0) || 1;
  const trs = kids(tbl, 'a:tr');
  const nCols = Math.max(gridCols.length, ...trs.map(tr => kids(tr, 'a:tc').length));
  const sizes = new Map();
  const lt = roleColor(ctx.pal, 'bg1') || '#FFFFFF';
  const bodyColor = (whole && whole.color) || ink;
  // a style without a firstRow part (e.g. "No Style, Table Grid") keeps the
  // header in body ink — only the built-in fallback has a coloured header
  const headColor = (headP && headP.color) || (useBuiltin ? lt : bodyColor);
  let padX = 10, padY = 5, border = styleBorder(styleEl, ctx) || (useBuiltin ? { color: lt, width: 1 } : null);
  const rows = trs.map((tr, r) => {
    const isHead = r === 0 && flag('firstRow');
    const isLast = r === trs.length - 1 && flag('lastRow') && trs.length > 1;
    const cells = kids(tr, 'a:tc').map((tc, c) => {
      const tcPr = kid(tc, 'a:tcPr');
      if (r === 0 && c === 0 && tcPr){
        padX = emuToPx(intAttr(tcPr, 'marL', 91440)); padY = emuToPx(intAttr(tcPr, 'marT', 45720));
        const lnB = kid(tcPr, 'a:lnB');
        const bf = lnB ? fillOf(lnB, ctx.pal, null) : null;
        if (bf && bf.color) border = { color: clrCss(bf.color), width: Math.max(1, emuToPx(intAttr(lnB, 'w', 12700))) };
        else if (bf && bf.kind === 'none' && !border) border = { color: 'transparent', width: 0 };
      }
      const merged = tc.getAttribute('hMerge') === '1' || tc.getAttribute('vMerge') === '1';
      const partHere = isHead ? headP : isLast ? lastP : (c === 0 && firstColP) ? firstColP
        : (band && ((r - (flag('firstRow') ? 1 : 0)) % 2 === 0)) ? band : null;
      const defColor = (partHere && partHere.color) || (isHead ? headColor : bodyColor);
      const own = tcPr ? fillOf(tcPr, ctx.pal, null) : null;
      const bg = own ? (own.kind === 'none' ? 'transparent' : (own.color ? clrCss(own.color) : null))
        : ((partHere && partHere.fill) || (whole && whole.fill) || null);
      const tb = kid(tc, 'a:txBody');
      const info = (!merged && tb) ? extractTextBody(tb, {
        sources: () => [], fallbackColor: defColor, fonts: ctx.fonts, pal: ctx.pal, rels: ctx.rels,
      }) : null;
      const cell = { html: info ? info.html : '' };
      if (info){
        sizes.set(info.style.fontSize, (sizes.get(info.style.fontSize) || 0) + 1);
        if (info.style.color && info.style.color !== (isHead ? headColor : bodyColor)) cell.color = info.style.color;
        if (info.style.bold && !isHead) cell.bold = true;
        if (info.style.align !== 'left' && info.style.align !== 'justify') cell.align = info.style.align;
        cell.__size = info.style.fontSize;
      }
      if (bg && !(isHead && headP && bg === headP.fill)) cell.bg = bg;
      return cell;
    });
    while (cells.length < nCols) cells.push({ html: '' });
    return { cells };
  });
  let fontSize = 24, bestN = -1;
  for (const [sz, n] of sizes) if (n > bestN){ fontSize = sz; bestN = n; }
  rows.forEach(row => row.cells.forEach(cell => {
    if (cell.__size && cell.__size !== fontSize && cell.html) cell.html = '<span style="font-size:' + cell.__size + 'px">' + cell.html + '</span>';
    delete cell.__size;
  }));
  return {
    id: elId, type: 'table',
    x: frame.x, y: frame.y, w: frame.w, h: frame.h, rotation: 0, opacity: 1,
    header: flag('firstRow'),
    columns: (gridCols.length ? gridCols : new Array(nCols).fill(1)).map(w => ({ w: Math.round(w / total * 1000) / 1000 })),
    rows,
    style: {
      headerBg: (headP && headP.fill && headP.fill !== 'transparent') ? headP.fill : 'transparent',
      headerColor: headColor,
      borderColor: border ? border.color : 'rgba(0,0,0,0.18)',
      borderWidth: border ? border.width : 1,
      cellPadX: padX, cellPadY: padY,
      fontSize,
      fontFamily: fontStack(ctx.fonts.minor),
      color: bodyColor,
      radius: 0,
    },
  };
}

// ---- click animations (p:timing main sequence → bento reveal steps) ----
function enterKindFor(presetID, sub){
  if (presetID === 1) return null; // Appear: a plain reveal
  if (presetID === 2 || presetID === 7 || presetID === 12){ // Fly In / Crawl In / Peek In
    if (sub & 4) return 'slide-up';
    if (sub & 1) return 'slide-down';
    if (sub & 8) return 'slide-right';
    if (sub & 2) return 'slide-left';
    return 'slide-up';
  }
  if (presetID === 37 || presetID === 42) return 'fade-up'; // Rise Up / Ascend
  if (presetID === 47) return 'fade-down'; // Descend
  return 'fade';
}
// Every entrance effect of the main sequence, in order: a "on click" effect
// opens the next step, "with/after previous" join the current one (step 0 =
// runs by itself when the slide appears). Paragraph builds (bullet by
// bullet) target a paragraph range of a shape and are kept per paragraph.
function parseTiming(sldRoot){
  const res = { shapes: new Map(), paras: new Map(), dropped: new Set() };
  const timing = kid(sldRoot, 'p:timing');
  if (!timing) return res;
  const mainSeq = Array.from(timing.getElementsByTagName('p:cTn')).find(c => c.getAttribute('nodeType') === 'mainSeq');
  if (!mainSeq) return res;
  let step = 0, order = 0;
  for (const eff of Array.from(mainSeq.getElementsByTagName('p:cTn')).filter(c => c.getAttribute('presetClass'))){
    const nt = eff.getAttribute('nodeType');
    if (nt === 'clickEffect'){ step++; order = 0; }
    else if (nt === 'afterEffect') order++;
    const cls = eff.getAttribute('presetClass');
    if (cls !== 'entr'){ res.dropped.add(cls); continue; }
    const tgt = eff.getElementsByTagName('p:spTgt')[0];
    if (!tgt) continue;
    const spid = tgt.getAttribute('spid');
    let dur = 0;
    for (const c of Array.from(eff.getElementsByTagName('p:cTn'))){ const d = parseInt(c.getAttribute('dur'), 10); if (d > dur) dur = d; }
    const fx = { step, order, enter: enterKindFor(intAttr(eff, 'presetID', 0), intAttr(eff, 'presetSubtype', 0)), dur };
    const pRg = tgt.getElementsByTagName('p:pRg')[0];
    if (pRg){
      let m = res.paras.get(spid);
      if (!m){ m = new Map(); res.paras.set(spid, m); }
      const st = intAttr(pRg, 'st', 0), en = intAttr(pRg, 'end', st);
      for (let k = st; k <= en; k++) if (!m.has(k)) m.set(k, fx);
    } else if (!res.shapes.has(spid)) res.shapes.set(spid, fx);
  }
  return res;
}
function bentoFx(a){
  if (!a) return null;
  const fx = {};
  if (a.step > 0) fx.step = a.step;
  if (a.enter){
    fx.enter = a.enter;
    if (a.dur) fx.enterDur = Math.round(Math.max(0.2, Math.min(3, a.dur / 1000)) * 100) / 100;
  }
  if (a.order && (fx.step || fx.enter)) fx.order = a.order;
  return Object.keys(fx).length ? fx : null;
}

// ---- slide transitions ----
function transitionOf(sldRoot){
  const trs = Array.from(sldRoot.getElementsByTagName('p:transition'));
  if (!trs.length) return 'none';
  // mc:AlternateContent carries the morph in its Choice and a fade in its
  // Fallback: take the morph whenever one is there.
  if (trs.some(t => Array.from(t.children).some(c => c.localName === 'morph'))) return 'morph';
  const kind = trs[0].children[0] ? trs[0].children[0].localName : '';
  if (!kind) return 'none';
  if (kind === 'cut') return 'none';
  if (/^(fade|dissolve)$/.test(kind)) return 'fade';
  if (/^(zoom|newsflash|flythrough|vortex|ripple|warp|glitter|shred|prism|doors|window|honeycomb|flash)$/.test(kind)) return 'zoom';
  if (/^(push|wipe|cover|pull|split|reveal|conveyor|pan|gallery|ferris|switch|flip|uncover|randomBar|strips|blinds|comb|checker|wheel|wedge|circle|diamond|plus)$/.test(kind)) return 'slide';
  return 'fade';
}

// ---- speaker notes ----
function notesTextOf(notesRoot){
  const tree = notesRoot ? first(notesRoot, 'p:spTree') : null;
  if (!tree) return '';
  const out = [];
  for (const sp of kids(tree, 'p:sp')){
    const ph = phOf(sp);
    if (!ph || ph.getAttribute('type') !== 'body') continue;
    const tb = kid(sp, 'p:txBody');
    if (!tb) continue;
    const text = kids(tb, 'a:p').map(p => Array.from(p.children).map(r => r.tagName === 'a:br' ? '\n' : ((kid(r, 'a:t') || {}).textContent || '')).join('')).join('\n').trim();
    if (text) out.push(text);
  }
  return out.join('\n');
}

// ---- element builders ----
const rnd = v => Math.round(v);

// PowerPoint draws a line from one corner of its box to the opposite one
// (flipH/flipV pick which); bento draws a horizontal line across the middle
// of its box and rotates it. Endpoints → centre, length and angle. The
// renderer insets the endpoints for tip markers, so the box grows by that
// inset to keep the tips where PowerPoint had them.
function lineElement(frame, ln, elId){
  let x1 = frame.flipH ? frame.x + frame.w : frame.x, y1 = frame.flipV ? frame.y + frame.h : frame.y;
  let x2 = frame.flipH ? frame.x : frame.x + frame.w, y2 = frame.flipV ? frame.y : frame.y + frame.h;
  if (frame.rotation){
    const cx = frame.x + frame.w / 2, cy = frame.y + frame.h / 2, a = frame.rotation * Math.PI / 180;
    const rot = (x, y) => [cx + (x - cx) * Math.cos(a) - (y - cy) * Math.sin(a), cy + (x - cx) * Math.sin(a) + (y - cy) * Math.cos(a)];
    [x1, y1] = rot(x1, y1); [x2, y2] = rot(x2, y2);
  }
  const L = Math.hypot(x2 - x1, y2 - y1);
  if (L < 1) return null;
  const ux = (x2 - x1) / L, uy = (y2 - y1) / L;
  const lw = Math.max(ln.width, 2);
  const ps = ln.head ? lw * 2.6 : 0, pe = ln.tail ? lw * 2.6 : 0;
  const w = L + ps + pe;
  const cx = x1 + ux * (w / 2 - ps), cy = y1 + uy * (w / 2 - ps);
  const h = Math.max(10, Math.ceil(lw * 4));
  const el = {
    id: elId, type: 'shape', shape: 'line',
    x: rnd(cx - w / 2), y: rnd(cy - h / 2), w: rnd(w), h,
    rotation: Math.round(Math.atan2(uy, ux) * 1800 / Math.PI) / 10, opacity: 1,
    fill: ln.color, stroke: 'transparent', strokeWidth: ln.width, radius: 0,
  };
  if (ln.dash) el.strokeStyle = ln.dash;
  if (ln.head) el.lineStart = ln.head;
  if (ln.tail) el.lineEnd = ln.tail;
  return el;
}

// A picture fill (p:pic, a picture-filled shape, a picture background) →
// image element. a:srcRect is PowerPoint's crop (1/1000 %, per edge) —
// bento's crop is the same idea in fractions.
async function blipImage(blipFill, ctx, frame, elId){
  const blip = blipFill ? kid(blipFill, 'a:blip') : null;
  const embed = blip ? blip.getAttribute('r:embed') : null;
  if (!embed) return null;
  const key = await loadImageAsset(ctx.zip, embed, ctx.rels, ctx.dir, ctx.assets, ctx.assetCounter, ctx.imagePathToKey);
  if (!key){
    ctx.warn('Ein Bild in einem nicht unterstützten Format (z.B. WMF/EMF) wurde übersprungen.');
    return null;
  }
  const el = {
    id: elId, type: 'image',
    x: frame.x, y: frame.y, w: frame.w, h: frame.h,
    rotation: frame.rotation || 0, opacity: 1,
    src: 'asset:' + key, fit: 'cover', radius: 0,
  };
  const amf = blip ? kid(blip, 'a:alphaModFix') : null;
  if (amf) el.opacity = Math.round(clamp01(intAttr(amf, 'amt', 100000) / 100000) * 100) / 100;
  const sr = kid(blipFill, 'a:srcRect');
  if (sr){
    const [l, t, r, b] = ['l','t','r','b'].map(k => intAttr(sr, k, 0) / 100000);
    if (l >= 0 && t >= 0 && r >= 0 && b >= 0 && (l || t || r || b) && l + r < 1 && t + b < 1)
      el.crop = { x: l, y: t, w: Math.round((1 - l - r) * 10000) / 10000, h: Math.round((1 - t - b) * 10000) / 10000 };
  }
  // Frames using the SAME picture share one morph identity, so a logo or
  // recurring picture glides across a morph instead of cross-fading.
  const firstId = ctx.imageKeyToMorphId.get(key);
  if (!firstId) ctx.imageKeyToMorphId.set(key, elId);
  else if (firstId !== elId) el.morphId = firstId;
  return el;
}

function textElement(id, box, info, valign){
  return {
    id, type: 'text',
    x: rnd(box.x), y: rnd(box.y), w: rnd(box.w), h: rnd(box.h),
    rotation: box.rotation || 0, opacity: 1,
    html: info.html,
    fontSize: info.style.fontSize,
    fontFamily: fontStack(info.style.fontFamily),
    fontWeight: info.style.bold ? 700 : 400,
    color: info.style.color,
    align: info.style.align,
    valign,
    lineHeight: info.style.lineHeight,
  };
}
// A bullet-by-bullet build reveals single paragraphs; bento reveals whole
// elements — so such a text box is split into one element per paragraph,
// stacked by an estimated height (≈0.5em per character, wrapped to the box).
function paragraphElements(baseId, box, info, valign, paraAnim, shapeFx){
  const fs = info.style.fontSize, lh = info.style.lineHeight;
  const est = info.paras.map(p => {
    const lines = (p.plain || ' ').split('\n').reduce((n, seg) => n + Math.max(1, Math.ceil((seg.length + (p.bullet ? 2 : 0)) * fs * 0.5 / Math.max(40, box.w))), 0);
    return lines * fs * lh;
  });
  const total = est.reduce((a, b) => a + b, 0);
  let y = valign === 'middle' ? box.y + (box.h - total) / 2 : valign === 'bottom' ? box.y + box.h - total : box.y;
  const out = [];
  info.paras.forEach((p, k) => {
    const h = est[k];
    if (p.plain.trim()){
      const el = textElement(baseId + 'p' + (k + 1), { x: box.x, y, w: box.w, h, rotation: box.rotation }, { html: p.html, style: info.style }, 'top');
      const fx = bentoFx(paraAnim.get(k)) || shapeFx;
      if (fx) el.fx = fx;
      out.push(el);
    }
    y += h;
  });
  return out;
}

function shapeKind(geom){ return GEOM_MAP[geom] || 'rect'; }

// Freeform geometry (a:custGeom) → one svg path in a 1000×1000 box (the
// renderer stretches pathBox onto the element, like PowerPoint stretches
// each a:path's own w×h). null when there is nothing drawable or it uses
// guide formulas we don't evaluate.
const PATH_BOX = 1000;
// DrawingML shape-guide formulas (a:gd fmla="*/ w 1 2" …) over the shape's
// size in EMU; returns name → value, including the built-in names.
function shapeGuides(cg, w, h){
  const g = new Map();
  const ss = Math.min(w, h), ls = Math.max(w, h);
  Object.entries({ w, h, l: 0, t: 0, r: w, b: h, hc: w / 2, vc: h / 2, ss, ls,
    wd2: w / 2, wd3: w / 3, wd4: w / 4, wd5: w / 5, wd6: w / 6, wd8: w / 8, wd10: w / 10, wd32: w / 32,
    hd2: h / 2, hd3: h / 3, hd4: h / 4, hd5: h / 5, hd6: h / 6, hd8: h / 8, hd10: h / 10, hd32: h / 32,
    ssd2: ss / 2, ssd4: ss / 4, ssd6: ss / 6, ssd8: ss / 8, ssd16: ss / 16, ssd32: ss / 32,
    cd2: 10800000, cd4: 5400000, cd8: 2700000, '3cd4': 16200000, '3cd8': 8100000, '5cd8': 13500000, '7cd8': 18900000,
  }).forEach(([k, v]) => g.set(k, v));
  const val = t => { const n = parseFloat(t); return Number.isFinite(n) && /^-?[\d.]+$/.test(t) ? n : (g.has(t) ? g.get(t) : NaN); };
  const rad = a => a / 60000 * Math.PI / 180;
  const run = gd => {
    const [op, ...args] = (gd.getAttribute('fmla') || '').trim().split(/\s+/);
    const [x, y, z] = args.map(val);
    switch (op){
      case 'val': return x;
      case '*/': return x * y / z;
      case '+-': return x + y - z;
      case '+/': return (x + y) / z;
      case '?:': return x > 0 ? y : z;
      case 'abs': return Math.abs(x);
      case 'sqrt': return Math.sqrt(x);
      case 'max': return Math.max(x, y);
      case 'min': return Math.min(x, y);
      case 'mod': return Math.sqrt(x * x + y * y + z * z);
      case 'pin': return y < x ? x : y > z ? z : y;
      case 'sin': return x * Math.sin(rad(y));
      case 'cos': return x * Math.cos(rad(y));
      case 'tan': return x * Math.tan(rad(y));
      case 'at2': return Math.atan2(y, x) * 180 / Math.PI * 60000;
      case 'cat2': return x * Math.cos(Math.atan2(z, y));
      case 'sat2': return x * Math.sin(Math.atan2(z, y));
    }
    return NaN;
  };
  for (const list of [kid(cg, 'a:avLst'), kid(cg, 'a:gdLst')]) for (const gd of kids(list, 'a:gd')) g.set(gd.getAttribute('name'), run(gd));
  return g;
}
function custGeomPath(spPr, wEmu, hEmu){
  const cg = spPr ? kid(spPr, 'a:custGeom') : null;
  const lst = cg ? kid(cg, 'a:pathLst') : null;
  if (!lst) return null;
  const guides = shapeGuides(cg, wEmu || 1, hEmu || 1);
  const num = t => { const n = parseFloat(t); return /^-?[\d.]+$/.test(t || '') ? n : (guides.has(t) ? guides.get(t) : NaN); };
  const f = n => Math.round(n * 100) / 100;
  let d = '', filled = false;
  for (const path of kids(lst, 'a:path')){
    const w = intAttr(path, 'w', 0) || PATH_BOX, h = intAttr(path, 'h', 0) || PATH_BOX;
    if (path.getAttribute('fill') !== 'none') filled = true;
    const sx = PATH_BOX / w, sy = PATH_BOX / h;
    let cx = 0, cy = 0, bad = false;
    const pt = el => {
      const x = num(el.getAttribute('x')), y = num(el.getAttribute('y'));
      if (!Number.isFinite(x) || !Number.isFinite(y)) { bad = true; return [0, 0]; }
      return [x * sx, y * sy];
    };
    for (const c of path.children){
      const pts = kids(c, 'a:pt').map(pt);
      if (c.tagName === 'a:moveTo' && pts[0]){ [cx, cy] = pts[0]; d += 'M' + f(cx) + ' ' + f(cy); }
      else if (c.tagName === 'a:lnTo' && pts[0]){ [cx, cy] = pts[0]; d += 'L' + f(cx) + ' ' + f(cy); }
      else if (c.tagName === 'a:cubicBezTo' && pts.length === 3){ d += 'C' + pts.map(q => f(q[0]) + ' ' + f(q[1])).join(' '); [cx, cy] = pts[2]; }
      else if (c.tagName === 'a:quadBezTo' && pts.length === 2){ d += 'Q' + pts.map(q => f(q[0]) + ' ' + f(q[1])).join(' '); [cx, cy] = pts[1]; }
      else if (c.tagName === 'a:close') d += 'Z';
      else if (c.tagName === 'a:arcTo'){
        const wR = num(c.getAttribute('wR')) * sx, hR = num(c.getAttribute('hR')) * sy;
        const st = num(c.getAttribute('stAng')) / 60000 * Math.PI / 180, sw = num(c.getAttribute('swAng')) / 60000 * Math.PI / 180;
        if (![wR, hR, st, sw].every(Number.isFinite)) { bad = true; break; }
        const ox = cx - wR * Math.cos(st), oy = cy - hR * Math.sin(st);
        cx = ox + wR * Math.cos(st + sw); cy = oy + hR * Math.sin(st + sw);
        d += 'A' + f(wR) + ' ' + f(hR) + ' 0 ' + (Math.abs(sw) > Math.PI ? 1 : 0) + ' ' + (sw > 0 ? 1 : 0) + ' ' + f(cx) + ' ' + f(cy);
      }
    }
    if (bad) return null;
  }
  return d ? { d, box: [0, 0, PATH_BOX, PATH_BOX], filled } : null;
}
function adjOf(spPr, dflt){
  const geom = spPr ? kid(spPr, 'a:prstGeom') : null;
  const av = geom ? kid(geom, 'a:avLst') : null;
  const gd = av ? kids(av, 'a:gd').find(g => /^adj1?$/.test(g.getAttribute('name') || '')) : null;
  const m = gd ? /val\s+(-?\d+)/.exec(gd.getAttribute('fmla') || '') : null;
  return m ? parseInt(m[1], 10) / 100000 : dflt;
}

async function convertNode(node, ctx, elId){
  const tag = node.tagName;
  const out = [];
  const pal = ctx.pal, theme = ctx.theme;
  const cnv = cNvPrOf(node);
  const spid = cnv ? cnv.getAttribute('id') : null;
  const anim = ctx.anim;
  const shapeAnim = anim ? (anim.shapes.get(spid) || (node.__grpIds || []).slice().reverse().map(g => anim.shapes.get(g)).find(Boolean)) : null;
  const paraAnim = anim ? anim.paras.get(spid) : null;
  const firstPara = paraAnim ? Array.from(paraAnim.values()).sort((a, b) => a.step - b.step)[0] : null;
  const fxShape = bentoFx(shapeAnim || firstPara);
  const phType = effectivePhType(node, ctx);
  const withFx = el => { if (el && fxShape) el.fx = Object.assign({}, fxShape); return el; };

  if (tag === 'p:sp' || tag === 'p:cxnSp'){
    const frame = frameFromChain(node, ctx) || fallbackFrame(phType, ctx.slideW, ctx.slideH);
    const spPr = kid(node, 'p:spPr');
    const prst = spPr ? kid(spPr, 'a:prstGeom') : null;
    const geom = prst ? prst.getAttribute('prst') : null;
    let fill = spPr ? fillOf(spPr, pal, null) : null;
    if (fill && fill.kind === 'grp') fill = fillOf(node.__grpSpPr, pal, null);
    if (!fill) fill = themeFillRef(styleRef(node, 'a:fillRef'), theme, pal);
    if (!fill && phType) for (const c of chainFor(node, ctx).slice(1)){ const f = fillOf(kid(c.el, 'p:spPr'), pal, null); if (f){ fill = f; break; } }
    const line = spPr ? lineOf(spPr, node, theme, pal) : null;

    if ((geom && LINE_GEOMS.test(geom)) || (tag === 'p:cxnSp' && !geom)){
      if (line) out.push(withFx(lineElement(frame, line, elId)));
      return out.filter(Boolean);
    }
    if (fill && fill.kind === 'blip'){
      out.push(withFx(await blipImage(fill.el, ctx, frame, elId + 'i')));
      fill = null;
    }
    const xf = spPr ? kid(spPr, 'a:xfrm') : null, ext = xf ? kid(xf, 'a:ext') : null;
    const custom = geom ? null : custGeomPath(spPr, intAttr(ext, 'cx', 0), intAttr(ext, 'cy', 0));
    const hasFill = !!fill && (fill.kind === 'solid' || fill.kind === 'grad') && (!custom || custom.filled);
    const emitsShape = hasFill || !!line;
    if (emitsShape){
      const orient = ARROW_ORIENTATION[geom];
      const shapeW = (orient && orient.swapWH) ? frame.h : frame.w;
      const shapeH = (orient && orient.swapWH) ? frame.w : frame.h;
      // keep the same visual centre when the box's own w/h swap
      const el = {
        id: elId, type: 'shape', shape: shapeKind(geom),
        x: (orient && orient.swapWH) ? rnd(frame.x + (frame.w - shapeW) / 2) : frame.x,
        y: (orient && orient.swapWH) ? rnd(frame.y + (frame.h - shapeH) / 2) : frame.y,
        w: shapeW, h: shapeH,
        rotation: frame.rotation + (orient ? orient.extraRotation : 0), opacity: 1,
        fill: hasFill ? clrCss(fill.color) : 'transparent',
        stroke: line ? line.color : 'none', strokeWidth: line ? line.width : 0,
        radius: 0,
      };
      if (hasFill && fill.kind === 'grad') el.fillGradient = fill.grad;
      if (line && line.dash) el.strokeStyle = line.dash;
      if (el.shape === 'polygon') el.sides = POLYGON_SIDES[geom] || 6;
      if (custom){ el.shape = 'path'; el.d = custom.d; el.pathBox = custom.box; }
      if (/^(roundRect|round1Rect|round2SameRect|flowChartAlternateProcess)$/.test(geom || ''))
        el.radius = rnd(Math.min(frame.w, frame.h) * Math.max(0, Math.min(0.5, adjOf(spPr, 0.16667))));
      out.push(withFx(el));
    }

    const txBody = kid(node, 'p:txBody');
    const info = txBody ? extractTextBody(txBody, {
      sources: lvl => textSources(node, ctx, lvl, phType),
      fallbackColor: roleColor(pal, 'tx1') || '#000000',
      fonts: ctx.fonts, pal, rels: ctx.rels,
    }) : null;
    if (info){
      const bl = bodyLayout(node, ctx);
      const txFrame = frameOfXfrm(kid(node, 'p:txXfrm')) || frame;
      const box = { x: txFrame.x + bl.l, y: txFrame.y + bl.t, w: Math.max(8, txFrame.w - bl.l - bl.r), h: Math.max(8, txFrame.h - bl.t - bl.b), rotation: frame.rotation };
      const textId = emitsShape ? elId + 't' : elId;
      if (paraAnim && info.paras.length > 1) out.push(...paragraphElements(textId, box, info, bl.valign, paraAnim, shapeAnim ? bentoFx(shapeAnim) : null));
      else out.push(withFx(textElement(textId, box, info, bl.valign)));
    }
    return out.filter(Boolean);
  }

  if (tag === 'p:pic'){
    const frame = frameFromChain(node, ctx) || fallbackFrame(null, ctx.slideW, ctx.slideH);
    const img = await blipImage(kid(node, 'p:blipFill'), ctx, frame, elId);
    if (img){
      // PowerPoint's alt text ("Beschreibung", falling back to the title);
      // a picture marked decorative stays empty on purpose.
      const decorative = cnv && Array.from(cnv.getElementsByTagName('*')).some(e => e.localName === 'decorative' && e.getAttribute('val') === '1');
      const alt = cnv ? ((cnv.getAttribute('descr') || '').trim() || (cnv.getAttribute('title') || '').trim()) : '';
      if (alt && !decorative) img.alt = alt.replace(/\s+/g, ' ');
      out.push(withFx(img));
    }
    return out;
  }

  if (tag === 'p:graphicFrame'){
    const frame = frameFromChain(node, ctx) || fallbackFrame(null, ctx.slideW, ctx.slideH);
    const gd = first(node, 'a:graphicData');
    const uri = gd ? (gd.getAttribute('uri') || '') : '';
    try {
      if (/\/chart$/.test(uri)){
        const c = first(gd, 'c:chart');
        const path = c ? resolvePartPath(ctx.dir, ctx.rels[c.getAttribute('r:id')]) : null;
        const chartDoc = path ? await ctx.xmlOf(path) : null;
        const res = chartDoc ? convertChartXml(chartDoc, pal, ctx.warn) : null;
        if (res){
          const ink = roleColor(pal, 'tx1') || '#000000';
          let y = frame.y, h = frame.h;
          if (res.title && h > 160){
            out.push(withFx({
              id: elId + 'h', type: 'text', x: frame.x, y, w: frame.w, h: 44, rotation: 0, opacity: 1,
              html: esc(res.title), fontSize: 24, fontFamily: fontStack(ctx.fonts.minor), fontWeight: 600,
              color: ink, align: 'center', valign: 'middle', lineHeight: 1.2,
            }));
            y += 48; h -= 48;
          }
          let tableEl = null, chartH = h;
          if (res.table){
            // the chart's data table: bento's own layout for a linked table
            // (labels down the first column, one column per series), so
            // editing a number redraws the chart
            const nRows = res.table.categories.length + 1;
            const tH = Math.min(Math.round(h * 0.45), nRows * 34);
            chartH = h - tH - 8;
            const fsz = Math.max(10, Math.min(18, Math.floor(tH / nRows / 1.8)));
            const fmtNum = v => String(Math.round(v * 1000) / 1000);
            tableEl = {
              id: elId + 'd', type: 'table', x: frame.x, y: y + chartH + 8, w: frame.w, h: tH, rotation: 0, opacity: 1,
              header: true,
              columns: [{ w: 1.4 }, ...res.table.series.map(() => ({ w: 1 }))],
              rows: [
                { cells: [{ html: '' }, ...res.table.series.map(s => ({ html: esc(s.name) }))] },
                ...res.table.categories.map((cat, j) => ({ cells: [{ html: esc(cat) }, ...res.table.series.map(s => ({ html: fmtNum(s.data[j] || 0), align: 'right' }))] })),
              ],
              style: {
                headerBg: pal.colors.accent1 || '#1E2A3A', headerColor: '#FFFFFF', borderColor: 'rgba(0,0,0,0.15)', borderWidth: 1,
                cellPadX: 8, cellPadY: Math.max(2, Math.round(fsz * 0.25)), fontSize: fsz, fontFamily: fontStack(ctx.fonts.minor), color: ink, radius: 0,
              },
            };
          }
          const chartEl = { id: elId, type: 'chart', x: frame.x, y, w: frame.w, h: Math.max(60, chartH), rotation: 0, opacity: 1, preset: res.preset, option: res.option };
          if (tableEl) chartEl.source = { tableId: tableEl.id };
          out.push(withFx(chartEl));
          if (tableEl) out.push(withFx(tableEl));
          return out;
        }
      } else if (/\/table$/.test(uri)){
        const tbl = first(gd, 'a:tbl');
        if (tbl) return [withFx(convertTableXml(tbl, frame, ctx, elId))];
      } else if (/\/ole$/.test(uri)){
        // an embedded object (Excel sheet, equation, …): newer files carry
        // its preview picture inline — the closest thing to the object
        const pic = first(gd, 'p:pic');
        const img = pic ? await blipImage(kid(pic, 'p:blipFill'), ctx, frame, elId) : null;
        if (img) return [withFx(img)];
      } else if (/\/diagram$/.test(uri)){
        // SmartArt: PowerPoint stores a pre-drawn copy of the layout
        // (ppt/diagrams/drawingN.xml) — ordinary shapes, positioned
        // relative to the frame. Converted like any other shapes.
        const relIds = first(gd, 'dgm:relIds');
        const dmPath = relIds ? resolvePartPath(ctx.dir, ctx.rels[relIds.getAttribute('r:dm')]) : null;
        const dataDoc = dmPath ? await ctx.xmlOf(dmPath) : null;
        const ext = dataDoc ? first(dataDoc, 'dsp:dataModelExt') : null;
        const drawPath = ext ? resolvePartPath(ctx.dir, ctx.rels[ext.getAttribute('relId')]) : null;
        const drawFile = drawPath ? ctx.zip.file(drawPath) : null;
        if (drawFile){
          const text = (await drawFile.async('string')).replace(/<(\/?)dsp:/g, '<$1p:').replace(/xmlns:dsp=/g, 'xmlns:p=');
          const drawDoc = parseXml(text);
          const tree = first(drawDoc, 'p:spTree');
          if (tree){
            const xf = kid(node, 'p:xfrm'), off = xf ? kid(xf, 'a:off') : null;
            const g = { offX: intAttr(off, 'x', 0), offY: intAttr(off, 'y', 0), extW: 1, extH: 1, chOffX: 0, chOffY: 0, chExtW: 1, chExtH: 1, rot: 0 };
            const shapes = flattenGroupedShapes(Array.from(tree.children));
            const drawRels = await ctx.relsOf(drawPath);
            const sub = Object.assign({}, ctx, { rels: drawRels, dir: drawPath.substring(0, drawPath.lastIndexOf('/')), anim: null });
            let k = 1;
            for (const s of shapes){
              applyGroupXfrmToChild(s, g);
              applyGroupToXfrm(kid(s, 'p:txXfrm'), g);
              out.push(...(await convertNode(s, sub, elId + 'g' + (k++))).map(withFx));
            }
            if (out.length) return out;
          }
        }
      }
    } catch (e){
      console.warn('graphicFrame nicht übernommen:', e);
    }
    ctx.warn('Ein eingebettetes Objekt (OLE/Sonderformat) konnte nicht übernommen werden — als Platzhalter markiert.');
    return [{
      id: elId, type: 'text',
      x: frame.x, y: frame.y, w: frame.w, h: frame.h,
      rotation: frame.rotation, opacity: 1,
      html: '[Objekt aus PowerPoint — manuell nachbauen]',
      fontSize: Math.max(10, Math.min(20, Math.round(frame.h / 5), Math.round(frame.w / 12))), fontFamily: 'system-ui, sans-serif', fontWeight: 500,
      color: '#999999', align: 'left', valign: 'top', lineHeight: 1.3,
    }];
  }
  return out;
}

async function convertTree(root, ctx){
  const cSld = root ? kid(root, 'p:cSld') : null;
  const spTree = cSld ? kid(cSld, 'p:spTree') : null;
  if (!spTree) return [];
  const nodes = flattenGroupedShapes(Array.from(spTree.children));
  const out = [];
  let n = 1;
  for (const node of nodes){
    // a layout's / master's placeholders are PROMPTS ("Click to add
    // title"), not content — only its own artwork and logos carry over
    if (ctx.layer !== 'slide' && phOf(node)) continue;
    const cnv = cNvPrOf(node);
    if (cnv && cnv.getAttribute('hidden') === '1') continue;
    const made = await convertNode(node, ctx, ctx.idPrefix + 'e' + (n++));
    const name = cnv ? (cnv.getAttribute('name') || '') : '';
    made.forEach((el, k) => { el.__name = name ? name + '#' + k : ''; });
    out.push(...made);
  }
  return out;
}

// Background: the FIRST level (slide → layout → master) that declares a p:bg
// settles it — p:bgPr directly, or p:bgRef into the theme's background
// styles. Gradients keep the gradient; a picture becomes a full-slide image
// behind everything.
function backgroundOf(levels, ctx){
  const dflt = roleColor(ctx.pal, 'bg1') || '#FFFFFF';
  for (const lv of levels){
    const cSld = lv.root ? kid(lv.root, 'p:cSld') : null;
    const bg = cSld ? kid(cSld, 'p:bg') : null;
    if (!bg) continue;
    const bgPr = kid(bg, 'p:bgPr');
    const f = bgPr ? fillOf(bgPr, ctx.pal, null) : themeFillRef(kid(bg, 'p:bgRef'), ctx.theme, ctx.pal);
    if (!f || f.kind === 'none' || f.kind === 'grp') return { color: dflt };
    if (f.kind === 'solid') return { color: clrCss(f.color) };
    if (f.kind === 'grad') return { color: clrCss(f.color), grad: f.grad };
    if (f.kind === 'blip') return { color: dflt, blip: f.el, level: lv };
  }
  return { color: dflt };
}

async function convertPptx(file, log){
  const zip = await JSZip.loadAsync(file);
  const xmlCache = new Map(), relsCache = new Map();
  const xmlOf = async path => {
    if (!path) return null;
    if (!xmlCache.has(path)){ const f = zip.file(path); xmlCache.set(path, f ? parseXml(await f.async('string')) : null); }
    return xmlCache.get(path);
  };
  const relsOf = async partPath => {
    if (!partPath) return parseRels('');
    if (!relsCache.has(partPath)){
      const dir = partPath.substring(0, partPath.lastIndexOf('/')), name = partPath.substring(partPath.lastIndexOf('/') + 1);
      const f = zip.file(dir + '/_rels/' + name + '.rels');
      relsCache.set(partPath, parseRels(f ? await f.async('string') : ''));
    }
    return relsCache.get(partPath);
  };
  const dirOf = p => p ? p.substring(0, p.lastIndexOf('/')) : 'ppt';

  const presDoc = await xmlOf('ppt/presentation.xml');
  const sldSzEl = first(presDoc, 'p:sldSz');
  const slideW = sldSzEl ? emuToPx(intAttr(sldSzEl, 'cx', 12192000)) : 1280;
  const slideH = sldSzEl ? emuToPx(intAttr(sldSzEl, 'cy', 6858000)) : 720;
  const presRels = await relsOf('ppt/presentation.xml');
  const slidePaths = all(presDoc, 'p:sldId').map(el => resolvePartPath('ppt', presRels[el.getAttribute('r:id')])).filter(Boolean);
  const defaultTextStyle = first(presDoc, 'p:defaultTextStyle');

  const tableStyles = {};
  const tsDoc = await xmlOf('ppt/tableStyles.xml');
  if (tsDoc) for (const s of all(tsDoc, 'a:tblStyle')) tableStyles[s.getAttribute('styleId')] = s;

  // title from core properties
  let title = file.name.replace(/\.pptx$/i, '');
  const coreDoc = await xmlOf('docProps/core.xml');
  if (coreDoc){
    const dcTitle = coreDoc.getElementsByTagName('dc:title')[0];
    if (dcTitle && dcTitle.textContent.trim()) title = dcTitle.textContent.trim();
  }

  const themeCache = new Map();
  const themeFor = async path => {
    if (!themeCache.has(path)) themeCache.set(path, parseTheme(await xmlOf(path)));
    return themeCache.get(path);
  };
  const fallbackThemePath = (zip.file(/ppt\/theme\/theme1\.xml/i)[0] || {}).name || null;
  const partIndex = new Map();
  const indexOf = path => { if (!partIndex.has(path)) partIndex.set(path, partIndex.size + 1); return partIndex.get(path); };

  const shared = {
    zip, xmlOf, relsOf, slideW, slideH, defaultTextStyle, tableStyles,
    assets: {}, assetCounter: { n: 1 },
    // Shared across every slide: a logo or recurring background referenced
    // from several slides is recognised as "the same picture".
    imagePathToKey: new Map(),
    imageKeyToMorphId: new Map(),
  };
  const warnings = new Set();
  shared.warn = msg => warnings.add(msg);
  const slides = [];
  let docTheme = null;

  for (let i = 0; i < slidePaths.length; i++){
    const slidePath = slidePaths[i];
    const sldDoc = await xmlOf(slidePath);
    if (!sldDoc) continue;
    const sldRoot = sldDoc.documentElement;
    const slideDir = dirOf(slidePath);
    const rels = await relsOf(slidePath);
    const layoutPath = resolvePartPath(slideDir, relOfType(rels, '/slideLayout'));
    const layoutDoc = await xmlOf(layoutPath);
    const layoutRels = await relsOf(layoutPath);
    const masterPath = layoutPath ? resolvePartPath(dirOf(layoutPath), relOfType(layoutRels, '/slideMaster')) : null;
    const masterDoc = await xmlOf(masterPath);
    const masterRels = await relsOf(masterPath);
    const themePath = masterPath ? (resolvePartPath(dirOf(masterPath), relOfType(masterRels, '/theme')) || fallbackThemePath) : fallbackThemePath;
    const theme = await themeFor(themePath);
    const layoutRoot = layoutDoc ? layoutDoc.documentElement : null;
    const masterRoot = masterDoc ? masterDoc.documentElement : null;
    const masterMap = clrMapFrom(masterRoot ? kid(masterRoot, 'p:clrMap') : null);
    const pal = { colors: theme.colors, map: clrMapOverride(sldRoot, clrMapOverride(layoutRoot, masterMap)) };
    const base = Object.assign({}, shared, { pal, theme, fonts: theme.fonts, layoutRoot, masterRoot });
    if (!docTheme) docTheme = { pal, fonts: theme.fonts };

    const levels = [
      { root: sldRoot, rels, dir: slideDir, prefix: 's' + (i + 1) + '_' },
      { root: layoutRoot, rels: layoutRels, dir: dirOf(layoutPath), prefix: 'l' + indexOf(layoutPath) + '_' },
      { root: masterRoot, rels: masterRels, dir: dirOf(masterPath), prefix: 'm' + indexOf(masterPath) + '_' },
    ];
    const bg = backgroundOf(levels, base);
    const elements = [];
    if (bg.blip){
      const img = await blipImage(bg.blip, Object.assign({}, base, { rels: bg.level.rels, dir: bg.level.dir }),
        { x: 0, y: 0, w: slideW, h: slideH, rotation: 0 }, bg.level.prefix + 'bg');
      if (img) elements.push(img);
    }
    // the master's and layout's own artwork (logos, bands, frames) shows on
    // every slide that doesn't switch it off — same ids on every slide, so it
    // simply stays put across transitions
    if (sldRoot.getAttribute('showMasterSp') !== '0'){
      if (masterRoot && (!layoutRoot || layoutRoot.getAttribute('showMasterSp') !== '0'))
        elements.push(...await convertTree(masterRoot, Object.assign({}, base, { layer: 'master', rels: masterRels, dir: dirOf(masterPath), idPrefix: levels[2].prefix })));
      if (layoutRoot)
        elements.push(...await convertTree(layoutRoot, Object.assign({}, base, { layer: 'layout', rels: layoutRels, dir: dirOf(layoutPath), idPrefix: levels[1].prefix })));
    }
    const anim = parseTiming(sldRoot);
    if ([...anim.dropped].some(c => c !== 'entr'))
      warnings.add('Nur Eingangsanimationen werden übernommen (als Klickschritte) — Hervorhebungs-, Ausgangs- und Pfadanimationen entfallen.');
    elements.push(...await convertTree(sldRoot, Object.assign({}, base, { layer: 'slide', rels, dir: slideDir, idPrefix: levels[0].prefix, anim })));

    const notesPath = resolvePartPath(slideDir, relOfType(rels, '/notesSlide'));
    const notesDoc = notesPath ? await xmlOf(notesPath) : null;

    const slide = {
      id: 's' + (i + 1),
      background: bg.color,
      transition: transitionOf(sldRoot),
      elements,
      notes: notesDoc ? notesTextOf(notesDoc.documentElement) : '',
    };
    if (bg.grad) slide.backgroundGradient = bg.grad;
    if (sldRoot.getAttribute('show') === '0') slide.hidden = true;
    slides.push(slide);
  }

  // Morph: PowerPoint pairs objects across a morph by NAME (the duplicate-
  // a-slide idiom keeps names; "!!name" forces a pair). bento pairs by
  // morph key — so a same-named element on the next slide adopts the key of
  // its namesake. Names that repeat on one slide are ambiguous and skipped.
  for (let i = 1; i < slides.length; i++){
    if (slides[i].transition !== 'morph') continue;
    const prevBy = new Map(), dup = new Set();
    for (const e of slides[i - 1].elements) if (e.__name){
      if (prevBy.has(e.__name)) dup.add(e.__name);
      prevBy.set(e.__name, e.morphId || e.id);
    }
    for (const e of slides[i].elements){
      if (!e.__name || dup.has(e.__name) || !prevBy.has(e.__name)) continue;
      const k = prevBy.get(e.__name);
      if (k !== e.id) e.morphId = k;
    }
  }
  // Two elements with the same morph key on ONE slide break the pairing.
  for (const s of slides){
    const seen = new Set();
    for (const e of s.elements){
      let k = e.morphId || e.id;
      if (seen.has(k) && e.morphId){ delete e.morphId; k = e.id; }
      seen.add(k);
      delete e.__name;
    }
  }

  if (!slides.length){
    slides.push({
      id: 's1', background: '#101418', transition: 'none', notes: '',
      elements: [{ id:'t1', type:'text', x:96, y:260, w: slideW-192, h:160, rotation:0, opacity:1,
        html:'(Keine Folien gefunden)', fontSize:48, fontFamily:'system-ui, sans-serif', fontWeight:700,
        color:'#ffffff', align:'left', valign:'top', lineHeight:1.1 }]
    });
  }

  const tp = docTheme ? docTheme.pal : { colors: {}, map: DEFAULT_CLR_MAP };
  const doc = {
    format: 'bento/slides',
    version: 1,
    docId: uuid(),
    title,
    size: { width: slideW, height: slideH },
    theme: {
      background: roleColor(tp, 'bg1') || '#FFFFFF',
      color: roleColor(tp, 'tx1') || '#111111',
      accent: roleColor(tp, 'accent1') || '#FF9E5E',
      fontFamily: fontStack(docTheme && docTheme.fonts.minor),
    },
    slides,
    modified: new Date().toISOString()
  };
  if (Object.keys(shared.assets).length) doc.assets = shared.assets;

  return { doc, warnings: Array.from(warnings), slideCount: slides.length };
}

// ================= Legacy .ppt (PowerPoint 97–2003 binary) =================
// The old format is an OLE2 compound file ("CFB", a small FAT file system)
// holding a "PowerPoint Document" stream of binary records [MS-PPT], whose
// drawings are Office Art records [MS-ODRAW], plus a "Pictures" stream with
// the images. Read here directly — no server, no LibreOffice — and mapped
// onto the same bento elements the PPTX path produces.

// ---- CFB reader: file bytes → Map(root-level stream name → bytes) ----
function cfbStreams(buf){
  const u8 = new Uint8Array(buf), dv = new DataView(buf);
  const SIG = [0xD0,0xCF,0x11,0xE0,0xA1,0xB1,0x1A,0xE1];
  if (u8.length < 512 || !SIG.every((b, i) => u8[i] === b))
    throw new Error('Keine PowerPoint-97–2003-Datei (kein OLE-Container).');
  const END = 0xFFFFFFFA; // ≥ this: end of chain / free / FAT / DIFAT markers
  const secSize = 1 << dv.getUint16(0x1E, true);
  const miniSize = 1 << dv.getUint16(0x20, true);
  const nFat = dv.getUint32(0x2C, true);
  const dirStart = dv.getUint32(0x30, true);
  const miniCutoff = dv.getUint32(0x38, true) || 4096;
  const miniFatStart = dv.getUint32(0x3C, true);
  let difat = dv.getUint32(0x44, true);
  const nDifat = dv.getUint32(0x48, true);
  const secOff = s => (s + 1) * secSize; // the header fills sector "-1"

  const fatSecs = [];
  for (let i = 0; i < 109 && fatSecs.length < nFat; i++){ const s = dv.getUint32(0x4C + i * 4, true); if (s < END) fatSecs.push(s); }
  for (let k = 0; k < nDifat && difat < END && secOff(difat) < u8.length; k++){
    const o = secOff(difat), per = secSize / 4 - 1;
    for (let i = 0; i < per && fatSecs.length < nFat; i++){ const s = dv.getUint32(o + i * 4, true); if (s < END) fatSecs.push(s); }
    difat = dv.getUint32(o + per * 4, true);
  }
  const perSec = secSize / 4;
  const fat = new Uint32Array(fatSecs.length * perSec).fill(0xFFFFFFFE);
  fatSecs.forEach((s, i) => {
    const o = secOff(s);
    for (let j = 0; j < perSec && o + j * 4 + 4 <= u8.length; j++) fat[i * perSec + j] = dv.getUint32(o + j * 4, true);
  });
  const chain = (start, table) => {
    const out = [];
    for (let s = start, n = 0; s < END && s < table.length && n <= table.length; s = table[s], n++) out.push(s);
    return out;
  };
  const readChain = (start, size) => {
    const secs = chain(start, fat);
    const out = new Uint8Array(secs.length * secSize);
    secs.forEach((s, i) => out.set(u8.subarray(secOff(s), secOff(s) + secSize), i * secSize));
    return size == null ? out : out.subarray(0, Math.min(size, out.length));
  };

  const dir = readChain(dirStart);
  const ddv = new DataView(dir.buffer, dir.byteOffset, dir.byteLength);
  const entries = [];
  for (let o = 0; o + 128 <= dir.length; o += 128){
    const type = dir[o + 0x42];
    if (!type){ entries.push(null); continue; }
    const nameLen = ddv.getUint16(o + 0x40, true);
    let name = '';
    for (let i = 0; i < Math.max(0, nameLen / 2 - 1) && i < 32; i++) name += String.fromCharCode(ddv.getUint16(o + i * 2, true));
    entries.push({
      name, type,
      left: ddv.getUint32(o + 0x44, true), right: ddv.getUint32(o + 0x48, true), child: ddv.getUint32(o + 0x4C, true),
      start: ddv.getUint32(o + 0x74, true), size: ddv.getUint32(o + 0x78, true),
    });
  }
  const root = entries[0];
  if (!root) throw new Error('Beschädigte .ppt-Datei (kein Stammverzeichnis).');
  const miniFatBytes = miniFatStart < END ? readChain(miniFatStart) : new Uint8Array(0);
  const mdv = new DataView(miniFatBytes.buffer, miniFatBytes.byteOffset, miniFatBytes.byteLength);
  const miniFat = new Uint32Array(miniFatBytes.length >> 2);
  for (let i = 0; i < miniFat.length; i++) miniFat[i] = mdv.getUint32(i * 4, true);
  const miniStream = root.start < END ? readChain(root.start, root.size) : new Uint8Array(0);

  // only the root storage's own streams (embedded OLE objects carry storages
  // of their own, sometimes with same-named streams)
  const top = [], seen = new Set();
  const walk = i => {
    if (i >= END || !entries[i] || seen.has(i)) return;
    seen.add(i);
    walk(entries[i].left); top.push(entries[i]); walk(entries[i].right);
  };
  walk(root.child);
  const streams = new Map();
  for (const e of top){
    if (e.type !== 2) continue;
    let data;
    if (e.size < miniCutoff){
      const secs = chain(e.start, miniFat);
      data = new Uint8Array(secs.length * miniSize);
      secs.forEach((s, i) => data.set(miniStream.subarray(s * miniSize, (s + 1) * miniSize), i * miniSize));
      data = data.subarray(0, e.size);
    } else data = readChain(e.start, e.size);
    streams.set(e.name, data);
  }
  return streams;
}

// ---- record primitives ----
function pptReader(u8){ return { u8, dv: new DataView(u8.buffer, u8.byteOffset, u8.byteLength) }; }
function pptRec(R, off){
  if (off == null || off < 0 || off + 8 > R.u8.length) return null;
  const vi = R.dv.getUint16(off, true);
  const len = R.dv.getUint32(off + 4, true);
  return { ver: vi & 0xF, inst: vi >> 4, type: R.dv.getUint16(off + 2, true), len: Math.min(len, R.u8.length - off - 8), off, body: off + 8 };
}
function pptKids(R, rec){
  const out = [];
  if (!rec) return out;
  let o = rec.body;
  const end = rec.body + rec.len;
  while (o + 8 <= end){
    const r = pptRec(R, o);
    if (!r) break;
    out.push(r);
    o = r.body + r.len;
  }
  return out;
}
const pptKid = (R, rec, type) => pptKids(R, rec).find(r => r.type === type) || null;
function pptFind(R, rec, type, depth){ // depth-first search through containers
  if (!rec || depth > 12) return null;
  for (const k of pptKids(R, rec)){
    if (k.type === type) return k;
    if (k.ver === 0xF){ const f = pptFind(R, k, type, (depth || 0) + 1); if (f) return f; }
  }
  return null;
}
function pptUtf16(R, off, len){
  let s = '';
  for (let i = 0; i + 1 < len; i += 2){ const c = R.dv.getUint16(off + i, true); if (!c) break; s += String.fromCharCode(c); }
  return s;
}

// ---- persist directory: persist id → record offset (newest edit wins) ----
function pptPersist(R, currentUser){
  let editOff = null;
  if (currentUser && currentUser.length >= 20){
    const cdv = new DataView(currentUser.buffer, currentUser.byteOffset, currentUser.byteLength);
    if (cdv.getUint32(12, true) === 0xF3D1C4DF) throw new Error('Passwortgeschützte .ppt-Dateien können nicht gelesen werden — bitte ohne Kennwort speichern.');
    editOff = cdv.getUint32(16, true);
  }
  let r = pptRec(R, editOff);
  if (!r || r.type !== 0x0FF5){ // broken pointer: take the last UserEditAtom in the stream
    let o = 0, last = null;
    for (let n = 0; o + 8 <= R.u8.length && n < 200000; n++){ const x = pptRec(R, o); if (!x) break; if (x.type === 0x0FF5) last = o; o = x.body + x.len; }
    editOff = last;
  }
  const persist = new Map();
  let docRef = null;
  const seen = new Set();
  for (let off = editOff; off != null && !seen.has(off);){
    seen.add(off);
    const ue = pptRec(R, off);
    if (!ue || ue.type !== 0x0FF5) break;
    if (docRef == null) docRef = R.dv.getUint32(ue.body + 16, true);
    const pd = pptRec(R, R.dv.getUint32(ue.body + 12, true));
    if (pd && pd.type === 0x1772){
      for (let o = pd.body, end = pd.body + pd.len; o + 4 <= end;){
        const w = R.dv.getUint32(o, true); o += 4;
        const pid = w & 0xFFFFF, n = w >>> 20;
        for (let k = 0; k < n && o + 4 <= end; k++, o += 4) if (!persist.has(pid + k)) persist.set(pid + k, R.dv.getUint32(o, true));
      }
    }
    const prev = R.dv.getUint32(ue.body + 8, true);
    off = prev ? prev : null;
  }
  return { persist, docRef };
}

// ---- text properties (StyleTextPropAtom / TextMasterStyleAtom) ----
function pptPF(dv, o){
  const m = dv.getUint32(o, true); o += 4;
  const p = { mask: m };
  if (m & 0xF){ const f = dv.getUint16(o, true); o += 2; if (m & 1) p.hasBullet = !!(f & 1); if (m & 2) p.bulletHasFont = !!(f & 2); }
  if (m & 0x80){ p.bulletChar = dv.getUint16(o, true); o += 2; }
  if (m & 0x10){ p.bulletFont = dv.getUint16(o, true); o += 2; }
  if (m & 0x40){ o += 2; }
  if (m & 0x20){ p.bulletColor = dv.getUint32(o, true); o += 4; }
  if (m & 0x800){ p.align = dv.getUint16(o, true); o += 2; }
  if (m & 0x1000){ p.lineSpacing = dv.getInt16(o, true); o += 2; }
  if (m & 0x2000){ o += 2; }
  if (m & 0x4000){ o += 2; }
  if (m & 0x100){ o += 2; }
  if (m & 0x400){ o += 2; }
  if (m & 0x8000){ o += 2; }
  if (m & 0x100000){ const n = dv.getUint16(o, true); o += 2 + n * 4; }
  if (m & 0x10000){ o += 2; }
  if (m & 0xE0000){ o += 2; }
  if (m & 0x200000){ o += 2; }
  return { props: p, next: o };
}
function pptCF(dv, o){
  const m = dv.getUint32(o, true); o += 4;
  const p = {};
  if (m & 0x3EB7){
    const s = dv.getUint16(o, true); o += 2;
    if (m & 1) p.bold = !!(s & 1);
    if (m & 2) p.italic = !!(s & 2);
    if (m & 4) p.underline = !!(s & 4);
  }
  if (m & 0x10000){ p.font = dv.getUint16(o, true); o += 2; }
  if (m & 0x200000){ o += 2; }
  if (m & 0x400000){ o += 2; }
  if (m & 0x800000){ o += 2; }
  if (m & 0x20000){ p.size = dv.getUint16(o, true); o += 2; }
  if (m & 0x40000){ p.color = dv.getUint32(o, true); o += 4; }
  if (m & 0x80000){ o += 2; }
  return { props: p, next: o };
}
function pptStyleRuns(R, rec, textLen){
  const dv = R.dv, end = rec.body + rec.len;
  let o = rec.body;
  const pf = [], cf = [];
  try {
    for (let total = 0; total < textLen + 1 && o + 6 <= end;){
      const count = dv.getUint32(o, true), indent = dv.getUint16(o + 4, true);
      const r = pptPF(dv, o + 6); o = r.next;
      pf.push({ count, indent, props: r.props });
      total += count;
      if (!count) break;
    }
    for (let total = 0; total < textLen + 1 && o + 4 <= end;){
      const count = dv.getUint32(o, true);
      const r = pptCF(dv, o + 4); o = r.next;
      cf.push({ count, props: r.props });
      total += count;
      if (!count) break;
    }
  } catch (e){ /* truncated property run — keep what was read */ }
  return { pf, cf };
}
function pptMasterStyle(R, rec){
  const dv = R.dv;
  const levels = [];
  try {
    const n = dv.getUint16(rec.body, true);
    let o = rec.body + 2;
    for (let i = 0; i < Math.min(n, 5); i++){
      if (rec.inst >= 5) o += 2;
      const pf = pptPF(dv, o); o = pf.next;
      const cf = pptCF(dv, o); o = cf.next;
      levels.push({ pf: pf.props, cf: cf.props });
    }
  } catch (e){ /* keep the levels read so far */ }
  return levels;
}

// ---- Office Art (drawing) ----
function pptFopt(R, rec, into){
  const n = rec.inst;
  let cx = rec.body + n * 6;
  for (let i = 0, o = rec.body; i < n && o + 6 <= rec.body + rec.len; i++, o += 6){
    const opid = R.dv.getUint16(o, true), val = R.dv.getInt32(o + 2, true);
    const e = { val };
    if (opid & 0x8000){
      const len = Math.max(0, Math.min(val >>> 0, rec.body + rec.len - cx));
      e.data = { off: cx, len };
      cx += len;
    }
    const id = opid & 0x3FFF, prev = into.get(id);
    // Boolean sets (ids ending in 0x3F) are split between the primary and
    // tertiary tables (the newer bits live in the latter): merge, don't replace.
    if ((id & 0x3F) === 0x3F && prev && !prev.data && !e.data && into.__own) e.val = (prev.val | e.val) >>> 0;
    into.set(id, e);
  }
}
// OfficeArtCOLORREF (fill/line colours): scheme index, or plain RGB.
function pptColorRef(v, scheme){
  if (v == null) return null;
  const r = v & 0xFF, g = (v >>> 8) & 0xFF, b = (v >>> 16) & 0xFF, flags = (v >>> 24) & 0xFF;
  if (flags & 0x08) return scheme[r] || null;
  if (flags & 0x10) return null; // system / "same as fill" colours: no fixed value
  return rgbToHex(r, g, b);
}
// ColorIndexStruct (text colours): index 0xFE = RGB, 0–7 = scheme slot.
function pptTextColor(v, scheme){
  if (v == null) return null;
  const idx = (v >>> 24) & 0xFF;
  if (idx === 0xFE) return rgbToHex(v & 0xFF, (v >>> 8) & 0xFF, (v >>> 16) & 0xFF);
  if (idx < 8) return scheme[idx] || null;
  return null;
}
// A boolean property set: `bit` is the value, `bit << 16` says it's set.
// Walks the lookup chain per bit; a set without any "set" markers is from
// an older writer and counts as fully specified.
function pptBoolIn(maps, id, bit, dflt){
  for (const m of maps){
    const e = m.get(id);
    if (!e) continue;
    const v = e.val >>> 0;
    if (v & (bit << 16)) return !!(v & bit);
    if (!(v >>> 16)) return !!(v & bit);
  }
  return dflt;
}
function pptFixed(props, id, dflt){ const e = props.get(id); return e ? e.val / 65536 : dflt; }

// Walk a drawing (OfficeArtDgContainer) → flat, slide-absolute shape list
// plus the background shape. Group children are mapped from the group's
// own coordinate space (FSPGR) onto its anchor.
function pptShapes(R, dgRec, defaults){
  const shapes = [];
  let background = null;
  const MU = 1 / 6; // master units (576 dpi) → px (96 dpi)
  const readSp = (sp, map) => {
    const own = new Map();
    own.__own = true;
    const s = { type: 0, flags: 0, own, anchor: null, child: null, spgr: null, textbox: null, clientData: null, grpIds: [] };
    for (const k of pptKids(R, sp)){
      switch (k.type){
        case 0xF00A: s.type = k.inst; s.spid = R.dv.getUint32(k.body, true); s.flags = R.dv.getUint32(k.body + 4, true); break;
        case 0xF00B: case 0xF122: pptFopt(R, k, s.own); break;
        case 0xF009: s.spgr = [0, 4, 8, 12].map(d => R.dv.getInt32(k.body + d, true)); break;
        case 0xF00F: s.child = [0, 4, 8, 12].map(d => R.dv.getInt32(k.body + d, true)); break; // left, top, right, bottom
        case 0xF010:
          // [top, left, right, bottom]: RectStruct (int32) or SmallRectStruct (int16)
          if (k.len >= 16) s.anchor = [0, 4, 8, 12].map(d => R.dv.getInt32(k.body + d, true));
          else if (k.len >= 8) s.anchor = [0, 2, 4, 6].map(d => R.dv.getInt16(k.body + d, true));
          break;
        case 0xF011: s.clientData = k; break;
        case 0xF00D: s.textbox = k; break;
      }
    }
    // [left, top, right, bottom] in slide master units
    let rect = s.anchor ? [s.anchor[1], s.anchor[0], s.anchor[2], s.anchor[3]] : null;
    if (s.child && map) rect = map(s.child);
    s.rect = rect;
    if (rect) s.frame = { x: rnd(rect[0] * MU), y: rnd(rect[1] * MU), w: rnd((rect[2] - rect[0]) * MU), h: rnd((rect[3] - rect[1]) * MU) };
    return s;
  };
  const walkGroup = (grp, map, grpIds) => {
    const kids = pptKids(R, grp);
    let own = null, inner = map;
    kids.forEach((k, i) => {
      if (i === 0 && k.type === 0xF004){
        own = readSp(k, map);
        if (own.spgr && own.rect && !(own.flags & 0x4)){ // a real (non-patriarch) group: child space → its anchor
          const [gl, gt, gr, gb] = own.spgr, [al, at, ar, ab] = own.rect;
          const sx = gr !== gl ? (ar - al) / (gr - gl) : 1, sy = gb !== gt ? (ab - at) / (gb - gt) : 1;
          inner = c => [al + (c[0] - gl) * sx, at + (c[1] - gt) * sy, al + (c[2] - gl) * sx, at + (c[3] - gt) * sy];
        }
        return;
      }
      const ids = own && own.spid && !(own.flags & 0x4) ? [...grpIds, own.spid] : grpIds;
      if (k.type === 0xF003) walkGroup(k, inner, ids);
      else if (k.type === 0xF004){
        const s = readSp(k, inner);
        s.grpIds = ids;
        if (!(s.flags & 0x8)) shapes.push(s); // fDeleted
      }
    });
  };
  for (const k of pptKids(R, dgRec)){
    if (k.type === 0xF003) walkGroup(k, null, []);
    else if (k.type === 0xF004){ const s = readSp(k, null); if (s.flags & 0x400) background = s; }
  }
  return { shapes, background };
}

// ---- pictures: BStore entry → bento asset key ----
function pptBlipBytes(R, off){
  const r = pptRec(R, off);
  if (!r) return null;
  const uids = 1 + (r.inst & 1);
  let mime = null;
  switch (r.type){
    case 0xF01D: case 0xF02A: mime = 'image/jpeg'; break;
    case 0xF01E: mime = 'image/png'; break;
    case 0xF01F: mime = 'image/bmp'; break;
    case 0xF029: mime = 'image/tiff'; break;
    default: return { unsupported: true }; // EMF / WMF / PICT
  }
  let data = R.u8.subarray(r.body + 16 * uids + 1, r.body + r.len);
  if (mime === 'image/bmp' && data.length > 40){
    // a DIB lacks the 14-byte BITMAPFILEHEADER browsers need
    const ddv = new DataView(data.buffer, data.byteOffset, data.byteLength);
    const hdr = ddv.getUint32(0, true), bits = ddv.getUint16(14, true), used = ddv.getUint32(32, true);
    const pal = bits <= 8 ? (used || (1 << bits)) * 4 : (ddv.getUint32(16, true) === 3 ? 12 : 0);
    const out = new Uint8Array(14 + data.length);
    const odv = new DataView(out.buffer);
    out[0] = 0x42; out[1] = 0x4D;
    odv.setUint32(2, out.length, true);
    odv.setUint32(10, 14 + hdr + pal, true);
    out.set(data, 14);
    data = out;
  }
  return { mime, data };
}
function bytesToBase64(u8){
  let s = '';
  for (let i = 0; i < u8.length; i += 0x8000) s += String.fromCharCode.apply(null, u8.subarray(i, i + 0x8000));
  return btoa(s);
}

// Freeform geometry of a legacy shape: pVertices (0x145) + pSegmentInfo
// (0x146) in the geoLeft/Top/Right/Bottom space (0x140–0x143, default
// 21600²) → an svg path. Guide-computed points and arc escapes are not
// evaluated — null, and the caller keeps a plain box.
function pptCustomPath(R, own){
  const arr = id => {
    const e = own.get(id);
    if (!e || !e.data || e.data.len < 6) return null;
    const o = e.data.off, n = R.dv.getUint16(o, true), cb = R.dv.getUint16(o + 4, true);
    return { o: o + 6, n, cb, end: e.data.off + e.data.len };
  };
  const V = arr(0x0145);
  if (!V || !V.n) return null;
  const pts = [];
  const size = V.cb === 0xFFF0 ? 4 : V.cb;
  if (size !== 4 && size !== 8) return null;
  for (let i = 0; i < V.n; i++){
    const o = V.o + i * size;
    if (o + size > V.end + 6) return null;
    if (size === 4){
      const x = R.dv.getUint16(o, true), y = R.dv.getUint16(o + 2, true);
      if (V.cb === 0xFFF0 && ((x & 0xFFF0) === 0x8000 || (y & 0xFFF0) === 0x8000)) return null; // guide reference
      pts.push([R.dv.getInt16(o, true), R.dv.getInt16(o + 2, true)]);
    } else pts.push([R.dv.getInt32(o, true), R.dv.getInt32(o + 4, true)]);
  }
  const gv = (id, d) => own.has(id) ? own.get(id).val : d;
  const gl = gv(0x0140, 0), gt = gv(0x0141, 0), gr = gv(0x0142, 21600), gb = gv(0x0143, 21600);
  if (gr === gl || gb === gt) return null;
  const f = n => Math.round(n * 100) / 100;
  const P = ([x, y]) => f((x - gl) * PATH_BOX / (gr - gl)) + ' ' + f((y - gt) * PATH_BOX / (gb - gt));
  let d = '', k = 0, filled = true;
  const S = arr(0x0146);
  if (!S){ d = 'M' + P(pts[0]) + pts.slice(1).map(q => 'L' + P(q)).join(''); }
  else for (let i = 0; i < S.n; i++){
    const w = R.dv.getUint16(S.o + i * 2, true), type = w >>> 13;
    const count = type === 5 ? (w & 0xFF) : (w & 0x1FFF);
    if (type === 0){ for (let c = 0; c < Math.max(1, count); c++){ if (!pts[k]) return null; d += 'L' + P(pts[k++]); } }
    else if (type === 1){ for (let c = 0; c < Math.max(1, count); c++){ if (!pts[k + 2]) return null; d += 'C' + P(pts[k]) + ' ' + P(pts[k + 1]) + ' ' + P(pts[k + 2]); k += 3; } }
    else if (type === 2){ if (!pts[k]) return null; d += 'M' + P(pts[k++]); }
    else if (type === 3) d += 'Z';
    else if (type === 4) continue;
    else if (type === 5){
      const esc = (w >>> 8) & 0x1F;
      if (esc === 10) filled = false;
      else if (esc === 9){ for (let c = 0; c < count; c++){ if (!pts[k + 1]) return null; d += 'Q' + P(pts[k]) + ' ' + P(pts[k + 1]); k += 2; } }
      else if (esc === 7 || esc === 8){ for (let c = 0; c < count; c++){ if (!pts[k]) return null; d += 'L' + P(pts[k++]); } }
      else if (esc >= 11) continue;
      else return null; // arcs and other escapes
    }
    else return null;
  }
  return d ? { d, box: [0, 0, PATH_BOX, PATH_BOX], filled } : null;
}

// PowerPoint's MSOSPT shape types → the DrawingML preset names the PPTX
// path already maps (GEOM_MAP / LINE_GEOMS / ARROW_ORIENTATION).
const SPT_PRST = {
  1: 'rect', 2: 'roundRect', 3: 'ellipse', 4: 'diamond', 5: 'triangle', 9: 'hexagon', 10: 'octagon',
  13: 'rightArrow', 15: 'homePlate', 20: 'line', 32: 'straightConnector1', 33: 'bentConnector2',
  34: 'bentConnector3', 35: 'bentConnector4', 36: 'bentConnector5', 37: 'curvedConnector2',
  38: 'curvedConnector3', 39: 'curvedConnector4', 40: 'curvedConnector5', 55: 'chevron', 56: 'pentagon',
  66: 'leftArrow', 67: 'downArrow', 68: 'upArrow', 109: 'flowChartProcess', 110: 'flowChartDecision',
  116: 'flowChartAlternateProcess', 120: 'flowChartConnector',
};
// Text types (TextHeaderAtom) and which master style they fall back to.
const PPT_TEXT_BASE = { 5: 1, 6: 0, 7: 1, 8: 1 };

// PowerPoint 2002+ keeps its animation timeline as a binary copy of the
// PPTX p:timing tree inside the slide's "___PPT10" programmable tag:
// ExtTimeNodeContainer (0xF144) nodes whose TimeVariant properties carry the
// same presetID (0x09), presetSubtype (0x0A), presetClass (0x0B, 1 = entr)
// and node type (0x14: 1 click, 2 with, 3 after previous). Files written by
// other programs (LibreOffice …) only have this form, not the 97-style
// AnimationInfo — so it's read first. Targets: VisualShapeAtom (0x2AFB),
// a shape id, or a character range of its text.
function pptTiming10(R, slideRec){
  const tags = pptKid(R, slideRec, 0x1388);
  let root = null;
  for (const c of pptKids(R, tags)){
    if (c.type !== 0x138A) continue;
    const name = pptKid(R, c, 0x0FBA);
    if (!name || pptUtf16(R, name.body, name.len) !== '___PPT10') continue;
    const blob = pptKid(R, c, 0x138B);
    root = blob ? pptKid(R, blob, 0xF144) : null;
  }
  if (!root) return null;
  const res = { shapes: new Map(), ranges: new Map(), dropped: new Set() };
  const propsOf = node => {
    const out = {};
    for (const v of pptKids(R, pptKid(R, node, 0xF13D))){
      if (v.type !== 0xF142) continue;
      const t = R.u8[v.body];
      out[v.inst] = t === 1 ? R.dv.getInt32(v.body + 1, true) : t === 0 ? R.u8[v.body + 1] : t === 2 ? R.dv.getFloat32(v.body + 1, true) : null;
    }
    return out;
  };
  let step = 0, order = 0, inMain = false;
  const walk = (node, depth) => {
    if (depth > 40) return;
    const p = propsOf(node);
    if (p[0x14] === 4) inMain = true;
    if (inMain && p[0x0B] != null && [1, 2, 3].includes(p[0x14])){
      if (p[0x14] === 1){ step++; order = 0; } else if (p[0x14] === 3) order++;
      if (p[0x0B] !== 1){ res.dropped.add(p[0x0B]); return; }
      let target = null, dur = 0;
      const scan = (r, d) => {
        for (const k of pptKids(R, r)){
          if (k.type === 0xF127 && k.len >= 28){ const du = R.dv.getInt32(k.body + 24, true); if (du > dur) dur = du; }
          if (k.type === 0x2AFB && !target) target = { type: R.dv.getUint32(k.body, true), id: R.dv.getUint32(k.body + 8, true), start: R.dv.getInt32(k.body + 12, true) };
          if (k.ver === 0xF && d < 30) scan(k, d + 1);
        }
      };
      scan(node, 0);
      if (!target) return;
      const fx = { step, order, enter: enterKindFor(p[0x09] || 0, p[0x0A] || 0), dur };
      if (target.type === 2){
        let m = res.ranges.get(target.id);
        if (!m){ m = new Map(); res.ranges.set(target.id, m); }
        if (!m.has(target.start)) m.set(target.start, fx);
      } else if (!res.shapes.has(target.id)) res.shapes.set(target.id, fx);
      return;
    }
    for (const k of pptKids(R, node)) if (k.type === 0xF144) walk(k, depth + 1);
  };
  walk(root, 0);
  return res;
}

async function convertPpt(file){
  const streams = cfbStreams(await file.arrayBuffer());
  if (streams.has('EncryptedSummary')) throw new Error('Passwortgeschützte .ppt-Dateien können nicht gelesen werden — bitte ohne Kennwort speichern.');
  const docStream = streams.get('PowerPoint Document');
  if (!docStream) throw new Error('Keine PowerPoint-Präsentation (Stream „PowerPoint Document“ fehlt).');
  const R = pptReader(docStream);
  const P = streams.get('Pictures') ? pptReader(streams.get('Pictures')) : null;
  const { persist, docRef } = pptPersist(R, streams.get('Current User'));
  const at = id => pptRec(R, persist.get(id));
  const docRec = at(docRef);
  if (!docRec || docRec.type !== 0x03E8) throw new Error('Beschädigte .ppt-Datei (Dokument nicht gefunden).');
  const warnings = new Set();
  const warn = m => warnings.add(m);

  // document-wide parts
  let slideW = 960, slideH = 720;
  const fonts = [];
  const envStyles = {};
  const lists = { 0: [], 1: [], 2: [] };
  let bstore = [], defaults = new Map();
  for (const k of pptKids(R, docRec)){
    if (k.type === 0x03E9){ slideW = rnd(R.dv.getInt32(k.body, true) / 6); slideH = rnd(R.dv.getInt32(k.body + 4, true) / 6); }
    else if (k.type === 0x03F2){
      const fc = pptKid(R, k, 0x07D5);
      for (const f of pptKids(R, fc)) if (f.type === 0x0FB7) fonts[f.inst] = pptUtf16(R, f.body, 64);
      for (const t of pptKids(R, k)) if (t.type === 0x0FA3) envStyles[t.inst] = pptMasterStyle(R, t);
    } else if (k.type === 0x040B){
      const dgg = pptKid(R, k, 0xF000);
      const bs = pptKid(R, dgg, 0xF001);
      bstore = pptKids(R, bs).filter(r => r.type === 0xF007);
      const opt = pptKid(R, dgg, 0xF00B);
      if (opt) pptFopt(R, opt, defaults);
    } else if (k.type === 0x0FF0 && lists[k.inst]){
      // slide list: a SlidePersistAtom, then the texts of that slide's
      // placeholders (older files keep placeholder text here, not in the shape)
      let cur = null;
      for (const r of pptKids(R, k)){
        if (r.type === 0x03F3){ cur = { persistId: R.dv.getUint32(r.body, true), slideId: R.dv.getUint32(r.body + 12, true), texts: [] }; lists[k.inst].push(cur); }
        else if (cur && r.type === 0x0F9F) cur.texts.push({ type: R.dv.getUint32(r.body, true), recs: [] });
        else if (cur && cur.texts.length) cur.texts[cur.texts.length - 1].recs.push(r);
      }
    }
  }
  const bySlideId = inst => new Map(lists[inst].map(e => [e.slideId, e]));
  const masters = bySlideId(1), notesById = bySlideId(2);

  const assets = {};
  let assetN = 1;
  const pibKey = new Map();
  const blipAsset = pib => {
    if (!pib || pib < 1 || pib > bstore.length) return null;
    if (pibKey.has(pib)) return pibKey.get(pib);
    const fbse = bstore[pib - 1];
    const cbName = R.u8[fbse.body + 33];
    const foDelay = R.dv.getUint32(fbse.body + 28, true);
    let blip = null;
    if (fbse.len > 36 + cbName) blip = pptBlipBytes(R, fbse.body + 36 + cbName);
    else if (P) blip = pptBlipBytes(P, foDelay);
    let key = null;
    if (blip && blip.data){ key = 'img' + (assetN++); assets[key] = 'data:' + blip.mime + ';base64,' + bytesToBase64(blip.data); }
    else warn('Ein Bild in einem nicht unterstützten Format (z.B. WMF/EMF) wurde übersprungen.');
    pibKey.set(pib, key);
    return key;
  };
  const keyFirstId = new Map();

  // a slide/master container → the parts the converter needs
  const partOf = rec => {
    const out = { rec, atom: null, drawing: null, scheme: null, styles: {}, transition: null };
    for (const k of pptKids(R, rec)){
      if (k.type === 0x03EF) out.atom = {
        masterId: R.dv.getUint32(k.body + 12, true), notesId: R.dv.getUint32(k.body + 16, true),
        flags: R.dv.getUint16(k.body + 20, true),
      };
      else if (k.type === 0x040C) out.drawing = pptKid(R, k, 0xF002);
      else if (k.type === 0x07F0 && (k.inst === 1 || !out.scheme)){
        out.scheme = [];
        for (let i = 0; i < 8; i++) out.scheme.push(rgbToHex(R.u8[k.body + i * 4], R.u8[k.body + i * 4 + 1], R.u8[k.body + i * 4 + 2]));
      }
      else if (k.type === 0x0FA3) out.styles[k.inst] = pptMasterStyle(R, k);
      else if (k.type === 0x03F9) out.transition = {
        effectDir: R.u8[k.body + 8], effect: R.u8[k.body + 9], flags: R.dv.getUint16(k.body + 10, true),
      };
    }
    return out;
  };
  const masterCache = new Map();
  const masterPart = id => {
    if (!masterCache.has(id)){
      const e = masters.get(id);
      const rec = e ? at(e.persistId) : null;
      const part = rec ? partOf(rec) : null;
      if (part){
        part.entry = e;
        // a title master is itself based on the main master
        if (rec.type !== 0x03F8 && part.atom && part.atom.masterId && part.atom.masterId !== id) part.parent = masterPart(part.atom.masterId);
      }
      masterCache.set(id, part);
    }
    return masterCache.get(id);
  };
  const DEFAULT_SCHEME = ['#FFFFFF', '#000000', '#808080', '#000000', '#BBE0E3', '#333399', '#009999', '#99CC00'];

  // text of one shape: its own records, or the slide list's entry it points at
  const textOf = (sp, entry) => {
    if (!sp.textbox) return null;
    const kids = pptKids(R, sp.textbox);
    let recs = kids, type = 4;
    const hdr = kids.find(r => r.type === 0x0F9F);
    if (hdr) type = R.dv.getUint32(hdr.body, true);
    const ref = kids.find(r => r.type === 0x0F9E);
    if (ref && entry){
      const t = entry.texts[R.dv.getInt32(ref.body, true)];
      if (!t) return null;
      recs = t.recs; type = t.type;
    }
    let text = null;
    const chars = recs.find(r => r.type === 0x0FA0), bytes = recs.find(r => r.type === 0x0FA8);
    if (chars){ text = ''; for (let i = 0; i + 1 < chars.len; i += 2) text += String.fromCharCode(R.dv.getUint16(chars.body + i, true)); }
    else if (bytes){ text = ''; for (let i = 0; i < bytes.len; i++) text += String.fromCharCode(R.u8[bytes.body + i]); }
    if (text == null) return null;
    const style = recs.find(r => r.type === 0x0FA1);
    const fields = new Map();
    for (const r of recs){
      if (r.type === 0x0FD8) fields.set(R.dv.getInt32(r.body, true), '{{page}}');
      else if (r.type === 0x0FF7 || r.type === 0x0FF8) fields.set(R.dv.getInt32(r.body, true), '{{date}}');
    }
    return { text, type, runs: style ? pptStyleRuns(R, style, text.length) : { pf: [], cf: [] }, fields };
  };

  // one text body → the {paras, html, style} shape assembleTextInfo builds
  const textInfo = (t, master, scheme) => {
    const styleFor = (type, lvl) => {
      const pick = src => { const s = src && (src[type] || (PPT_TEXT_BASE[type] != null ? src[PPT_TEXT_BASE[type]] : null)); return s && (s[lvl] || s[0]); };
      const chain = [];
      // a master level stores only what differs from the level below it
      const levels = st => { const out = []; if (st) for (let k = Math.min(lvl, st.length - 1); k >= 0; k--) out.push(st[k]); return out; };
      for (let m = master; m; m = m.parent){
        chain.push(...levels(m.styles[type]));
        if (PPT_TEXT_BASE[type] != null) chain.push(...levels(m.styles[PPT_TEXT_BASE[type]]));
      }
      const env = pick(envStyles) || (envStyles[4] && envStyles[4][0]);
      if (env) chain.push(env);
      return chain.filter(Boolean);
    };
    const firstOf = (list, get) => { for (const x of list){ const v = get(x); if (v != null) return v; } return null; };
    const len = t.text.length;
    const pfAt = [], cfAt = [];
    let i = 0;
    for (const r of t.runs.pf){ for (let k = 0; k < r.count && i <= len; k++) pfAt[i++] = r; }
    i = 0;
    for (const r of t.runs.cf){ for (let k = 0; k < r.count && i <= len; k++) cfAt[i++] = r.props; }
    const out = [], allRuns = [];
    let algnFirst = null, lnFirst = null;
    let start = 0;
    const counters = [];
    const paraTexts = t.text.split('\r');
    for (const ptext of paraTexts){
      const pr = pfAt[start] || { indent: 0, props: {} };
      const lvl = Math.min(4, pr.indent || 0);
      const ms = styleFor(t.type, lvl);
      const pfp = [pr.props, ...ms.map(m => m.pf)];
      const align = firstOf(pfp, p => p.align);
      const runs = [];
      let cur = null;
      for (let k = 0; k < ptext.length; k++){
        const ci = start + k;
        const ch = ptext[k];
        if (ch === '\x0B'){ runs.push({ br: true }); cur = null; continue; }
        const cfp = [cfAt[ci] || {}, ...ms.map(m => m.cf)];
        const sz = firstOf(cfp, c => c.size) || 18;
        const col = pptTextColor(firstOf(cfp, c => c.color), scheme) || scheme[1] || '#000000';
        const fi = firstOf(cfp, c => c.font);
        const run = {
          size: Math.max(1, ptToPx(sz)), color: col, font: fonts[fi || 0] || null,
          bold: !!firstOf(cfp, c => c.bold), italic: !!firstOf(cfp, c => c.italic), underline: !!firstOf(cfp, c => c.underline), strike: false,
        };
        const piece = t.fields.has(ci) ? t.fields.get(ci) : (ch === '\t' ? '\u00A0\u00A0\u00A0\u00A0' : symbolChar(ch, run.font));
        const same = cur && ['size', 'color', 'font', 'bold', 'italic', 'underline'].every(p => cur[p] === run[p]);
        if (same) cur.text += piece;
        else { cur = Object.assign(run, { text: piece }); runs.push(cur); allRuns.push(cur); }
      }
      const plain = runs.map(r => r.br ? '\n' : r.text).join('');
      let bullet = '';
      if (plain.trim()){
        if (algnFirst === null) algnFirst = ['l', 'ctr', 'r', 'just'][align || 0] || 'l';
        const ls = firstOf(pfp, p => p.lineSpacing);
        if (lnFirst === null && ls > 0) lnFirst = ls / 100;
        counters.length = lvl + 1;
        counters[lvl] = 0;
        if (firstOf(pfp, p => p.hasBullet)){
          const code = firstOf(pfp, p => p.bulletChar);
          const bf = firstOf(pfp, p => p.bulletHasFont ? p.bulletFont : null);
          // map symbol-font bullets to real characters here; bulletText gets no font
          bullet = bulletText({ kind: 'char', char: code ? symbolChar(String.fromCharCode(code), bf != null ? fonts[bf] : '') : '•' }, 0);
        }
      }
      out.push({ runs, plain, bullet, lvl });
      start += ptext.length + 1;
    }
    if (!allRuns.some(r => r.text.trim())) return null;
    return assembleTextInfo(out, allRuns, algnFirst, lnFirst);
  };

  // one shape → bento elements
  const convertShape = (sp, ctx, elId) => {
    const out = [];
    if (!sp.frame) return out;
    const scheme = ctx.scheme;
    const ph = (() => { const pa = ctx.clientKids(sp).find(r => r.type === 0x0BC3); return pa ? R.u8[pa.body + 4] : 0; })();
    // Property lookup chain. A placeholder takes what it doesn't set itself
    // from the master's placeholder (whose defaults are "no fill, no line");
    // any other shape from the drawing defaults. Boolean sets are resolved
    // PER BIT — a shape that only sets two unrelated bits of the fill set
    // still inherits "filled" from further up.
    const mph = ph ? ctx.masterPh(ph) : null;
    const chain = ph ? [sp.own, mph ? mph.own : null].filter(Boolean) : [sp.own, defaults];
    const pv = (id, d) => { for (const m of chain) if (m.has(id)) return m.get(id).val; return d; };
    const pfix = (id, d) => { const v = pv(id, null); return v == null ? d : v / 65536; };
    const pbool = (id, bit, d) => pptBoolIn(chain, id, bit, d);
    const rot = pfix(0x0004, 0);
    let frame = Object.assign({ rotation: Math.round(rot * 10) / 10, flipH: !!(sp.flags & 0x40), flipV: !!(sp.flags & 0x80) }, sp.frame);
    // shapes turned 45–135° / 225–315° store their bounds pre-rotated
    const nr = ((rot % 360) + 360) % 360;
    if ((nr >= 45 && nr < 135) || (nr >= 225 && nr < 315)){
      const cx = frame.x + frame.w / 2, cy = frame.y + frame.h / 2;
      frame = Object.assign({}, frame, { x: rnd(cx - frame.h / 2), y: rnd(cy - frame.w / 2), w: frame.h, h: frame.w });
    }
    if (pbool(0x03BF, 0x2, false)) return out; // hidden
    const anim = ctx.animOf(sp);
    const fx = anim ? bentoFx(anim) : null;
    const withFx = el => { if (el && fx) el.fx = Object.assign({}, fx); return el; };
    const prst = SPT_PRST[sp.type] || 'rect';

    // pictures (picture frames, OLE previews) and picture fills
    const pib = sp.own.has(0x0104) ? sp.own.get(0x0104).val : 0;
    const fillType = pv(0x0180, 0);
    const blipPib = pib || ((fillType === 2 || fillType === 3) ? pv(0x0186, 0) : 0);
    if (blipPib){
      const key = blipAsset(blipPib);
      if (key){
        // a picture FILL may still get an outline shape with elId below
        const el = { id: pib ? elId : elId + 'i', type: 'image', x: frame.x, y: frame.y, w: frame.w, h: frame.h, rotation: frame.rotation, opacity: 1, src: 'asset:' + key, fit: 'cover', radius: 0 };
        if (pib){
          const [t, b, l, r] = [0x0100, 0x0101, 0x0102, 0x0103].map(id => pfix(id, 0));
          if (t >= 0 && b >= 0 && l >= 0 && r >= 0 && (t || b || l || r) && l + r < 1 && t + b < 1)
            el.crop = { x: Math.round(l * 10000) / 10000, y: Math.round(t * 10000) / 10000, w: Math.round((1 - l - r) * 10000) / 10000, h: Math.round((1 - t - b) * 10000) / 10000 };
          const d = sp.own.get(0x0381);
          const alt = d && d.data ? pptUtf16(R, d.data.off, d.data.len).trim() : '';
          if (alt) el.alt = alt.replace(/\s+/g, ' ');
        }
        const first = keyFirstId.get(key);
        if (!first) keyFirstId.set(key, el.id); else if (first !== el.id) el.morphId = first;
        out.push(withFx(el));
      } else if (sp.flags & 0x10){
        ctx.warn('Ein eingebettetes Objekt (OLE, z.B. ein MS-Graph-Diagramm) hatte nur ein WMF/EMF-Vorschaubild — als Platzhalter markiert.');
        out.push({ id: elId, type: 'text', x: frame.x, y: frame.y, w: frame.w, h: frame.h, rotation: 0, opacity: 1,
          html: '[Objekt aus PowerPoint — manuell nachbauen]', fontSize: Math.max(10, Math.min(20, rnd(frame.h / 5), rnd(frame.w / 12))),
          fontFamily: 'system-ui, sans-serif', fontWeight: 500, color: '#999999', align: 'left', valign: 'top', lineHeight: 1.3 });
      }
      if (pib) return out;
    }

    const lineOn = pbool(0x01FF, 0x8, !ph);
    const lineColor = lineOn ? pptColorRef(pv(0x01C0, 0), scheme) : null;
    const dashV = pv(0x01CE, 0);
    const arrow = id => { const v = pv(id, 0); return !v ? null : (v === 4 ? 'dot' : 'arrow'); };
    const line = lineColor ? {
      color: lineColor,
      width: Math.max(1, Math.round(pv(0x01CB, 9525) / EMU_PER_PX * 10) / 10),
      dash: !dashV ? null : (dashV === 2 || dashV === 5 ? 'dotted' : 'dashed'),
      head: arrow(0x01D0), tail: arrow(0x01D1),
    } : null;
    if (LINE_GEOMS.test(prst) || (sp.flags & 0x100)){
      if (line) out.push(withFx(lineElement(frame, line, elId)));
      return out.filter(Boolean);
    }

    const custom = sp.type === 0 ? pptCustomPath(R, sp.own) : null;
    const filled = pbool(0x01BF, 0x10, !ph && sp.type !== 202 && sp.type !== 0) && (!custom || custom.filled);
    let fill = null;
    if (filled && !blipPib){
      const c1 = pptColorRef(pv(0x0181, 0xFFFFFF), scheme) || '#FFFFFF';
      const op = pfix(0x0182, 1);
      const withA = (hex, a) => a >= 0.995 ? hex : clrCss({ rgb: hexToRgb(hex), a });
      fill = { color: withA(c1, op) };
      if (fillType >= 4 && fillType <= 8){
        const c2 = pptColorRef(pv(0x0183, 0xFFFFFF), scheme) || '#FFFFFF';
        const focus = pv(0x018C, 0);
        const a = pfix(0x018B, 0);
        const angle = Math.round((((180 - a) % 360) + 360) % 360);
        const stops = focus === 50 || focus === -50
          ? [{ at: 0, color: withA(c1, op) }, { at: 0.5, color: withA(c2, pfix(0x0184, 1)) }, { at: 1, color: withA(c1, op) }]
          : focus === 100 || focus === -100
            ? [{ at: 0, color: withA(c2, pfix(0x0184, 1)) }, { at: 1, color: withA(c1, op) }]
            : [{ at: 0, color: withA(c1, op) }, { at: 1, color: withA(c2, pfix(0x0184, 1)) }];
        fill.grad = { angle, stops };
      }
    }
    const t = textOf(sp, ctx.entry);
    const info = t ? textInfo(t, ctx.master, scheme) : null;
    // an empty placeholder paints nothing (its fill/outline come from the master's prompt)
    if (ph && !info) return out;
    if (fill || line){
      const orient = ARROW_ORIENTATION[prst];
      const shapeW = (orient && orient.swapWH) ? frame.h : frame.w;
      const shapeH = (orient && orient.swapWH) ? frame.w : frame.h;
      const el = {
        id: elId, type: 'shape', shape: GEOM_MAP[prst] || 'rect',
        x: (orient && orient.swapWH) ? rnd(frame.x + (frame.w - shapeW) / 2) : frame.x,
        y: (orient && orient.swapWH) ? rnd(frame.y + (frame.h - shapeH) / 2) : frame.y,
        w: shapeW, h: shapeH,
        rotation: frame.rotation + (orient ? orient.extraRotation : 0), opacity: 1,
        fill: fill ? fill.color : 'transparent',
        stroke: line ? line.color : 'none', strokeWidth: line ? line.width : 0, radius: 0,
      };
      if (fill && fill.grad) el.fillGradient = fill.grad;
      if (line && line.dash) el.strokeStyle = line.dash;
      if (el.shape === 'polygon') el.sides = POLYGON_SIDES[prst] || 6;
      if (custom){ el.shape = 'path'; el.d = custom.d; el.pathBox = custom.box; }
      if (prst === 'roundRect' || prst === 'flowChartAlternateProcess'){
        const adj = pv(0x0147, 3600);
        el.radius = rnd(Math.min(frame.w, frame.h) * Math.max(0, Math.min(0.5, adj / 21600)));
      }
      out.push(withFx(el));
    }
    if (info){
      // text anchor + insets (a placeholder's come from the master's)
      const ins = id => pv(id, id === 0x0081 || id === 0x0083 ? 91440 : 45720) / EMU_PER_PX;
      const [l, tp, r, b] = [ins(0x0081), ins(0x0082), ins(0x0083), ins(0x0084)];
      const anchor = pv(0x0087, 0);
      const valign = [0, 3, 6, 8].includes(anchor) ? 'top' : [1, 4, 7, 9].includes(anchor) ? 'middle' : 'bottom';
      const box = { x: frame.x + l, y: frame.y + tp, w: Math.max(8, frame.w - l - r), h: Math.max(8, frame.h - tp - b), rotation: frame.rotation };
      const textId = (fill || line) ? elId + 't' : elId;
      if (anim && anim.byPara && info.paras.length > 1) out.push(...paragraphElements(textId, box, info, valign, anim.byPara, fx));
      else out.push(withFx(textElement(textId, box, info, valign)));
    }
    return out;
  };

  // background: the drawing's background shape (own, or the master's)
  const backgroundOf = (bgSp, part, scheme) => {
    if (!bgSp) return { color: scheme[0] || '#FFFFFF' };
    // only the background shape's OWN properties: the drawing defaults are
    // for new shapes, and the format default fill is white
    const props = bgSp.own;
    if (!pptBoolIn([props], 0x01BF, 0x10, true)) return { color: '#FFFFFF' };
    const ft = props.has(0x0180) ? props.get(0x0180).val : 0;
    const c1 = pptColorRef(props.has(0x0181) ? props.get(0x0181).val : 0xFFFFFF, scheme) || '#FFFFFF';
    if ((ft === 2 || ft === 3) && props.get(0x0186)){
      const key = blipAsset(props.get(0x0186).val);
      if (key) return { color: c1, image: key };
    }
    if (ft >= 4 && ft <= 8){
      const c2 = pptColorRef(props.has(0x0183) ? props.get(0x0183).val : 0xFFFFFF, scheme) || '#FFFFFF';
      const a = pptFixed(props, 0x018B, 0);
      const focus = props.has(0x018C) ? props.get(0x018C).val : 0;
      const [s0, s1] = focus === 100 || focus === -100 ? [c2, c1] : [c1, c2];
      const stops = focus === 50 || focus === -50 ? [{ at: 0, color: c1 }, { at: 0.5, color: c2 }, { at: 1, color: c1 }] : [{ at: 0, color: s0 }, { at: 1, color: s1 }];
      return { color: stops[0].color, grad: { angle: Math.round((((180 - a) % 360) + 360) % 360), stops } };
    }
    return { color: c1 };
  };

  // old-style (97-compatible) animation info, kept by every PowerPoint version
  const animOfFactory = () => {
    const list = [];
    return {
      collect: (sp, clientKids) => {
        const ac = clientKids(sp).find(r => r.type === 0x1014);
        const a = ac ? pptKid(R, ac, 0x0FF1) : null;
        if (!a || a.len < 24) return;
        list.push({ sp, flags: R.dv.getUint16(a.body + 4, true), order: R.dv.getUint16(a.body + 14, true),
          build: R.u8[a.body + 18], effect: R.u8[a.body + 19], dir: R.u8[a.body + 20] });
      },
      resolve: () => {
        const map = new Map();
        let step = 0, order = 0;
        list.sort((x, y) => x.order - y.order);
        for (const a of list){
          if (a.flags & 0x4){ order++; } else { step++; order = 0; }
          const enter = a.effect === 0 ? null : a.effect === 12
            ? ({ 0: 'slide-right', 1: 'slide-down', 2: 'slide-left', 3: 'slide-up' }[a.dir] || 'slide-up')
            : 'fade';
          const fx = { step, order, enter, dur: 0 };
          if (a.build >= 1){
            // text build by paragraph: each further paragraph is one more click
            const byPara = new Map();
            const t = textOf(a.sp, null);
            const n = t ? t.text.split('\r').length : 1;
            for (let k = 0; k < n; k++){
              if (k > 0 && !(a.flags & 0x4)) step++;
              byPara.set(k, { step, order, enter, dur: 0 });
            }
            fx.byPara = byPara;
          }
          map.set(a.sp, fx);
        }
        return map;
      },
    };
  };

  const slides = [];
  const clientKids = sp => sp.clientData ? pptKids(R, sp.clientData) : [];
  // a slide placeholder's counterpart on the master (title ↔ master title, …)
  const PH_MASTER = { 13: [1], 15: [3, 1], 14: [2], 18: [2], 19: [2], 16: [4, 2] };
  const masterShapes = m => { if (!m.shapesCache) m.shapesCache = m.drawing ? pptShapes(R, m.drawing, defaults).shapes : []; return m.shapesCache; };
  const masterPhOf = (master, type) => {
    for (const want of PH_MASTER[type] || []){
      for (let m = master; m; m = m.parent){
        const hit = masterShapes(m).find(sp => { const pa = clientKids(sp).find(r => r.type === 0x0BC3); return pa && R.u8[pa.body + 4] === want; });
        if (hit) return hit;
      }
    }
    return null;
  };
  let docScheme = null, firstFont = fonts[0] || null;
  const masterElements = new Map();

  for (let i = 0; i < lists[0].length; i++){
    const entry = lists[0][i];
    const rec = at(entry.persistId);
    if (!rec || rec.type !== 0x03EE) continue;
    const part = partOf(rec);
    const master = part.atom ? masterPart(part.atom.masterId) : null;
    const mainMaster = master && master.parent ? master.parent : master;
    const flags = part.atom ? part.atom.flags : 0x7;
    const scheme = ((flags & 0x2) || !part.scheme ? (master && master.scheme) || (mainMaster && mainMaster.scheme) : part.scheme) || DEFAULT_SCHEME;
    if (!docScheme) docScheme = scheme;
    const ctx = { scheme, master, entry, warn, clientKids, animOf: () => null, masterPh: t => masterPhOf(master, t) };
    const elements = [];

    const own = part.drawing ? pptShapes(R, part.drawing, defaults) : { shapes: [], background: null };
    let bgSp = own.background, bgPart = part;
    if ((flags & 0x4) || !bgSp){
      for (const m of [master, mainMaster]){
        if (!m || !m.drawing) continue;
        const ms = pptShapes(R, m.drawing, defaults);
        if (ms.background){ bgSp = ms.background; bgPart = m; break; }
      }
    }
    const bg = backgroundOf(bgSp, bgPart, scheme);
    if (bg.image) elements.push({ id: 'bg_' + bg.image, type: 'image', x: 0, y: 0, w: slideW, h: slideH, rotation: 0, opacity: 1, src: 'asset:' + bg.image, fit: 'cover', radius: 0 });

    // master artwork (logos, bands) — same ids on every slide
    if (flags & 0x1){
      for (const m of [mainMaster, master !== mainMaster ? master : null]){
        if (!m || !m.drawing) continue;
        const mctx = Object.assign({}, ctx, { master: m, entry: null });
        const prefix = 'm' + m.entry.slideId + '_';
        let n = 1;
        for (const sp of pptShapes(R, m.drawing, defaults).shapes){
          if (clientKids(sp).some(r => r.type === 0x0BC3)) continue; // placeholder prompts
          elements.push(...convertShape(sp, mctx, prefix + 'e' + (n++)));
        }
      }
    }
    const t10 = pptTiming10(R, rec);
    let anims;
    if (t10 && (t10.shapes.size || t10.ranges.size || t10.dropped.size)){
      anims = new Map();
      for (const sp of own.shapes){
        const ids = [sp.spid, ...sp.grpIds.slice().reverse()];
        const hit = ids.map(id => t10.shapes.get(id)).find(Boolean);
        const ranges = t10.ranges.get(sp.spid);
        let byPara = null;
        if (ranges){
          // character offsets → paragraph indices of this shape's text
          const t = textOf(sp, entry);
          const starts = [];
          if (t) t.text.split('\r').reduce((o, para) => { starts.push(o); return o + para.length + 1; }, 0);
          byPara = new Map();
          for (const [off, fx] of ranges){
            let k = 0;
            while (k + 1 < starts.length && starts[k + 1] <= off) k++;
            if (!byPara.has(k)) byPara.set(k, fx);
          }
        }
        if (hit || byPara){
          const first = byPara ? Array.from(byPara.values()).sort((a, b) => a.step - b.step)[0] : null;
          anims.set(sp, Object.assign({}, hit || first, byPara ? { byPara } : {}));
        }
      }
      if ([...t10.dropped].length) warn('Nur Eingangsanimationen werden übernommen (als Klickschritte) — Hervorhebungs-, Ausgangs- und Pfadanimationen entfallen.');
    } else {
      const animCol = animOfFactory();
      own.shapes.forEach(sp => animCol.collect(sp, clientKids));
      anims = animCol.resolve();
    }
    ctx.animOf = sp => anims.get(sp) || null;
    let n = 1;
    for (const sp of own.shapes) elements.push(...convertShape(sp, ctx, 's' + (i + 1) + '_e' + (n++)));

    // speaker notes
    let notes = '';
    const ne = part.atom && part.atom.notesId ? notesById.get(part.atom.notesId) : null;
    const nrec = ne ? at(ne.persistId) : null;
    if (nrec){
      const npart = partOf(nrec);
      const texts = [];
      for (const sp of (npart.drawing ? pptShapes(R, npart.drawing, defaults).shapes : [])){
        const t = textOf(sp, ne);
        if (t && t.type === 2 && t.text.trim()) texts.push(t.text.replace(/\r/g, '\n').replace(/\x0B/g, '\n').trim());
      }
      notes = texts.join('\n');
    }

    const tr = part.transition;
    const EFFECTS_SLIDE = [2, 3, 4, 7, 9, 10, 13, 20, 21];
    const transition = !tr ? 'none'
      : tr.effect === 0 ? 'none'
      : [5, 6, 23].includes(tr.effect) ? 'fade'
      : EFFECTS_SLIDE.includes(tr.effect) ? 'slide'
      : [11, 22].includes(tr.effect) ? 'zoom' : 'fade';
    const slide = { id: 's' + (i + 1), background: bg.color, transition, elements, notes };
    if (bg.grad) slide.backgroundGradient = bg.grad;
    if (tr && (tr.flags & 0x4)) slide.hidden = true;
    slides.push(slide);
  }

  // Two elements with the same morph key on one slide break pairing.
  for (const s of slides){
    const seen = new Set();
    for (const e of s.elements){
      let k = e.morphId || e.id;
      if (seen.has(k) && e.morphId){ delete e.morphId; k = e.id; }
      seen.add(k);
    }
  }
  if (!slides.length) slides.push({
    id: 's1', background: '#FFFFFF', transition: 'none', notes: '',
    elements: [{ id: 't1', type: 'text', x: 96, y: rnd(slideH / 2 - 80), w: slideW - 192, h: 160, rotation: 0, opacity: 1,
      html: '(Keine Folien gefunden)', fontSize: 48, fontFamily: 'system-ui, sans-serif', fontWeight: 700,
      color: '#111111', align: 'left', valign: 'top', lineHeight: 1.1 }],
  });

  const sc = docScheme || DEFAULT_SCHEME;
  let title = file.name.replace(/\.ppt$/i, '');
  const doc = {
    format: 'bento/slides', version: 1, docId: uuid(), title,
    size: { width: slideW, height: slideH },
    theme: { background: sc[0], color: sc[1], accent: sc[5] || '#FF9E5E', fontFamily: fontStack(firstFont) },
    slides, modified: new Date().toISOString(),
  };
  if (Object.keys(assets).length) doc.assets = assets;
  warn('Aus dem alten .ppt-Format übernommen: Texte, Formen, Bilder, Hintergründe, Klick-Animationen, Übergänge und Notizen. Diagramme/Tabellen aus alten Dateien kommen als Einzelformen oder Platzhalter.');
  return { doc, warnings: Array.from(warnings), slideCount: slides.length };
}

// ---------------- Bento shell fetch + splice ----------------

// Primary source: the base64 blob embedded above (#bento-shell-b64) — a self-built
// v1.0.11 release of Bento_Slides.bento.html with an added image-crop feature
// (see the "Crop image…" button this patch adds to the Image properties panel),
// bundled 2026-08-01. This makes conversion work fully offline / independent of
// bento.page uptime, and gives you crop even before it's upstreamed.
// These URLs are only a fallback, used if the embedded copy is ever missing or
// corrupt (e.g. someone stripped it while editing this file). They point at the
// OFFICIAL nyblnet/bento build — no crop patch — so falling back here still works,
// just without the "Crop image…" button until the embedded copy is restored.
const SHELL_URLS = [
  'https://bento.page/releases/slides/Bento_Slides.bento.html',
  'https://bento.page/slides',
  'https://github.com/nyblnet/bento/releases/latest/download/Bento_Slides.bento.html',
  'https://github.com/nyblnet/bento/releases/download/v1.0.7/Bento_Slides.bento.html'
];
let shellCache = null;
let shellCacheError = null;

async function getShell(){
  if (shellCache) return shellCache;
  if (shellCacheError) throw shellCacheError;

  // 1) Bundled copy — embedded in this file, works fully offline.
  try{
    const el = document.getElementById('bento-shell-b64');
    if (el && el.textContent.trim()){
      const clean = el.textContent.replace(/\s+/g, '');
      const text = atob(clean);
      if (/id=["']bento-doc["']/.test(text)){
        shellCache = text;
        return text;
      }
    }
  } catch(e){ console.warn('Eingebettete Bento-Hülle unlesbar, versuche Netzwerk:', e); }

  // 2) Fallback — only reached if the bundled copy is missing or corrupt.
  let lastErr = null;
  for (const url of SHELL_URLS){
    try{
      const res = await fetch(url, { mode: 'cors' });
      if (!res.ok) { lastErr = new Error('HTTP ' + res.status + ' von ' + url); continue; }
      const text = await res.text();
      if (!/id=["']bento-doc["']/.test(text)) { lastErr = new Error('Antwort von ' + url + ' enthält keinen bento-doc-Block'); continue; }
      shellCache = text;
      return text;
    } catch(e){ lastErr = e; }
  }
  shellCacheError = lastErr || new Error('Keine Bento-Hülle verfügbar (weder eingebettet noch per Netzwerk)');
  throw shellCacheError;
}

function spliceDoc(shellHtml, doc){
  const jsonStr = JSON.stringify(doc).replace(/</g, '\\u003c');
  const re = /(<script[^>]*id=["']bento-doc["'][^>]*>)([\s\S]*?)(<\/script>)/;
  if (!re.test(shellHtml)) throw new Error('bento-doc-Block nicht in der Hülle gefunden');
  return shellHtml.replace(re, (m, open, _old, close) => open + '\n' + jsonStr + '\n' + close);
}

async function buildBentoHtml(doc, filenameBase){
  const shell = await getShell();
  const html = spliceDoc(shell, doc);
  return { filename: filenameBase + '.bento.html', html };
}

// ---------------- UI wiring ----------------

const importTile = document.getElementById('importTile');
const fileInput = document.getElementById('fileInput');
const cardsEl = document.getElementById('cards');
const itemsEl = document.getElementById('items');
const toastEl = document.getElementById('toast');
const blankBtn = document.getElementById('blankBtn');
const demoBtn = document.getElementById('demoBtn');

let items = []; // successfully-parsed decks (from pptx, bento-json, or bento.html), shown with + connectors between them

function blankBentoDoc(title){
  return {
    format: 'bento/slides',
    version: 1,
    docId: (crypto.randomUUID ? crypto.randomUUID() : 'blank-' + Date.now()),
    title: title || 'Neue Präsentation',
    size: { width: 1280, height: 720 },
    theme: { background: '#FFFFFF', color: '#111111', accent: '#FF9E5E', fontFamily: 'system-ui, sans-serif' },
    slides: [ { id: 's1', background: '#FFFFFF', transition: 'none', elements: [], notes: '' } ],
    modified: new Date().toISOString(),
  };
}

/** Combine two bento/slides docs into one: B's slides (and only the assets
 *  they actually use) are appended after A's. A's size/theme win — decks
 *  with a different canvas size will look off on B's slides, since nothing
 *  here rescales element positions; that's a known limitation, not a bug. */
function mergeDocs(docA, docB){
  const merged = JSON.parse(JSON.stringify(docA));
  merged.assets = merged.assets || {};
  const assetsB = docB.assets || {};
  const keyMap = {}; // key in B -> key in merged (only set when renamed to dodge a collision)
  for (const [key, val] of Object.entries(assetsB)){
    let newKey = key;
    if (Object.prototype.hasOwnProperty.call(merged.assets, newKey) && merged.assets[newKey] !== val){
      let i = 1;
      while (Object.prototype.hasOwnProperty.call(merged.assets, key + '_' + i)) i++;
      newKey = key + '_' + i;
    }
    merged.assets[newKey] = val;
    if (newKey !== key) keyMap[key] = newKey;
  }

  const slidesB = JSON.parse(JSON.stringify(docB.slides || []));
  const usedIds = new Set(merged.slides.map(s => s.id));
  const idMap = {};
  for (const slide of slidesB){
    let newId = slide.id;
    let i = 1;
    while (usedIds.has(newId)) newId = slide.id + '_' + (i++);
    usedIds.add(newId);
    idMap[slide.id] = newId;
  }
  const remapAssetRefs = (node) => {
    if (typeof node === 'string'){
      const m = /^asset:(.+)$/.exec(node);
      return (m && keyMap[m[1]]) ? 'asset:' + keyMap[m[1]] : node;
    }
    if (Array.isArray(node)) return node.map(remapAssetRefs);
    if (node && typeof node === 'object'){
      const out = {};
      for (const k in node) out[k] = remapAssetRefs(node[k]);
      return out;
    }
    return node;
  };
  for (const slide of slidesB){
    slide.id = idMap[slide.id] || slide.id;
    if (slide.stateOf && idMap[slide.stateOf]) slide.stateOf = idMap[slide.stateOf];
  }
  merged.slides.push(...slidesB.map(remapAssetRefs));
  merged.title = (docA.title || 'Deck') + ' + ' + (docB.title || 'Deck');
  merged.docId = (crypto.randomUUID ? crypto.randomUUID() : 'merged-' + Date.now());
  merged.modified = new Date().toISOString();
  return merged;
}

// ---------------- Medien verkleinern & In Teile aufteilen ----------------
// Portiert aus moodle-mod_bentos bentoconvert.js (dieselbe Funktionalität wie
// dort in manage.php - siehe dessen eigenen Kommentar dazu: reines Client-JS
// ohne Build-Schritt, arbeitet nur auf dem doc-Objekt, daher 1:1 uebertragbar).

/** Ermittelt alle tatsaechlich von Folien/Layouts referenzierten Asset- und
 *  Font-Keys - Grundlage fuer bentoBuildSplitDoc() (ein Teil bekommt nur die
 *  Assets, die seine eigenen Folien wirklich brauchen). */
function bentoFindUsedAssetAndFontKeys(doc){
  const assetKeys = {};
  const fontFamiliesInUse = {};
  if (doc.theme && doc.theme.fontFamily) fontFamiliesInUse[doc.theme.fontFamily] = true;
  const assetKeyFrom = (value) => (typeof value === 'string' && value.indexOf('asset:') === 0) ? value.slice('asset:'.length) : null;
  function visitElement(el){
    if (!el || !el.type) return;
    if (el.type === 'image'){
      const src = assetKeyFrom(el.src); if (src) assetKeys[src] = true;
      const mask = assetKeyFrom(el.mask); if (mask) assetKeys[mask] = true;
    } else if (el.type === 'svg'){
      if (el.asset) assetKeys[el.asset] = true;
    } else if (el.type === 'media'){
      const msrc = assetKeyFrom(el.src); if (msrc) assetKeys[msrc] = true;
      const poster = assetKeyFrom(el.poster); if (poster) assetKeys[poster] = true;
    } else if (el.type === 'text'){
      if (el.fontFamily) fontFamiliesInUse[el.fontFamily] = true;
    } else if (el.type === 'table'){
      if (el.style && el.style.fontFamily) fontFamiliesInUse[el.style.fontFamily] = true;
    } else if (el.type === 'chart'){
      const str = JSON.stringify(el.option || {});
      let m; const re = /asset:([a-zA-Z0-9_-]+)/g;
      while ((m = re.exec(str))) assetKeys[m[1]] = true;
    }
  }
  const visitSlide = (s) => (s.elements || []).forEach(visitElement);
  (doc.slides || []).forEach(visitSlide);
  (doc.layouts || []).forEach(visitSlide);
  const fontKeys = {};
  (doc.fonts || []).forEach((f) => { if (fontFamiliesInUse[f.family]) { fontKeys[f.asset] = true; assetKeys[f.asset] = true; } });
  return { assetKeys, fontKeys };
}

/** Baut ein eigenstaendiges Dokument aus einer zusammenhaengenden Folge von
 *  doc.slides - nur die Assets/Fonts, die genau diese Folien tatsaechlich
 *  verwenden, kommen mit (siehe bentoFindUsedAssetAndFontKeys). */
function bentoBuildSplitDoc(doc, startIdx, endIdx){
  const part = JSON.parse(JSON.stringify(doc));
  part.slides = (doc.slides || []).slice(startIdx, endIdx);
  const used = bentoFindUsedAssetAndFontKeys(part);
  if (part.assets) Object.keys(part.assets).forEach((k) => { if (!used.assetKeys[k]) delete part.assets[k]; });
  if (part.fonts) part.fonts = part.fonts.filter((f) => used.fontKeys[f.asset]);
  return part;
}

function bentoRemapAssetRefs(node, keyMap){
  if (typeof node === 'string'){
    const m = /^asset:(.+)$/.exec(node);
    return (m && keyMap[m[1]]) ? 'asset:' + keyMap[m[1]] : node;
  }
  if (Array.isArray(node)) return node.map((n) => bentoRemapAssetRefs(n, keyMap));
  if (node && typeof node === 'object'){
    const out = {};
    for (const k in node) out[k] = bentoRemapAssetRefs(node[k], keyMap);
    return out;
  }
  return node;
}

/** Echte dekodierte Byte-Groesse eines data:-URIs (base64-Aufblaehung + Padding
 *  beruecksichtigt), nicht die rohe String-Laenge. */
function bentoDataUriByteSize(dataUri){
  const comma = dataUri.indexOf(',');
  if (comma < 0) return dataUri.length;
  const payload = dataUri.slice(comma + 1);
  if (!/;base64$/.test(dataUri.slice(0, comma))) return payload.length;
  const padding = payload.slice(-2) === '==' ? 2 : payload.slice(-1) === '=' ? 1 : 0;
  return Math.floor((payload.length * 3) / 4) - padding;
}

/** PNG bleibt PNG (Transparenz), alles andere wird JPEG; loest unveraendert
 *  auf, falls schon klein genug oder bei jedem Dekodier-Fehler. */
function bentoDownscaleImageDataUrl(dataUrl, maxDim, quality){
  return new Promise((resolve) => {
    const img = new Image();
    img.onload = () => {
      const scale = Math.min(1, maxDim / Math.max(img.naturalWidth, img.naturalHeight));
      if (scale >= 1){ resolve(dataUrl); return; }
      const canvas = document.createElement('canvas');
      canvas.width = Math.round(img.naturalWidth * scale);
      canvas.height = Math.round(img.naturalHeight * scale);
      const ctx = canvas.getContext('2d');
      if (!ctx){ resolve(dataUrl); return; }
      ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
      const isPng = dataUrl.indexOf('data:image/png') === 0;
      resolve(canvas.toDataURL(isPng ? 'image/png' : 'image/jpeg', quality));
    };
    img.onerror = () => resolve(dataUrl);
    img.src = dataUrl;
  });
}

function slideLabel(slide, idx){
  let found = null;
  (function walk(node){
    if (found || !node || typeof node !== 'object') return;
    if (Array.isArray(node)){ node.forEach(walk); return; }
    if (typeof node.text === 'string' && node.text.trim()){ found = node.text.trim(); return; }
    if (typeof node.content === 'string' && node.content.trim()){ found = node.content.trim(); return; }
    for (const k in node) walk(node[k]);
  })(slide.elements || []);
  const text = found ? found.slice(0, 40) + (found.length > 40 ? '…' : '') : '(ohne Text)';
  return 'Folie ' + (idx + 1) + ' — ' + text;
}

// Ein Thumbnail (iframe-Render) nach dem anderen - alle gleichzeitig zu bauen
// machte das Modal bei vielen Folien spuerbar traege.
const thumbnailQueue = [];
let thumbnailQueueRunning = false;
function pumpThumbnailQueue(){
  if (thumbnailQueueRunning || !thumbnailQueue.length) return;
  thumbnailQueueRunning = true;
  const job = thumbnailQueue.shift();
  job(() => { thumbnailQueueRunning = false; pumpThumbnailQueue(); });
}

function buildSlideThumbnail(doc, idx){
  const wrap = document.createElement('div');
  wrap.className = 'mb-split-thumb';
  const placeholder = document.createElement('button');
  placeholder.type = 'button';
  placeholder.className = 'mb-split-thumb-placeholder';
  placeholder.textContent = '▶';
  placeholder.title = 'Vorschau laden';
  wrap.appendChild(placeholder);
  let loaded = false;
  function load(){
    if (loaded) return;
    loaded = true;
    placeholder.remove();
    const spinner = document.createElement('div');
    spinner.className = 'mb-split-thumb-spinner';
    wrap.appendChild(spinner);
    const w = (doc.size && doc.size.width) || 1280;
    const h = (doc.size && doc.size.height) || 720;
    const iframe = document.createElement('iframe');
    iframe.className = 'mb-split-thumb-frame';
    iframe.style.width = w + 'px';
    iframe.style.height = h + 'px';
    iframe.style.transform = 'scale(' + (120 / w) + ')';
    iframe.setAttribute('tabindex', '-1');
    iframe.setAttribute('aria-hidden', 'true');
    iframe.setAttribute('sandbox', 'allow-scripts');
    wrap.appendChild(iframe);
    thumbnailQueue.push((done) => {
      const finish = () => { spinner.remove(); done(); };
      getShell().then((shell) => {
        const slideDoc = {
          format: doc.format, version: doc.version || 1,
          docId: 'thumb-' + idx, title: doc.title || '',
          size: doc.size || { width: 1280, height: 720 },
          theme: doc.theme || { background: '#FFFFFF', color: '#111111', accent: '#FF9E5E', fontFamily: 'system-ui, sans-serif' },
          assets: doc.assets, slides: [doc.slides[idx]],
          readonly: true,
        };
        const html = spliceDoc(shell, slideDoc);
        iframe.addEventListener('load', finish, { once: true });
        iframe.srcdoc = html;
      }).catch((e) => { console.warn('Thumbnail konnte nicht geladen werden:', e); finish(); });
    });
    pumpThumbnailQueue();
  }
  placeholder.addEventListener('click', load);
  return { el: wrap, load };
}

function openSplitModal(it, onDone){
  const slides = it.doc.slides || [];
  const breakAfter = new Array(slides.length - 1).fill(false);
  const customNames = [];

  const overlay = document.createElement('div');
  overlay.className = 'paste-modal show';
  const box = document.createElement('div');
  box.className = 'paste-modal-inner mb-modal-box';
  box.innerHTML = `
    <button class="paste-modal-close" type="button">✕</button>
    <h3>In Teile aufteilen</h3>
    <p>Zwischen zwei Folien klicken, um dort eine Trennung einzufügen. Jeder entstehende Teil bekommt nur die Assets, die seine eigenen Folien tatsächlich verwenden.</p>
    <button type="button" class="mb-split-load-all" style="width:auto; margin-bottom:12px;">Alle Thumbnails öffnen</button>
    <div class="mb-split-list"></div>
    <div class="mb-split-names"></div>
    <div class="mb-modal-actions">
      <button type="button" class="mb-split-cancel" style="width:auto;">Abbrechen</button>
      <button type="button" class="primary mb-split-confirm" style="width:auto;">Aufteilen</button>
    </div>`;
  overlay.appendChild(box);
  document.body.appendChild(overlay);

  const listEl = box.querySelector('.mb-split-list');
  const namesEl = box.querySelector('.mb-split-names');
  const confirmBtn = box.querySelector('.mb-split-confirm');
  const thumbLoaders = [];

  function computeGroups(){
    const groups = [];
    let current = [];
    slides.forEach((_, idx) => {
      current.push(idx);
      if (breakAfter[idx]){ groups.push(current); current = []; }
    });
    if (current.length) groups.push(current);
    return groups;
  }
  function renderNameInputs(){
    const groups = computeGroups();
    namesEl.innerHTML = '';
    if (groups.length <= 1) return;
    groups.forEach((g, partNum) => {
      const row = document.createElement('label');
      row.className = 'mb-split-name-row';
      row.innerHTML = `<span>Teil ${partNum + 1} (${g.length} Folie${g.length === 1 ? '' : 'n'})</span>`;
      const input = document.createElement('input');
      input.type = 'text';
      input.value = customNames[partNum] || ((it.doc.title || it.baseName || 'Deck') + ' — Teil ' + (partNum + 1));
      input.addEventListener('input', () => { customNames[partNum] = input.value; });
      row.appendChild(input);
      namesEl.appendChild(row);
    });
  }
  function updateConfirmState(){
    const partCount = breakAfter.filter(Boolean).length + 1;
    confirmBtn.textContent = partCount > 1 ? ('In ' + partCount + ' Teile aufteilen') : 'Keine Trennung gewählt';
    confirmBtn.disabled = partCount <= 1;
    renderNameInputs();
  }

  slides.forEach((slide, idx) => {
    const row = document.createElement('div');
    row.className = 'mb-split-row';
    const thumb = buildSlideThumbnail(it.doc, idx);
    thumbLoaders.push(thumb.load);
    row.appendChild(thumb.el);
    const label = document.createElement('div');
    label.className = 'mb-split-label';
    label.textContent = slideLabel(slide, idx);
    row.appendChild(label);
    listEl.appendChild(row);
    if (idx < slides.length - 1){
      const brk = document.createElement('button');
      brk.type = 'button';
      brk.className = 'mb-split-break';
      brk.textContent = '+ Trennung hier einfügen';
      brk.addEventListener('click', () => {
        breakAfter[idx] = !breakAfter[idx];
        brk.className = 'mb-split-break' + (breakAfter[idx] ? ' active' : '');
        brk.textContent = breakAfter[idx] ? '✂ Trennung hier — klicken zum Entfernen' : '+ Trennung hier einfügen';
        updateConfirmState();
      });
      listEl.appendChild(brk);
    }
  });
  updateConfirmState();
  box.querySelector('.mb-split-load-all').addEventListener('click', () => thumbLoaders.forEach((load) => load()));

  const close = () => overlay.remove();
  box.querySelector('.paste-modal-close').addEventListener('click', close);
  box.querySelector('.mb-split-cancel').addEventListener('click', close);
  overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });
  confirmBtn.addEventListener('click', () => {
    const groups = computeGroups();
    const newItems = groups.map((g, partNum) => {
      const partDoc = bentoBuildSplitDoc(it.doc, g[0], g[g.length - 1] + 1);
      const name = (customNames[partNum] || '').trim() || ((it.doc.title || it.baseName || 'Deck') + ' — Teil ' + (partNum + 1));
      partDoc.title = name;
      partDoc.docId = (crypto.randomUUID ? crypto.randomUUID() : 'part-' + Date.now() + '-' + partNum);
      return { baseName: name, doc: partDoc, slideCount: partDoc.slides.length, warnings: [] };
    });
    close();
    onDone(newItems);
  });
}

function openShrinkAssetsModal(it, onDone){
  const assets = it.doc.assets || {};
  const imageEntries = Object.keys(assets)
    .map((key) => ({ key, value: assets[key], bytes: bentoDataUriByteSize(assets[key]) }))
    .filter((e) => e.value.indexOf('data:image/') === 0)
    .sort((a, b) => b.bytes - a.bytes);

  if (imageEntries.length === 0){ toast('Keine eingebetteten Bilder in dieser Präsentation.'); return; }

  const byValue = {};
  imageEntries.forEach((e) => { (byValue[e.value] = byValue[e.value] || []).push(e.key); });
  let dupCount = 0;
  Object.keys(byValue).forEach((v) => { if (byValue[v].length > 1) dupCount += byValue[v].length - 1; });

  const overlay = document.createElement('div');
  overlay.className = 'paste-modal show';
  const box = document.createElement('div');
  box.className = 'paste-modal-inner mb-modal-box';
  box.innerHTML = `
    <button class="paste-modal-close" type="button">✕</button>
    <h3>Medien verkleinern</h3>
    <p>Ausgewählte Bilder werden auf die angegebene Kantenlänge verkleinert und neu komprimiert (PNG bleibt PNG, alles andere wird JPEG).</p>
    <div class="mb-shrink-settings">
      <label>Max. Kantenlänge (px)<input type="number" class="mb-shrink-maxdim" min="200" max="8000" value="1920"></label>
      <label>Qualität (%)<input type="number" class="mb-shrink-quality" min="10" max="100" value="85"></label>
    </div>
    ${dupCount > 0 ? `<label class="mb-shrink-dupes"><input type="checkbox" class="mb-shrink-dedupe" checked> ${dupCount} doppelte Bilder gefunden — entfernen</label>` : ''}
    <div class="mb-shrink-list"></div>
    <div class="mb-modal-actions">
      <button type="button" class="mb-shrink-cancel" style="width:auto;">Abbrechen</button>
      <button type="button" class="primary mb-shrink-confirm" style="width:auto;">Anwenden</button>
    </div>`;
  overlay.appendChild(box);
  document.body.appendChild(overlay);

  const listEl = box.querySelector('.mb-shrink-list');
  const maxDimInput = box.querySelector('.mb-shrink-maxdim');
  const qualityInput = box.querySelector('.mb-shrink-quality');
  const dedupeCb = box.querySelector('.mb-shrink-dedupe');
  const rows = [];
  imageEntries.forEach((e) => {
    const row = document.createElement('div');
    row.className = 'mb-shrink-row';
    const cb = document.createElement('input');
    cb.type = 'checkbox';
    cb.checked = true;
    row.appendChild(cb);
    const thumb = document.createElement('img');
    thumb.className = 'mb-shrink-thumb';
    thumb.src = e.value;
    thumb.loading = 'lazy';
    row.appendChild(thumb);
    const sizeEl = document.createElement('span');
    sizeEl.className = 'mb-shrink-size';
    sizeEl.textContent = (e.bytes / 1024).toFixed(0) + ' KB';
    row.appendChild(sizeEl);
    listEl.appendChild(row);
    rows.push({ entry: e, checkbox: cb });
  });

  const close = () => overlay.remove();
  box.querySelector('.paste-modal-close').addEventListener('click', close);
  box.querySelector('.mb-shrink-cancel').addEventListener('click', close);
  overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });

  const confirmBtn = box.querySelector('.mb-shrink-confirm');
  confirmBtn.addEventListener('click', () => {
    confirmBtn.disabled = true;
    confirmBtn.textContent = 'Wird verarbeitet…';
    const maxDim = parseInt(maxDimInput.value, 10) || 1920;
    const quality = (parseInt(qualityInput.value, 10) || 85) / 100;
    const toShrink = rows.filter((r) => r.checkbox.checked);
    Promise.all(toShrink.map((r) => bentoDownscaleImageDataUrl(r.entry.value, maxDim, quality).then((shrunk) => { it.doc.assets[r.entry.key] = shrunk; })))
      .then(() => {
        if (dedupeCb && dedupeCb.checked){
          const byValue2 = {};
          const keyMap = {};
          Object.keys(it.doc.assets).forEach((key) => {
            const val = it.doc.assets[key];
            if (Object.prototype.hasOwnProperty.call(byValue2, val)) keyMap[key] = byValue2[val];
            else byValue2[val] = key;
          });
          Object.keys(keyMap).forEach((oldKey) => { delete it.doc.assets[oldKey]; });
          it.doc.slides = bentoRemapAssetRefs(it.doc.slides, keyMap);
          if (it.doc.fonts) it.doc.fonts = it.doc.fonts.map((f) => keyMap[f.asset] ? Object.assign({}, f, { asset: keyMap[f.asset] }) : f);
        }
        close();
        onDone();
      });
  });
}

function toast(msg, duration){
  toastEl.textContent = msg;
  toastEl.classList.add('show');
  setTimeout(()=>toastEl.classList.remove('show'), duration || 1400);
}

function download(filename, content, mime){
  const blob = new Blob([content], { type: mime || 'application/json' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url; a.download = filename;
  document.body.appendChild(a); a.click(); a.remove();
  setTimeout(()=>URL.revokeObjectURL(url), 4000);
}

document.body.addEventListener('dragover', e => { e.preventDefault(); importTile.classList.add('drag'); });
document.body.addEventListener('dragleave', e => { if (e.target === document.body) importTile.classList.remove('drag'); });
document.body.addEventListener('drop', e => {
  e.preventDefault(); importTile.classList.remove('drag');
  handleFiles(Array.from(e.dataTransfer.files));
});
fileInput.addEventListener('change', e => handleFiles(Array.from(e.target.files)));

async function handleFiles(files, opts = {}){
  for (const file of files){
    const isPptx = /\.pptx$/i.test(file.name);
    const isPpt = /\.ppt$/i.test(file.name);
    const isJson = /\.json$/i.test(file.name);
    const isHtml = /\.html?$/i.test(file.name);

    if (!isPptx && !isPpt && !isJson && !isHtml){
      const card = document.createElement('div');
      card.className = 'card error';
      cardsEl.appendChild(card);
      card.innerHTML = `
        <div class="card-top">
          <div>
            <div class="card-name">${esc(file.name)}</div>
            <div class="card-meta">nicht unterstützt</div>
          </div>
          <span class="pill err">?</span>
        </div>
        <div class="err-msg">
          Keine .pptx-, .ppt-, .json- oder .bento.html-Datei erkannt.
        </div>`;
      continue;
    }

    const card = document.createElement('div');
    card.className = 'card';
    cardsEl.appendChild(card);
    card.innerHTML = `
      <div class="card-top">
        <div>
          <div class="card-name">${esc(file.name)}</div>
          <div class="card-meta">wird verarbeitet…</div>
        </div>
        <span class="pill">läuft</span>
      </div>`;

    try{
      let doc, warnings = [], slideCount, baseName;
      if (isPptx){
        const res = await convertPptx(file);
        doc = res.doc; warnings = res.warnings; slideCount = res.slideCount;
        baseName = file.name.replace(/\.pptx$/i, '');
      } else if (isPpt){
        const res = await convertPpt(file);
        doc = res.doc; warnings = res.warnings; slideCount = res.slideCount;
        baseName = file.name.replace(/\.ppt$/i, '');
      } else if (isHtml){
        const text = await file.text();
        const m = /<script[^>]*id=["']bento-doc["'][^>]*>([\s\S]*?)<\/script>/.exec(text);
        if (!m || !m[1].trim()) throw new Error('Keine eingebettete bento/slides-JSON in dieser Datei gefunden (kein #bento-doc-Inhalt).');
        let parsed;
        try{ parsed = JSON.parse(m[1].trim()); }
        catch{ throw new Error('Der #bento-doc-Inhalt dieser Datei ist kein gültiges JSON.'); }
        if (parsed.format !== 'bento/slides') throw new Error('Kein bento/slides-Dokument in dieser Datei.');
        doc = parsed;
        slideCount = (doc.slides || []).length;
        baseName = file.name.replace(/\.bento\.html$/i, '').replace(/\.html?$/i, '');
      } else {
        const text = await file.text();
        let parsed;
        try{ parsed = JSON.parse(text); }
        catch{ throw new Error('Keine gültige JSON-Datei.'); }
        if (parsed.format !== 'bento/slides') throw new Error('Keine bento/slides-JSON-Datei (Feld "format" fehlt oder falsch).');
        doc = parsed;
        slideCount = (doc.slides || []).length;
        baseName = file.name.replace(/\.bento\.json$/i, '').replace(/\.json$/i, '');
      }
      card.remove(); // this file's own progress card is replaced by its entry in the items row
      const newItem = { baseName, doc, slideCount, warnings };
      items.push(newItem);
      renderItems();
      // queueDecision:false = interner Nachlade-Weg (🗜/✂️/✚ bei einer bereits
      // gespeicherten Praesentation, siehe deren jeweilige Klick-Handler weiter
      // unten) - die Datei existiert dort schon auf dem Server, ein weiterer
      // Speichern/Betrachten/Herunterladen-Dialog waere unpassend.
      if (opts.queueDecision !== false) queueCardDecision(newItem);
    } catch(err){
      console.error(err);
      card.classList.add('error');
      card.innerHTML = `
        <div class="card-top">
          <div>
            <div class="card-name">${esc(file.name)}</div>
            <div class="card-meta">Fehler bei der Verarbeitung</div>
          </div>
          <span class="pill err">Fehler</span>
        </div>
        <div class="err-msg">${esc(err.message || String(err))}</div>`;
    }
  }
}

let draggedItem = null;

// Geteilte Speichern-/Herunterladen-Logik - genutzt sowohl vom "📝 Bearbeitbar
// speichern"-Knopf einer noch unsigespeicherten Karte als auch vom neuen
// Speichern/Betrachten/Herunterladen-Dialog (siehe queueCardDecision() weiter
// unten), damit beide garantiert identisch speichern.
async function saveItemAsDeck(it){
  // KEIN readonly hier - die Datei landet weiterhin im vollen Editor, inkl.
  // eingebettetem bento-host-config (siehe PHP oben): der native "Speichern"-
  // Knopf im Editor schreibt danach direkt wieder in dieselbe Datei zurück,
  // genau wie bei einer Moodle-mod_bento-Aktivität.
  const { filename, html } = await buildBentoHtml(it.doc, it.baseName);
  const fd = new FormData();
  fd.append('bento_save_html', html);
  fd.append('bento_filename', filename);
  fd.append('bento_kind', 'deck');
  const resp = await fetch('', { method: 'POST', body: fd });
  const data = await resp.json();
  if (!resp.ok || !data.ok) throw new Error(data.error || ('Serverfehler (' + resp.status + ')'));
  return data; // { ok, url, filename } - "url" kommt bereits absolut vom Server (bentoServerUrlFor())
}
async function downloadItemAsHtml(it){
  const { filename, html } = await buildBentoHtml(it.doc, it.baseName);
  download(filename, html, 'text/html');
}
// Dieselben Cycling-Parameter wie bei den PHP-gerenderten Deck-Bannern
// ($deckPlayUrl) - ▶ und 🔗 einer bereits gespeicherten Karte sollen sich
// exakt gleich verhalten, ob sie hier direkt frisch gespeichert wurde oder
// erst nach einem Seiten-Reload als Banner erscheint.
function bentoPlayUrlFor(savedUrl){
  return savedUrl + '?autostart=1&loop&interval=8#present';
}

// Im IMMER geladenen Hauptskript (nicht im nur bei bereits vorhandenen
// gespeicherten Praesentationen bedingt gerenderten Banner-Block!), damit
// auch das 🔗 einer GERADE ERST hier per Dialog gespeicherten Karte
// funktioniert, ohne dass vorher schon ein Banner existiert haben muss.
// navigator.clipboard.writeText existiert nur in einem "secure context"
// (HTTPS/localhost) - auf einem reinen HTTP-Server ist navigator.clipboard
// schlicht undefined, ein direkter Aufruf wirft dann eine synchrone
// TypeError NOCH VOR jedem then()/catch() - daher hier explizit geprueft und
// mit einer execCommand('copy')-Fallback-Route ueber ein verstecktes
// Textfeld abgesichert, bevor als letzter Ausweg ein prompt() zum manuellen
// Kopieren erscheint.
function bentoCopyToClipboard(text){
  if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
    return navigator.clipboard.writeText(text).catch(function(){ return bentoCopyFallback(text); });
  }
  return bentoCopyFallback(text);
}
function bentoCopyFallback(text){
  try {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.cssText = 'position:fixed; top:-1000px; left:-1000px;';
    document.body.appendChild(ta);
    ta.focus();
    ta.select();
    var ok = document.execCommand('copy');
    ta.remove();
    if (ok) return Promise.resolve();
  } catch (e) { /* faellt unten auf den Prompt zurueck */ }
  return Promise.reject(new Error('clipboard unavailable'));
}

// Die in infomaster.php konfigurierten Monitore (siehe PHP oben, $bentoScreensForModal) -
// leer, wenn config.json fehlt oder keine Monitore eingerichtet sind (eigenstaendiger
// bento-pronto-Betrieb ohne Infomaster).
function bentoScreensForModal(){
  try {
    var el = document.getElementById('bento-screens-json');
    if (!el || !el.textContent.trim()) return [];
    var list = JSON.parse(el.textContent);
    return Array.isArray(list) ? list : [];
  } catch(e){ return []; }
}

// "🔗"-Klick auf einer gespeicherten Praesentation (Karte oder Banner, siehe
// beide Aufrufer unten): statt den Link nur zu kopieren, zunaechst fragen, ob er
// gleich als Inhalt A eines Monitors gesetzt werden soll - schreibt dazu direkt in
// infomaster.php's config.json (derselbe POST wie das Monitor-Formular dort selbst,
// siehe dessen "screen_id"-Handler: orient/split/duration/modeB/valB muessen
// mitgeschickt werden, sonst wuerden sie durch dessen Fehlen ueberschrieben/geleert).
// Kein eigener JSON-Endpunkt noetig - infomaster.php beantwortet das ganz normal mit
// der neu gerenderten Seite, die hier ungenutzt bleibt; nur response.ok zaehlt.
function openMonitorAssignModal(playUrl){
  var screens = bentoScreensForModal();
  var overlay = document.createElement('div');
  overlay.className = 'paste-modal show';
  var box = document.createElement('div');
  box.className = 'paste-modal-inner mb-modal-box';
  box.style.maxWidth = '440px';
  var rowsHtml = screens.length
    ? screens.map(function(s){
        return '<div class="bento-screen-row" data-id="' + esc(s.id) + '" style="display:flex; align-items:center; gap:10px; padding:8px 0; border-bottom:1px solid #222;">'
          + '<div style="flex:1; min-width:0;">'
          + '<div style="font-size:13px; font-weight:600;">Monitor ' + esc(s.id) + '</div>'
          + '<div style="font-size:11px; color:var(--ink-dim); overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="' + esc(s.currentLabel) + '">z.Z.: ' + esc(s.currentLabel) + '</div>'
          + '</div>'
          + '<button type="button" class="bento-screen-pick-btn" style="width:auto; flex:0 0 auto;">Hierher legen</button>'
          + '</div>';
      }).join('')
    : '<div style="font-size:12px; color:var(--ink-dim); padding:8px 0;">Keine Monitore konfiguriert.</div>';
  box.innerHTML = `
    <button class="paste-modal-close" type="button">✕</button>
    <h3>Auf Monitor legen</h3>
    <div class="bento-screen-list" style="max-height:280px; overflow-y:auto; margin-bottom:14px;">${rowsHtml}</div>
    <div class="mb-modal-actions" style="justify-content:space-between;">
      <button type="button" class="bento-screen-copy-only" style="width:auto;">📋 Nur Link kopieren</button>
    </div>
    <div class="bento-screen-error err-msg" style="display:none; margin-top:10px;"></div>`;
  overlay.appendChild(box);
  document.body.appendChild(overlay);

  var closed = false;
  function close(){ if (closed) return; closed = true; overlay.remove(); }
  function showError(msg){
    var errBox = box.querySelector('.bento-screen-error');
    errBox.style.display = 'block';
    errBox.textContent = msg;
  }
  box.querySelector('.paste-modal-close').addEventListener('click', close);
  overlay.addEventListener('click', function(ev){ if (ev.target === overlay) close(); });
  box.querySelector('.bento-screen-copy-only').addEventListener('click', function(){
    bentoCopyToClipboard(playUrl).then(function(){ toast('🔗 Link kopiert'); close(); })
      .catch(function(){ prompt('Link zum manuellen Kopieren:', playUrl); close(); });
  });
  box.querySelectorAll('.bento-screen-pick-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
      var row = btn.closest('.bento-screen-row');
      var sid = row.getAttribute('data-id');
      var s = screens.find(function(x){ return x.id === sid; });
      if (!s) return;
      btn.disabled = true;
      var orig = btn.textContent;
      btn.textContent = '…';
      var fd = new FormData();
      fd.append('screen_id', s.id);
      fd.append('orient', s.orient);
      fd.append('split', s.split);
      fd.append('duration', s.duration);
      fd.append('modeA', 'url');
      fd.append('valA', playUrl);
      fd.append('modeB', s.modeB);
      fd.append('valB', s.valB);
      fetch('infomaster.php', { method: 'POST', body: fd })
        .then(function(resp){
          if (!resp.ok) throw new Error('HTTP ' + resp.status);
          toast('📺 Auf Monitor ' + sid + ' gelegt');
          close();
        })
        .catch(function(e){
          showError('Konnte Monitor ' + sid + ' nicht setzen: ' + (e.message || e));
          btn.disabled = false;
          btn.textContent = orig;
        });
    });
  });
}

// ============================================================================
// Speichern/Betrachten/Herunterladen-Dialog: erscheint sofort bei jeder neu
// entstehenden Karte (Konvertierung, Import, Text einfuegen, Verbinden, jeder
// Teil eines Aufteilens), damit man nicht erst nach mehreren Klicks merkt,
// dass eine Karte noch gar nicht auf dem Server liegt. Eine Warteschlange
// sorgt dafuer, dass bei mehreren gleichzeitig neu entstandenen Karten
// (z.B. "In Teile aufteilen") die Dialoge nacheinander statt uebereinander
// erscheinen.
// ============================================================================
let cardDecisionQueue = [];
let cardDecisionOpen = false;
function queueCardDecision(it){
  it._decided = false;
  cardDecisionQueue.push(it);
  processCardDecisionQueue();
}
function processCardDecisionQueue(){
  if (cardDecisionOpen) return;
  const it = cardDecisionQueue.shift();
  if (!it) return;
  // Zwischenzeitlich schon anders entschieden (z.B. durch Verbinden/Loeschen
  // entfernt) - ueberspringen statt einen Dialog fuer eine nicht mehr
  // vorhandene Karte zu zeigen.
  if (it._decided || items.indexOf(it) < 0) { processCardDecisionQueue(); return; }
  cardDecisionOpen = true;
  openCardDecisionModal(it, () => {
    cardDecisionOpen = false;
    processCardDecisionQueue();
  });
}
function openCardDecisionModal(it, onClose){
  const overlay = document.createElement('div');
  overlay.className = 'paste-modal show';
  const box = document.createElement('div');
  box.className = 'paste-modal-inner mb-modal-box';
  box.style.maxWidth = '420px';
  box.innerHTML = `
    <button class="paste-modal-close" type="button">✕</button>
    <h3>Neue Präsentation</h3>
    <label style="display:block; font-size:12px; color:var(--ink-dim); margin-bottom:6px;">Name</label>
    <input type="text" class="cd-name-input" style="width:100%; margin-bottom:18px; box-sizing:border-box;">
    <div class="mb-modal-actions" style="justify-content:space-between; flex-wrap:wrap; gap:8px;">
      <button type="button" class="cd-download" style="width:auto;">⬇ Herunterladen</button>
      <button type="button" class="cd-open" style="width:auto;">✎ Öffnen<span class="btn-progress"></span></button>
      <button type="button" class="primary cd-save" style="width:auto;">💾 Speichern<span class="btn-progress"></span></button>
    </div>
    <div class="cd-error err-msg" style="display:none; margin-top:10px;"></div>`;
  overlay.appendChild(box);
  document.body.appendChild(overlay);

  const nameInput = box.querySelector('.cd-name-input');
  nameInput.value = (it.doc.title || it.baseName || 'Präsentation').trim();
  nameInput.focus();
  nameInput.select();

  let closed = false;
  function applyName(){
    const next = nameInput.value.trim();
    if (next) { it.baseName = next; it.doc.title = next; }
  }
  function close(){
    if (closed) return; closed = true;
    overlay.remove();
    it._decided = true;
    onClose();
  }
  function showError(msg){
    const errBox = box.querySelector('.cd-error');
    errBox.style.display = 'block';
    errBox.textContent = msg;
  }
  box.querySelector('.paste-modal-close').addEventListener('click', close);
  nameInput.addEventListener('keydown', (ev) => { if (ev.key === 'Escape') close(); });

  box.querySelector('.cd-download').addEventListener('click', async () => {
    applyName();
    try {
      await downloadItemAsHtml(it);
      toast('Bento-Datei heruntergeladen');
    } catch(e){
      console.error(e);
      showError('Herunterladen fehlgeschlagen: ' + (e.message || e));
      return;
    }
    // Heruntergeladen und NICHT gespeichert - die Karte hat keinen weiteren Zweck mehr
    // hier (kein "unsaved"-Panel mehr, siehe buildItemCard()), also gleich entfernen.
    const i = items.indexOf(it);
    if (i >= 0) items.splice(i, 1);
    renderItems();
    close();
  });

  const openBtn = box.querySelector('.cd-open');
  openBtn.addEventListener('click', () => {
    applyName();
    // Tab SYNCHRON oeffnen (noch im Klick-Handler, vor jedem await) - sonst blockieren
    // manche Browser das spaetere Setzen von win.location als nicht nutzergesteuert.
    const win = window.open('', '_blank');
    openBtn.disabled = true;
    openBtn.classList.add('busy');
    (async () => {
      try {
        // Speichert genau wie "💾 Speichern" (bento-host-config wird dabei eingebettet) -
        // nur so kann der NATIVE Speichern-Knopf im vollen Editor anschliessend wirklich
        // wieder in dieselbe Datei zurueckschreiben, statt auf einen lokalen Download
        // zurueckzufallen.
        const data = await saveItemAsDeck(it);
        it.savedFile = data.filename;
        it.savedUrl = data.url;
        renderItems();
        if (win) { win.location.href = data.url; }
        else { toast('Popup blockiert — bitte Popups für diese Seite erlauben'); }
        close();
      } catch(e){
        console.error(e);
        if (win) win.close();
        showError('Öffnen fehlgeschlagen: ' + (e.message || e));
        openBtn.disabled = false;
        openBtn.classList.remove('busy');
      }
    })();
  });

  const saveBtn = box.querySelector('.cd-save');
  saveBtn.addEventListener('click', async () => {
    applyName();
    saveBtn.disabled = true;
    saveBtn.classList.add('busy');
    box.querySelector('.cd-error').style.display = 'none';
    try {
      const data = await saveItemAsDeck(it);
      it.savedFile = data.filename;
      it.savedUrl = data.url;
      toast('Bearbeitbar gespeichert ✓');
      renderItems();
      close();
    } catch(e){
      console.error(e);
      showError('Speichern fehlgeschlagen: ' + (e.message || e));
      saveBtn.disabled = false;
      saveBtn.classList.remove('busy');
    }
  });
}

function buildItemCard(it){
  const card = document.createElement('div');
  card.className = 'card' + (it.merged ? ' merged' : '');
  card.draggable = true;
  const savedActionsHtml = `
      <a href="${esc(bentoPlayUrlFor(it.savedUrl))}" target="_blank" rel="noopener" title="Präsentation starten (Endlos-Loop, 8s/Folie)">▶</a>
      <a href="${esc(it.savedUrl)}" target="_blank" rel="noopener" title="Bearbeiten (im vollen Editor)">✎</a>
      <button data-action="shrink" title="Medien verkleinern">🗜</button>
      ${it.slideCount > 1 ? `<button data-action="split" title="In Teile aufteilen">✂️</button>` : ''}
      <a href="${esc(it.savedUrl)}" download title="Als .bento.html herunterladen">⬇</a>
      <button data-action="delete-saved" title="Löschen">✕</button>
      <button data-action="copy-link" title="Auf Monitor legen / Link kopieren">🔗</button>`;
  // Vor der Entscheidung im Speichern/Oeffnen/Herunterladen-Dialog (siehe
  // openCardDecisionModal(), der diese Karte sofort ueberdeckt) gibt es absichtlich
  // KEINE eigene Button-Reihe mehr - alle drei moeglichen Ausgaenge (Speichern, Oeffnen,
  // Herunterladen) fuehren entweder zur gespeicherten Button-Reihe oder entfernen die
  // Karte gleich wieder, sie ist also nie laenger sichtbar interaktiv ohne diese Reihe.
  card.innerHTML = `
    <div class="card-top">
      <div class="card-top-left">
        <div class="card-grip" title="Ziehen zum Sortieren">⠿</div>
        <div>
          <div class="card-name" tabindex="0" title="Doppelklick zum Umbenennen">${esc(it.baseName)}</div>
          <div class="card-meta">${it.slideCount} Folie${it.slideCount===1?'':'n'} · ${it.doc.size.width}×${it.doc.size.height}px</div>
        </div>
      </div>
      <span class="pill ok">${it.merged ? 'verbunden' : 'fertig'}</span>
    </div>
    ${it.warnings && it.warnings.length ? `<ul class="warn-list">${it.warnings.map(w=>`<li>${esc(w)}</li>`).join('')}</ul>` : ''}
    <div class="actions actions-icons">${it.savedFile ? savedActionsHtml : ''}</div>`;

  const nameEl = card.querySelector('.card-name');
  nameEl.addEventListener('dblclick', () => {
    const input = document.createElement('input');
    input.type = 'text';
    input.className = 'card-name-input';
    input.value = it.baseName;
    nameEl.replaceWith(input);
    input.focus();
    input.select();
    let done = false;
    function commit(save){
      if (done) return; done = true;
      const next = input.value.trim();
      if (save && next && next !== it.baseName){
        it.baseName = next;
        it.doc.title = next;
        if (it.savedFile) {
          // Bereits gespeichert - Dateiname UND Titel auf dem Server mitziehen,
          // damit ▶/✎/🔗 dieser Karte weiterhin auf dem aktuellen Stand bleiben
          // (dieselbe Logik wie beim langen Klick auf einen Deck-Banner-Namen).
          const fd = new FormData();
          fd.append('file', it.savedFile);
          fd.append('new_name', next);
          fetch('?api=rename_deck', { method: 'POST', body: fd })
            .then((r) => r.json())
            .then((data) => {
              if (data.ok) { it.savedFile = data.filename; it.savedUrl = data.url; }
              else console.error('[bento] Umbenennen auf dem Server fehlgeschlagen:', data.error);
            })
            .catch((e) => console.error('[bento] Umbenennen auf dem Server fehlgeschlagen:', e));
        }
        renderItems();
        return;
      }
      input.replaceWith(nameEl);
    }
    input.addEventListener('keydown', (ev) => {
      if (ev.key === 'Enter'){ ev.preventDefault(); commit(true); }
      else if (ev.key === 'Escape'){ ev.preventDefault(); commit(false); }
    });
    input.addEventListener('blur', () => commit(true));
  });


  const deleteSavedBtn = card.querySelector('[data-action="delete-saved"]');
  if (deleteSavedBtn) deleteSavedBtn.addEventListener('click', async () => {
    if (!confirm('"' + it.baseName + '" wirklich löschen?')) return;
    deleteSavedBtn.disabled = true;
    try{
      const fd = new FormData();
      fd.append('file', it.savedFile);
      await fetch('?api=delete_deck', { method: 'POST', body: fd });
      const i = items.indexOf(it);
      if (i >= 0) items.splice(i, 1);
      renderItems();
      toast('🗑 Gelöscht');
    } catch(e){
      console.error(e);
      toast('Löschen fehlgeschlagen: ' + (e.message || e));
      deleteSavedBtn.disabled = false;
    }
  });

  const copyLinkBtn = card.querySelector('[data-action="copy-link"]');
  if (copyLinkBtn) copyLinkBtn.addEventListener('click', () => {
    openMonitorAssignModal(bentoPlayUrlFor(it.savedUrl));
  });

  card.addEventListener('dragstart', () => {
    draggedItem = it;
    card.classList.add('dragging');
  });
  card.addEventListener('dragend', () => { card.classList.remove('dragging'); draggedItem = null; });
  card.addEventListener('dragover', (e) => e.preventDefault());
  card.addEventListener('drop', (e) => {
    e.preventDefault();
    if (!draggedItem || draggedItem === it) return;
    const fromIdx = items.indexOf(draggedItem);
    if (fromIdx < 0) return;
    items.splice(fromIdx, 1);
    const rect = card.getBoundingClientRect();
    const above = (e.clientY - rect.top) < rect.height / 2;
    const targetIdx = items.indexOf(it);
    items.splice(above ? targetIdx : targetIdx + 1, 0, draggedItem);
    renderItems();
  });

  const shrinkBtn = card.querySelector('[data-action="shrink"]');
  if (shrinkBtn) shrinkBtn.addEventListener('click', () => {
    openShrinkAssetsModal(it, () => { renderItems(); toast('Medien verkleinert — noch nicht gespeichert.'); });
  });

  const splitBtn = card.querySelector('[data-action="split"]');
  if (splitBtn){
    splitBtn.addEventListener('click', () => {
      openSplitModal(it, (newItems) => {
        const i = items.indexOf(it);
        if (i >= 0) items.splice(i, 1, ...newItems);
        else items.push(...newItems);
        renderItems();
        toast('In ' + newItems.length + ' Teile aufgeteilt — noch nicht gespeichert.');
        newItems.forEach(queueCardDecision);
      });
    });
  }

  return card;
}

function mergeItems(i, j){
  const a = items[i], b = items[j];
  let mergedDoc;
  try{
    mergedDoc = mergeDocs(a.doc, b.doc);
  } catch(e){
    console.error(e);
    toast('Verbinden fehlgeschlagen: ' + (e.message || e));
    return;
  }
  const mergedItem = {
    baseName: a.baseName + '+' + b.baseName,
    doc: mergedDoc,
    slideCount: mergedDoc.slides.length,
    warnings: [...(a.warnings || []), ...(b.warnings || [])],
    merged: true,
  };
  items.splice(i, 2, mergedItem);
  renderItems();
  toast('Verbunden: ' + mergedItem.slideCount + ' Folien');
  queueCardDecision(mergedItem);
}

function renderItems(){
  itemsEl.innerHTML = '';
  items.forEach((it, idx) => {
    itemsEl.appendChild(buildItemCard(it));
    if (idx < items.length - 1){
      const connector = document.createElement('div');
      connector.className = 'connector';
      const btn = document.createElement('button');
      btn.className = 'connector-btn';
      btn.textContent = '✚';
      btn.title = 'Verbinden: "' + it.baseName + '" + "' + items[idx + 1].baseName + '"';
      btn.addEventListener('click', () => mergeItems(idx, idx + 1));
      connector.appendChild(btn);
      itemsEl.appendChild(connector);
    }
  });
}

// ————— paste-and-split: turn copied text (webpage/PDF) into several slides
// plus longRead ("Zusatztext") companions, with a user-driven split step —
// heuristics only (no AI call — this tool stays fully offline), so getting
// the SLIDE boundaries right matters most here: reclassifying a longRead
// block's exact type (explain/quote/glossary/…) is easy to fix later in
// Bento's own editor (select text, pick a type) — but there's no equivalent
// "select and reassign" tool for ON-SLIDE content, so that split needs to
// be right going in.
const pasteTile = document.getElementById('pasteTile');
const pasteModal = document.getElementById('pasteModal');
const pasteModalClose = document.getElementById('pasteModalClose');
const pasteStep1 = document.getElementById('pasteStep1');
const pasteStep2 = document.getElementById('pasteStep2');
const pasteCatcher = document.getElementById('pasteCatcher');
const lrDoc = document.getElementById('lrDoc');
const lrPreview = document.getElementById('lrPreview');
const lrViewToggle = document.getElementById('lrViewToggle');
const lrGenerateBtn = document.getElementById('lrGenerateBtn');
const lrCtxMenu = document.getElementById('lrCtxMenu');
const lrCtxEndSlide = document.getElementById('lrCtxEndSlide');
const lrCtxToggleMode = document.getElementById('lrCtxToggleMode');

let lrBlockSeq = 1;
const newBlockId = () => 'pb' + (lrBlockSeq++);

function openPasteModal(){
  pasteStep1.style.display = '';
  pasteStep2.style.display = 'none';
  pasteCatcher.textContent = 'Hier klicken und einfügen…';
  pasteCatcher.dataset.filled = '0';
  pasteModal.classList.add('show');
  pasteCatcher.focus();
}
function closePasteModal(){ pasteModal.classList.remove('show'); lrCtxMenu.style.display = 'none'; }
pasteTile.addEventListener('click', openPasteModal);
pasteModalClose.addEventListener('click', closePasteModal);
pasteModal.addEventListener('click', (e) => { if (e.target === pasteModal) closePasteModal(); });

// A first click just focuses the catcher (so a real paste event fires
// there next) — the placeholder text disappears so it isn't accidentally
// merged into whatever's pasted.
pasteCatcher.addEventListener('focus', () => {
  if (pasteCatcher.dataset.filled !== '1') pasteCatcher.textContent = '';
});

pasteCatcher.addEventListener('paste', async (ev) => {
  ev.preventDefault();
  const cd = ev.clipboardData;
  if (!cd) return;
  const html = cd.getData('text/html');
  const plain = cd.getData('text/plain');
  // Direct image items (e.g. a single copied image, or some browsers'
  // image-in-clipboard for a copied selection) — these are actual bytes,
  // no CORS concern at all, unlike an <img src="https://…"> found in HTML.
  const imageFiles = [];
  for (const item of cd.items || []){
    if (item.type && item.type.startsWith('image/')){
      const file = item.getAsFile();
      if (file) imageFiles.push(file);
    }
  }
  pasteCatcher.dataset.filled = '1';
  pasteCatcher.textContent = 'Wird verarbeitet…';
  const imgStats = { found: 0, embedded: 0 };
  const blocks = await parsePastedContent(html, plain, imageFiles, imgStats);
  if (!blocks.length){
    pasteCatcher.textContent = 'Kein Text erkannt — nochmal versuchen.';
    pasteCatcher.dataset.filled = '0';
    return;
  }
  buildInitialDoc(blocks);
  pasteStep1.style.display = 'none';
  pasteStep2.style.display = '';
  lrDoc.style.display = '';
  lrPreview.style.display = 'none';
  lrViewToggle.classList.remove('active');
  if (imgStats.found > imgStats.embedded){
    const missing = imgStats.found - imgStats.embedded;
    toast(missing + ' von ' + imgStats.found + ' Bild(ern) konnten nicht geladen werden (Webseiten-Sicherheitsbeschränkung/CORS). Abhilfe: Bild einzeln kopieren (Rechtsklick → Bild kopieren) — oder zuerst in Word/Pages einfügen, dort alles erneut kopieren und hier einfügen.', 8000);
  } else if (imgStats.embedded > 0){
    toast(imgStats.embedded + ' Bild(er) übernommen');
  }
  lrViewToggle.textContent = 'Folienansicht';
});

/** Turns clipboard content into a flat ordered list — deliberately NOT
 *  trying to guess which paragraph is a heading/quote/etc: that guessing
 *  turned out unreliable in practice and produced a confusing starting
 *  point that was more work to undo than to build from scratch. Every
 *  paragraph becomes a plain, neutral entry — automatic TYPE guessing
 *  (which one becomes a slide vs. Zusatztext) is gone for good, that's a
 *  fully manual choice made in the continuous document view via the
 *  cursor-position context menu. What HTML IS still used for now: basic
 *  visual fidelity (bold/italic, heading size) and image POSITION — a
 *  paste that looked like a formatted page with inline images shouldn't
 *  turn into a wall of identical unstyled paragraphs with images dumped
 *  at the end; that's not "no parsing", that's throwing away information
 *  the clipboard actually offered. None of this feeds slide/Zusatztext
 *  decisions — it's purely how the text renders. Falls back to plain
 *  paragraph splitting (2+ linebreaks) only when no HTML flavour exists
 *  at all. `imgStats` (found/embedded counts) lets the caller show the
 *  user what actually happened instead of images silently vanishing. */
async function parsePastedContent(html, plain, imageFiles, imgStats){
  const blocks = [];

  if (html && html.trim()){
    const doc = new DOMParser().parseFromString(html, 'text/html');
    const nodes = doc.body ? Array.from(doc.body.querySelectorAll('h1,h2,h3,h4,h5,h6,p,li,blockquote,img')) : [];
    for (const node of nodes){
      const tag = node.tagName.toLowerCase();
      if (tag === 'img'){
        const src = node.getAttribute('src');
        if (!src) continue;
        imgStats.found++;
        const dataUrl = await tryFetchImageAsDataUrl(src);
        if (dataUrl){ imgStats.embedded++; blocks.push({ id: newBlockId(), kind: 'image', dataUrl, sourceUrl: src, retrievedAt: new Date().toISOString().slice(0, 10) }); }
        continue;
      }
      // skip a node whose text is fully covered by a nested node we'll
      // also visit separately (e.g. a <p> that only wraps an <img>, or a
      // <li> containing nothing but text already captured) — avoids
      // duplicate empty paragraphs; a real check is "does this element
      // have any of its OWN direct text", not just non-empty textContent.
      const html_ = sanitizeInlineHtml(node);
      if (!html_.trim()) continue;
      const level = /^h([1-6])$/.exec(tag);
      blocks.push({ id: newBlockId(), kind: 'text', html: html_, headingLevel: level ? +level[1] : 0 });
    }
  } else if (plain && plain.trim()){
    const paragraphs = plain.split(/\n{2,}/).map(p => p.replace(/[ \t]+/g, ' ').trim()).filter(Boolean);
    for (const text of paragraphs) blocks.push({ id: newBlockId(), kind: 'text', html: escHtml(text), headingLevel: 0 });
  }

  // Direct clipboard image files (an actually-copied image — no CORS
  // concern at all, unlike a URL found inside pasted HTML above) —
  // appended at the end; move them in the document like any other line.
  for (const file of imageFiles){
    const dataUrl = await fileToDataUrl(file).catch(() => null);
    imgStats.found++;
    if (dataUrl){ imgStats.embedded++; blocks.push({ id: newBlockId(), kind: 'image', dataUrl }); }
  }
  return blocks;
}

function escHtml(s){
  return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

/** Keeps ONLY a small whitelist of purely visual inline tags (bold/italic/
 *  underline) from a clipboard HTML node, discarding everything else
 *  (classes, ids, data attributes, links, spans carrying tracking/ad
 *  styling, etc.) — text formatting for legibility, not a general HTML
 *  passthrough. */
function sanitizeInlineHtml(node){
  const ALLOWED = { b: 1, strong: 1, i: 1, em: 1, u: 1 };
  const SKIP = { script: 1, style: 1 };
  const walk = (n) => {
    if (n.nodeType === 3) return escHtml(n.textContent);
    if (n.nodeType !== 1) return '';
    const tag = n.tagName.toLowerCase();
    if (SKIP[tag]) return '';
    const inner = Array.from(n.childNodes).map(walk).join('');
    return ALLOWED[tag] ? `<${tag}>${inner}</${tag}>` : inner;
  };
  return Array.from(node.childNodes).map(walk).join('').replace(/\s+/g, ' ').trim();
}

function fileToDataUrl(file){
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result));
    reader.onerror = reject;
    reader.readAsDataURL(file);
  });
}

/** Best-effort only — a URL from someone else's page very often has no
 *  CORS header allowing us to actually read its bytes, and some sites
 *  serve images via a blob: URL that's only valid on the ORIGINAL page's
 *  own tab, meaningless once copied elsewhere — neither is fixable from
 *  the browser side alone, which is why the optional PHP proxy (see
 *  proxy/image-proxy.php in this repo, and the settings link near the
 *  paste box) exists: CORS is a browser-enforced rule, not a server-to-
 *  server one — this version, unlike the plain-static bento-moodle-tools
 *  converter, runs as real PHP, so ?proxy= on this SAME file (see the
 *  dispatch block at the very top of this file) always handles it,
 *  no configuration needed. A failure here just means that one image
 *  quietly doesn't make it into the document — never blocks the rest
 *  of the paste; imgStats surfaces the count so it's not silent overall. */
async function tryFetchImageAsDataUrl(url){
  const direct = await tryOneFetch(url);
  if (direct) return direct;
  return await tryOneFetch(location.pathname + '?proxy=' + encodeURIComponent(url));
}
async function tryOneFetch(fetchUrl){
  try {
    const res = await fetch(fetchUrl, { mode: 'cors' });
    if (!res.ok) return null;
    const blob = await res.blob();
    if (!blob.type.startsWith('image/')) return null;
    return await fileToDataUrl(blob);
  } catch { return null; }
}

/** Builds the initial continuous document from the flat paste-parse
 *  result: a slide-break marker up front (there's always at least one
 *  slide), then one <p data-lr="text"> or <img data-lr="image"> per
 *  entry, in order. Everything from here on is edited directly in place —
 *  this only ever runs once, right after paste. */
function buildInitialDoc(blocks){
  lrDoc.innerHTML = '';
  lrDoc.appendChild(makeBreakMarker(1));
  for (const b of blocks){
    if (b.kind === 'image'){
      const img = document.createElement('img');
      img.src = b.dataUrl;
      img.dataset.lr = 'image';
      if (b.sourceUrl) img.dataset.sourceUrl = b.sourceUrl;
      if (b.retrievedAt) img.dataset.retrievedAt = b.retrievedAt;
      lrDoc.appendChild(img);
    } else if (b.html) {
      const p = document.createElement('p');
      p.innerHTML = b.html;
      p.dataset.lr = 'text';
      if (b.headingLevel) p.classList.add('lr-h' + b.headingLevel);
      lrDoc.appendChild(p);
    }
  }
  refreshSlideNumbers();
}

function makeBreakMarker(n){
  const el = document.createElement('div');
  el.className = 'lr-marker-break';
  el.dataset.lrMarker = 'break';
  el.contentEditable = 'false';
  el.textContent = '▪ Folie ' + n + ' beginnt';
  return el;
}
function makeModeMarker(toZusatz){
  const el = document.createElement('div');
  el.className = 'lr-marker-mode';
  el.dataset.lrMarker = toZusatz ? 'zusatz-on' : 'zusatz-off';
  el.contentEditable = 'false';
  el.textContent = toZusatz ? '↳ ab hier: Zusatztext' : '↳ ab hier: Folientext';
  return el;
}

/** Renumbers every break marker's label ("Folie N") and re-applies the
 *  .lr-zusatz shading to every text/image node between mode markers — run
 *  after ANY structural edit (marker inserted, node deleted) since both
 *  the slide count and the current on/off mode are derived by scanning
 *  the whole document top to bottom, not stored per node. */
function refreshSlideNumbers(){
  let slideNum = 0;
  let inZusatz = false;
  for (const node of Array.from(lrDoc.children)){
    if (node.dataset.lrMarker === 'break'){
      slideNum++;
      node.textContent = '▪ Folie ' + slideNum + ' beginnt';
      inZusatz = false; // a new slide always starts back in "Folieninhalt" mode
      continue;
    }
    if (node.dataset.lrMarker === 'zusatz-on'){ inZusatz = true; continue; }
    if (node.dataset.lrMarker === 'zusatz-off'){ inZusatz = false; continue; }
    node.classList.toggle('lr-zusatz', inZusatz);
  }
}

/** Splits whichever <p> the cursor is currently in in two at that exact
 *  position (Word-style "insert a break in the middle of a line") and
 *  returns the node the marker should be inserted after — if the cursor
 *  isn't inside a paragraph at all (e.g. between blocks, or on an image),
 *  just anchors on that node directly instead, nothing to split. */
function splitAtCursorForMarker(){
  const sel = window.getSelection();
  if (!sel || !sel.rangeCount) return lrDoc.lastElementChild;
  const range = sel.getRangeAt(0);
  if (!lrDoc.contains(range.startContainer)) return lrDoc.lastElementChild;
  let p = range.startContainer;
  if (p.nodeType === 3) p = p.parentElement;
  while (p && p.parentElement !== lrDoc) p = p.parentElement;
  if (!p || p.tagName !== 'P') return p || lrDoc.lastElementChild;

  const beforeRange = document.createRange();
  beforeRange.selectNodeContents(p);
  beforeRange.setEnd(range.startContainer, range.startOffset);
  const beforeText = beforeRange.toString();
  const afterText = p.textContent.slice(beforeText.length);

  if (!beforeText.trim() || !afterText.trim()) return p; // cursor at an edge — nothing meaningful to split, anchor on the whole paragraph

  const afterP = document.createElement('p');
  afterP.textContent = afterText;
  afterP.dataset.lr = 'text';
  p.textContent = beforeText;
  p.after(afterP);
  return p;
}

lrCtxEndSlide.addEventListener('click', () => {
  const anchor = splitAtCursorForMarker();
  anchor.after(makeBreakMarker(1));
  refreshSlideNumbers();
  lrCtxMenu.style.display = 'none';
});
lrCtxToggleMode.addEventListener('click', () => {
  const anchor = splitAtCursorForMarker();
  // figure out the CURRENT mode right at this point, to toggle it correctly
  let inZusatz = false;
  for (const node of Array.from(lrDoc.children)){
    if (node === anchor || node.contains?.(anchor)) break;
    if (node.dataset.lrMarker === 'break') inZusatz = false;
    else if (node.dataset.lrMarker === 'zusatz-on') inZusatz = true;
    else if (node.dataset.lrMarker === 'zusatz-off') inZusatz = false;
  }
  anchor.after(makeModeMarker(!inZusatz));
  refreshSlideNumbers();
  lrCtxMenu.style.display = 'none';
});

// Cursor-position context menu — Word-style: click (or move the cursor
// with arrow keys) anywhere in the document and a small menu appears
// right above wherever the caret now is.
function showCtxMenuAtCursor(){
  const sel = window.getSelection();
  if (!sel || !sel.rangeCount || !lrDoc.contains(sel.anchorNode)) { lrCtxMenu.style.display = 'none'; return; }
  const range = sel.getRangeAt(0).cloneRange();
  let rect = range.getClientRects()[0];
  if (!rect) rect = range.startContainer.nodeType === 1 ? range.startContainer.getBoundingClientRect() : null;
  if (!rect || (!rect.width && !rect.height)) { lrCtxMenu.style.display = 'none'; return; }
  lrCtxMenu.style.display = 'flex';
  const menuRect = lrCtxMenu.getBoundingClientRect();
  lrCtxMenu.style.left = Math.max(8, Math.min(rect.left, window.innerWidth - menuRect.width - 8)) + 'px';
  lrCtxMenu.style.top = Math.max(8, rect.top - menuRect.height - 8) + 'px';
}
lrDoc.addEventListener('click', showCtxMenuAtCursor);
lrDoc.addEventListener('keyup', (ev) => {
  if (ev.key.startsWith('Arrow')) showCtxMenuAtCursor();
});
document.addEventListener('click', (ev) => {
  if (!lrCtxMenu.contains(ev.target) && ev.target !== lrDoc && !lrDoc.contains(ev.target)) lrCtxMenu.style.display = 'none';
});

/** Read-only overview grouped by slide — what "Folienansicht" toggles to,
 *  computed fresh from the current document state each time it's opened
 *  rather than kept in sync live, since it's just a check, not a second
 *  place to edit from. */
function renderSlidePreview(){
  const blocks = parseDocToBlocks();
  lrPreview.innerHTML = '';
  let slideEl = null;
  let slideNum = 0;
  const ensureSlide = () => {
    if (slideEl) return slideEl;
    slideNum++;
    slideEl = document.createElement('div');
    slideEl.className = 'lr-preview-slide';
    const h = document.createElement('h4');
    h.textContent = 'Folie ' + slideNum;
    slideEl.appendChild(h);
    lrPreview.appendChild(slideEl);
    return slideEl;
  };
  for (const b of blocks){
    if (b.slideBreakBefore) slideEl = null;
    const s = ensureSlide();
    if (b.kind === 'image'){
      const img = document.createElement('img');
      img.src = b.dataUrl;
      img.style.cssText = 'max-width:100%;max-height:120px;border-radius:6px;margin-bottom:6px';
      s.appendChild(img);
    } else {
      const p = document.createElement('p');
      if (b.role === 'longread') p.className = 'lr-preview-zusatz';
      p.textContent = (b.role === 'longread' ? '(Zusatztext) ' : '') + b.text;
      s.appendChild(p);
    }
  }
  if (!lrPreview.children.length){
    lrPreview.innerHTML = '<p style="opacity:.6">Noch kein Inhalt.</p>';
  }
}
lrViewToggle.addEventListener('click', () => {
  const showingPreview = lrPreview.style.display !== 'none';
  if (showingPreview){
    lrPreview.style.display = 'none';
    lrDoc.style.display = '';
    lrViewToggle.classList.remove('active');
    lrViewToggle.textContent = 'Folienansicht';
  } else {
    renderSlidePreview();
    lrDoc.style.display = 'none';
    lrPreview.style.display = '';
    lrViewToggle.classList.add('active');
    lrViewToggle.textContent = 'Textansicht';
  }
});

/** Walks the continuous document top to bottom and reconstructs the same
 *  flat block shape buildDocFromBlocks already expects (kind/role/text/
 *  slideBreakBefore/dataUrl) — markers themselves never become blocks,
 *  they just set flags on whatever comes right after them. */
function parseDocToBlocks(){
  const blocks = [];
  let pendingBreak = false;
  let inZusatz = false;
  for (const node of Array.from(lrDoc.children)){
    if (node.dataset.lrMarker === 'break'){ pendingBreak = true; inZusatz = false; continue; }
    if (node.dataset.lrMarker === 'zusatz-on'){ inZusatz = true; continue; }
    if (node.dataset.lrMarker === 'zusatz-off'){ inZusatz = false; continue; }
    if (node.dataset.lr === 'image'){
      blocks.push({
        kind: 'image', dataUrl: node.getAttribute('src'), slideBreakBefore: pendingBreak,
        sourceUrl: node.dataset.sourceUrl, retrievedAt: node.dataset.retrievedAt,
      });
    } else {
      const text = (node.textContent || '').trim();
      if (text) blocks.push({ kind: 'text', role: inZusatz ? 'longread' : 'slide', text, slideBreakBefore: pendingBreak });
    }
    pendingBreak = false;
  }
  if (blocks.length) blocks[0].slideBreakBefore = true;
  return blocks;
}


function buildDocFromBlocks(blocks){
  const doc = blankBentoDoc('Eingefügter Text');
  doc.slides = [];
  doc.assets = {};
  let assetN = 1;
  const internImage = (dataUrl) => {
    const key = 'pasted' + (assetN++);
    doc.assets[key] = dataUrl;
    return 'asset:' + key;
  };

  let current = null;
  let slideTextCount = 0;
  const startSlide = () => {
    current = { id: 's' + (doc.slides.length + 1), background: '#FFFFFF', transition: 'none', elements: [], notes: '' };
    slideTextCount = 0;
    doc.slides.push(current);
  };
  const bodyBlockY = () => 60 + slideTextCount * 90;
  const ensureLongRead = () => { if (!current.longRead) current.longRead = { blocks: [] }; return current.longRead; };

  for (const block of blocks){
    if (block.slideBreakBefore || !current) startSlide();
    if (block.kind === 'image'){
      const imageEl = {
        id: uuid(), type: 'image', x: 240, y: 160, w: 800, h: 450, rotation: 0, opacity: 1,
        src: internImage(block.dataUrl), fit: 'contain', radius: 0,
      };
      if (block.sourceUrl) {
        imageEl.citation = { sourceUrl: block.sourceUrl, retrievedAt: block.retrievedAt || new Date().toISOString().slice(0, 10) };
      }
      current.elements.push(imageEl);
      continue;
    }
    const text = block.text.trim();
    if (!text) continue;
    if (block.role === 'longread'){
      ensureLongRead().blocks.push({ id: uuid(), type: 'explain', text });
    } else {
      const isTitle = slideTextCount === 0;
      current.elements.push({
        id: uuid(), type: 'text', x: 60, y: bodyBlockY(), w: 1160, h: isTitle ? 90 : 70, rotation: 0, opacity: 1,
        html: esc(text), fontSize: isTitle ? 40 : 22, fontFamily: 'system-ui, sans-serif', fontWeight: isTitle ? 700 : 400,
        color: doc.theme.color, align: 'left', valign: 'top', lineHeight: isTitle ? 1.2 : 1.4,
      });
      slideTextCount++;
    }
  }
  if (!doc.slides.length) startSlide();
  return doc;
}

lrGenerateBtn.addEventListener('click', () => {
  const doc = buildDocFromBlocks(parseDocToBlocks());
  const newItem = { baseName: 'Eingefuegter-Text', doc, slideCount: doc.slides.length, warnings: [] };
  items.push(newItem);
  renderItems();
  closePasteModal();
  toast(doc.slides.length + ' Folie(n) erstellt');
  queueCardDecision(newItem);
});

blankBtn.addEventListener('click', () => {
  const doc = blankBentoDoc();
  const newItem = { baseName: 'Neue-Praesentation', doc, slideCount: doc.slides.length, warnings: [] };
  items.push(newItem);
  renderItems();
  toast('Leere Präsentation erstellt');
  queueCardDecision(newItem);
});

demoBtn.addEventListener('click', () => {
  const b64 = document.getElementById('bento-demo-b64').textContent.replace(/\s+/g, '');
  const doc = JSON.parse(decodeURIComponent(escape(atob(b64))));
  doc.docId = (crypto.randomUUID ? crypto.randomUUID() : 'demo-' + Date.now());
  const newItem = { baseName: 'Bento-Showcase', doc, slideCount: doc.slides.length, warnings: [] };
  items.push(newItem);
  renderItems();
  toast('Demopräsentation geladen');
  queueCardDecision(newItem);
});
</script>
</body>
</html>
