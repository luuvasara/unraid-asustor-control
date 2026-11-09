#!/bin/bash
# shellcheck disable=SC2015,SC2016  # "check && ok || bad" is the test idiom; php -r code is single-quoted on purpose
# The tabs of the built package with Unraid's real stylesheets in headless Chrome, all four themes, on the made-up AS6706T of
# tests/mkserver.sh; the LCD and LED daemons' answers come from the made-up fixtures of tests/harness/mkfixture.py (the ajax
# endpoint is replaced by a stub), so nothing reads this machine's hardware. Then the endpoints (history, report, the Main page
# labels), and the same pages on another board (no drawing, no labels, said so). OUT=<dir> keeps the screenshots.
set -uo pipefail
HERE=$(cd "$(dirname "$0")/.." && pwd); NAME=asustor-control
OUT=${OUT:-$HERE/tests/.work/shots}; mkdir -p "$OUT"
# shellcheck disable=SC1091
. "$HERE/kit/render/render-lib.sh"
PLUGIN_ROOT=$HERE "$HERE/kit/render/fetch-webgui.sh" >/dev/null
W=$HERE/tests/.work/render; rm -rf "$W"; mkdir -p "$W/bin"
fail=0; ok() { echo "ok   $*"; }; bad() { echo "FAIL $*"; fail=1; }
VER=$(sed -n 's/^ *<!ENTITY version *"\([^"]*\)">.*/\1/p' "$HERE/$NAME.plg")
printf '#!/bin/sh\necho %s\n' "$VER" >"$W/bin/plugin"; chmod 755 "$W/bin/plugin"
python3 -I "$HERE/tests/harness/mkfixture.py" "$W/fx" >/dev/null
K=$HERE/tests/.work/server-r; bash "$HERE/tests/mkserver.sh" "$K" || { bad fixture; exit 1; }
export PATH="$W/bin:$PATH" DRIVEMAP_ROOT=$K AC_SYS=$K/sys AC_PROC=$K/proc AC_ETC=$K/etc AC_KERNEL=6.18.58-Unraid AC_BIN_LSPCI=$K/bin/lspci AC_BIN_LSUSB=$K/bin/lsusb AC_BIN_DMESG=$K/bin/dmesg
TABS="Overview=AsustorControlOverview,LCD=AsustorControlLcd,LEDs and Buttons=AsustorControlLeds,History=AsustorControlHistory,Hardware=AsustorControlHardware,Report=AsustorControlReport,About=AsustorControlAbout"
stub() {   # the LCD/LED daemons' answers: the ajax endpoint replaced by a stub on made-up fixtures
  cat >"$1/plugins/$NAME/include/ajax.php" <<PHP
<?php
header('Content-Type: application/json');
\$map = ['getStatus' => 'status', 'getLog' => 'log', 'getLcdConfig' => 'lcdcfg', 'getLeds' => 'leds'];
\$a = \$_POST['action'] ?? '';
echo isset(\$map[\$a]) ? file_get_contents("$W/fx/{\$map[\$a]}.json") : json_encode(['status' => 'ok']);
PHP
}
for theme in black white gray azure; do
  web=$W/web-$theme; stage_web "$web" || exit 1; stub "$web"
  port=$(free_port); start_harness "$web" "$port" "$theme" "$TABS"; echo "$port" >"$W/port-$theme"
  for t in 1:overview 2:lcd 3:leds 4:history 5:hardware 6:report 7:about; do
    shot "http://127.0.0.1:$port/?tab=${t%%:*}" "$OUT/$NAME-${t#*:}-$theme.png"
    [ -s "$OUT/$NAME-${t#*:}-$theme.png" ] || bad "$theme: no screenshot of ${t#*:}"
  done
  dom "http://127.0.0.1:$port/?tab=1" "$W/dom-$theme.html"; D=$W/dom-$theme.html
  [ "$(grep -o 'class="enc-bay ' "$D" | wc -l)" = 6 ] && grep -q 'data-enc-unit="none"' "$D" && grep -q 'rear (seen from behind)' "$D" && ok "$theme: Overview: the drawing, six bays, front and rear" || bad "$theme: drawing"
  grep -q '43 °C in Bay 5' "$D" && grep -q '6 in 6 bays, 1 in standby' "$D" && grep -q 'not confirmed: bay N is taken' "$D" && ok "$theme: Overview: drives, hottest, bay order not confirmed" || bad "$theme: unit facts"
  grep -q 'Bay map' "$D" && grep -q 'name="MAIN_BADGES"' "$D" && ok "$theme: Overview: bay map and settings" || bad "$theme: bay map/settings"
  grep -q 'id="st-fan-rpm">[0-9]* RPM' "$D" && grep -q 'nvme1n1 (NVMe)' "$D" && grep -q 'id="lcd-line1">Disk:43C' "$D" && ok "$theme: Overview: hardware monitor, drive list with NVMe, LCD preview (live)" || bad "$theme: live status"
  grep -q 'id="ac-bay-list"' "$D" && grep -q 'id="cfg-bay-confirmed"' "$D" && grep -q 'id="cfg-lcd-port"' "$D" && ok "$theme: LCD and LEDs and Buttons tabs with Bay order confirmed" || bad "$theme: lcd/leds"
  grep -q 'id="ac-history"' "$D" && grep -q 'class="uplot' "$D" && grep -q 'Hottest drive (°C)' "$D" && ok "$theme: History: graphs and table" || bad "$theme: history"
  grep -q 'ASMedia ASM1166' "$D" && grep -q 'port 1 (ata1)' "$D" && grep -q 'The CPU has no idle states' "$D" && grep -q 'Phoenix Technologies Ltd. V1.21' "$D" && ok "$theme: Hardware: controller, ports, known issues, BIOS" || bad "$theme: hardware"
  grep -q 'Report unsupported hardware' "$D" && grep -q "$VER" "$D" && grep -q 'Asustor Control is by the Asustor Control project' "$D" && grep -q 'General Public License' "$D" && ok "$theme: Report and About" || bad "$theme: report/about"
  if grep -E "PHP (Warning|Fatal|Parse|Notice|Deprecated)" "$web.log" | head -5; then bad "$theme: PHP errors"; fi
done
port=$(cat "$W/port-black"); E=http://127.0.0.1:$port/plugins/$NAME
[ "$(curl -s -o /dev/null -w '%{http_code}' "$E/include/history.php")" = 405 ] && [ "$(curl -s -o /dev/null -w '%{http_code}' "$E/include/report.php")" = 405 ] && ok "endpoints: GET refused" || bad "GET"
H=$(curl -s -d action=history -d range=3600 "$E/include/history.php")
php -r '$j = json_decode($argv[1], true); exit($j["ok"] && count($j["t"]) >= 119 && count($j["t"]) <= 121 && max($j["drv"]) === 57 && $j["warn"] === 45 && max($j["pwm"]) > 52 ? 0 : 1);' "$H" && ok "history endpoint: 1 h of 30 s samples, the ramp, Unraid's warning line" || bad "history: $(head -c 300 <<<"$H")"
Rj=$(curl -s -d action=collect "$E/include/report.php")
php -r '$j = json_decode($argv[1], true); $t = $j["text"] ?? ""; exit($j["ok"] && strpos($t, "### plugin view (asustor-control)") !== false && strpos($t, "bay Bay 3: sdd") !== false && strpos($t, "other drive nvme1n1") !== false && strpos($t, "nas-example") === false && !preg_match("/AS6\\d\\d[0-9a-f]{5}/", $t) && $j["model"] === "Asustor AS6706T" ? 0 : 1);' "$Rj" && ok "report endpoint: collected, plugin view, no hostname or serial" || bad "report: $(head -c 400 <<<"$Rj")"
curl -s -d action=bays "$E/kit/enclosures.php" | grep -q '"sdd":{"bay":"Bay 3","unit":"Asustor AS6706T"' && ok "Main page labels endpoint" || bad "bays endpoint"
curl -s -d action=view "$E/kit/enclosures.php" | grep -q '"Bay 4":{"device":"sde","slot":"Disk 3","temp":"standby"' && ok "live view endpoint" || bad "view endpoint"
[ -f "$W/web-black/plugins/$NAME/AsustorControlBadges.page" ] && ok "Main page labels page in the package" || bad "badges page"
render_cleanup; PIDS=()
# another board: no drawing, no labels, the page says why; every tab still opens without a PHP error
K2=$HERE/tests/.work/server-other; NOTAS=1 bash "$HERE/tests/mkserver.sh" "$K2" || bad "fixture 2"
export DRIVEMAP_ROOT=$K2 AC_SYS=$K2/sys
web=$W/web-other; stage_web "$web" || exit 1; stub "$web"
port=$(free_port); start_harness "$web" "$port" black "$TABS"
dom "http://127.0.0.1:$port/?tab=1" "$W/dom-other.html"; D=$W/dom-other.html
shot "http://127.0.0.1:$port/?tab=1" "$OUT/$NAME-overview-other-black.png"
! grep -q 'class="enc-bay ' "$D" && grep -q 'This server is not an Asustor AS6706T' "$D" && grep -q 'Checked on an AS6706T only' "$D" && grep -q 'not an AS6706T, or its SATA controller is not seen' "$D" && ok "another board: no drawing, said so; Hardware says so too" || bad "another board"
[ "$(curl -s -d action=bays "http://127.0.0.1:$port/plugins/$NAME/kit/enclosures.php")" = '{"ok":true,"bays":{}}' ] && ok "another board: no Main page labels" || bad "another board: labels"
if grep -E "PHP (Warning|Fatal|Parse|Notice|Deprecated)" "$web.log" | head -5; then bad "another board: PHP errors"; fi
render_cleanup
[ $fail = 0 ] && echo "ALL PASSED" || echo FAILED
exit $fail
