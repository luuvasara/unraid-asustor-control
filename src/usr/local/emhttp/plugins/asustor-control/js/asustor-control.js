/* asustor-control.js - the live parts of the Overview, LCD and LEDs and Buttons tabs (every tab renders into one DOM, so this
 * file is loaded once, by include/acs.php). Answers come from include/ajax.php (POST; Unraid's pages define csrf_token and its
 * prologue checks it). Text from logs and files goes in with .text(). The History tab has its own script (js/ac-charts.js). */
if (!window.acStarted) {
window.acStarted = true;
var ajaxURL = '/plugins/asustor-control/include/ajax.php';
var autoTimer = null;
var unraidWarn = 45, unraidCrit = 55, unraidWarnSsd = 60;

function post(action, extra) {
    var data = {action: action};
    if (extra) { for (var k in extra) data[k] = extra[k]; }
    return Promise.resolve($.post(ajaxURL, data));
}

function parse(data) {
    if (typeof data === 'string') return JSON.parse(data);
    return data;
}

function tempClass(t) {
    if (t >= unraidCrit) return 'ac-hot';
    if (t >= unraidWarn) return 'ac-warm';
    return 'ac-cool';
}

function refreshStatus() {
    post('getStatus').then(function(data) {
        data = parse(data);
        var lcdRun = data.lcd_running;
        var hw = data.hw || {};

        if (data.unraid_warn) unraidWarn = data.unraid_warn;
        if (data.unraid_crit) unraidCrit = data.unraid_crit;
        if (data.unraid_warnssd) unraidWarnSsd = data.unraid_warnssd;

        $('#ac-lcd-dot').removeClass('ac-dot-green ac-dot-red ac-dot-grey')
            .addClass(lcdRun ? 'ac-dot-green' : 'ac-dot-red');
        var statusClass = lcdRun ? 'ac-status-ok' : 'ac-status-error';
        var statusText = lcdRun ? 'LCD daemon running' : 'LCD daemon stopped';
        $('#ac-status').removeClass('ac-status-ok ac-status-warning ac-status-error').addClass(statusClass);
        $('#ac-status-text').text(statusText);
        $('#st-lcd-status').text(lcdRun ? 'Running' : 'Stopped');
        showDriverBanner(data.drivers);

        // Hardware readings
        var pwm = hw.pwm;
        var rpm = hw.rpm;
        var cpuTemp = hw.cpu_temp;
        var maxDrive = hw.max_drive_temp;

        if (pwm != null) {
            var pct = Math.round(pwm * 100 / 255);
            $('#st-fan-pwm').text(pwm + ' / 255 (' + pct + '%)');
            $('#st-pwm-bar').css('width', pct + '%');
        } else {
            $('#st-fan-pwm').text('N/A');
            $('#st-pwm-bar').css('width', '0%');
        }
        $('#st-fan-rpm').text(rpm != null ? rpm + ' RPM' : 'N/A');
        var hl = data.hwlog;
        if (!data.hwmon_running) {
            $('#st-hwlog').html('<span class="ac-hot">monitor daemon not running</span>');
        } else if (!hl) {
            $('#st-hwlog').text('starting (no status yet)');
        } else {
            var mb = (hl.bytes / 1048576).toFixed(1);
            var when = hl.last_flush_ts ? new Date(hl.last_flush_ts * 1000).toLocaleTimeString() : 'never';
            var txt = hl.files + ' day file(s), ' + mb + ' MB in ' + hl.logdir + ' &middot; last write ' + when + ' &middot; ' + hl.rows_buffered + ' rows buffered';
            if (hl.last_error) txt += ' &middot; <span class="ac-hot">' + $('<div>').text(hl.last_error).html() + '</span>';
            $('#st-hwlog').html(txt);
        }
        $('#st-cpu-temp').html(cpuTemp != null ? '<span class="' + tempClass(cpuTemp) + '">' + cpuTemp + '&deg;C</span>' : 'N/A');
        $('#st-drive-temp').html(maxDrive != null ? '<span class="' + tempClass(maxDrive) + '">' + maxDrive + '&deg;C</span>' : 'N/A');

        // Drive list
        var drives = hw.drive_temps || {};
        var driveKeys = Object.keys(drives).sort();
        if (driveKeys.length > 0) {
            var dhtml = '';
            driveKeys.forEach(function(d) {
                var t = drives[d];
                var isNvme = d.indexOf('nvme') >= 0;
                var dWarn = isNvme ? unraidWarnSsd : unraidWarn;
                var warnTag = '';
                if (t >= unraidCrit) warnTag = ' <b>CRITICAL</b>';
                else if (t >= dWarn) warnTag = ' Warning';
                dhtml += '<tr><td>' + d + (isNvme ? ' (NVMe)' : '') + '</td><td><span class="' + tempClass(t) + '">' + t + '&deg;C</span>' + warnTag + '</td></tr>';
            });
            $('#st-drive-list').html(dhtml);
        } else {
            $('#st-drive-list').html('<tr><td colspan="2">No drives detected</td></tr>');
        }

        // LCD info
        var lcd = data.lcd;
        if (lcd) {
            $('#st-lcd-screen').text(lcd.current_screen || '--');
            $('#st-lcd-port').text(lcd.port || '--');
            if (lcd.line1 !== undefined) $('#lcd-line1').text(lcd.line1 || ' ');
            if (lcd.line2 !== undefined) $('#lcd-line2').text(lcd.line2 || ' ');
        }
    });
}

function showDriverBanner(d) {
    if (!d || d.state === 'ok' || d.state === 'warn') {
        $('#ac-driver-banner').hide();
        return;
    }
    $('#ac-driver-title').text('Asustor kernel drivers missing on kernel ' + d.kernel);
    $('#ac-driver-msg').text(d.message);
    $('#ac-driver-banner').show();
}

var ledLabels = {none: 'Nothing', log: 'Log only', notify: 'Notification', locate: 'Locate all bays', script: 'Run script'};
function refreshLeds() {
    post('getLeds').then(function(data) {
        data = parse(data);
        var L = data.leds, cfg = data.cfg || {};
        var note = '';
        var kk = data.kmods || {};
        if (!data.led_class) note = 'LED/button drivers missing: leds-gpio and gpio-keys-polled are not loaded on this kernel. ' +
            (kk.provided ? 'They should come from the Asustor Platform Drivers plugin; check it and the system log.' :
             (kk.available ? 'Install the add-on below.' : 'The add-on has no build for this kernel yet; the panel keeps its firmware state.'));
        else if (cfg.leds_enabled !== 'yes') note = 'LED control is disabled; the LEDs keep whatever state they were left in.';
        else if (!data.leds_running) note = 'LED daemon not running.';
        $('#ac-leds-note').text(note).toggle(note !== '');
        var html = '';
        if (L && L.bays && L.bays.length) {
            L.bays.forEach(function(b) {
                var disk = b.dev ? (b.name ? b.name + ' (' + b.dev + ')' : b.dev) : '<span class="ac-muted">empty</span>';
                var act = b.dev ? '<span class="ac-dot ac-dot-green"></span> blinks on I/O' : '<span class="ac-dot ac-dot-grey"></span> off';
                var flt = b.fault ? '<span class="ac-dot ac-dot-red"></span> <b>on</b> (' + $('<div>').text(b.status).html() + ')' : '<span class="ac-dot ac-dot-grey"></span> off';
                var loc = (L.locate_bay === b.bay || L.locate_bay === 0) ? ' <i class="fa fa-lightbulb-o"></i> ' + L.locate_left + ' s' : '';
                html += '<tr><td>' + b.bay + '</td><td>' + disk + '</td><td>' + act + '</td><td>' + flt + '</td>' +
                        '<td><button class="ac-btn" onclick="locateBay(' + b.bay + ')">Locate</button>' + loc + '</td></tr>';
            });
            $('#ac-front-status').text('Status LED: array ' + L.array + (L.faults ? ', red: ' + L.faults + ' disk(s) need attention' : ''));
        } else {
            html = '<tr><td colspan="5">' + (data.led_class ? 'Waiting for the LED daemon...' : 'No front-panel LEDs available.') + '</td></tr>';
        }
        $('#ac-bay-list').html(html);
        $('.ac-action').each(function() {
            var sel = $(this);
            if (sel.children().length) return;
            Object.keys(ledLabels).forEach(function(k) { sel.append($('<option>').val(k).text(ledLabels[k])); });
        });
        if (!$('#tab-leds').data('loaded')) {
            $('#cfg-leds-enabled').val(cfg.leds_enabled);
            $('#cfg-bay-ports').val(cfg.bay_ports === 'auto' ? '' : cfg.bay_ports);
            $('#cfg-bay-confirmed').val(cfg.bay_order_confirmed === 'yes' ? 'yes' : 'no');
            $('#cfg-copy-short').val(cfg.copy_short);
            $('#cfg-copy-long').val(cfg.copy_long);
            $('#cfg-long-press').val(cfg.long_press_s);
            $('#tab-leds').data('loaded', true);
        }
        var B = data.buttons;
        $('#ac-buttons-last').text(B && B.device ? (B.last ? 'Last press: ' + B.last : 'Button device ready') : 'No button device');
        var k = data.kmods || {};
        $('#ac-kmods-status').html((k.lines || []).map(function(l) { return $('<div>').text(l).html(); }).join('<br>') || '--');
        var loaded = (k.lines || []).join(' ').indexOf('leds_gpio: loaded') >= 0;
        $('#ac-kmods-install').prop('disabled', !k.available || loaded)
            .text(k.provided ? 'Provided by another package' : (loaded ? 'Installed and loaded' : (k.available ? 'Install LED/button drivers' : 'No build for this kernel')));
    });
}

function locateBay(bay) {
    post('locate', {bay: bay, seconds: 30}).then(function() { setTimeout(refreshLeds, 5500); });
}

function saveLedConfig() {
    post('saveLedConfig', {
        leds_enabled: $('#cfg-leds-enabled').val(), bay_ports: $('#cfg-bay-ports').val() || 'auto', bay_order_confirmed: $('#cfg-bay-confirmed').val(),
        copy_short: $('#cfg-copy-short').val(), copy_long: $('#cfg-copy-long').val(),
        long_press_s: $('#cfg-long-press').val()
    }).then(function(data) {
        data = parse(data);
        $('#ac-bay-note').text(data.note || '');
        $('#tab-leds').data('loaded', false); setTimeout(refreshLeds, 2500);
    });
}

function kmodsInstall() {
    $('#ac-kmods-result').text('Installing...');
    post('kmodsInstall').then(function(data) {
        data = parse(data);
        $('#ac-kmods-result').text((data.lines || []).join(' '));
        setTimeout(refreshAll, 3000);
    });
}

function refreshLog() {
    post('getLog').then(function(data) {
        data = parse(data);
        if (data.length === 0) {
            $('#ac-action-log').text('No actions yet.');
        } else {
            $('#ac-action-log').html(data.map(function(l) { return $('<div>').text(l).prop('outerHTML'); }).join(''));
            var el = document.getElementById('ac-action-log');
            el.scrollTop = el.scrollHeight;
        }
    });
}

function loadLcdConfig() {
    post('getLcdConfig').then(function(data) {
        data = parse(data);
        $('#cfg-lcd-enabled').val(data.enabled !== 'false' ? 'true' : 'false');
        $('#cfg-lcd-port').val(data.port || '/dev/ttyS1');
        $('#cfg-lcd-cycle').val(data.cycle_interval || 5);
        var screens = (data.screens || 'hostname,ip,temps,array,time').split(',');
        ['hostname','ip','temps','array','time','fan'].forEach(function(s) {
            $('#scr-' + s).prop('checked', screens.indexOf(s) >= 0);
        });
    });
}

function saveLcdConfig() {
    var screens = [];
    ['hostname','ip','temps','array','time','fan'].forEach(function(s) {
        if ($('#scr-' + s).is(':checked')) screens.push(s);
    });
    var data = {
        enabled: $('#cfg-lcd-enabled').val(),
        port: $('#cfg-lcd-port').val(),
        cycle_interval: $('#cfg-lcd-cycle').val(),
        screens: screens.join(','),
    };
    post('saveLcdConfig', data).then(function() {
        post('restartLcd').then(function() {
            setTimeout(refreshAll, 2000);
        });
    });
}

function doAction(action) {
    post(action).then(function() {
        setTimeout(refreshAll, 2000);
    });
}

function refreshAll() {
    refreshStatus();
    if ($('#ac-bay-list').is(':visible')) refreshLeds();
    refreshLog();
}

// the LCD and LEDs and Buttons tabs are pages in the same document: load them once, refresh the LEDs when shown
$(document).on('click', '.tabs [role="tab"]', function() { setTimeout(function() { if ($('#ac-bay-list').is(':visible')) refreshLeds(); }, 50); });

$(function() {
    $('#ac-auto-refresh').on('change', function() {
        if (autoTimer) { clearInterval(autoTimer); autoTimer = null; }
        if (this.checked) autoTimer = setInterval(refreshAll, 10000);
    });
    loadLcdConfig();
    refreshLeds();
    refreshAll();
    autoTimer = setInterval(refreshAll, 10000);
});

}
