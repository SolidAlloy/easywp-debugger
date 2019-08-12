<?php

ini_set('display_errors', 0);  // in order to not mess up json responses in case of any warnings (like "file not found")
define('ENVIRONMENT', 'PRODUCTION'); // use 'DEVELOPMENT' for more logging messages

/**
 * Puts a message in debug-log.txt if the environment is DEVELOPMENT
 * 
 * @param  string $message Message to put into debug-log.txt
 * @return null
 */
function debugLog($message)
{
    if (ENVIRONMENT == 'DEVELOPMENT') {
        file_put_contents('debug-log.txt', $message."\n", FILE_APPEND);
    }
}


/**
 * Runs for a given number of seconds and writes the current second number to a file each second
 * 
 * @param  integer $index     Index of the request
 * @param  integer $timeLimit Number of seconds to run the request
 * @return null
 */
function waitAndWrite($index, $timeLimit) {
    for ($i = 1; $i <= $timeLimit; ++$i) {
        sleep(1);
        file_put_contents($index.'.txt', strval($i));
    }
}

/**
 * Collects an array of contents from the log files created by waitAndWrite()
 * 
 * @param  integer $number Number of log files to collect the data from
 * @return array           Array containing the data from files
 */
function getValuesFromFiles($number) {
    $values = array();
    for ($i = 1; $i <= $number; ++$i) {
        $filename = strval($i).'.txt';
        $content = file_get_contents($filename);
        if ($content) {
            debugLog('content of '.strval($i).'.txt: '.$content);
        } elseif ($content === false) {
            debugLog("failed to open ".strval($i).".txt");
        }
        array_push($values, $content);
        $unlinkResult = unlink($filename) ? 'true' : 'false';
        debugLog('result of unlinking '.strval($i).'.txt: '.$unlinkResult);
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
 * Outputs given text in the progress log if the user is authorized. It has two versions for
 * the main and the login page so that one function can use it to display text differently
 * 
 * @param  {string} msg     String to print
 * @param  {string} color   Color attribute to add to the <li> tag
 * @param  {boolean} small  Small text
 * @param  {boolean} scroll Auto-scroll to the bottom
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
 * Shows warning if no JSON was found in the response
 * 
 * @param  {object} $button  Button which state should be changed to failed
 * @param  {object} jsonData Parsed json string
 * @param  {string} failText Text to insert into the button
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
 * Outputs message about the occured error
 * 
 * @param  {object}  jqXHR        jQuery XHR object
 * @param  {string}  exception    Exception returned by ajax on fail
 * @param  {integer} index        Index of the failed request
 * @param  {integer} completeTime Time at which the request failed
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
 * Sets maximum height of progress log so that it always stays within the window
 */
var setMaxheight = function(){
    var progressLog = $("#progress-log");
    var canvas = $("#canvas");
    var winHeight = $(window).height();
    winHeight -= 220;
    progressLog.css({'max-height' : winHeight + "px"});
    canvas.css({'height' : winHeight + "px"});
};

/**
 * Checks how long the requests were actually running for and starts a new test
 * 
 * @param  {integer} number     Total number of requests
 * @param  {integer} timeLimit  Time limit of requests
 * @param  {integer} testIndex  Index of the completed test
 * @param  {integer} testsTotal Total number of tests
 * @return {null}
 */
var sendFileCheckRequest = function(number, timeLimit, testIndex, testsTotal) {
    $.ajax({
        type: "POST",
        timeout: 20000,
        data: {filecheck: 'submit' ,
               number: number ,
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
                var counter = 0;
                var realIndex;
                jsonData.files.forEach(function(item, index, array) {
                    if (Number(item) == timeLimit) {  // if the time in files is equal to the time limit, add them to the counter
                        ++counter;
                    } else if (item === false) {  // if the file was not open, it means the request didn't start running
                        realIndex = index+1;
                        printMsg('Request #'+realIndex+' did not start running .', true, 'bg-warning-light');
                    } else {  // if the time in a file is not equal to the time limit, print a message about it
                        realIndex = index+1;
                        printMsg('Request #'+realIndex+' was running for '+item+' seconds on the backend.', true, 'bg-info-light');
                    }
                });
                // print a message about all the successful requests based on the counter value
                if (counter == number) {
                    printMsg('All requests were running for '+timeLimit+' seconds on the backend.', true, 'bg-success-light');
                } else {
                    printMsg('All other requests were running for '+timeLimit+' seconds on the backend.', true, 'bg-success-light');
                }
            }
        },
        error: function (jqXHR, exception) {
            var completeTime = (new Date().getTime() - this.start_time) / 1000;
            handleErrors(jqXHR, exception, 'FileCheck', completeTime);
        },
        complete: function() {
            if (testIndex == testsTotal) {
                printMsg('All tests have been completed.', true, 'bg-success-light');
            } else {
                performTest(number, timeLimit, testIndex+1, testsTotal);  // start the next test and increment testIndex 
            }
        }
    });
};

/**
 * Send a request that will just sleep and update the log file from time to time
 * 
 * @param  {integer} index     Index of the request
 * @param  {integer} timeLimit Time limit of the request
 * @return {null}
 */
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

/**
 * Rounds number to 20 e.g. 52 => 60
 * 
 * @param  {integer} num Number to round
 * @return {integer}     Rounded number
 */
var round20 = function(num) {
  return Math.round(num / 20) * 20;
};

/**
 * Transforms an array of timestamps into a dictionary where timestamps are rounded to 20
 * and sorted into groups
 * 
 * @return {ojbect} [dictionary with timestamps grouped by lengths]
 */
var getGroups = function() {
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
        var rounded = round20(item);
        ++groups[rounded];  // increase the number in the matching group
    });
    return groups;
};

