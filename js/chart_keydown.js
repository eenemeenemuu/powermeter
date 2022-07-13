document.onkeydown = function(e) {
    if (document.activeElement === document.getElementById("fix")) {
        return;
    }
    if (!e) {
        e = window.event;
    }
    if (e.which) {
        kcode = e.which;
    } else if (e.keyCode) {
        kcode = e.keyCode;
    }
    if (kcode == 33) { // PageUp
        e.preventDefault();
        document.getElementById("max").click();
    }
    if (kcode == 34) { // PageDown
        e.preventDefault();
        document.getElementById("reset").click();
    }
    if (kcode == 35) { // End
        e.preventDefault();
        document.getElementById("live").click();
    }
    if (kcode == 36) { // Home
        e.preventDefault();
        document.getElementById("home").click();
    }
    if (kcode == 37) { // ArrowLeft
        e.preventDefault();
        document.getElementById("prev").click();
    }
    if (kcode == 38) { // ArrowUp
        e.preventDefault();
        if (document.body.contains(document.getElementById("expand"))) {
            document.getElementById("expand").click();
        } else {
            document.getElementById("home").click();
        }
    }
    if (kcode == 39) { // ArrowRight
        e.preventDefault();
        document.getElementById("next").click();
    }
    if (kcode == 40) { // ArrowDown
        e.preventDefault();
        document.getElementById("download").click();
    }
    if (kcode >= 48 && kcode <= 54) { // 0-6
        if (document.getElementById("res").selectedIndex != kcode - 48) {
            document.getElementById("res").selectedIndex = kcode - 48;
            document.forms[0].submit();
        }
    }
};
