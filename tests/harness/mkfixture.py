#!/usr/bin/env python3
"""mkfixture.py <out-dir> - made-up data for the local render harness (tests/harness/serve.sh).

Every value here is invented: hostname, addresses (192.0.2.0/24 is the RFC 5737 documentation
range), device names, process ids. Nothing is read from this machine or from a NAS. The story is
one hour of an idle unit with one disk-driven fan ramp (a large SMB write warming an NVMe cache
drive), which is the kind of event the Action Log is for. Output: status.json, history.json,
log.json, lcdcfg.json, leds.json (the answers of include/ajax.php's getStatus/getHistory/getLog/getLcdConfig/getLeds).
"""
import json
import math
import sys
from pathlib import Path

OUT = Path(sys.argv[1])
OUT.mkdir(parents=True, exist_ok=True)
T0 = 1767240000          # 2026-01-01 04:00:00 UTC; the harness browser runs in UTC
STEP = 10                # asustor-hwmon samples every 10 s
N = 360                  # the page keeps 360 points (one hour)


def rpm_of(pwm):         # fan curve of the test unit: RPM ~ 577 + 8.4 * PWM
    return int(577 + 8.4 * pwm)


hist = []
pwm = 133
for i in range(N):
    t = T0 + i * STEP
    cpu = 51 + round(1.5 * math.sin(i / 7.0) + 0.8 * math.sin(i / 2.3))
    # disk ramp between minute 36 and minute 46, peak 57 C at minute 41
    m = i * STEP / 60.0
    ramp = max(0.0, 1 - abs(m - 41) / 6.0)
    drv = round(41 + 16 * ramp)
    if i % 6 == 0:       # the controller acts once a minute
        want = 133 if drv < 46 else min(255, 133 + int((drv - 45) * 6))
        if abs(want - pwm) >= 5:
            pwm = want
    hist.append({"ts": t, "pwm": pwm, "cpu": cpu, "drv": drv, "rpm": rpm_of(pwm)})

now = hist[-1]
status = {
    "hw": {
        "pwm": now["pwm"], "rpm": now["rpm"], "cpu_temp": 52, "max_drive_temp": 43,
        "drive_temps": {"sda": 35, "sdb": 36, "sdc": 34, "sdd": 37, "nvme0n1": 39, "nvme1n1": 43},
        "hwmon": "hwmon3",
    },
    "lcd": {"timestamp": "2026-01-01T04:59:50+0000", "current_screen": "temps", "line1": "Disk:43C",
            "line2": "CPU:52C", "port": "/dev/ttyS1", "screens": "hostname,ip,temps,array,time"},
    "unraid_warn": 45, "unraid_crit": 55, "unraid_warnssd": 60,
    "lcd_running": True, "hwmon_running": True,
    "hwlog": {"last_flush_ts": now["ts"] - 40, "last_flush_file": "hwlog-2026-01-01.csv", "rows_buffered": 4,
              "rows_written": 8640, "last_error": "", "logdir": "/mnt/user/appdata/asustor-control/hwlog",
              "files": 14, "bytes": 27262976},
    "drivers": {"state": "ok", "kernel": "6.18.58-Unraid", "missing": [], "not_installed": [], "optional_missing": [],
                "message": "Asustor drivers loaded on kernel 6.18.58-Unraid."},
}

# LEDs & Buttons tab: five made-up disks in six bays, one marked disabled (red LED), bay 2 being located
bays = [{"bay": 1, "dev": "sdb", "name": "parity", "status": "DISK_OK", "fault": 0},
        {"bay": 2, "dev": "sdc", "name": "disk1", "status": "DISK_OK", "fault": 0},
        {"bay": 3, "dev": "sdd", "name": "disk2", "status": "DISK_DSBL", "fault": 1},
        {"bay": 4, "dev": "sde", "name": "disk3", "status": "DISK_OK", "fault": 0},
        {"bay": 5, "dev": "sdf", "name": "disk4", "status": "DISK_OK", "fault": 0},
        {"bay": 6, "dev": "", "name": "", "status": "", "fault": 0}]
leds = {"leds": {"ts": now["ts"], "array": "started", "faults": 1, "locate_bay": 2, "locate_left": 24, "poll_ms": 200,
                 "bays": bays},
        "buttons": {"device": "/dev/input/event2", "last": "copy short 300ms log", "ts": now["ts"]},
        "leds_running": True, "buttons_running": True, "led_class": True,
        "kmods": {"available": True, "provided": False,
                  "lines": ["kernel 6.18.58-Unraid: build asustor-ledkeys-kmod-6.18.58_Unraid-x86_64-1.txz",
                            "cached on flash: yes", "leds_gpio: loaded", "gpio_keys_polled: loaded"]},
        "cfg": {"leds_enabled": "yes", "activity_poll_ms": "200", "bay_ports": "auto", "copy_short": "log",
                "copy_long": "notify", "long_press_s": "3", "kmods": "yes"}}


def stamp(i):
    s = hist[i]["ts"] - T0
    return "[2026-01-01 04:%02d:%02d]" % (s // 60, s % 60)


log = ["[2026-01-01 03:12:05] Services started",   # message texts as in rc.asustor-control / ajax.php
       "[2026-01-01 03:20:41] Saved LCD config", "[2026-01-01 03:20:41] Restarted LCD daemon"]
prev = hist[0]["pwm"]
for i, r in enumerate(hist):
    if r["pwm"] != prev:
        if r["pwm"] > prev:
            why = "FanCtrl Plus: disk %d°C (hottest cache2=%d)" % (r["drv"], r["drv"])
            who = ("top CPU (60 s): smbd[4127] <- 192.0.2.10 17.4%%; top write: smbd[4127] <- 192.0.2.10 "
                   "%.1f MB/s" % (92 + r["drv"] - 46))
            busy = 19
        else:
            why = "FanCtrl Plus: disk %d°C (hottest cache2=%d)" % (r["drv"], r["drv"])
            who = "top CPU (60 s): emhttpd[2210] 2.1%"
            busy = 3
        log.append("%s PWM %d -> %d (%d%%, %d rpm) | %s | system busy %d%% | %s"
                   % (stamp(i), prev, r["pwm"], r["pwm"] * 100 // 255, r["rpm"], why, busy, who))
        prev = r["pwm"]

lcdcfg = {"enabled": "true", "port": "/dev/ttyS1", "cycle_interval": "5", "screens": "hostname,ip,temps,array,time"}

for name, obj in (("status", status), ("history", hist), ("log", log), ("lcdcfg", lcdcfg), ("leds", leds)):
    (OUT / f"{name}.json").write_text(json.dumps(obj, ensure_ascii=False))
print(f"fixtures in {OUT}: {N} history rows, {len(log)} log lines")