/**
 * Creates a data object for the chart
 * 
 * @return {object} chart data
 */
var getChartData = function() {
    var groups = getGroups();
    var horizontalBarChartData = {
        labels: Object.keys(groups),  // time groups like 10, 20, etc.
        datasets: [{
            label: '',
            backgroundColor: color(blue).alpha(0.5).rgbString(),
            borderColor: blue,
            borderWidth: 1,
            data: Object.values(groups)  // values of each group
        }]

    };
    return horizontalBarChartData;
};

/**
 * Creates a canvas element next to the progress log
 * @return {null}
 */
var createCanvas = function() {
    secondRow = $('#second-row');
    secondRow.append('<div class="col-6"><canvas id="canvas"></canvas></div>');
    secondRow.removeClass('justify-content-center');
};

/**
 * Takes data from errorTimeStamps and creates a new chart on the canvas
 * @return {null}
 */
var printChart = function() {
    createCanvas();
    var ctx = document.getElementById('canvas').getContext('2d');
    window.requestsChart = new Chart(ctx, {
        type: 'horizontalBar',
        data: getChartData(),
        options: {
            responsive: true,
            legend: {
                display: false,
            },
            title: {
                display: true,
                text: 'Time at which requests failed'
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
                        labelString: 'number of requests in a time group',
                    },
                    ticks: {
                        callback: function(value) {if (value % 1 === 0) {return value;}}  // use only integers on the X axis
                    }
                }],
            },
        }
    });
};

/**
 * Updates a chart with the new data
 * 
 * @return {null}
 */
var updateChart = function() {
    window.requestsChart.data = getChartData();
    window.requestsChart.update();
};

/**
 * Performs a single test: makes a number of requests and checks their completion in 5 minutes
 * 
 * @param  {integer} number     Total number of requests
 * @param  {integer} timeLimit  Time limit of each request
 * @param  {integer} testIndex  Index of the test going on
 * @param  {integer} testsTotal Total number of tests that have to be run
 * @return {null}
 */
var performTest = function(number, timeLimit, testIndex, testsTotal) {
    for (const x of Array(number).keys()) {
        sendDummyRequest(x+1, timeLimit);
    }

    printMsg('<strong>Test #'+testIndex+'</strong>: Requests have been sent. Please wait for the test completion in 5 minutes', true, 'bg-info-light');

    setTimeout(function() { sendFileCheckRequest(number, timeLimit, testIndex, testsTotal); }, 304000);  // send file-check request in 304 seconds
    // show percentage of requests in the chart in 301 seconds
    if (window.requestsChart) {
        setTimeout(function() { updateChart(); }, 301000);
    } else {
        setTimeout(function() { printChart(); }, 301000);
    }
};

/**
 * Gets values from the form and starts the first test
 * 
 * @param  {object} form Form to get values from
 * @return {null}
 */
var processDummyForm = function(form) {
    form.preventDefault();
    var number = Number($("#index").val());
    var timeLimit = Number($("#time-limit").val());
    var testsTotal = Number($("#times-number").val());

    performTest(number, timeLimit, 1, testsTotal);
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
        <div class="row mt-5 offset-3" id="first-row">
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

                    <div class="input-group-prepend">
                        <div class="input-group-text input-group-text-info ml-3">and run the test</div>
                    </div>
                    <input type="text" class="form-control col-1" id="times-number" name="times-number" placeholder="3" required>
                    <div class="input-group-append">
                        <div class="input-group-text input-group-text-info">times</div>
                    </div>

                    <span class="input-group-btn ml-3">
                        <button type="submit" class="btn btn-secondary" id="btnDummy">Send</button>
                    </span>
                </div>
            </form>
        </div>

        <div class="row mt-5 justify-content-center" id="second-row">
            <div class="col-6" id="progress-col">
                <div class="panel panel-primary" id="result-panel">
                    <div class="panel-heading"><h3 class="panel-title">Progress Log</h3>
                    </div>
                    <div class="panel-body">
                        <ul class="progress-log list-group scrollbar-secondary border-top border-bottom rounded" id="progress-log">
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>