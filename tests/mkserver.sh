#!/bin/bash
# shellcheck disable=SC2016  # php -r code is single-quoted on purpose
# mkserver.sh <dir> - a made-up Asustor AS6706T for the tests: the kit's AS6706T fixture (board, ASM1166, six bays, two NVMe
# drives, Unraid's state files; invented serials and addresses), the hardware monitor's chart file for 12 hours, the driver
# watchdog's file, /etc and /proc bits for the report, and stub lspci/lsusb/dmesg in <dir>/bin. Nothing is read from this
# machine or from a NAS. NOTAS=1 makes it another board (the drawing and the bay labels must then stay away).
set -euo pipefail
HERE=$(cd "$(dirname "$0")/.." && pwd); K=$1
rm -rf "$K"; mkdir -p "$K"
php "$HERE/kit/render/as6706t-fixture.php" "$K"
[ "${NOTAS:-}" = 1 ] && printf 'Standard PC (Q35 + ICH9, 2009)\n' >"$K/sys/class/dmi/id/product_name"
S=$K/var/local/emhttp/plugins/asustor-control
mkdir -p "$K/etc" "$K/proc/sys/kernel" "$K/bin" "$S" "$K/boot/config/plugins/asustor-control" "$K/sys/devices/system/cpu/cpuidle"
echo 'version="7.3.4"' >"$K/etc/unraid-version"; echo 'nas-example' >"$K/etc/hostname"
echo '6.18.58-Unraid' >"$K/proc/sys/kernel/osrelease"
printf 'processor\t: 0\nmodel name\t: Intel(R) Celeron(R) N5105 @ 2.00GHz\nprocessor\t: 1\nmodel name\t: Intel(R) Celeron(R) N5105 @ 2.00GHz\n' >"$K/proc/cpuinfo"
printf 'MemTotal:        8000000 kB\n' >"$K/proc/meminfo"
echo none >"$K/sys/devices/system/cpu/cpuidle/current_driver"
for m in asustor asustor_it87 asustor_gpio_it87; do mkdir -p "$K/sys/module/$m"; done
mkdir -p "$K/sys/class/hwmon/hwmon2" "$K/sys/class/hwmon/hwmon3"; echo coretemp >"$K/sys/class/hwmon/hwmon2/name"; echo it8625 >"$K/sys/class/hwmon/hwmon3/name"
cp "$HERE/src/usr/local/emhttp/plugins/asustor-control/default/asustor-control.cfg" "$K/boot/config/plugins/asustor-control/"
printf '{"state":"ok","kernel":"6.18.58-Unraid","missing":[],"not_installed":[],"optional_missing":["leds_gpio","gpio_keys_polled"],"message":"Asustor drivers loaded on kernel 6.18.58-Unraid."}\n' >"$S/drivers.json"
printf '#!/bin/bash\ncase "$*" in *-t*) echo "-[0000:00]-+-00.0  Intel Corporation Device [8086:4e24]\n           +-1c.0-[01]----00.0  ASMedia Technology Inc. ASM1166 Serial ATA Controller [1b21:1166]" ;; *) echo "01:00.0 SATA controller [0106]: ASMedia Technology Inc. ASM1166 Serial ATA Controller [1b21:1166] (rev 02)" ;; esac\n' >"$K/bin/lspci"
printf '#!/bin/bash\necho "Bus 001 Device 001: ID 1d6b:0002 Linux Foundation 2.0 root hub"\n' >"$K/bin/lsusb"
printf '#!/bin/bash\necho "[    0.000000] DMI: Intel Corporation Jasper Lake Client Platform/Jasper Lake Client Platform, BIOS V1.21 11/23/2022"\necho "[    2.345678] ata1: SATA link up 6.0 Gbps (SStatus 133 SControl 300)"\necho "[    5.100000] asustor: found AS6706"\n' >"$K/bin/dmesg"
chmod 755 "$K/bin/"*
# the hardware monitor's chart file: 12 hours, one line every 30 s ending now, "ts,pwm,cpu,drive,rpm"; a disk-driven ramp
# 40 to 30 minutes ago (made-up values; RPM follows the measured fan curve shape 577 + 8.4 * PWM)
php -r '
$k = $argv[1]; $now = time(); $now -= $now % 30; $L = []; $pwm = 133;
for ($i = 1439; $i >= 0; $i--) {
  $t = $now - 30 * $i; $m = $i / 2;
  $cpu = (int)round(52 + 1.5 * sin($t / 700) + 0.8 * sin($t / 230));
  $ramp = max(0, 1 - abs($m - 35) / 6); $drv = (int)round(41 + 16 * $ramp);
  if ($i % 2 === 0) { $want = $drv < 46 ? 133 : min(255, 133 + ($drv - 45) * 6); if (abs($want - $pwm) >= 5) $pwm = $want; }
  $L[] = "$t,$pwm,$cpu,$drv," . (int)(577 + 8.4 * $pwm);
}
file_put_contents("$k/var/local/emhttp/plugins/asustor-control/hwmon-history.csv", implode("\n", $L) . "\n");' "$K"
