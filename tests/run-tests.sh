#!/bin/bash
# shellcheck disable=SC2015,SC2231,SC2012,SC2016  # "check && ok || bad" is the test idiom; php/python code is single-quoted on purpose
# run-tests.sh - offline checks; touches no NAS, no serial port and no hardware.
# Checks: every script parses, PHP lints, the .plg is well-formed XML, its version/md5 match the
# package in packages/, its URLs point at this repository, and tools/hwlog-report.py runs on a sample log
# (made-up values).
set -euo pipefail
HERE=$(cd "$(dirname "$0")/.." && pwd)
NAME=asustor-control
PLG=$HERE/$NAME.plg
fail=0; ok() { echo "ok   $*"; }; bad() { echo "FAIL $*"; fail=1; }

for f in "$HERE"/src/etc/rc.d/rc.$NAME "$HERE"/src/usr/local/bin/asustor-* "$HERE"/build.sh "$0" "$HERE"/tests/test-leds.sh; do
  bash -n "$f" && ok "bash -n $(basename "$f")" || bad "bash -n $f"
done
if command -v php >/dev/null; then
  for f in "$HERE"/src/usr/local/emhttp/plugins/$NAME/*.page "$HERE"/src/usr/local/emhttp/plugins/$NAME/include/*.php; do
    php -l "$f" >/dev/null 2>&1 && ok "php -l $(basename "$f")" || bad "php -l $f"
  done
else echo "skip php -l (php not installed)"; fi

python3 -c 'import sys,xml.dom.minidom as m; m.parse(sys.argv[1])' "$PLG" && ok "plg is well-formed XML" || bad "plg XML"
VER=$(sed -n 's/^ *<!ENTITY version *"\([^"]*\)">.*/\1/p' "$PLG")
MD5=$(sed -n 's/^ *<!ENTITY md5 *"\([^"]*\)">.*/\1/p' "$PLG")
[[ $VER =~ ^[0-9]{4}\.[0-9]{2}\.[0-9]{2}[a-z]?$ ]] && ok "version $VER (YYYY.MM.DD[a-z])" || bad "version format $VER"
PKG=$HERE/packages/$NAME-$VER-x86_64-1.txz
if [ -f "$PKG" ]; then
  [ "$(md5sum <"$PKG" | cut -d' ' -f1)" = "$MD5" ] && ok "package md5 matches the .plg" || bad "package md5 != .plg &md5;"
  [ "$(cut -d' ' -f1 "$PKG.md5")" = "$MD5" ] && ok ".md5 file matches" || bad ".md5 file"
else bad "missing $PKG (run ./build.sh)"; fi
grep -q '<!ENTITY gitURL *"https://raw.githubusercontent.com/luuvasara/unraid-asustor-control/main">' "$PLG" && ok "gitURL" || bad "gitURL"
grep -q 'pluginURL="&pluginURL;"' "$PLG" && ok "pluginURL attribute" || bad "pluginURL attribute"
grep -q '<URL>&pkgURL;</URL>' "$PLG" && grep -q '<MD5>&md5;</MD5>' "$PLG" && ok "payload URL + MD5" || bad "payload URL/MD5"
[ "$(ls "$HERE"/packages/*.txz 2>/dev/null | wc -l)" -le 2 ] && ok "packages/ holds the current and the previous package at most" || bad "packages/ holds more than the current and the previous package"
# About tab and the boot trap: the pre-install cleanup compares with &version; and keeps the current txz
grep -q 'case $f in \*/asustor-control-&version;-x86_64-1.txz) ;;' "$PLG" && ok "pre-install keeps the current version's txz" || bad "pre-install cleanup does not keep the current txz"
grep -q 'kit/install-notes.sh "&version;"' "$PLG" && ok "post-install prints the changes box" || bad "post-install does not call install-notes.sh"
[ "$(sed -n '/^<CHANGES>/{n;p;q}' "$PLG")" = "###$VER" ] && ok "first <CHANGES> entry is $VER" || bad "first <CHANGES> entry is not $VER"
[ -s "$HERE/icon.png" ] && grep -q '/main/icon.png<' "$HERE/plugins/$NAME.xml" && grep -q "<MinVer>$(sed -n 's/^.*min=\"\([^\"]*\)\".*/\1/p' "$PLG" | head -1)</MinVer>" "$HERE/plugins/$NAME.xml" && ok "icon and Community Applications template (MinVer = .plg min)" || bad "icon or template"
LIST=$(tar -tJf "$PKG")   # not piped into grep -q: with pipefail its early exit can fail the pipeline
grep -q "plugins/$NAME/AsustorControlAbout.page" <<<"$LIST" && ok "package holds the About page" || bad "package has no About page"

