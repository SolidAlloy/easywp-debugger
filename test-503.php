<?php

ini_set('display_errors', 0);

function waitAndWrite($index, $timeLimit) {
    for ($i = 1; $i <= $timeLimit; ++$i) {
        sleep(1);
        file_put_contents($index.'.txt', strval($i));
    }
}


function getValuesFromFiles($number) {
    $values = array();
    for ($i = 1; $i <= $number; ++$i) {
        $filename = strval($i).'.txt';
        array_push($values, file_get_contents($filename));
        unlink($filename);
    }
    return $values;
}


if (isset($_POST['dummy'])) {
    waitAndWrite($_POST['index'], $_POST['timeLimit']);
    die(json_encode(array('success' => true)));
}

if (isset($_POST['filecheck'])) {
    $values = getValuesFromFiles($_POST['number']);
    die(json_encode(array('success' => true ,
                          'files' => $values ,
                         )));
}

?>

<!DOCTYPE html>
<html lang="en">
<head>

<script src="https://code.jquery.com/jquery-3.4.0.min.js" integrity="sha256-BJeo0qm959uMBGb65z40ejJYGSgR7REI4+CW1fNKwOg=" crossorigin="anonymous"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/2.8.0/Chart.bundle.min.js"></script>

<script>
/*jshint esversion: 6 */

// global values
var errorTimeStamps = [];
var color = Chart.helpers.color;
var blue = 'rgb(54, 162, 235)';

/**
 * [printMsg outputs given text in the progress log if the user is authorized. It has two versions for
 *  the main and the login page so that one function can use it to display text differently]
 * @param  {string} msg    [string to print]
 * @param  {string} color  [color attribute to add to the <li> tag]
 * @param  {boolean} small  [small text]
 * @param  {boolean} scroll [auto-scroll to the bottom]
 * @return {null}
 */
var printMsg = function(msg, scroll, color, small) {
    // default parameters
    color = color || '';
    small = small || false;

    if (small) {
        liString = '<li class="list-group-item '+color+'" style="height: 30px; padding-top: 0px; padding-bottom: 0px;"><small>'+msg+'</small></li>';
    } else {
        liString = '<li class="list-group-item '+color+'" style="height: 40px; padding-top: 7px;">'+msg+'</li>';
    }

    $('#progress-log').append(liString);

    if (scroll) {
        var progressLog = document.getElementById("progress-log");
        progressLog.scrollTop = progressLog.scrollHeight;
    }
};

/**
 * [handleEmptyResponse shows warning if no JSON was found in the response]
 * @param  {object} $button  [button which state should be changed to failed]
 * @param  {object} jsonData [parsend json string]
 * @param  {string} failText [text to insert into the button]
 * @return {null}
 */
var handleEmptyResponse = function($button, jsonData, failText) {
    if (!$.trim(jsonData)){
        if (failText) {
            $button.html(failText);
            $button.prop("disabled", false);
        }
        printMsg("Empty response was returned", true, 'bg-danger-light');
    }
};

/**
 * [handleErrors outputs message about the occured error]
 * @param  {object} jqXHR       [jQuery XHR object]
 * @param  {string} exception   [exception returned by ajax on fail]
 * @param  {object} excludeList [array of errors to ignore]
 * @return {null}
 */
var handleErrors = function (jqXHR, exception, index, completeTime) {
    var msg = '<strong>Request #'+index+' ran for '+completeTime+' seconds and returned:</strong> ';

    if (jqXHR.status === 0) {
        msg += 'Failed To Connect. Network Error';
    } else if (jqXHR.status == 503) {
        msg += 'Service Unavailable. [503]';
    } else if (jqXHR.status == 404) {
        msg += 'Requested page not found. [404]';
    } else if (jqXHR.status == 500) {
        msg += 'Internal Server Error [500].';
    } else if (exception === 'parsererror') {
        msg += 'Requested JSON parse failed.';
    } else if (exception === 'timeout') {
        msg += 'Time out error.';
    } else if (exception === 'abort') {
        msg += 'Ajax request aborted.';
    } else {
        msg += 'Uncaught Error.\n' + jqXHR.responseText;
    }

    printMsg(msg, true, 'bg-danger-light');
};

/**
 * [setMaxheight sets maximum height of progress log so that it always stays within the window]
 */
var setMaxheight = function(){
    var progressLog = $("#progress-log");
    var canvas = $("#canvas");
    var winHeight = $(window).height();
    winHeight -= 220;
    progressLog.css({'max-height' : winHeight + "px"});
    canvas.css({'height' : winHeight + "px"});
};


