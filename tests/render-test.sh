#!/bin/bash
# shellcheck disable=SC2015,SC2016
# The four tabs of the built package (Asustor Control, LCD Settings, LEDs and Buttons, About) with Unraid's real
# stylesheets in headless Chrome, all four themes. The ajax endpoint is replaced by a stub that answers from the made-up
# fixtures of tests/harness/mkfixture.py, so nothing reads this machine's hardware. OUT=<dir> keeps the screenshots.
set -uo pipefail
HERE=$(cd "$(dirname "$0")/.." && pwd); NAME=asustor-control
OUT=${OUT:-$HERE/tests/.work/shots}; mkdir -p "$OUT"
# shellcheck disable=SC1091
. "$HERE/kit/render/render-lib.sh"
PLUGIN_ROOT=$HERE "$HERE/kit/render/fetch-webgui.sh"
W=$HERE/tests/.work/render; rm -rf "$W"; mkdir -p "$W/bin"
fail=0; ok() { echo "ok   $*"; }; bad() { echo "FAIL $*"; fail=1; }
VER=$(sed -n 's/^ *<!ENTITY version *"\([^"]*\)">.*/\1/p' "$HERE/$NAME.plg")
printf '#!/bin/sh\necho %s\n' "$VER" >"$W/bin/plugin"; chmod 755 "$W/bin/plugin"
python3 -I "$HERE/tests/harness/mkfixture.py" "$W/fx" >/dev/null
export PATH="$W/bin:$PATH"
TABS="Asustor Control=AsustorControl,LCD Settings=AsustorControlLcd,LEDs and Buttons=AsustorControlLeds,About=AsustorControlAbout"
for theme in black white gray azure; do
  web=$W/web-$theme; stage_web "$web" || exit 1
  cat >"$web/plugins/$NAME/include/ajax.php" <<PHP
<?php
header('Content-Type: application/json');
\$map = ['getStatus' => 'status', 'getHistory' => 'history', 'getLog' => 'log', 'getLcdConfig' => 'lcdcfg', 'getLeds' => 'leds'];
\$a = \$_POST['action'] ?? '';
echo isset(\$map[\$a]) ? file_get_contents("$W/fx/{\$map[\$a]}.json") : json_encode(['status' => 'ok']);
PHP
  port=$(free_port); start_harness "$web" "$port" "$theme" "$TABS"
  for tab in 1 2 3 4; do
    shot "http://127.0.0.1:$port/?tab=$tab" "$OUT/$NAME-$theme-$tab.png"
    [ -s "$OUT/$NAME-$theme-$tab.png" ] && ok "$theme tab $tab rendered" || bad "no screenshot $theme tab $tab"
  done
  dom "http://127.0.0.1:$port/?tab=4" "$W/dom-$theme.html"
  grep -q 'kit-about' "$W/dom-$theme.html" && grep -q "$VER" "$W/dom-$theme.html" && ok "$theme: About shows the version" || bad "$theme: About version"
  grep -q 'Asustor Control is by the Asustor Control project' "$W/dom-$theme.html" && grep -q 'General Public License' "$W/dom-$theme.html" && ok "$theme: About names project, licence and source" || bad "$theme: About attribution"
  grep -q 'Latest changes' "$W/dom-$theme.html" && grep -q 'New About tab' "$W/dom-$theme.html" && ok "$theme: About lists the latest changes" || bad "$theme: About changes"
  if grep -E "PHP (Warning|Fatal|Parse)" "$web.log" | head -5; then bad "$theme: PHP errors"; fi
done
render_cleanup
[ $fail = 0 ] && echo "ALL PASSED" || echo FAILED
exit $fail
