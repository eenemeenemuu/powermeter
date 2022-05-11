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
};
