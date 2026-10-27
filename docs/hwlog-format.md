# Behaviour log (asustor-hwmon, since 2026.09.21c; events since 2026.09.21d)

Written by `/usr/local/bin/asustor-hwmon` on the box. Read-only sensor sampling; the
daemon never writes to sysfs. Fan control itself is FanCtrl Plus.

| Where | What |
|---|---|
| `/mnt/user/appdata/asustor-control/hwlog/hwlog-YYYY-MM-DD.csv` | one file per day, header row, 10 s samples, 30-day retention |
| `/var/local/emhttp/plugins/asustor-control/hwlog-buffer.csv` | RAM buffer, flushed to the day file every 5 min (and on daemon stop) |
| `/var/local/emhttp/plugins/asustor-control/hwlog-status.json` | last flush time/file, rows buffered/written, last error, `drivers` (`ok` or `missing: <modules>`, checked at start and every flush); shown on the Status tab |
| `/var/local/emhttp/plugins/asustor-control/hwmon-history.csv` | unchanged 30 s chart ring (`ts,pwm,cpu_max_c,drive_max_c,rpm`) |
| `/mnt/user/appdata/asustor-control/hwlog/pwm-events-YYYY-MM-DD.log` | one line per PWM change: `<ts> PWM a -> b (pct, rpm) \| FanCtrl Plus: <source temp, hottest drive> \| system busy N% \| top CPU: comm[pid] N% (docker:name), ...; top write/read: comm[pid] MB/s`. The same line goes to the Status tab Action Log (last 200 kept). CPU% is over the window shown in parentheses (up to 60 s before the change), measured from /proc/PID/stat deltas including reaped children (so an exited helper is charged to its parent); I/O from /proc/PID/io. |

Columns of the day file:

| Column | Source | Notes |
|---|---|---|
| `ts` | `date +%s` | |
| `pwm` | it8625/nct hwmon `pwm1` | 0..255, the one PWM channel (chassis fan + parallel-wired CPU fan) |
| `rpm` | `fan1_input` | only one tach is connected |
| `pkg_c` | coretemp "Package id 0" | |
| `core_max_c` | max of coretemp inputs below 120 | what the old chart called "cpu" |
| `cpu_khz` | max `scaling_cur_freq` over cores | drops under thermal/power limits |
| `throttle_pkg` | `cpu0/thermal_throttle/package_throttle_count` | cumulative since boot; a delta > 0 is a throttle event |
| `fcp_temp`, `fcp_src` | `/var/tmp/fanctrlplus/temp_*` | the temperature and source (CPU/Disk/Idle) FanCtrl Plus acted on last |
| `nvme` | nvme hwmon `temp1_input` (Composite) | `nvme0=40;nvme1=44`, live every sample |
| `disks` | Unraid `disks.ini` | `parity=42;disk1=40;...;flash=*`; `*` = unknown or spun down; refreshed at Unraid's own cadence |
| `cpu_busy_pct` | `/proc/stat` delta since the previous sample | whole-system busy % (all cores); added 2026-09-23, absent in older rows |

Empty fields mean the source was absent at that sample. The 5-minute append is a plain
`cat >>`, so a daemon killed mid-write can leave one torn row at the end of a day file;
`tools/hwlog-report.py` skips malformed rows and reports them on stderr. Analyse with
`tools/hwlog-report.py` on a workstation (the box has no python):

```bash
scp 'root@your-server:/mnt/user/appdata/asustor-control/hwlog/*.csv' ./hwlog/
python3 tools/hwlog-report.py hwlog/*.csv
```
