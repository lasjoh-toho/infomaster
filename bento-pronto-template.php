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
      Diese Seite wandelt <code>.pptx</code>-Präsentationen in HTML-Dateien um, die sich in
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
          <button type="button" class="bento-deck-link-btn" title="Link erzeugen (kopieren)" style="width:28px; height:28px; display:flex; align-items:center; justify-content:center; background:#1e293b; color:#93c5fd; border-radius:6px; border:none; cursor:pointer; font-size:14px;">🔗</button>
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
      bentoCopyToClipboard(url).then(function(){
        toast('🔗 Link kopiert');
      }).catch(function(){
        prompt('Link zum manuellen Kopieren:', url);
      });
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
    else if (sys) map[slot] = '#'+(sys.getAttribute('lastClr')||'000000');
  }
  return map;
}

function resolveColor(fillParent, themeColors, fallback){
  if (!fillParent) return fallback;
  const solid = first(fillParent, 'a:solidFill');
  if (!solid) return fallback;
  const srgb = first(solid, 'a:srgbClr');
  if (srgb) return '#'+srgb.getAttribute('val');
  const scheme = first(solid, 'a:schemeClr');
  if (scheme){
    const val = scheme.getAttribute('val');
    const aliasMap = { tx1:'dk1', bg1:'lt1', tx2:'dk2', bg2:'lt2' };
    const key = aliasMap[val] || val;
    if (themeColors[key]) return themeColors[key];
  }
  return fallback;
}

function hasNoFill(fillParent){
  if (!fillParent) return false;
  return !!first(fillParent, 'a:noFill');
}

function extractFrame(spEl){
  const spPr = first(spEl, 'p:spPr') || first(spEl, 'a:spPr');
  if (!spPr) return null;
  const xfrm = first(spPr, 'a:xfrm');
  if (!xfrm) return null;
  const off = first(xfrm, 'a:off');
  const ext = first(xfrm, 'a:ext');
  if (!off || !ext) return null;
  return {
    x: emuToPx(parseInt(off.getAttribute('x'),10)),
    y: emuToPx(parseInt(off.getAttribute('y'),10)),
    w: emuToPx(parseInt(ext.getAttribute('cx'),10)),
    h: emuToPx(parseInt(ext.getAttribute('cy'),10)),
    rotation: rotToDeg(xfrm.getAttribute('rot'))
  };
}

