<?php
// Mis Exámenes: sincronización entre el móvil Android y la app web.
//
//   GET  sync.php?c=CODIGO          -> {"ok":true,"updated":N,"json":"..."}  (updated 0 = no hay datos)
//   POST sync.php?c=CODIGO          body {"base":N,"json":"...","force":false}
//                                   -> {"ok":true,"updated":N}  o 409 {"conflict":true,"updated":N,"json":"..."}
//   GET  sync.php?ics=HUELLA        -> calendario .ics para suscribirse (solo lectura)
//
// El código es la llave de los datos: quien lo tenga puede leerlos y cambiarlos.
// Los datos se guardan en datos-sync/<sha256 del código>.php, que empieza con un
// "exit" de PHP para que nadie pueda descargarlos aunque el servidor ignore .htaccess.

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

const MAX_BYTES = 2000000;
const HEADER = "<?php exit; ?>\n";

$dir = __DIR__ . '/datos-sync';
if (!is_dir($dir)) {
    @mkdir($dir, 0700, true);
    @file_put_contents("$dir/.htaccess", "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    @file_put_contents("$dir/index.html", "");
}

function reply($code, $data) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function load($path) {
    if (!is_file($path)) return null;
    $raw = @file_get_contents($path);
    if ($raw === false) return null;
    if (strpos($raw, HEADER) === 0) $raw = substr($raw, strlen(HEADER));
    $o = json_decode($raw, true);
    return is_array($o) ? $o : null;
}

// ---------- Calendario de unos datos enviados por la app (botón "Añadir al calendario") ----------
if (($_GET['ics'] ?? '') === 'post' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode((string)($_POST['json'] ?? ''), true);
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: inline; filename="mis-examenes.ics"');
    echo ics(is_array($data) ? $data : []);
    exit;
}

// ---------- Calendario para suscribirse (solo lectura) ----------
if (isset($_GET['ics'])) {
    $id = (string)$_GET['ics'];
    if (!preg_match('/^[a-f0-9]{64}$/', $id)) { http_response_code(404); exit; }
    $doc = load("$dir/$id.php");
    $data = $doc ? json_decode($doc['json'] ?? '', true) : null;
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: inline; filename="mis-examenes.ics"');
    echo ics(is_array($data) ? $data : []);
    exit;
}

function ics_text($s) {
    $s = str_replace(["\\", ";", ",", "\r\n", "\n", "\r"], ["\\\\", "\\;", "\\,", "\\n", "\\n", ""], (string)$s);
    return $s;
}

function ics_line($line) {
    // Las líneas largas se doblan a 75 bytes sin partir caracteres UTF-8
    $out = '';
    while (strlen($line) > 75) {
        $cut = 75;
        while ($cut > 1 && (ord($line[$cut]) & 0xC0) === 0x80) $cut--;
        $out .= substr($line, 0, $cut) . "\r\n ";
        $line = substr($line, $cut);
    }
    return $out . $line . "\r\n";
}

function ics($d) {
    $subjects = [];
    foreach (($d['subjects'] ?? []) as $s) $subjects[(string)($s['id'] ?? '')] = (string)($s['name'] ?? '');
    $now = gmdate('Ymd\THis\Z');
    $out = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Mis Examenes//ES\r\nCALSCALE:GREGORIAN\r\nMETHOD:PUBLISH\r\n";
    $out .= ics_line("X-WR-CALNAME:Mis Exámenes");
    $out .= "REFRESH-INTERVAL;VALUE=DURATION:PT6H\r\nX-PUBLISHED-TTL:PT6H\r\n";
    $events = [];
    foreach (($d['exams'] ?? []) as $e) {
        $date = (string)($e['date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;
        $sub = $subjects[(string)($e['subjectId'] ?? '')] ?? '';
        $kind = ((int)($e['type'] ?? 0)) === 3 ? 'Recuperación' : 'Examen';
        $topics = trim((string)($e['topics'] ?? ''));
        $events[] = ['e' . (int)$e['id'], $date, "$kind: " . ($e['title'] ?? '') . ($sub !== '' ? " ($sub)" : ''), $topics !== '' ? "Temas: $topics" : ''];
    }
    foreach (($d['tasks'] ?? []) as $t) {
        $date = (string)($t['due'] ?? '');
        if (!empty($t['done']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;
        $sub = $subjects[(string)($t['subjectId'] ?? '')] ?? '';
        $events[] = ['t' . (int)$t['id'], $date, 'Entrega: ' . ($t['title'] ?? '') . ($sub !== '' ? " ($sub)" : ''), trim((string)($t['notes'] ?? ''))];
    }
    foreach ($events as $ev) {
        $start = str_replace('-', '', $ev[1]);
        $end = gmdate('Ymd', strtotime($ev[1] . ' 00:00:00 UTC') + 86400);
        $out .= "BEGIN:VEVENT\r\n";
        $out .= ics_line("UID:{$ev[0]}@mis-examenes");
        $out .= "DTSTAMP:$now\r\nDTSTART;VALUE=DATE:$start\r\nDTEND;VALUE=DATE:$end\r\nTRANSP:TRANSPARENT\r\n";
        $out .= ics_line('SUMMARY:' . ics_text($ev[2]));
        if ($ev[3] !== '') $out .= ics_line('DESCRIPTION:' . ics_text($ev[3]));
        $out .= "END:VEVENT\r\n";
    }
    return $out . "END:VCALENDAR\r\n";
}

// ---------- Datos ----------
$code = (string)($_GET['c'] ?? '');
if (!preg_match('/^[A-Za-z0-9]{16,64}$/', $code)) reply(400, ['error' => 'codigo']);
$path = $dir . '/' . hash('sha256', $code) . '.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $doc = load($path);
    reply(200, ['ok' => true, 'updated' => $doc ? (int)$doc['updated'] : 0, 'json' => $doc ? $doc['json'] : null]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') reply(405, ['error' => 'metodo']);

$body = file_get_contents('php://input', false, null, 0, MAX_BYTES + 1000);
if ($body === false || strlen($body) > MAX_BYTES) reply(413, ['error' => 'grande']);
$in = json_decode($body, true);
if (!is_array($in) || !isset($in['json']) || !is_string($in['json'])) reply(400, ['error' => 'formato']);
$check = json_decode($in['json'], true);
if (!is_array($check) || !isset($check['subjects']) || !is_array($check['subjects'])) reply(400, ['error' => 'formato']);

$fh = @fopen($path, 'c+');
if (!$fh) reply(500, ['error' => 'guardar']);
flock($fh, LOCK_EX);
$raw = stream_get_contents($fh);
if (strpos($raw, HEADER) === 0) $raw = substr($raw, strlen(HEADER));
$cur = $raw ? json_decode($raw, true) : null;
$curUpdated = is_array($cur) ? (int)$cur['updated'] : 0;
$base = (int)($in['base'] ?? 0);
if (empty($in['force']) && $curUpdated !== 0 && $curUpdated !== $base) {
    flock($fh, LOCK_UN);
    fclose($fh);
    reply(409, ['conflict' => true, 'updated' => $curUpdated, 'json' => $cur['json']]);
}
$updated = max((int)round(microtime(true) * 1000), $curUpdated + 1);
$doc = HEADER . json_encode(['updated' => $updated, 'json' => $in['json']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
ftruncate($fh, 0);
rewind($fh);
fwrite($fh, $doc);
fflush($fh);
flock($fh, LOCK_UN);
fclose($fh);
reply(200, ['ok' => true, 'updated' => $updated]);