# hwlog-report.py on a two-row sample (format: docs/hwlog-format.md)
W=$(mktemp -d); trap 'rm -rf "$W"' EXIT
H=$(sed -n 's/^HEADER="\([^"]*\)".*/\1/p' "$HERE"/src/usr/local/bin/asustor-hwmon | head -1)
if [ -n "$H" ]; then
  { echo "$H"
    echo "1767225600,133,1694,51,53,2800000,0,51,CPU,nvme0=40;nvme1=44,parity=35;disk1=34;flash=*,2"
    echo "1767225610,200,2257,60,62,2800000,0,58,Disk,nvme0=41;nvme1=58,parity=36;disk1=*;flash=*,9"
  } >"$W/hwlog-2026-01-01.csv"
  if python3 -I "$HERE"/tools/hwlog-report.py "$W/hwlog-2026-01-01.csv" >"$W/out" 2>&1; then ok "hwlog-report.py runs on a sample day file"; else bad "hwlog-report.py: $(tail -3 "$W/out")"; fi
else echo "skip hwlog-report.py (no HEADER= in asustor-hwmon)"; fi

# asustor-hwmon log-dir hardening (fake sysroot, ASUSTOR_TEST_FN hook): planted or raced-in symlinks (day file, events
# file, hwlog dir, asustor-control dir) are never written through, process names from /proc are reduced to [A-Za-z0-9._-]
HWMON=$HERE/src/usr/local/bin/asustor-hwmon
mksr() {   # mksr <dir>: fake sysroot with an it87 hwmon chip and one buffered sample row
  mkdir -p "$1/sys/class/hwmon/hwmon0" "$1/var/local/emhttp/plugins/asustor-control" "$1/var/run" "$1/mnt/user/appdata/asustor-control"
  echo it8625 >"$1/sys/class/hwmon/hwmon0/name"
  echo "1767225600,133,1694,51,53,2800000,0,51,CPU,,,2" >"$1/var/local/emhttp/plugins/asustor-control/hwlog-buffer.csv"
}
TODAY=$(date +%F)
flush() { local rc=0; ASUSTOR_SYSROOT=$1 ASUSTOR_TEST_FN=flush_buffer ASUSTOR_TEST_BEFORE_WRITE=${2:-} bash "$HWMON" 2>/dev/null || rc=$?; return $rc; }
BUF() { cat "$1/var/local/emhttp/plugins/asustor-control/hwlog-buffer.csv"; }
SR=$W/sr1; mksr "$SR"; L=$SR/mnt/user/appdata/asustor-control/hwlog; mkdir -p "$L"; echo victim >"$W/victim1"
ln -s "$W/victim1" "$L/hwlog-$TODAY.csv"
rc1=0; flush "$SR" || rc1=$?; [ $rc1 -ne 0 ] && [ "$(cat "$W/victim1")" = victim ] && grep -q 'WARNING: .*hwlog-'"$TODAY"'.csv is a symlink' "$SR/var/local/emhttp/plugins/asustor-control/action.log" && [ -n "$(BUF "$SR")" ] \
  && ok "hwmon: planted symlink day file is not followed (warned, rows kept buffered)" || bad "hwmon followed a planted hwlog symlink"
SR=$W/sr2; mksr "$SR"; L=$SR/mnt/user/appdata/asustor-control/hwlog; mkdir -p "$L"; echo victim >"$W/victim2"
ln -s "$W/victim2" "$L/pwm-events-$TODAY.log"; echo "1767225600 PWM 133 -> 200" >"$SR/var/local/emhttp/plugins/asustor-control/pwm-events.buffer"
flush "$SR"; [ "$(cat "$W/victim2")" = victim ] && [ -f "$L/hwlog-$TODAY.csv" ] && [ ! -L "$L/hwlog-$TODAY.csv" ] \
  && ok "hwmon: planted symlink events file is not followed, day file still written" || bad "hwmon: pwm-events symlink / day file"
