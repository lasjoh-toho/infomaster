<?php
$id = $_GET['id'] ?? '1';
$configFile = 'config.json';
$uploadBase = 'media/';

$config = ["screens" => []];
if (file_exists($configFile)) {
    $json = json_decode(file_get_contents($configFile), true);
    if ($json) $config = $json;
}
$s = $config['screens'][$id] ?? null;

function getPlaylist($type, $content, $uploadBase, $hiddenFiles = []) {
    if ($type && strpos($type, 'folder:') === 0) {
        $folder = substr($type, 7);
        $path = $uploadBase . $folder;
        if (is_dir($path)) {
            $files = array_diff(scandir($path), ['.', '..']);
            $list = [];
            foreach ($files as $f) { 
                if (in_array($f, $hiddenFiles)) continue; // im Ordner ausgeblendete Datei ueberspringen
                $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
                if(in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'])) {
                    $list[] = $path . '/' . $f; 
                }
            }
            natcasesort($list);
            return array_values($list);
        }
    }
    return [];
}

function getHiddenFilesFor($type, $config) {
    if ($type && strpos($type, 'folder:') === 0) {
        $folder = substr($type, 7);
        return $config['folder_hidden_files'][$folder] ?? [];
    }
    return [];
}

$playlistA = $s ? getPlaylist($s['type'], $s['content'] ?? '', $uploadBase, getHiddenFilesFor($s['type'] ?? '', $config)) : [];
$playlistB = $s ? getPlaylist($s['typeB'], $s['contentB'] ?? '', $uploadBase, getHiddenFilesFor($s['typeB'] ?? '', $config)) : [];
$duration = (int)($s['duration'] ?? 10);
// Pass nextcloud URLs to JS for async fetching
$ncUrlA = ($s && $s['type'] === 'nextcloud') ? ($s['content'] ?? '') : '';
$ncUrlB = ($s && ($s['typeB'] ?? '') === 'nextcloud') ? ($s['contentB'] ?? '') : '';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Monitor View - <?php echo $id; ?></title>
    <style>
        body, html { margin: 0; padding: 0; width: 100%; height: 100%; overflow: hidden; background: #000; }

        /* Base container: always fills the logical content area */
        #main-container {
            display: flex;
            overflow: hidden;
            position: absolute;
        }
        .split-h { flex-direction: column; }
        .split-v { flex-direction: row; }
        .pane { flex: 1; overflow: hidden; position: relative; background: #000; }
        iframe, img { width: 100%; height: 100%; border: none; display: block; object-fit: contain; }

        /* PDF Styling */
        .pdf-wrapper { width: 100%; height: 100%; position: relative; overflow: hidden; background: #fff; }
        .pdf-content {
            width: 108%; height: 2000vh; position: absolute;
            top: -56px; left: -4%; border: none; pointer-events: none;
        }

        /* 0° - Querformat normal */
        body.orient-0 #main-container {
            width: 100vw; height: 100vh;
            top: 0; left: 0;
            transform: none;
        }
        /* 180° - Querformat kopfüber */
        body.orient-180 #main-container {
            width: 100vw; height: 100vh;
            top: 0; left: 0;
            transform: rotate(180deg);
            transform-origin: center center;
        }
        /* 90° / 270° - Hochformat: Container ist 100vh breit, 100vw hoch.
           Wir positionieren seinen Mittelpunkt auf den Viewport-Mittelpunkt,
           dann rotieren um die eigene Mitte. */
        body.orient-90 #main-container,
        body.orient-270 #main-container {
            width: 100vh;
            height: 100vw;
            top: calc(50vh - 50vw);
            left: calc(50vw - 50vh);
            transform-origin: center center;
        }
        body.orient-90  #main-container { transform: rotate(90deg);  }
        body.orient-270 #main-container { transform: rotate(270deg); }

        /* Vorschau-Modus im Infomaster: keine Rotation, kein Offset */
        body.is-preview #main-container {
            position: relative !important;
            width: 100% !important;
            height: 100% !important;
            transform: none !important;
            top: auto !important;
            left: auto !important;
        }
        body.is-preview { pointer-events: none; overflow: hidden; display: block; }

        /* Splash-Screen: zeigt kurz nach dem Laden die Monitor-ID gross im Bild an, damit
           bei der Installation/beim Kabel-Check vor Ort sofort erkennbar ist, welcher
           physische Ausgang welche Monitor-ID zeigt. Blendet sich nach ein paar Sekunden
           per Fade wieder aus - der eigentliche Inhalt laedt schon dahinter. */
        #splash-screen {
            position: fixed; inset: 0; z-index: 9999;
            background: #000; color: #fff;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            font-family: Arial, Helvetica, sans-serif;
            transition: opacity 0.6s ease;
        }
        #splash-screen .splash-number { font-size: 35vw; font-weight: bold; line-height: 1; }
        #splash-screen .splash-label { font-size: 2.2vw; color: #94a3b8; margin-top: 1vw; letter-spacing: 0.1em; text-transform: uppercase; }
    </style>
