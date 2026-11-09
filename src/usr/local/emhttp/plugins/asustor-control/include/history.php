<?php
/* history.php - the History tab's data. POST only (Unraid's prologue checks the token; a GET gets 405). READ-ONLY: reads the
 * chart file asustor-hwmon keeps in RAM (/var/local/emhttp/plugins/asustor-control/hwmon-history.csv, one line every 30 s,
 * "ts,pwm,cpu,drive,rpm", the last 12 hours). action=history&range=3600|21600|43200 ->
 * {"ok":true,"now":epoch,"range":s,"t":[...],"cpu":[...],"drv":[...],"pwm":[percent,...],"rpm":[...],"warn":45,"crit":55} */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); header('Allow: POST'); exit; }
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/acs.php';

/* Unraid's disk temperature thresholds (Settings, Disk Settings), as the Overview uses them */
function acs_thresholds(): array {
  $w = 45; $c = 55;
  foreach (@file(acs_root() . '/boot/config/plugins/dynamix/dynamix.cfg', FILE_IGNORE_NEW_LINES) ?: [] as $l) {
    if (preg_match('/^hot="?(\d+)"?/', $l, $m)) $w = (int)$m[1];
    if (preg_match('/^max="?(\d+)"?/', $l, $m)) $c = (int)$m[1];
  }
  return [$w, $c];
}

function acs_history_answer(int $range, int $now): array {
  $o = ['t' => [], 'cpu' => [], 'drv' => [], 'pwm' => [], 'rpm' => []];
  foreach (@file(acs_state() . '/hwmon-history.csv', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
    $f = explode(',', $l);
    if (count($f) < 5 || !ctype_digit($f[0]) || (int)$f[0] < $now - $range) continue;
    $n = function ($v, $max) { return ctype_digit($v) && (int)$v <= $max ? (int)$v : null; };
    $o['t'][] = (int)$f[0];
    $o['cpu'][] = ($v = $n($f[2], 150)) ? $v : null;    // 0: no reading
    $o['drv'][] = ($v = $n($f[3], 150)) ? $v : null;
    $o['pwm'][] = ($v = $n($f[1], 255)) !== null ? (int)round($v * 100 / 255) : null;
    $o['rpm'][] = ($v = $n($f[4], 20000)) ? $v : null;
  }
  [$w, $c] = acs_thresholds();
  return ['ok' => true, 'now' => $now, 'range' => $range, 'warn' => $w, 'crit' => $c] + $o;
}

try {
  if (($_POST['action'] ?? '') !== 'history') { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'unknown action']); exit; }
  $range = (int)($_POST['range'] ?? 3600);
  if (!in_array($range, [3600, 21600, 43200], true)) $range = 3600;
  echo json_encode(acs_history_answer($range, time()), JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'history failed']);
}
