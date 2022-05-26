document.onkeydown = function(e) { 
    if (!e) {
        e = window.event;
    }
    if (e.which) {
        kcode = e.which;
    } else if (e.keyCode) {
        kcode = e.keyCode;
    }
    if (kcode == 37) {
        document.getElementById("prev").click();
    }
    if (kcode == 39) {
        document.getElementById("next").click();
    }
    if (kcode == 38) {
        document.getElementById("home").click();
    }
    if (kcode == 40) {
        document.getElementById("download").click();
    }
    if (kcode == 33) {
        document.getElementById("max").click();
    }
    if (kcode == 34) {
        document.getElementById("reset").click();
    }
    e.preventDefault();
};