</head>
<body class="<?php echo isset($_GET['preview']) ? 'is-preview' : 'real-monitor orient-'.($s['orient']??'0'); ?>">

    <?php if (!isset($_GET['preview'])): ?>
    <div id="splash-screen">
        <div class="splash-number"><?php echo htmlspecialchars($id); ?></div>
        <div class="splash-label">Monitor-ID</div>
    </div>
    <?php endif; ?>


    <div id="main-container" class="<?php echo ($s && $s['split']!=='none') ? 'split-'.$s['split'] : ''; ?>">
        <div id="paneA" class="pane"></div>
        <div id="paneB" class="pane" style="display:<?php echo ($s && $s['split']!=='none') ? 'block' : 'none'; ?>;"></div>
    </div>

<script>
    const durationSec = <?php echo $duration; ?>;
    let playlistA = <?php echo json_encode($playlistA); ?>;
    let playlistB = <?php echo json_encode($playlistB); ?>;
    const ncUrlA  = <?php echo json_encode($ncUrlA); ?>;
    const ncUrlB  = <?php echo json_encode($ncUrlB); ?>;
    let idxA = 0;
    let idxB = 0;
    let currentHash = "";

    // Load Nextcloud playlists asynchronously
    async function loadNextcloudPlaylist(shareUrl) {
        try {
            const resp = await fetch('nextcloud_proxy.php?url=' + encodeURIComponent(shareUrl));
            return await resp.json();
        } catch(e) { return []; }
    }

    async function checkUpdate() {
        try {
            const resp = await fetch('config.json', { method: 'HEAD', cache: 'no-cache' });
            const newHash = resp.headers.get('Last-Modified') + resp.headers.get('Content-Length');
            if (currentHash && newHash !== currentHash) { location.reload(); }
            currentHash = newHash;
        } catch (e) {}
    }

    function updatePane(paneId, type, url, playlist, index) {
        const pane = document.getElementById(paneId);
        if (type === 'url') {
            if(!pane.querySelector('iframe') || pane.querySelector('iframe').src !== url) {
                pane.innerHTML = `<iframe src="${url}"></iframe>`;
            }
        } else if (type.startsWith('folder:')) {
            if (playlist.length > 0) {
                const file = playlist[index % playlist.length];
                if (file.toLowerCase().endsWith('.pdf')) {
                    // Scroll-Distanz: 100vh pro 5 Sekunden Dauer
                    const scrollDist = Math.max(100, (durationSec / 5) * 100);
                    pane.innerHTML = `
                        <div class="pdf-wrapper">
                            <style>
                                @keyframes dynScroll {
                                    0% { transform: translateY(0); }
                                    15% { transform: translateY(0); }
                                    85% { transform: translateY(-${scrollDist}vh); }
                                    100% { transform: translateY(-${scrollDist}vh); }
                                }
                            </style>
                            <iframe src="${file}#toolbar=0&navpanes=0&scrollbar=0&view=FitW" 
                                    class="pdf-content" 
                                    style="animation: dynScroll ${durationSec}s linear forwards;">
                            </iframe>
                        </div>`;
                } else {
                    pane.innerHTML = `<img src="${file}?t=${Date.now()}">`;
                }
            } else {
                pane.innerHTML = '<div style="color:#222;font-size:12px;padding:20px;">Ordner leer</div>';
            }
        }
    }

    function cycle() {
        const config = <?php echo json_encode($s); ?>;
        if(!config) return;
        idxA++; idxB++;
        updatePane('paneA', config.type, config.content, playlistA, idxA);
        if(config.split !== 'none') updatePane('paneB', config.typeB, config.contentB, playlistB, idxB);
    }

    const config = <?php echo json_encode($s); ?>;
    if(config) {
        // Splash-Screen mit der Monitor-ID: nach 5s ausblenden - der eigentliche Inhalt ist
        // bis dahin schon im Hintergrund geladen (Promise.all unten laeuft parallel).
        const splashEl = document.getElementById('splash-screen');
        if (splashEl) {
            setTimeout(() => {
                splashEl.style.opacity = '0';
                setTimeout(() => splashEl.remove(), 600);
            }, 5000);
        }
        // Load Nextcloud playlists first if needed, then start
        Promise.all([
            ncUrlA ? loadNextcloudPlaylist(ncUrlA) : Promise.resolve(playlistA),
            ncUrlB ? loadNextcloudPlaylist(ncUrlB) : Promise.resolve(playlistB)
        ]).then(([plA, plB]) => {
            playlistA = plA;
            playlistB = plB;
            updatePane('paneA', config.type, config.content, playlistA, idxA);
            if(config.split !== 'none') updatePane('paneB', config.typeB, config.contentB, playlistB, idxB);
            setInterval(cycle, durationSec * 1000);
            setInterval(checkUpdate, 5000);
            // Refresh Nextcloud playlists every 5 minutes
            if(ncUrlA || ncUrlB) setInterval(async () => {
                if(ncUrlA) playlistA = await loadNextcloudPlaylist(ncUrlA);
                if(ncUrlB) playlistB = await loadNextcloudPlaylist(ncUrlB);
            }, 300000);
        });
    }
</script>
</body>
</html>