#!/bin/bash
# serve.sh <port> [theme] - local render harness for AsustorControl.page (build host only, NOT the Unraid webGui).
# Written 2026-10-27. Builds made-up fixtures with tests/harness/mkfixture.py and serves the page through router.php
# with php -S on 127.0.0.1. Unraid's own stylesheets and jQuery are NOT in this repository:
#   WEBGUI_COPY  a local dir holding webGui/styles/ (default-*.css, font-awesome.css, the fonts, themes/<theme>.css),
#                copied from an Unraid 7.x install (/usr/local/emhttp/webGui/styles)
#   JQUERY       a local jquery.min.js (Unraid bundles jQuery 3.x in dynamix.js)
# Work files go to tests/harness/.work (git-ignored). Screenshots: tests/harness/shot.cjs.
set -euo pipefail
H=$(cd "$(dirname "$0")" && pwd); P=$(cd "$H/../.." && pwd)
PORT=${1:?port}; THEME=${2:-black}
WEBGUI_COPY=${WEBGUI_COPY:?set WEBGUI_COPY to a dir with webGui/styles copied from Unraid 7.x}
JQUERY=${JQUERY:?set JQUERY to a local jquery.min.js}
[ -f "$WEBGUI_COPY/webGui/styles/default-base.css" ] || { echo "WEBGUI_COPY incomplete: $WEBGUI_COPY"; exit 1; }
[ -f "$JQUERY" ] || { echo "JQUERY not found: $JQUERY"; exit 1; }
W=$H/.work/$PORT; rm -rf "$W"; mkdir -p "$W/webroot/plugins" "$W/bin"
ln -s "$WEBGUI_COPY/webGui" "$W/webroot/webGui"
ln -s "$P/src/usr/local/emhttp/plugins/asustor-control" "$W/webroot/plugins/asustor-control"
# `plugin version <plg>` stub: the page asks Unraid's plugin command for its version; answer from this repo's .plg
cat >"$W/bin/plugin" <<EOF
#!/bin/sh
sed -n 's/^ *<!ENTITY version *"\([^"]*\)">.*/\1/p' "$P/asustor-control.plg"
EOF
chmod 755 "$W/bin/plugin"
python3 -I "$H/mkfixture.py" "$W/fx"
echo "harness: http://127.0.0.1:$PORT/  theme=$THEME work=$W"
exec env PATH="$W/bin:$PATH" HARNESS_WORK="$W" PLUGIN_SRC="$P/src/usr/local/emhttp/plugins/asustor-control" THEME="$THEME" \
  JQUERY="$JQUERY" php -S "127.0.0.1:$PORT" -t "$W/webroot" "$H/router.php"
