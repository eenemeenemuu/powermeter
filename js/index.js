// multiDevice, deviceIds, deviceMeta are set by PHP when in multi-device mode
if (typeof multiDevice === 'undefined') var multiDevice = false;
if (typeof deviceIds === 'undefined') var deviceIds = [];
if (typeof deviceMeta === 'undefined') var deviceMeta = {};
if (typeof virtualTotals === 'undefined') var virtualTotals = [];
if (typeof fieldMap === 'undefined') var fieldMap = {};
if (typeof gesamtGroups === 'undefined') var gesamtGroups = [];

function ajax_update() {
    var xhttp = new XMLHttpRequest();
    xhttp.onreadystatechange = function() {
        if (this.readyState == 4 && this.status == 200) {
            if (multiDevice) {
                ajax_update_multi(this.responseText);
            } else {
                ajax_update_single(this.responseText);
            }
        }
    };
    xhttp.open('GET', 'index.php?ajax', true);
    xhttp.send();
}

function ajax_update_single(responseText) {
    data = responseText.split(',');
    if (unit1_digits) {
        power = Math.round(data[2]);
        if (power > "9".repeat(unit1_digits)) {
            power = "9".repeat(unit1_digits);
        } else if (power < 0 && power < -"9".repeat(unit1_digits-1)) {
            power = "-" + "9".repeat(unit1_digits-1);
        } else if (power < 0) {
            power = "-" + ("0".repeat(unit1_digits) + -power).slice(-unit1_digits+1);
        } else {
            power = ("0".repeat(unit1_digits) + power).slice(-unit1_digits);
        }
        document.getElementById('power').innerHTML = power;
        document.title = data[2] + ' ' + unit1 + ' [' + data[1] + ' ' + data[0] + ']';
    } else {
        unit2_title = '';
        document.getElementById('date').innerHTML = data[0];
        document.getElementById('time').innerHTML = data[1];
        document.getElementById('power').innerHTML = data[2];
        if (data[3]) {
            document.getElementById('temp').innerHTML = data[3];
            unit2_title = ' / ' + data[3] + ' ' + document.getElementById('unit2').innerHTML;
        }
        for (i = 4; i < data.length; i++) {
            if (data[i]) {
                document.getElementById('l'+(i-3)).innerHTML = data[i];
            }
        }
        document.title = data[2] + ' ' + unit1 + unit2_title + ' [' + data[1] + ' ' + data[0] + ']';
    }
}

function ajax_update_multi(responseText) {
    var json;
    try {
        json = JSON.parse(responseText);
    } catch (e) {
        return;
    }

    // Update combined power per gesamt group
    for (var gi = 0; gi < gesamtGroups.length; gi++) {
        var group = gesamtGroups[gi];
        var groupPower = 0;
        for (var gd = 0; gd < group.devices.length; gd++) {
            var gDevId = group.devices[gd];
            if (json.devices[gDevId] && json.devices[gDevId].stats && json.devices[gDevId].stats[2] !== undefined) {
                groupPower += parseFloat(json.devices[gDevId].stats[2]) || 0;
            }
        }
        var elId = gi === 0 ? 'combined_power' : 'combined_power_' + gi;
        var el = document.getElementById(elId);
        if (el) el.innerHTML = Math.round(groupPower);
        if (gi === 0) {
            document.title = Math.round(groupPower) + ' ' + unit1 + ' ' + group.label;
        }
    }

    // Update each device box
    for (var d = 0; d < deviceIds.length; d++) {
        var id = deviceIds[d];
        var deviceData = json.devices[id];
        if (!deviceData || !deviceData.stats) continue;
        var stats = deviceData.stats;

        // Check for error
        if (stats.error) continue;

        var powerEl = document.getElementById('power_' + id);
        if (powerEl && stats[2] !== undefined) powerEl.innerHTML = stats[2];

        var tempEl = document.getElementById('temp_' + id);
        if (tempEl && stats[3] !== undefined) tempEl.innerHTML = stats[3];

        var timeEl = document.getElementById('time_' + id);
        if (timeEl && stats[1] !== undefined) timeEl.innerHTML = stats[1];

        var dateEl = document.getElementById('date_' + id);
        if (dateEl && stats[0] !== undefined) dateEl.innerHTML = stats[0];

        // Extra units (l1, l2, l3, ...)
        for (var i = 4; i < stats.length; i++) {
            var lEl = document.getElementById('l' + (i - 3) + '_' + id);
            if (lEl && stats[i]) lEl.innerHTML = stats[i];
        }
    }

    // Update virtual totals
    if (virtualTotals.length > 0) {
        for (var v = 0; v < virtualTotals.length; v++) {
            var vt = virtualTotals[v];
            var value = pm_evaluate_formula(vt.formula, json.devices);
            var vtEl = document.getElementById('virtual_' + v);
            if (vtEl) vtEl.innerHTML = Math.round(value);
        }
    }
}

window.onload = function() {
    setInterval(ajax_update, refresh_rate);
    set_colors();
}

function pm_evaluate_formula(formula, devices) {
    var tokens = formula.split(/\s*([+\-])\s*/);
    var result = 0;
    var op = '+';
    for (var i = 0; i < tokens.length; i++) {
        var token = tokens[i].trim();
        if (!token) continue;
        if (token === '+' || token === '-') { op = token; continue; }
        var parts = token.split('.');
        if (parts.length !== 2) continue;
        var deviceId = parts[0];
        var field = parts[1];
        var index = fieldMap[field];
        if (index === undefined) continue;
        var val = 0;
        if (devices[deviceId] && devices[deviceId].stats && devices[deviceId].stats[index] !== undefined) {
            val = parseFloat(devices[deviceId].stats[index]) || 0;
        }
        if (op === '+') { result += val; } else { result -= val; }
    }
    return result;
}

function set_colors() {
    var dm = document.getElementById('dark_mode');
    if (!dm) return;

    if (multiDevice) {
        // Multi-device: toggle body.dark class, CSS handles the rest
        if (dm.checked) {
            document.body.classList.add('dark');
        } else {
            document.body.classList.remove('dark');
        }
    } else {
        // Single-device: legacy inline style approach
        if (dm.checked) {
            var color1 = 'black';
            var color2 = '#DCDCDC';
            var bodycolor = 'black';
        } else {
            var color1 = 'white';
            var color2 = 'black';
            var bodycolor = '#252525';
        }
        document.body.style.backgroundColor = bodycolor;
        var td = document.getElementsByTagName('td');
        for (var i = 0; i < td.length; i++) {
            td[i].style.backgroundColor = color1;
            td[i].style.color = color2;
        }
        var a = document.getElementsByTagName('a');
        for (var i = 0; i < a.length; i++) {
            a[i].style.color = color2;
        }
    }
}
