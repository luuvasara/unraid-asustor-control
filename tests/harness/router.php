<?php
/* Local render harness for AsustorControl.page (build host only; NOT the Unraid webGui). Written 2026-10-27.
 * serve.sh sets it up. What it mimics: the .page header split (header above "---", body below), the content wrapper
 * (<html class="Theme--<theme>">, #displaybox, .content) and the dynamix stylesheets in DefaultPageLayout.php order,
 * followed by themes/<theme>.css. The page body runs unmodified; `plugin version` is answered by a stub on PATH that
 * reads this repository's .plg. include/ajax.php is NOT run: its actions are answered from the made-up fixtures that
 * tests/harness/mkfixture.py writes (no value comes from this machine or from a NAS). */
$W = getenv('HARNESS_WORK'); $theme = getenv('THEME') ?: 'black'; $P = getenv('PLUGIN_SRC');
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($uri === '/plugins/asustor-control/include/ajax.php') {
  header('Content-Type: application/json');
  $map = ['getStatus' => 'status', 'getHistory' => 'history', 'getLog' => 'log', 'getLcdConfig' => 'lcdcfg', 'getLeds' => 'leds'];
  $a = $_POST['action'] ?? '';
  echo isset($map[$a]) ? file_get_contents("$W/fx/{$map[$a]}.json") : json_encode(['status' => 'ok']);   // actions: no-op
  return true;
}
if ($uri === '/harness/jquery.js') { header('Content-Type: text/javascript'); readfile(getenv('JQUERY')); return true; }
if ($uri !== '/' && $uri !== '/Utilities/AsustorControl') return false;   // static files from the work webroot

$raw = file_get_contents("$P/AsustorControl.page");
$body = explode("\n---\n", $raw, 2)[1];
?><!DOCTYPE html>
<html lang="en" class="Theme--<?=$theme?>">
<head><meta charset="utf-8"><title>Utilities/AsustorControl (local harness)</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php foreach (['default-fonts','default-cases','font-awesome','default-color-palette','default-base','default-dynamix',"themes/$theme"] as $c):
  if (is_file("$W/webroot/webGui/styles/$c.css")): ?>
<link type="text/css" rel="stylesheet" href="/webGui/styles/<?=$c?>.css">
<?php endif; endforeach; ?>
<script src="/harness/jquery.js"></script>
</head>
<body>
<div id="displaybox"><div class="content">
<?php /* runs this repository's own page body, as Unraid's PageBuilder does; local harness only */ eval('?>' . $body); ?>
</div></div>
</body></html>
