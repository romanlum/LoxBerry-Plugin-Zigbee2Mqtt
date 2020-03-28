$(document).ready(function () {

    var editor = ace.edit("editor");
    editor.setTheme("ace/theme/chrome");
    editor.getSession().setMode("ace/mode/yaml");

    $("#saveapply").click(function () {
        saveapply();
    });

    $("#saveapply").blur(function () {
        $("#savemessages").html("");
    });
});

function applyChanges() {
    return new Promise((resolve, reject) => {
        const jqxhr = $.post(`ajax.php/?action=applyChanges`);
        jqxhr.done(function (data) {
            resolve(data);
        });

        jqxhr.fail(function (jqxhr, textStatus, error) {
            reject(error);
        });
    });
}


function saveapply(action = "save", template = "") {

    $(".saveok").fadeOut();
    $(".saveerror").fadeOut();
    $(".submitting").fadeIn();

    var editor = ace.edit("editor");
    data = editor.getValue();

    jqxhr = $.ajax({
        type: "POST",
        contentType: "text/plain",
        url: "ajax.php?action=setDevices",
        dataType: "text",
        data: data
    });

    jqxhr.done(function (data) {

        applyChanges().then(function (values) {
            $(".submitting").fadeOut();
            $(".saveok").fadeIn();
        })
            .catch(function (error) {
                $(".submitting").fadeOut();
                $(".saveerror").fadeIn();
            })
    });
    jqxhr.fail(function (jqxhr, textStatus, error) {
        $(".submitting").fadeOut();
        $(".saveerror").fadeIn();
    });

}