function placeholderType(spEl){
  const nvSpPr = first(spEl, 'p:nvSpPr');
  if (!nvSpPr) return null;
  const nvPr = first(nvSpPr, 'p:nvPr');
  if (!nvPr) return null;
  const ph = first(nvPr, 'p:ph');
  if (!ph) return null;
  return ph.getAttribute('type') || 'body';
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

// Build inline html for a txBody, applying per-run b/i/u and paragraph-level align/color/size from first run
function extractText(txBody, themeColors){
  const paras = all(txBody, 'a:p');
  if (!paras.length) return null;
  let html = [];
  let style = { fontSize: 32, color: '#111111', bold: false, align: 'left', fontFamily: null };
  let styleSet = false;
  let any = false;

  for (const p of paras){
    const pPr = first(p, 'a:pPr');
    if (pPr && !styleSet){
      const algn = pPr.getAttribute('algn');
      if (algn === 'ctr') style.align = 'center';
      else if (algn === 'r') style.align = 'right';
      else style.align = 'left';
    }
    const runs = all(p, 'a:r');
    let line = '';
    for (const r of runs){
      const t = first(r, 'a:t');
      const text = t ? t.textContent : '';
      if (!text) continue;
      any = true;
      const rPr = first(r, 'a:rPr');
      let seg = esc(text);
      let bold = false, italic = false, underline = false;
      if (rPr){
        bold = rPr.getAttribute('b') === '1';
        italic = rPr.getAttribute('i') === '1';
        underline = (rPr.getAttribute('u')||'none') !== 'none';
        if (!styleSet){
          const sz = rPr.getAttribute('sz');
          if (sz) style.fontSize = ptToPx(parseInt(sz,10)/100);
          const col = resolveColor(rPr, themeColors, null);
          if (col) style.color = col;
          const latin = first(rPr, 'a:latin');
          if (latin && latin.getAttribute('typeface')) style.fontFamily = latin.getAttribute('typeface');
          style.bold = bold;
          styleSet = true;
        }
      }
      if (bold) seg = '<b>'+seg+'</b>';
      if (italic) seg = '<i>'+seg+'</i>';
      if (underline) seg = '<u>'+seg+'</u>';
      line += seg;
    }
    html.push(line);
  }
  if (!any) return null;
  return { html: html.join('<br>'), style };
}

const GEOM_MAP = {
  ellipse: 'ellipse',
  triangle: 'triangle',
  roundRect: 'rect',
  rect: 'rect'
};

async function loadImageAsset(zip, embedId, relsMap, slideDir, assets, assetCounter){
  const target = relsMap[embedId];
  if (!target) return null;
  // resolve relative path against slideDir (e.g. ../media/image1.png against ppt/slides/)
  const parts = (slideDir + '/' + target).split('/');
  const stack = [];
  for (const part of parts){
    if (part === '..') stack.pop();
    else if (part === '.' || part === '') continue;
    else stack.push(part);
  }
  const path = stack.join('/');
  const file = zip.file(path);
  if (!file) return null;
  const ext = (path.split('.').pop()||'png').toLowerCase();
  const mimeMap = { png:'image/png', jpg:'image/jpeg', jpeg:'image/jpeg', gif:'image/gif', bmp:'image/bmp', svg:'image/svg+xml', tif:'image/tiff', tiff:'image/tiff', wmf:'image/wmf', emf:'image/emf' };
  const mime = mimeMap[ext] || 'application/octet-stream';
  if (mime === 'application/octet-stream' || ext === 'wmf' || ext === 'emf'){
    return null; // unsupported vector legacy formats — skip embedding
  }
  const base64 = await file.async('base64');
  const key = 'img' + (assetCounter.n++);
  assets[key] = 'data:'+mime+';base64,'+base64;
  return key;
}

function parseRels(relsXmlText){
  const map = {};
  if (!relsXmlText) return map;
  const doc = new DOMParser().parseFromString(relsXmlText, 'application/xml');
  const rels = Array.from(doc.getElementsByTagName('Relationship'));
  for (const r of rels){
    map[r.getAttribute('Id')] = r.getAttribute('Target');
  }
  return map;
}

async function convertPptx(file, log){
  const zip = await JSZip.loadAsync(file);

  const presXmlText = await zip.file('ppt/presentation.xml').async('string');
  const presDoc = new DOMParser().parseFromString(presXmlText, 'application/xml');
  const sldSzEl = first(presDoc, 'p:sldSz');
  const slideW = sldSzEl ? emuToPx(parseInt(sldSzEl.getAttribute('cx'),10)) : 1280;
  const slideH = sldSzEl ? emuToPx(parseInt(sldSzEl.getAttribute('cy'),10)) : 720;

  const presRelsText = await zip.file('ppt/_rels/presentation.xml.rels').async('string');
  const presRelsMap = parseRels(presRelsText);

  const sldIds = all(presDoc, 'p:sldId').map(el => el.getAttribute('r:id'));
  const slidePaths = sldIds.map(id => 'ppt/' + presRelsMap[id]).filter(Boolean);

  // theme (best effort — usually one theme file shared by the default master)
  let themeColors = {};
  const themeFile = zip.file(/ppt\/theme\/theme1\.xml/i)[0];
  if (themeFile){
    const themeText = await themeFile.async('string');
    const themeDoc = new DOMParser().parseFromString(themeText, 'application/xml');
    themeColors = parseThemeColors(themeDoc);
  }

  // title from core properties
  let title = file.name.replace(/\.pptx$/i, '');
  const coreFile = zip.file('docProps/core.xml');
  if (coreFile){
    const coreText = await coreFile.async('string');
    const coreDoc = new DOMParser().parseFromString(coreText, 'application/xml');
    const dcTitle = coreDoc.getElementsByTagName('dc:title')[0];
    if (dcTitle && dcTitle.textContent.trim()) title = dcTitle.textContent.trim();
  }

  const assets = {};
  const assetCounter = { n: 1 };
  const slides = [];
  const warnings = new Set();

  for (let i = 0; i < slidePaths.length; i++){
    const slidePath = slidePaths[i];
    const slideDir = slidePath.substring(0, slidePath.lastIndexOf('/'));
    const slideFile = zip.file(slidePath);
    if (!slideFile) continue;
    const slideXmlText = await slideFile.async('string');
    const slideDoc = new DOMParser().parseFromString(slideXmlText, 'application/xml');

    const relsPath = slideDir + '/_rels/' + slidePath.substring(slidePath.lastIndexOf('/')+1) + '.rels';
    const relsFile = zip.file(relsPath);
    const relsMap = relsFile ? parseRels(await relsFile.async('string')) : {};

    // background
    let background = themeColors.lt1 || '#FFFFFF';
    const bg = first(slideDoc, 'p:bg');
    if (bg){
      const bgPr = first(bg, 'p:bgPr');
      if (bgPr){
        const col = resolveColor(bgPr, themeColors, null);
        if (col) background = col;
      }
    }

    const spTree = first(slideDoc, 'p:spTree');
    const elements = [];
    let elCounter = 1;

    if (spTree){
      // iterate direct meaningful children in document order
      const nodes = Array.from(spTree.childNodes).filter(n =>
        n.nodeType === 1 && ['p:sp','p:pic','p:graphicFrame','p:cxnSp'].includes(n.tagName)
      );

      for (const node of nodes){
        const tag = node.tagName;
        const elId = `s${i+1}_e${elCounter++}`;

        if (tag === 'p:sp' || tag === 'p:cxnSp'){
          const phType = placeholderType(node);
          let frame = extractFrame(node);
          if (!frame) frame = fallbackFrame(phType, slideW, slideH);

          const spPr = first(node, 'p:spPr');
          const prstGeom = spPr ? first(spPr, 'a:prstGeom') : null;
          const geomPrst = prstGeom ? prstGeom.getAttribute('prst') : null;
          const shapeFill = spPr ? resolveColor(spPr, themeColors, null) : null;
          const noFill = spPr ? hasNoFill(spPr) : true;

          const txBody = first(node, 'p:txBody');
          const textInfo = txBody ? extractText(txBody, themeColors) : null;

          const emitsShape = (geomPrst && geomPrst !== 'rect' ) ? true : (!noFill && shapeFill);

          if (emitsShape){
            let ln = spPr ? first(spPr, 'a:ln') : null;
            let stroke = 'none', strokeWidth = 0;
            if (ln){
              const lnColor = resolveColor(ln, themeColors, null);
              if (lnColor){ stroke = lnColor; strokeWidth = emuToPx(parseInt(ln.getAttribute('w')||'0',10)) || 1; }
            }
            elements.push({
              id: elId, type: 'shape',
              shape: GEOM_MAP[geomPrst] || 'rect',
              x: frame.x, y: frame.y, w: frame.w, h: frame.h,
              rotation: frame.rotation, opacity: 1,
              fill: noFill ? 'transparent' : (shapeFill || '#CCCCCC'),
              stroke, strokeWidth,
              radius: geomPrst === 'roundRect' ? 12 : 0
            });
          }

          if (textInfo){
            const textElId = emitsShape ? elId + 't' : elId;
            elements.push({
              id: textElId, type: 'text',
              x: frame.x, y: frame.y, w: frame.w, h: frame.h,
              rotation: frame.rotation, opacity: 1,
              html: textInfo.html,
              fontSize: textInfo.style.fontSize,
              fontFamily: textInfo.style.fontFamily || 'system-ui, sans-serif',
              fontWeight: textInfo.style.bold ? 700 : 400,
              color: textInfo.style.color,
              align: textInfo.style.align,
              valign: 'top',
              lineHeight: 1.2
            });
          }

          if (!emitsShape && !textInfo){
            // nothing extractable (empty placeholder) — skip
          }
        }

        else if (tag === 'p:pic'){
          const frame = extractFrame(node) || fallbackFrame(null, slideW, slideH);
          const blipFill = first(node, 'p:blipFill');
          const blip = blipFill ? first(blipFill, 'a:blip') : null;
          const embedId = blip ? blip.getAttribute('r:embed') : null;
          if (embedId){
            const key = await loadImageAsset(zip, embedId, relsMap, slideDir, assets, assetCounter);
            if (key){
              elements.push({
                id: elId, type: 'image',
                x: frame.x, y: frame.y, w: frame.w, h: frame.h,
                rotation: frame.rotation, opacity: 1,
                src: 'asset:' + key, fit: 'cover', radius: 0
              });
            } else {
              warnings.add('Ein Bild in einem nicht unterstützten Format (z.B. WMF/EMF) wurde übersprungen.');
            }
          }
        }

        else if (tag === 'p:graphicFrame'){
          warnings.add('Diagramme/Tabellen (graphicFrame) werden derzeit nicht übernommen — als Platzhalter markiert.');
          const frame = extractFrame(node) || fallbackFrame(null, slideW, slideH);
          elements.push({
            id: elId, type: 'text',
            x: frame.x, y: frame.y, w: frame.w, h: frame.h,
            rotation: frame.rotation, opacity: 1,
            html: '[Diagramm/Tabelle aus PowerPoint — manuell nachbauen]',
            fontSize: 20, fontFamily: 'system-ui, sans-serif', fontWeight: 500,
            color: '#999999', align: 'left', valign: 'top', lineHeight: 1.3
          });
        }
      }
    }

    slides.push({
      id: 's' + (i+1),
      background,
      transition: 'none',
      elements,
      notes: ''
    });
  }

  if (!slides.length){
    slides.push({
      id: 's1', background: '#101418', transition: 'none', notes: '',
      elements: [{ id:'t1', type:'text', x:96, y:260, w: slideW-192, h:160, rotation:0, opacity:1,
        html:'(Keine Folien gefunden)', fontSize:48, fontFamily:'system-ui, sans-serif', fontWeight:700,
        color:'#ffffff', align:'left', valign:'top', lineHeight:1.1 }]
    });
  }

  const doc = {
    format: 'bento/slides',
    version: 1,
    docId: uuid(),
    title,
    size: { width: slideW, height: slideH },
    theme: {
      background: themeColors.lt1 || '#FFFFFF',
      color: themeColors.dk1 || '#111111',
      accent: themeColors.accent1 || '#FF9E5E',
      fontFamily: 'system-ui, sans-serif'
    },
    slides,
    modified: new Date().toISOString()
  };
  if (Object.keys(assets).length) doc.assets = assets;

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

    if (!isPptx && !isJson && !isHtml){
      const card = document.createElement('div');
      card.className = 'card error';
      cardsEl.appendChild(card);
      card.innerHTML = `
        <div class="card-top">
          <div>
            <div class="card-name">${esc(file.name)}</div>
            <div class="card-meta">nicht unterstützt</div>
          </div>
          <span class="pill err">.ppt</span>
        </div>
        <div class="err-msg">
          ${isPpt
            ? 'Altes binäres PowerPoint-Format — in PowerPoint über „Speichern unter → .pptx" konvertieren und erneut ablegen.'
            : 'Keine .pptx-, .json- oder .bento.html-Datei erkannt.'}
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
      <button type="button" class="cd-view" style="width:auto;">👁 Erst betrachten</button>
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
  box.querySelector('.paste-modal-close').addEventListener('click', close);
  box.querySelector('.cd-view').addEventListener('click', close);
  nameInput.addEventListener('keydown', (ev) => { if (ev.key === 'Escape') close(); });

  box.querySelector('.cd-download').addEventListener('click', async () => {
    applyName();
    renderItems();
    try { await downloadItemAsHtml(it); toast('Bento-Datei heruntergeladen'); }
    catch(e){ console.error(e); toast('Herunterladen fehlgeschlagen: ' + (e.message || e)); }
    close();
  });

  const saveBtn = box.querySelector('.cd-save');
  saveBtn.addEventListener('click', async () => {
    applyName();
    saveBtn.disabled = true;
    saveBtn.classList.add('busy');
    const errBox = box.querySelector('.cd-error');
    errBox.style.display = 'none';
    try {
      const data = await saveItemAsDeck(it);
      it.savedFile = data.filename;
      it.savedUrl = data.url;
      toast('Bearbeitbar gespeichert ✓');
      renderItems();
      close();
    } catch(e){
      console.error(e);
      errBox.style.display = 'block';
      errBox.textContent = 'Speichern fehlgeschlagen: ' + (e.message || e);
      saveBtn.disabled = false;
      saveBtn.classList.remove('busy');
    }
  });
}

function buildItemCard(it){
  const card = document.createElement('div');
  card.className = 'card' + (it.merged ? ' merged' : '');
  card.draggable = true;
  const json = JSON.stringify(it.doc, null, 2);
  const outName = it.baseName + '.bento.json';
  const savedActionsHtml = `
      <a href="${esc(bentoPlayUrlFor(it.savedUrl))}" target="_blank" rel="noopener" title="Präsentation starten (Endlos-Loop, 8s/Folie)">▶</a>
      <a href="${esc(it.savedUrl)}" target="_blank" rel="noopener" title="Bearbeiten (im vollen Editor)">✎</a>
      <button data-action="shrink" title="Medien verkleinern">🗜</button>
      ${it.slideCount > 1 ? `<button data-action="split" title="In Teile aufteilen">✂️</button>` : ''}
      <a href="${esc(it.savedUrl)}" download title="Als .bento.html herunterladen">⬇</a>
      <button data-action="delete-saved" title="Löschen">✕</button>
      <button data-action="copy-link" title="Link erzeugen (kopieren)">🔗</button>`;
  const unsavedActionsHtml = `
      <button class="primary" data-action="html" title="Als .bento.html herunterladen">⬇</button>
      <button data-action="save-server" title="Auf Server speichern (für Monitor)">📺<span class="btn-progress"></span></button>
      <button data-action="save-deck" title="Bearbeitbar auf Server speichern">📝<span class="btn-progress"></span></button>
      <button data-action="open" title="Direkt öffnen (nicht gespeichert)">↗</button>
      <button data-action="download" title="Nur JSON herunterladen">📄</button>
      <button data-action="copy" title="JSON kopieren">📋</button>
      <button data-action="shrink" title="Medien verkleinern">🗜</button>
      ${it.slideCount > 1 ? `<button data-action="split" title="In Teile aufteilen">✂️</button>` : ''}`;
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
    <div class="actions actions-icons">${it.savedFile ? savedActionsHtml : unsavedActionsHtml}</div>
    <div class="fetch-note" style="display:none" class="err-msg"></div>
    <div class="save-server-result" style="display:none; margin-top:10px; background:#0f2a1c; border:1px solid #1f6b3f; border-radius:6px; padding:10px 12px; font-size:12px; color:#bbf7d0;"></div>
    <div class="save-deck-result" style="display:none; margin-top:10px; background:#0b1f3a; border:1px solid #1e3a63; border-radius:6px; padding:10px 12px; font-size:12px; color:#bfdbfe;"></div>`;

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

  const htmlBtn = card.querySelector('[data-action="html"]');
  if (htmlBtn) htmlBtn.addEventListener('click', async () => {
    htmlBtn.disabled = true;
    htmlBtn.classList.add('busy');
    try{
      await downloadItemAsHtml(it);
      toast('Bento-Datei heruntergeladen');
    } catch(e){
      console.error(e);
      let note = card.querySelector('.fetch-note');
      note.style.display = 'block';
      note.className = 'err-msg';
      note.textContent = 'Weder die eingebettete Bento-App noch ein Live-Nachladen hat funktioniert (' +
        (e.message || e) + '). Bitte stattdessen „Nur JSON herunterladen" bzw. „JSON kopieren" ' +
        'nutzen und über About → Replace document from JSON… in bento.page/slides einfügen.';
    } finally {
      htmlBtn.disabled = false;
      htmlBtn.classList.remove('busy');
    }
  });

  const saveServerBtn = card.querySelector('[data-action="save-server"]');
  if (saveServerBtn) saveServerBtn.addEventListener('click', async () => {
    saveServerBtn.disabled = true;
    saveServerBtn.classList.add('busy');
    const resultBox = card.querySelector('.save-server-result');
    try{
      // Als schreibgeschützte Datei bauen (eigene Kopie, das Original in
      // items[] bleibt weiter bearbeitbar): so startet die gespeicherte
      // Version am Monitor automatisch als Slideshow statt im Editor zu
      // landen (siehe playerMode/?autostart in der Bento-App).
      const kioskDoc = Object.assign({}, it.doc, { readonly: true });
      const { filename, html } = await buildBentoHtml(kioskDoc, it.baseName);
      const fd = new FormData();
      fd.append('bento_save_html', html);
      fd.append('bento_filename', filename);
      fd.append('bento_kind', 'monitor');
      const resp = await fetch('', { method: 'POST', body: fd });
      const data = await resp.json();
      if (!resp.ok || !data.ok) throw new Error(data.error || ('Serverfehler (' + resp.status + ')'));
      const monitorUrl = data.url + '?autostart=1&loop';
      resultBox.style.display = 'block';
      resultBox.innerHTML =
        '✅ Gespeichert. Diese Adresse im Infomaster-Dashboard bei einem Monitor als Inhalt „Webseite (URL)" eintragen:<br>' +
        '<div style="display:flex; gap:6px; align-items:center; margin-top:6px;">' +
        '<input type="text" readonly value="' + esc(monitorUrl) + '" class="save-server-url" style="flex:1; font-size:11px; padding:6px; background:#04110a; color:#bbf7d0; border:1px solid #1f6b3f; border-radius:4px;">' +
        '<button type="button" class="save-server-copy" style="width:auto; padding:6px 10px; font-size:11px;">Kopieren</button>' +
        '</div>';
      resultBox.querySelector('.save-server-copy').addEventListener('click', async () => {
        await navigator.clipboard.writeText(monitorUrl);
        toast('Adresse kopiert');
      });
      toast('Für Monitor gespeichert ✓');
    } catch(e){
      console.error(e);
      resultBox.style.display = 'block';
      resultBox.style.background = '#2a0f0f';
      resultBox.style.borderColor = '#6b1f1f';
      resultBox.style.color = '#fecaca';
      resultBox.textContent = 'Fehler beim Speichern: ' + (e.message || e);
    } finally {
      saveServerBtn.disabled = false;
      saveServerBtn.classList.remove('busy');
    }
  });

  const saveDeckBtn = card.querySelector('[data-action="save-deck"]');
  if (saveDeckBtn) saveDeckBtn.addEventListener('click', async () => {
    saveDeckBtn.disabled = true;
    saveDeckBtn.classList.add('busy');
    try{
      const data = await saveItemAsDeck(it);
      it.savedFile = data.filename;
      it.savedUrl = data.url;
      toast('Bearbeitbar gespeichert ✓');
      renderItems(); // wechselt ab jetzt auf denselben Button-Satz wie ein Deck-Banner
    } catch(e){
      console.error(e);
      const resultBox = card.querySelector('.save-deck-result');
      resultBox.style.display = 'block';
      resultBox.style.background = '#2a0f0f';
      resultBox.style.borderColor = '#6b1f1f';
      resultBox.style.color = '#fecaca';
      resultBox.textContent = 'Fehler beim Speichern: ' + (e.message || e);
      saveDeckBtn.disabled = false;
      saveDeckBtn.classList.remove('busy');
    }
  });

  const openBtn = card.querySelector('[data-action="open"]');
  if (openBtn) openBtn.addEventListener('click', () => {
    // Open the tab SYNCHRONOUSLY, in direct response to the click — once
    // an `await` happens first, some browsers no longer treat the later
    // window.open() as user-initiated and silently block it.
    const win = window.open('', '_blank');
    if (!win) {
      toast('Popup blockiert — bitte Popups für diese Seite erlauben');
      return;
    }
    win.document.write(
      '<!doctype html><meta charset="utf-8"><title>Bento wird geladen…</title>' +
      '<body style="font-family:system-ui,sans-serif;padding:2.5rem;color:#667">Bento wird geladen…</body>'
    );
    openBtn.disabled = true;
    openBtn.classList.add('busy');
    (async () => {
      try{
        const { html } = await buildBentoHtml(it.doc, it.baseName);
        // Writing the document directly (not via a blob: URL) sidesteps a
        // Firefox issue where blob: URLs created in an opaque-origin page
        // (e.g. a locally opened file — they come out as "blob:null/…")
        // can fail to load when opened in a new tab.
        win.document.open();
        win.document.write(html);
        win.document.close();
      } catch(e){
        console.error(e);
        try{
          win.document.open();
          win.document.write(
            '<!doctype html><meta charset="utf-8"><pre style="white-space:pre-wrap;font-family:system-ui,sans-serif;padding:2rem;color:#c33">' +
            'Fehler beim Laden: ' + esc(String(e.message || e)) + '</pre>'
          );
          win.document.close();
        } catch{}
        let note = card.querySelector('.fetch-note');
        note.style.display = 'block';
        note.className = 'err-msg';
        note.textContent = 'Konnte die Bento-App nicht laden (' + (e.message || e) + ').';
      } finally {
        openBtn.disabled = false;
        openBtn.classList.remove('busy');
      }
    })();
  });

  const downloadJsonBtn = card.querySelector('[data-action="download"]');
  if (downloadJsonBtn) downloadJsonBtn.addEventListener('click', () => download(outName, json));
  const copyJsonBtn = card.querySelector('[data-action="copy"]');
  if (copyJsonBtn) copyJsonBtn.addEventListener('click', async () => {
    await navigator.clipboard.writeText(json);
    toast('JSON kopiert');
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
  if (copyLinkBtn) copyLinkBtn.addEventListener('click', async () => {
    const url = bentoPlayUrlFor(it.savedUrl);
    try { await bentoCopyToClipboard(url); toast('🔗 Link kopiert'); }
    catch { prompt('Link zum manuellen Kopieren:', url); }
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
  shrinkBtn.addEventListener('click', () => {
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
