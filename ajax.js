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
                temp_title = '/ ' + data[3] + ' °C ';
            }
            document.title = data[2] + ' W ' + temp_title + '[' + data[1] + ' ' + data[0];
        }
    };
    xhttp.open("GET", "index.php?ajax", true);
    xhttp.send();
}

window.onload = function() {
    setInterval(ajax_update, refresh_rate);
}