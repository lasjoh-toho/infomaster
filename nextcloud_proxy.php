<?php
/**
 * nextcloud_proxy.php
 * Fetches the file listing from a public Nextcloud share URL and returns
 * a JSON array of direct media file URLs.
 *
 * Usage: nextcloud_proxy.php?url=https://cloud.example.com/s/SHAREID
 */
header('Content-Type: application/json');
header('Cache-Control: max-age=300');

$shareUrl = $_GET['url'] ?? '';
if (!$shareUrl) { die('[]'); }

// Build the WebDAV endpoint URL for the public share
$shareUrl = rtrim($shareUrl, '/');
// Extract the token (last path segment)
$token = basename(parse_url($shareUrl, PHP_URL_PATH));

// Build Nextcloud public WebDAV URL
$parsed = parse_url($shareUrl);
$baseHost = $parsed['scheme'] . '://' . $parsed['host'] . (isset($parsed['port']) ? ':' . $parsed['port'] : '');
$davUrl = $baseHost . '/public.php/webdav/';

// PROPFIND request
$ch = curl_init($davUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => 'PROPFIND',
    CURLOPT_USERPWD        => $token . ':',
    CURLOPT_HTTPHEADER     => ['Depth: 1', 'Content-Type: application/xml'],
    CURLOPT_POSTFIELDS     => '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:displayname/><d:getcontenttype/></d:prop></d:propfind>',
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_TIMEOUT        => 10,
]);
$xml = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!$xml || $httpCode >= 400) { die('[]'); }

// Parse XML response
$allowedExts = ['jpg','jpeg','png','gif','webp','pdf','mp4'];
$files = [];

try {
    $dom = new SimpleXMLElement($xml);
    $dom->registerXPathNamespace('d', 'DAV:');
    $responses = $dom->xpath('//d:response');
    foreach($responses as $resp) {
        $href = (string)($resp->xpath('d:href')[0] ?? '');
        if (!$href) continue;
        $ext = strtolower(pathinfo($href, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExts)) continue;
        $filename = basename(urldecode($href));
        // Build direct download URL
        $directUrl = $baseHost . '/index.php/s/' . $token . '/download?files=' . urlencode($filename);
        $files[] = $directUrl;
    }
} catch(Exception $e) {
    die('[]');
}

sort($files);
echo json_encode(array_values($files));
