let pollTimer = null;

/**
 * Fetches installed/pinned version, available releases and the current
 * upgrade job state
 */
function fetchVersionInfo() {
    return new Promise((resolve, reject) => {
        const jqxhr = $.getJSON(`ajax.php/?action=getVersionInfo`);
        jqxhr.done(function (data) {
            resolve(data);
        });

        jqxhr.fail(function (jqxhr, textStatus, error) {
            reject(error);
        });
    });
}

/**
 * Starts a zigbee2mqtt upgrade to the given version
 */
function startUpgrade(version) {
    return new Promise((resolve, reject) => {
        const jqxhr = $.post(`ajax.php/?action=startUpgrade`, { version: version });
        jqxhr.done(function (data) {
            resolve(data);
        });

        jqxhr.fail(function (jqxhr, textStatus, error) {
            reject(jqxhr.responseJSON || error);
        });
    });
}

/**
 * Fetches the current upgrade job state (status + log tail)
 */
function fetchUpgradeStatus() {
    return new Promise((resolve, reject) => {
        const jqxhr = $.getJSON(`ajax.php/?action=getUpgradeStatus`);
        jqxhr.done(function (data) {
            resolve(data);
        });

        jqxhr.fail(function (jqxhr, textStatus, error) {
            reject(error);
        });
    });
}

function setBusy(busy) {
    $("#upgradebtn").prop("disabled", busy);
    $("#targetVersion").prop("disabled", busy);
    if (busy) {
        $(".saveok").fadeOut();
        $(".saveerror").fadeOut();
        $(".submitting").fadeIn();
        $("#upgradelog").show();
    } else {
        $(".submitting").fadeOut();
    }
}

function appendLog(text) {
    const $log = $("#upgradelog");
    $log.text(text || "");
    $log.scrollTop($log[0].scrollHeight);
}

function pollUpgradeStatus() {
    clearTimeout(pollTimer);
    fetchUpgradeStatus().then(function (state) {
        appendLog(state.log);

        if (state.status === "running") {
            pollTimer = setTimeout(pollUpgradeStatus, 2000);
            return;
        }

        setBusy(false);
        if (state.status === "success") {
            $(".saveok").fadeIn();
            fetchVersionInfo().then(populateVersionInfo);
        } else if (state.status === "failed") {
            if (state.message) {
                $(".saveerror").text(state.message);
            }
            $(".saveerror").fadeIn();
            // A failed start still leaves the backup taken before it
            fetchVersionInfo().then(function (data) {
                populateBackups(data.backups);
            });
        }
    }).catch(function () {
        // Transient network hiccup while polling - keep trying
        pollTimer = setTimeout(pollUpgradeStatus, 2000);
    });
}

function populateVersionInfo(data) {
    $("#installedVersion").text(data.installedVersion || "-");

    const $select = $("#targetVersion");
    $select.empty();
    (data.releases || []).forEach(function (version) {
        $select.append($("<option>").val(version).text(version));
    });

    const preselect = data.pinnedVersion || (data.releases && data.releases[0]) || "";
    if (preselect) {
        $select.val(preselect);
    }
    if ($.fn.selectmenu) {
        $select.selectmenu("refresh");
    }

    populateBackups(data.backups);

    if (data.upgrade && data.upgrade.status === "running") {
        setBusy(true);
        pollUpgradeStatus();
    }
}

function formatSize(bytes) {
    if (bytes >= 1024 * 1024) {
        return (bytes / (1024 * 1024)).toFixed(1) + " MB";
    }
    return Math.max(1, Math.round(bytes / 1024)) + " KB";
}

function populateBackups(backups) {
    const $body = $("#backups tbody");
    const downloadText = $("#backups").data("download");
    $body.empty();
    (backups || []).forEach(function (backup) {
        const $download = $("<a>")
            .attr("href", "#")
            .addClass("ui-btn ui-mini ui-btn-inline ui-icon-arrow-d ui-btn-icon-left")
            .text(downloadText)
            .click(function (e) {
                e.preventDefault();
                downloadBackup(backup.file);
            });
        $body.append($("<tr>").append(
            $("<td>").text(new Date(backup.created).toLocaleString()),
            $("<td>").addClass("mono").text(backup.version),
            $("<td>").text(formatSize(backup.size)),
            $("<td>").append($download)
        ));
    });
    $("#nobackups").toggle(!backups || backups.length === 0);
}

/**
 * Downloads a backup. A plain link would not carry the X-Requested-With
 * header that ajax.php requires for it (backups contain the MQTT password
 * and network key), so it is fetched and then saved from a blob.
 */
function downloadBackup(file) {
    $("#backuperror").hide();
    fetch(`ajax.php/?action=downloadBackup&file=${encodeURIComponent(file)}`, {
        headers: { "X-Requested-With": "XMLHttpRequest" },
        credentials: "same-origin",
    }).then(function (response) {
        if (!response.ok) {
            throw new Error(response.statusText);
        }
        return response.blob();
    }).then(function (blob) {
        const url = URL.createObjectURL(blob);
        const link = document.createElement("a");
        link.href = url;
        link.download = file;
        document.body.appendChild(link);
        link.click();
        link.remove();
        // Revoking right away can cancel the download in some browsers
        setTimeout(function () {
            URL.revokeObjectURL(url);
        }, 1000);
    }).catch(function () {
        $("#backuperror").fadeIn();
    });
}

$(document).ready(function () {

    $("#upgradebtn").click(function () {
        const version = $("#targetVersion").val();
        if (!version) {
            return;
        }
        if (!confirm($("#upgradebtn").data("confirm"))) {
            return;
        }

        setBusy(true);
        startUpgrade(version).then(function () {
            pollUpgradeStatus();
        }).catch(function (err) {
            setBusy(false);
            if (err && err.error) {
                $(".saveerror").text(err.error);
            }
            $(".saveerror").fadeIn();
        });
    });

    fetchVersionInfo().then(populateVersionInfo);

})
