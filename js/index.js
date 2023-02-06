function ajax_update() {
    var xhttp = new XMLHttpRequest();
    xhttp.onreadystatechange = function() {
        if (this.readyState == 4 && this.status == 200) {
            temp_title = '';
            data = this.responseText.split(',');
            document.getElementById('date').innerHTML = data[0];
            document.getElementById('time').innerHTML = data[1];
            document.getElementById('power').innerHTML = data[2];
            if (data[3]) {
                document.getElementById('temp').innerHTML = data[3];
                temp_title = ' / ' + data[3] + ' ' + document.getElementById('temp_unit').innerHTML;
            }
            for (i = 4; i < data.length; i++) {
                if (data[i]) {
                    document.getElementById('l'+(i-3)).innerHTML = data[i];
                }
            }
            document.title = data[2] + ' W' + temp_title + ' [' + data[1] + ' ' + data[0] + ']';
        }
    };
    xhttp.open('GET', 'index.php?ajax', true);
    xhttp.send();
}

window.onload = function() {
    setInterval(ajax_update, refresh_rate);
    set_colors();
}

function set_colors() {
    if (document.getElementById('dark_mode').checked) {
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
    for (i = 0; i < td.length; i++) {
        td[i].style.backgroundColor = color1;
        td[i].style.color = color2;
    }
    var a = document.getElementsByTagName('a');
    for (i = 0; i < a.length; i++) {
        a[i].style.color = color2;
    } 
}
