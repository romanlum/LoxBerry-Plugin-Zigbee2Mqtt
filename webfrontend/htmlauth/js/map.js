$(document).ready(function () {
    var container = document.getElementById("devicemap");
    const options = {
        autoResize: true,
        physics: {
            enabled: true,
            minVelocity: 1,
            maxVelocity: 50,
            repulsion: {
                nodeDistance: 200
            },
            stabilization: {
                enabled: true,
                iterations: 10
            },
            solver: 'repulsion'
        }
    };
    data = vis.parseDOTNetwork($("#deviceMapData").val());
    var network = new vis.Network(container, data, options);
});