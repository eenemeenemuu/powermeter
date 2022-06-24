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
    if (kcode == 37) {
        e.preventDefault();
        document.getElementById("prev").click();
    }
    if (kcode == 39) {
        e.preventDefault();
        document.getElementById("next").click();
    }
    if (kcode == 38) {
        e.preventDefault();
        document.getElementById("home").click();
    }
    if (kcode == 40) {
        e.preventDefault();
        document.getElementById("download").click();
    }
    if (kcode == 33) {
        e.preventDefault();
        document.getElementById("max").click();
    }
    if (kcode == 34) {
        e.preventDefault();
        document.getElementById("reset").click();
    }
    if (kcode >= 48 && kcode <= 54) {
        if (document.getElementById("res").selectedIndex != kcode - 48) {
            document.getElementById("res").selectedIndex = kcode - 48;
            document.forms[0].submit();
        }
    }
};