SR=$W/sr3; mksr "$SR"; mkdir -p "$W/elsewhere3"; ln -s "$W/elsewhere3" "$SR/mnt/user/appdata/asustor-control/hwlog"
! flush "$SR" && [ -z "$(ls -A "$W/elsewhere3")" ] && ok "hwmon: symlinked log dir is refused" || bad "hwmon wrote through a symlinked log dir"
SR=$W/sr5; mksr "$SR"; rmdir "$SR/mnt/user/appdata/asustor-control"; mkdir -p "$W/elsewhere5/hwlog"; ln -s "$W/elsewhere5" "$SR/mnt/user/appdata/asustor-control"
! flush "$SR" && [ -z "$(ls -A "$W/elsewhere5/hwlog")" ] && ok "hwmon: symlinked asustor-control parent dir is refused" || bad "hwmon wrote through a symlinked parent dir"
SR=$W/sr6; mksr "$SR"; L=$SR/mnt/user/appdata/asustor-control/hwlog
flush "$SR" && [ "$(stat -c %a "$L")" = 755 ] && head -1 "$L/hwlog-$TODAY.csv" | grep -q '^ts,pwm,' && [ -z "$(BUF "$SR")" ] \
  && ok "hwmon: new log dir created 755, day file starts with the header, buffer flushed" || bad "hwmon: fresh log dir / header / buffer"
SR=$W/sr7; mksr "$SR"; L=$SR/mnt/user/appdata/asustor-control/hwlog; mkdir -p "$L"; echo old >"$L/hwlog-$TODAY.csv"; echo victim >"$W/victim7"
! flush "$SR" "rm -f '$L/hwlog-$TODAY.csv'; ln -s '$W/victim7' '$L/hwlog-$TODAY.csv'" && [ "$(cat "$W/victim7")" = victim ] \
  && ok "hwmon: day file swapped to a symlink between check and write is not followed" || bad "hwmon: race on the day file followed the symlink"
SR=$W/sr8; mksr "$SR"; L=$SR/mnt/user/appdata/asustor-control/hwlog; mkdir -p "$L" "$W/elsewhere8"
! flush "$SR" "mv '$L' '$L.old'; ln -s '$W/elsewhere8' '$L'" && [ -z "$(ls -A "$W/elsewhere8")" ] \
  && ok "hwmon: log dir swapped to a symlink between check and write is refused" || bad "hwmon: race on the log dir followed the symlink"
prune() { local rc=0; ASUSTOR_SYSROOT=$1 ASUSTOR_TEST_FN=prune_logdir bash "$HWMON" 2>/dev/null || rc=$?; return $rc; }
SR=$W/sr9; mksr "$SR"; L=$SR/mnt/user/appdata/asustor-control/hwlog; mkdir -p "$L"
for f in hwlog-2020-01-01.csv pwm-events-2020-01-01.log notours-2020-01-01.csv hwlog-$TODAY.csv; do echo x >"$L/$f"; done
echo victim >"$W/victim9"; touch -d '2020-01-02' "$W/victim9"; ln -s "$W/victim9" "$L/hwlog-2020-01-02.csv"
touch -d '2020-01-02' "$L/hwlog-2020-01-01.csv" "$L/pwm-events-2020-01-01.log" "$L/notours-2020-01-01.csv"; touch -h -d '2020-01-02' "$L/hwlog-2020-01-02.csv"
prune "$SR" && [ ! -e "$L/hwlog-2020-01-01.csv" ] && [ ! -e "$L/pwm-events-2020-01-01.log" ] && [ -f "$L/notours-2020-01-01.csv" ] \
  && [ -f "$L/hwlog-$TODAY.csv" ] && [ -L "$L/hwlog-2020-01-02.csv" ] && [ "$(cat "$W/victim9")" = victim ] \
  && ok "hwmon: retention removes only old regular files with our names (symlink, other names, today kept)" || bad "hwmon: retention"
SR=$W/sr10; mksr "$SR"; mkdir -p "$W/decoy10"; for f in hwlog-2020-01-01.csv pwm-events-2020-01-01.log; do echo keep >"$W/decoy10/$f"; touch -d '2020-01-02' "$W/decoy10/$f"; done
ln -s "$W/decoy10" "$SR/mnt/user/appdata/asustor-control/hwlog"
! prune "$SR" && [ "$(ls "$W/decoy10" | wc -l)" = 2 ] && ok "hwmon: retention refuses a log dir swapped to a symlink (decoy untouched)" || bad "hwmon: retention followed a symlinked log dir"
SR=$W/sr4; mksr "$SR"; mkdir -p "$SR/proc/4242"
printf '4242 (ev il;$(id)`x) S 1 1 1 0 -1 0 0 0 0 0 7 3 0 0 20 0 1 0 100 0 0\n' >"$SR/proc/4242/stat"; printf 'read_bytes: 0\nwrite_bytes: 0\n' >"$SR/proc/4242/io"
ASUSTOR_SYSROOT=$SR ASUSTOR_TEST_FN=snapshot_procs bash "$HWMON" 2>/dev/null || true
S0=$SR/var/local/emhttp/plugins/asustor-control/procsnap/0
[ -f "$S0" ] && awk '$1 == 4242 && $2 ~ /^[A-Za-z0-9._-]+$/ {f=1} END {exit !f}' "$S0" \
  && ok "hwmon: process names sanitised ($(awk '$1 == 4242 {print $2}' "$S0"))" || bad "hwmon: process name not sanitised"