var sendFileCheckRequest = function(number, timeLimit) {
    $.ajax({
        type: "POST",
        timeout: 20000,
        data: {filecheck: 'submit' ,
               number: number ,
              },
        success: function(response) {
            var jsonData;
            try {
                jsonData = JSON.parse(response);
            } catch (e) {
                printMsg('The returned value is not JSON', true, 'bg-danger-light');
                return;
            }
            handleEmptyResponse($("#btnDummy"), jsonData);
            if (jsonData.success) {
                var counter = 0;
                jsonData.files.forEach(function(item, index, array) {
                    if (Number(item) == timeLimit) {
                        ++counter;
                    } else if (item === false) {
                        var realIndex = index+1;
                        printMsg('Request #'+realIndex+' did not start running in fact.', true, 'bg-warning-light');
                    } else {
                        var realIndex = index+1;
                        printMsg('Request #'+realIndex+' was running for '+item+' seconds in fact.', true, 'bg-info-light');
                    }
                });
                if (counter == number) {
                    printMsg('All requests were running for '+timeLimit+' seconds in fact.', true, 'bg-success-light');
                } else {
                    printMsg('All other requests were running for '+timeLimit+' seconds in fact.', true, 'bg-success-light');
                }
            }
        },
        error: function (jqXHR, exception) {
            handleErrors(jqXHR, exception, index);
        }
    });
};


var sendDummyRequest = function(index, timeLimit) {
    $.ajax({
        type: "POST",
        timeout: 300000,
        data: {dummy: 'submit' ,
               timeLimit: timeLimit ,
               index: index ,
              },
        start_time: new Date().getTime(),
        success: function(response) {
            var jsonData;
            try {
                jsonData = JSON.parse(response);
            } catch (e) {
                printMsg('The returned value is not JSON', true, 'bg-danger-light');
                return;
            }
            handleEmptyResponse($("#btnDummy"), jsonData);
            if (jsonData.success) {
                printMsg('Request #'+index+' returned successfully', true, 'bg-success-light');
            }
        },
        error: function (jqXHR, exception) {
            var completeTime = (new Date().getTime() - this.start_time) / 1000;
            handleErrors(jqXHR, exception, index, completeTime);
            errorTimeStamps.push(completeTime);
        },
    });
};


var round10 = function(num) {
  return Math.round(num / 10) * 10;
};


var getPercentage = function() {
    var groups = {0: 0,
                  20: 0,
                  40: 0,
                  60: 0,
                  80: 0,
                  100: 0,
                  120: 0,
                  140: 0,
                  160: 0,
                  180: 0,
                  200: 0,
                  220: 0,
                  240: 0,
                  260: 0,
                  280: 0,
                  300: 0,
                 };

    errorTimeStamps.forEach(function(item, index, array) {
        var rounded = round10(item);  // round to 10
        ++groups[rounded];  // increase the number in the matching group
    });
    return groups;
};


var printChart = function() {
    var groups = getPercentage();
    var horizontalBarChartData = {
        labels: Object.keys(groups),
        datasets: [{
            label: '',
            backgroundColor: color(blue).alpha(0.5).rgbString(),
            borderColor: blue,
            borderWidth: 1,
            data: Object.values(groups)
        }]

    };

    var ctx = document.getElementById('canvas').getContext('2d');
    window.requestsChart = new Chart(ctx, {
        type: 'horizontalBar',
        data: horizontalBarChartData,
        options: {
            responsive: true,
            legend: {
                display: false,
            },
            title: {
                display: true,
                text: 'Times at which requests failed'
            },
            scales: {
                yAxes: [{
                    scaleLabel: {
                        display: true,
                        labelString: 'time in seconds',
                    }
                }],
                xAxes: [{
                    scaleLabel: {
                        display: true,
                        labelString: 'number of requests in one time group',
                    },
                    ticks: {
                        callback: function(value) {if (value % 1 === 0) {return value;}}
                    }
                }],
            },
        }
    });
};


var processDummyForm = function(form) {
    form.preventDefault();
    var number = Number($("#index").val());
    var timeLimit = Number($("#time-limit").val());

    for (const x of Array(number).keys()) {
        sendDummyRequest(x+1, timeLimit);
    }

    printMsg('Requests have been sent. Please wait for the test completion in 300 seconds', true, 'bg-info-light');

    setTimeout(function() { sendFileCheckRequest(number, timeLimit); }, 301000);  // send file-check request in 301 seconds

    setTimeout(function() { printChart(); }, 301000);  // show percentage of requests in 301 seconds
};


