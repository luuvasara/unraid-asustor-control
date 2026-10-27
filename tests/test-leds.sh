#!/bin/bash
# test-leds.sh - offline tests for the LED/button/driver-watchdog parts. Builds fake /sys, /proc,
# /var/local/emhttp trees under a temp dir and runs the real scripts against them through
# ASUSTOR_SYSROOT. Touches no hardware and no NAS. Bay layout mirrors an AS6706T (made-up disks).
set -uo pipefail
HERE=$(cd "$(dirname "$0")/.." && pwd)
case "$HERE" in *" "*)           # the checks below are eval'd strings: run through a space-free symlink
    L=$(mktemp -d); ln -s "$HERE" "$L/repo"; bash "$L/repo/tests/test-leds.sh"; rc=$?; rm -rf "$L"; exit $rc ;; esac
BIN=$HERE/src/usr/local/bin
fail=0; ok() { echo "ok   $*"; }; bad() { echo "FAIL $*"; fail=1; }
check() { if eval "$2"; then ok "$1"; else bad "$1"; fi; }
T=$(mktemp -d -p "$HERE/tests" .work.XXXXXX); trap 'rm -rf "$T"' EXIT
KV=6.18.58-Unraid

mkroot() {                     # $1 root: AS6706T-like tree
    local r=$1 b n
    mkdir -p "$r"/var/run "$r"/var/local/emhttp/plugins/asustor-control "$r"/boot/config/plugins/asustor-control \
             "$r"/proc/sys/kernel/random "$r"/lib/modules/$KV/kernel/drivers/{platform/x86,hwmon,gpio} \
             "$r"/usr/local/emhttp/webGui/scripts "$r"/usr/local/emhttp/plugins/asustor-control
    echo "boot-A" > "$r"/proc/sys/kernel/random/boot_id
    for m in asustor asustor_it87 asustor_gpio_it87 leds_gpio gpio_keys_polled; do mkdir -p "$r"/sys/module/$m; done
    touch "$r"/lib/modules/$KV/kernel/drivers/platform/x86/asustor.ko.xz "$r"/lib/modules/$KV/kernel/drivers/hwmon/asustor_it87.ko.xz \
          "$r"/lib/modules/$KV/kernel/drivers/gpio/asustor_gpio_it87.ko.xz
    printf '#!/bin/bash\necho "$*" >> %s/notify.calls\n' "$r" > "$r"/usr/local/emhttp/webGui/scripts/notify
    chmod +x "$r"/usr/local/emhttp/webGui/scripts/notify
    # ASM1166 at 0000:01:00.0 with ata1..6, plus an onboard controller ata7 that is not a bay
    local asm="$r/sys/devices/pci0000:00/0000:00:1c.0/0000:01:00.0" onb="$r/sys/devices/pci0000:00/0000:00:17.0"
    mkdir -p "$asm" "$onb" "$r"/sys/class/ata_port "$r"/sys/block
    echo 0x1b21 > "$asm/vendor"; echo 0x1166 > "$asm/device"; echo 0x8086 > "$onb/vendor"; echo 0x4dd3 > "$onb/device"
    for n in 1 2 3 4 5 6; do mkdir -p "$asm/ata$n" "$r/sys/class/ata_port/ata$n"; ln -s "$asm/ata$n" "$r/sys/class/ata_port/ata$n/device"; done
    mkdir -p "$onb/ata7" "$r/sys/class/ata_port/ata7"; ln -s "$onb/ata7" "$r/sys/class/ata_port/ata7/device"
    # disks: ata1->sdb ata2->sdc ata3->sdd ata5->sdf (bay 4 and 6 empty), ata7->sdh (not a bay)
    for x in 1:sdb 2:sdc 3:sdd 5:sdf 7:sdh; do
        n=${x%%:*}; b=${x#*:}; local base=$asm; [ "$n" = 7 ] && base=$onb
        mkdir -p "$base/ata$n/host$n/target/block/$b"; echo "100 0 800 10 50 0 400 5 0 20 15" > "$base/ata$n/host$n/target/block/$b/stat"
        ln -s "$base/ata$n/host$n/target/block/$b" "$r/sys/block/$b"
    done
    # LED class devices (plain files stand in for sysfs attributes)
    for l in green:status red:status power:lcd blue:power sata{1..6}:green:disk sata{1..6}:red:disk; do
        mkdir -p "$r/sys/class/leds/$l"
        echo "[none] oneshot timer heartbeat" > "$r/sys/class/leds/$l/trigger"
        for a in brightness delay_on delay_off invert shot; do echo 0 > "$r/sys/class/leds/$l/$a"; done
    done
    cat > "$r"/var/local/emhttp/disks.ini <<'EOF'
["parity"]
name="parity"
device="sdf"
status="DISK_OK"
numErrors="0"
["disk1"]
name="disk1"
device="sdb"
status="DISK_OK"
numErrors="0"
["disk2"]
name="disk2"
device="sdc"
status="DISK_DSBL"
numErrors="0"
["disk3"]
name="disk3"
device="sdd"
status="DISK_OK"
numErrors="7"
EOF
    printf 'mdState="STARTED"\nmdResync="0"\nmdNumDisabled="1"\nmdNumInvalid="0"\nmdNumMissing="0"\nfsState="Started"\n' > "$r"/var/local/emhttp/var.ini
}

# ---------------------------------------------------------------- drivercheck
R1=$T/dc; mkroot "$R1"
out=$(ASUSTOR_SYSROOT=$R1 ASUSTOR_KVER=$KV bash "$BIN"/asustor-drivercheck --notify); rc=$?
check "drivercheck: all loaded -> ok, exit 0" "[ $rc -eq 0 ] && grep -q '\"state\":\"ok\"' $R1/var/local/emhttp/plugins/asustor-control/drivers.json"
check "drivercheck: no notification when ok" "[ ! -f $R1/notify.calls ]"
rm -rf "$R1"/sys/module/asustor_it87 "$R1"/sys/module/asustor_gpio_it87
out=$(ASUSTOR_SYSROOT=$R1 ASUSTOR_KVER=$KV bash "$BIN"/asustor-drivercheck --notify); rc=$?
check "drivercheck: installed-but-not-loaded -> missing, exit 1, 'reboot' hint" "[ $rc -eq 1 ] && grep -q 'not loaded' <<<\"\$out\""
rm -rf "$R1"/sys/module/asustor; rm -f "$R1"/lib/modules/$KV/kernel/drivers/hwmon/asustor_it87.ko.xz "$R1"/lib/modules/$KV/kernel/drivers/gpio/asustor_gpio_it87.ko.xz "$R1"/var/local/emhttp/plugins/asustor-control/drivers-notified
rm -f "$R1"/notify.calls
NEWKV=6.18.99-Unraid
out=$(ASUSTOR_SYSROOT=$R1 ASUSTOR_KVER=$NEWKV bash "$BIN"/asustor-drivercheck --notify); rc=$?
J=$R1/var/local/emhttp/plugins/asustor-control/drivers.json
check "drivercheck: kernel update w/o build -> missing + not_installed listed" "[ $rc -eq 1 ] && grep -q '\"not_installed\":\[\"asustor\",\"asustor_it87\",\"asustor_gpio_it87\"\]' $J"
check "drivercheck: drivers.json is valid JSON" "python3 -c 'import json,sys; json.load(open(sys.argv[1]))' $J"
check "drivercheck: one notification raised (alert, names kernel)" "[ \$(wc -l < $R1/notify.calls) -eq 1 ] && grep -q -- '-i alert' $R1/notify.calls && grep -q $NEWKV $R1/notify.calls"
ASUSTOR_SYSROOT=$R1 ASUSTOR_KVER=$NEWKV bash "$BIN"/asustor-drivercheck --notify >/dev/null
ASUSTOR_SYSROOT=$R1 ASUSTOR_KVER=$NEWKV bash "$BIN"/asustor-drivercheck --notify >/dev/null
check "drivercheck: same boot -> no repeat notification" "[ \$(wc -l < $R1/notify.calls) -eq 1 ]"
echo boot-B > "$R1"/proc/sys/kernel/random/boot_id
ASUSTOR_SYSROOT=$R1 ASUSTOR_KVER=$NEWKV bash "$BIN"/asustor-drivercheck --notify >/dev/null
check "drivercheck: next boot -> notifies again" "[ \$(wc -l < $R1/notify.calls) -eq 2 ]"
R2=$T/dc2; mkroot "$R2"; rm -rf "$R2"/sys/module/leds_gpio; echo kmods=yes > "$R2"/boot/config/plugins/asustor-control/leds.cfg
ASUSTOR_SYSROOT=$R2 ASUSTOR_KVER=$KV bash "$BIN"/asustor-drivercheck --notify >/dev/null; rc=$?
check "drivercheck: add-on enabled but not loaded -> warn (exit 0, no notification)" "[ $rc -eq 0 ] && grep -q '\"state\":\"warn\"' $R2/var/local/emhttp/plugins/asustor-control/drivers.json && [ ! -f $R2/notify.calls ]"

# ---------------------------------------------------------------- hwmon degraded mode
R3=$T/hw; mkroot "$R3"; rm -rf "$R3"/sys/module/asustor_it87
mkdir -p "$R3"/sys/class/hwmon/hwmon0; echo coretemp > "$R3"/sys/class/hwmon/hwmon0/name
ASUSTOR_SYSROOT=$R3 ASUSTOR_KVER=$NEWKV ASUSTOR_DEGRADED_ONCE=1 timeout 20 bash "$BIN"/asustor-hwmon 2>/dev/null; rc=$?
S=$R3/var/local/emhttp/plugins/asustor-control/hwlog-status.json
check "hwmon: no fan chip -> stays up (degraded), exit 0 via test hook" "[ $rc -eq 0 ]"
check "hwmon: status says why + drivers field" "grep -q 'no fan sensor chip' $S && grep -q '\"drivers\":\"missing: asustor_it87\"' $S"
check "hwmon: Action Log records missing drivers with kernel" "grep -q 'Asustor drivers: missing: asustor_it87 (kernel $NEWKV)' $R3/var/local/emhttp/plugins/asustor-control/action.log"

# ---------------------------------------------------------------- asustor-leds
R4=$T/leds; mkroot "$R4"
L=$R4/sys/class/leds; SD=$R4/var/local/emhttp/plugins/asustor-control
echo "idle_poll_ms=200" > "$R4"/boot/config/plugins/asustor-control/leds.cfg   # keep the 20-pass run short
echo "2 20" > "$SD/locate.req"
( ASUSTOR_SYSROOT=$R4 ASUSTOR_ONESHOT=20 bash "$BIN"/asustor-leds 2>"$T/leds.err" ) & LP=$!
sleep 1.2
STATF=$(readlink -f "$R4/sys/block/sdd")/stat; echo "130 0 900 12 50 0 400 5 0 22 17" > "$STATF"   # I/O on bay 3
wait $LP; rc=$?
J=$SD/leds.json
check "leds: daemon ran 20 passes, exit 0" "[ $rc -eq 0 ]"
check "leds: leds.json valid JSON" "python3 -c 'import json,sys; json.load(open(sys.argv[1]))' $J"
check "leds: bays mapped by ASM1166 port (1=sdb 2=sdc 3=sdd 4=- 5=sdf 6=-; onboard sdh ignored)" \
  "python3 -c 'import json,sys; b=json.load(open(sys.argv[1]))[\"bays\"]; sys.exit(0 if [x[\"dev\"] for x in b]==[\"sdb\",\"sdc\",\"sdd\",\"\",\"sdf\",\"\"] else 1)' $J"
check "leds: green LEDs of occupied bays use inverted oneshot" "[ \"\$(cat $L/sata1:green:disk/trigger)\" = oneshot ] && [ \"\$(cat $L/sata1:green:disk/invert)\" = 1 ]"
check "leds: empty bay green LED off" "[ \"\$(cat $L/sata4:green:disk/brightness)\" = 0 ] && [ \"\$(cat $L/sata4:green:disk/trigger)\" = none ]"
check "leds: I/O on bay 3 fired a oneshot blink" "[ \"\$(cat $L/sata3:green:disk/shot)\" = 1 ]"
check "leds: no I/O on bay 1 -> no blink" "[ \"\$(cat $L/sata1:green:disk/shot)\" = 0 ]"
check "leds: DISK_DSBL (bay 2) is located -> red blinking via timer" "[ \"\$(cat $L/sata2:red:disk/trigger)\" = timer ] && [ \"\$(cat $L/sata2:red:disk/delay_on)\" = 250 ]"
check "leds: read errors (bay 3, numErrors=7) -> red fault on" "[ \"\$(cat $L/sata3:red:disk/brightness)\" = 1 ]"
check "leds: healthy disk (bay 1) -> red off" "[ \"\$(cat $L/sata1:red:disk/brightness)\" = 0 ]"
check "leds: locate request consumed and reported" "[ ! -f $SD/locate.req ] && grep -q '\"locate_bay\":2' $J"
check "leds: array started -> green:status steady on" "[ \"\$(cat $L/green:status/trigger)\" = none ] && [ \"\$(cat $L/green:status/brightness)\" = 1 ]"
check "leds: mdNumDisabled=1 -> red:status on" "[ \"\$(cat $L/red:status/brightness)\" = 1 ]"
check "leds: never touches power:* LEDs" "[ \"\$(cat $L/power:lcd/brightness)\" = 0 ] && grep -q '^\[none\]' $L/power:lcd/trigger"
# parity check running + array healthy
for t in "$L"/*/trigger; do echo "[none] oneshot timer heartbeat" > "$t"; done   # plain files keep the last write
printf 'mdState="STARTED"\nmdResync="123456"\nmdNumDisabled="0"\nmdNumInvalid="0"\nmdNumMissing="0"\n' > "$R4"/var/local/emhttp/var.ini
ASUSTOR_SYSROOT=$R4 ASUSTOR_ONESHOT=1 bash "$BIN"/asustor-leds 2>/dev/null
check "leds: parity check -> green:status slow blink, red:status off" "[ \"\$(cat $L/green:status/trigger)\" = timer ] && [ \"\$(cat $L/green:status/delay_on)\" = 1000 ] && [ \"\$(cat $L/red:status/brightness)\" = 0 ]"
for t in "$L"/*/trigger; do echo "[none] oneshot timer heartbeat" > "$t"; done
echo "bay_ports=6,5,4,3,2,1" > "$R4"/boot/config/plugins/asustor-control/leds.cfg
ASUSTOR_SYSROOT=$R4 ASUSTOR_ONESHOT=1 bash "$BIN"/asustor-leds 2>/dev/null
check "leds: bay_ports override reverses the map" \
  "python3 -c 'import json,sys; b=json.load(open(sys.argv[1]))[\"bays\"]; sys.exit(0 if [x[\"dev\"] for x in b]==[\"\",\"sdf\",\"\",\"sdd\",\"sdc\",\"sdb\"] else 1)' $J"
R5=$T/noleds; mkroot "$R5"; rm -rf "$R5"/sys/class/leds
ASUSTOR_SYSROOT=$R5 timeout 5 bash "$BIN"/asustor-leds 2>/dev/null; rc=$?
check "leds: no LED class devices -> exits 0 at once" "[ $rc -eq 0 ]"

# CPU cost of the real loop (200 ms poll, 4 occupied bays, idle disks) over 20 s
rm -f "$R4"/boot/config/plugins/asustor-control/leds.cfg
ASUSTOR_SYSROOT=$R4 bash "$BIN"/asustor-leds 2>/dev/null & DP=$!
sleep 20
read -r -a st < /proc/$DP/stat; ticks=$(( st[13] + st[14] + st[15] + st[16] )); hz=$(getconf CLK_TCK)
kill $DP; wait $DP 2>/dev/null
pct=$(awk -v t=$ticks -v hz=$hz 'BEGIN{printf "%.2f", 100*t/hz/20}')
echo "info leds daemon CPU over 20 s on this host: $ticks ticks = ${pct}% of one core (children included)"
check "leds: daemon CPU < 2% of one core" "awk -v p=$pct 'BEGIN{exit !(p < 2)}'"
check "leds: TERM restores a plain panel (status steady on, reds off)" "[ \"\$(cat $L/green:status/brightness)\" = 1 ] && [ \"\$(cat $L/sata3:red:disk/brightness)\" = 0 ] && [ ! -f $R4/var/run/asustor-leds.pid ]"

# ---------------------------------------------------------------- asustor-buttons
R6=$T/btn; mkroot "$R6"
printf 'copy_short=log\ncopy_long=locate\nlong_press_s=3\n' > "$R6"/boot/config/plugins/asustor-control/leds.cfg
python3 -I - "$T/events.bin" <<'EOF'
import struct, sys
def ev(sec, usec, typ, code, val): return struct.pack('<qqHHi', sec, usec, typ, code, val)
d = b''.join([
    ev(1000, 100000, 1, 133, 1), ev(1000, 100000, 0, 0, 0),   # press
    ev(1000, 400000, 1, 133, 0), ev(1000, 400000, 0, 0, 0),   # release after 300 ms
    ev(2000, 900000, 1, 116, 1), ev(2000, 950000, 1, 116, 0), # KEY_POWER: ignored
    ev(3000, 500000, 1, 133, 1), ev(3004, 200000, 1, 133, 0), # long: 3.7 s
])
open(sys.argv[1], 'wb').write(d)
EOF
ASUSTOR_SYSROOT=$R6 ASUSTOR_BUTTON_DEV=$T/events.bin ASUSTOR_ONESHOT=1 timeout 10 bash "$BIN"/asustor-buttons 2>/dev/null; rc=$?
A=$R6/var/local/emhttp/plugins/asustor-control/action.log
check "buttons: short press logged with duration" "grep -q 'Button: copy short press (300 ms)' $A"
check "buttons: long press -> locate all bays" "grep -q 'copy long press -> locate' $A && [ \"\$(cat $R6/var/local/emhttp/plugins/asustor-control/locate.req)\" = '0 30' ]"
check "buttons: KEY_POWER ignored, exactly two actions" "[ \$(grep -c 'Button:' $A) -eq 2 ]"
check "buttons: buttons.json valid" "python3 -c 'import json,sys; json.load(open(sys.argv[1]))' $R6/var/local/emhttp/plugins/asustor-control/buttons.json"
printf 'copy_short=shutdown\n' > "$R6"/boot/config/plugins/asustor-control/leds.cfg; : > "$A"
ASUSTOR_SYSROOT=$R6 ASUSTOR_BUTTON_DEV=$T/events.bin ASUSTOR_ONESHOT=1 timeout 10 bash "$BIN"/asustor-buttons 2>/dev/null
check "buttons: unknown action (e.g. 'shutdown') is ignored, not executed" "grep -q \"unknown action 'shutdown'\" $A"

# ---------------------------------------------------------------- asustor-kmods
R7=$T/km; mkroot "$R7"; cp "$HERE"/src/usr/local/emhttp/plugins/asustor-control/kmods.manifest "$R7"/usr/local/emhttp/plugins/asustor-control/
out=$(ASUSTOR_SYSROOT=$R7 ASUSTOR_KVER=$KV bash "$BIN"/asustor-kmods status); rc=$?
check "kmods: build known for $KV" "[ $rc -eq 0 ] && grep -q 'build asustor-ledkeys-kmod-6.18.58_Unraid-x86_64-1.txz' <<<\"\$out\""
out=$(ASUSTOR_SYSROOT=$R7 ASUSTOR_KVER=$NEWKV bash "$BIN"/asustor-kmods install 2>&1); rc=$?
check "kmods: other kernel -> refuses (exit 3) with clear message, nothing cached" "[ $rc -eq 3 ] && grep -q 'No LED/button module build for kernel $NEWKV' <<<\"\$out\" && [ -z \"\$(ls $R7/boot/config/plugins/asustor-control/kmods 2>/dev/null)\" ]"
mkdir -p "$R7"/boot/config/plugins/asustor-control/kmods; echo junk > "$R7"/boot/config/plugins/asustor-control/kmods/asustor-ledkeys-kmod-6.18.58_Unraid-x86_64-1.txz
out=$(ASUSTOR_SYSROOT=$R7 ASUSTOR_KVER=$KV bash "$BIN"/asustor-kmods load 2>&1); rc=$?
check "kmods: corrupt cached package -> MD5 refusal (exit 5), file removed" "[ $rc -eq 5 ] && [ ! -f $R7/boot/config/plugins/asustor-control/kmods/asustor-ledkeys-kmod-6.18.58_Unraid-x86_64-1.txz ]"
cp "$HERE"/kmods/asustor-ledkeys-kmod-6.18.58_Unraid-x86_64-1.txz "$R7"/boot/config/plugins/asustor-control/kmods/
out=$(ASUSTOR_SYSROOT=$R7 ASUSTOR_KVER=$KV bash "$BIN"/asustor-kmods load 2>&1); rc=$?
check "kmods: good cached package -> installpkg + depmod + modprobe" "[ $rc -eq 0 ] && grep -q 'would run: installpkg' <<<\"\$out\" && grep -q 'would run: /sbin/modprobe leds-gpio' <<<\"\$out\""
out=$(ASUSTOR_SYSROOT=$R7 ASUSTOR_KVER=$KV bash "$BIN"/asustor-kmods load 2>&1 </dev/null); rm -f "$R7"/boot/config/plugins/asustor-control/kmods/*.txz
out=$(ASUSTOR_SYSROOT=$R7 ASUSTOR_KVER=$KV bash "$BIN"/asustor-kmods load 2>&1); rc=$?
check "kmods: boot-time load never downloads (exit 4 when not cached)" "[ $rc -eq 4 ]"
M=$(awk '$1=="6.18.58-Unraid"{print $3}' "$HERE"/src/usr/local/emhttp/plugins/asustor-control/kmods.manifest)
check "kmods: manifest MD5 matches kmods/ package" "[ \"\$(md5sum < $HERE/kmods/asustor-ledkeys-kmod-6.18.58_Unraid-x86_64-1.txz | cut -d' ' -f1)\" = $M ]"
check "kmods: package dirs 755 root, root entry drwxr-xr-x" "tar -tJvf $HERE/kmods/asustor-ledkeys-kmod-6.18.58_Unraid-x86_64-1.txz | awk 'NR==1&&\$1!=\"drwxr-xr-x\"{b=1} \$1~/^d/&&\$1!=\"drwxr-xr-x\"{b=1} \$2!=\"0/0\"{b=1} END{exit b}'"

check "kmods: package files 644, nothing group/world-writable" "! tar -tJvf $HERE/kmods/asustor-ledkeys-kmod-6.18.58_Unraid-x86_64-1.txz | awk '\$1!~/^d/ && \$1!=\"-rw-r--r--\"' | grep -q ."
# Prefer the platform-driver package once it ships the modules (the Terebi42 PR): ours is not installed.
R8=$T/km2; mkroot "$R8"; cp "$HERE"/src/usr/local/emhttp/plugins/asustor-control/kmods.manifest "$R8"/usr/local/emhttp/plugins/asustor-control/
mkdir -p "$R8"/var/log/packages "$R8"/boot/config/plugins/asustor-control/kmods
cp "$HERE"/kmods/asustor-ledkeys-kmod-6.18.58_Unraid-x86_64-1.txz "$R8"/boot/config/plugins/asustor-control/kmods/
printf 'PACKAGE NAME: asustor_pfd-20990101-%s-1\nFILE LIST:\nlib/modules/%s/kernel/drivers/leds/leds-gpio.ko.xz\n' "$KV" "$KV" > "$R8"/var/log/packages/asustor_pfd-20990101-$KV-1
out=$(ASUSTOR_SYSROOT=$R8 ASUSTOR_KVER=$KV bash "$BIN"/asustor-kmods status 2>&1); rc=$?
check "kmods: platform package has leds-gpio only -> add-on still offered" "[ $rc -eq 0 ]"
printf 'lib/modules/%s/kernel/drivers/input/keyboard/gpio_keys_polled.ko.xz\n' "$KV" >> "$R8"/var/log/packages/asustor_pfd-20990101-$KV-1
out=$(ASUSTOR_SYSROOT=$R8 ASUSTOR_KVER=$KV bash "$BIN"/asustor-kmods status 2>&1); rc=$?
check "kmods: platform package provides both -> status says not needed (exit 6)" "[ $rc -eq 6 ] && grep -q 'provided by asustor_pfd-20990101' <<<\"\$out\""
out=$(ASUSTOR_SYSROOT=$R8 ASUSTOR_KVER=$KV bash "$BIN"/asustor-kmods load 2>&1); rc=$?
check "kmods: platform package provides both -> load modprobes theirs, never installpkg ours" "[ $rc -eq 0 ] && ! grep -q 'installpkg' <<<\"\$out\" && grep -q 'would run: /sbin/modprobe leds-gpio' <<<\"\$out\""
rm -f "$R8"/var/log/packages/*; echo "kernel/drivers/leds/leds-gpio.ko" > "$R8"/lib/modules/$KV/modules.builtin
echo "kernel/drivers/input/keyboard/gpio_keys_polled.ko" >> "$R8"/lib/modules/$KV/modules.builtin
out=$(ASUSTOR_SYSROOT=$R8 ASUSTOR_KVER=$KV bash "$BIN"/asustor-kmods install 2>&1); rc=$?
check "kmods: drivers built into the kernel -> nothing installed" "[ $rc -eq 0 ] && ! grep -q 'installpkg' <<<\"\$out\" && grep -q 'from kernel' <<<\"\$out\""

[ $fail -eq 0 ] && echo "test-leds: all passed" || { echo "test-leds: FAILED"; exit 1; }