# render harness (tests/harness/): fixtures build and hold only made-up values, router lints, shot script parses
if python3 -I "$HERE"/tests/harness/mkfixture.py "$W/fx" >/dev/null; then
  ok "harness mkfixture.py builds fixtures"
  python3 -I - "$W/fx" <<'PY' && ok "harness fixtures parse, documentation-range addresses only" || bad "harness fixtures"
import json, re, sys, pathlib
d = pathlib.Path(sys.argv[1])
st = json.loads((d / "status.json").read_text()); hist = json.loads((d / "history.json").read_text())
assert st["hw"]["pwm"] == hist[-1]["pwm"] and len(hist) == 360
text = "".join(p.read_text() for p in d.glob("*.json"))
ips = set(re.findall(r"\b\d{1,3}(?:\.\d{1,3}){3}\b", text))
assert ips <= {"192.0.2.10"}, ips
PY
else bad "harness mkfixture.py"; fi
bash -n "$HERE"/tests/mkserver.sh && bash -n "$HERE"/tests/render-test.sh && ok "bash -n mkserver.sh render-test.sh" || bad "bash -n mkserver/render-test"
if command -v node >/dev/null; then for f in "$HERE"/src/usr/local/emhttp/plugins/$NAME/js/*.js; do node --check "$f" && ok "node --check $(basename "$f")" || bad "node --check $f"; done; fi
bash -n "$HERE"/src/usr/local/emhttp/plugins/$NAME/scripts/ac-report && ok "bash -n ac-report" || bad "bash -n ac-report"
# the collector is read-only: no drive command, no write
if grep -v '^#' "$HERE"/src/usr/local/emhttp/plugins/$NAME/scripts/ac-report | grep -nE '\b(smartctl|hdparm|sg_[a-z]+|dd|setpci|tee|mknod)\b|>>? *"?[$/]' | grep -v '/dev/null'; then bad "ac-report: a command that could touch a device or write"; else ok "ac-report: no device command, no write"; fi
# the page pieces: tabs in the sibling order, the Main page labels, the AS6706T profile from the kit
for t in AsustorControlOverview:1 AsustorControlLcd:2 AsustorControlLeds:3 AsustorControlHistory:4 AsustorControlHardware:5 AsustorControlReport:6; do
  grep -q "^Menu=\"AsustorControl:${t#*:}\"" "$HERE/src/usr/local/emhttp/plugins/$NAME/${t%%:*}.page" && ok "tab ${t%%:*} at ${t#*:}" || bad "tab order ${t%%:*}"
done
grep -q "plugins/$NAME/AsustorControlBadges.page" <<<"$LIST" && grep -q "plugins/$NAME/kit/enclosures/asustor-as6706t.json" <<<"$LIST" && ok "package holds the Main page labels and the AS6706T profile" || bad "package: badges page or profile"
php "$HERE"/kit/bin/page-scope.php "$HERE"/src/usr/local/emhttp/plugins/$NAME/*.page && ok "pages keep out of the page layout's variables" || bad "page scope"
README=$HERE/README.md; [ -f "$README" ] || README=$HERE/README.public.md   # the private tree keeps it as README.public.md
for f in "$HERE"/docs/screenshots/*.png; do grep -q "($(basename "$f"))\|/$(basename "$f"))" "$README" && ok "README shows $(basename "$f")" || bad "README does not reference $f"; done

# LED/button/driver-watchdog scripts against fake sysfs trees
if bash "$HERE"/tests/test-leds.sh; then ok "test-leds.sh"; else bad "test-leds.sh"; fi

[ $fail -eq 0 ] && echo "all checks passed" || { echo "checks FAILED"; exit 1; }