$(document).ready(function() {

    setMaxheight();

    $(window).resize(function(){
        setMaxheight();
    });

    $('#dummy-form').submit(function(form) {
        processDummyForm(form);
    });
});

</script>


<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css">
<link rel="stylesheet" href="https://use.fontawesome.com/releases/v5.8.1/css/all.css" integrity="sha384-50oBUHEmvpQ+1lW4y57PTFmhCaXp0ML5d60M1M7uH2+nqUivzIebhndOJK28anvf" crossorigin="anonymous">


<style>

    .container-fluid {
        margin-left: auto;
        margin-right: auto;
        padding-left: 50px;
        padding-right: 50px;
        padding-top: 20px;
    }

    .progress-log{
        margin-bottom: 10px;
        overflow-x: hidden;
        overflow-y: scroll;
        -webkit-overflow-scrolling: touch;
    }

    .bg-info-light{
        background-color: #bee5eb;
    }
    .bg-warning-light{
        background-color: #ffeeba;
    }
    .bg-danger-light{
        background-color: #f5c6cb;
    }
    .bg-success-light{
        background-color: #c3e6cb;
    }

    .scrollbar-secondary::-webkit-scrollbar {
        background-color: #F5F5F5;
        border-bottom-right-radius: 3px;
        border-top-right-radius: 3px;
        max-height: 550px;
        width: 12px;

    }
    .scrollbar-secondary::-webkit-scrollbar-thumb {
        background-color: #6C757D;
        border-radius: 3px;
        -webkit-box-shadow: inset 0 0 6px rgba(0, 0, 0, 0.1);
    }

    .input-group-text-info {
        background-color: #bee5eb;
        color: #0c5460;
    }

    canvas {
        -moz-user-select: none;
        -webkit-user-select: none;
        -ms-user-select: none;
    }

    /*
        fix round borders for forms
     */

    .input-group > .input-group-append:last-child > .btn:not(:last-child):not(.dropdown-toggle), .input-group > .input-group-append:last-child > .input-group-text:not(:last-child), .input-group > .input-group-append:not(:last-child) > .btn, .input-group > .input-group-append:not(:last-child) > .input-group-text, .input-group > .input-group-prepend > .btn, .input-group > .input-group-prepend > .input-group-text {
        border-bottom-right-radius: 3px;
        border-top-right-radius: 3px;
    }

    .input-group > .input-group-append > .btn, .input-group > .input-group-append > .input-group-text, .input-group > .input-group-prepend:first-child > .btn:not(:first-child), .input-group > .input-group-prepend:first-child > .input-group-text:not(:first-child), .input-group > .input-group-prepend:not(:first-child) > .btn, .input-group > .input-group-prepend:not(:first-child) > .input-group-text {
        border-bottom-left-radius: 3px;
        border-top-left-radius: 3px;
    }

</style>

    <title>Test POST Requests</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>

<body>
    <div class="container-fluid h-100">
        <div class="row justify-content-center mt-5">
            <form id="dummy-form">
                <div class="form-group input-group mb-0">
                    <div class="input-group-prepend">
                        <div class="input-group-text input-group-text-info">Send</div>
                    </div>
                    <input type="text" class="form-control col-1" id="index" name="requests-number" placeholder="5" required>
                    <div class="input-group-append">
                        <div class="input-group-text input-group-text-info">requests</div>
                    </div>

                    <div class="input-group-prepend">
                        <div class="input-group-text input-group-text-info ml-3">with</div>
                    </div>
                    <input type="text" class="form-control col-1" id="time-limit" name="time-limit" placeholder="200" required>
                    <div class="input-group-append">
                        <div class="input-group-text input-group-text-info">time limit</div>
                    </div>

                    <span class="input-group-btn ml-3">
                        <button type="submit" class="btn btn-secondary" id="btnDummy">Send</button>
                    </span>
                </div>
            </form>
        </div>

        <div class="row mt-5 d-flex">
            <div class="col-6">
                <div class="panel panel-primary" id="result-panel">
                    <div class="panel-heading"><h3 class="panel-title">Progress Log</h3>
                    </div>
                    <div class="panel-body">
                        <ul class="progress-log list-group scrollbar-secondary border-top border-bottom rounded" id="progress-log">
                        </ul>
                    </div>
                </div>
            </div>
            <div class="col-6">
                <canvas id="canvas"></canvas>
            </div>
        </div>
    </div>
</body>