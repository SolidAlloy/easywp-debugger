<?php

define('ERRORS', [0 => 'No error',
                  1 => 'Multi-disk zip archives not supported',
                  2 => 'Renaming temporary file failed',
                  3 => 'Closing zip archive failed',
                  4 => 'Seek error',
                  5 => 'Read error',
                  6 => 'Write error',
                  7 => 'CRC error',
                  8 => 'Containing zip archive was closed',
                  9 => 'No such file',
                 10 => 'File already exists',
                 11 => "Can't open file",
                 12 => 'Failure to create temporary file',
                 13 => 'Zlib error',
                 14 => 'Allowed RAM exhausted',
                 15 => 'Entry has been changed',
                 16 => 'Compression method not supported',
                 17 => 'Premature EOF',
                 18 => 'Invalid argument',
                 19 => 'Not a zip archive',
                 20 => 'Internal error',
                 21 => 'Zip archive inconsistent',
                 22 => "Can't remove file",
                 23 => 'Entry has been deleted',
                 28 => 'Not a zip archive']);

define('DIRS', 'dirs.txt');
define('FILES', 'files.txt');


class FileCounter
{
    protected $sizeLimit = 52428800;  // 50 MB
    protected $ignoreList;
    protected $directory;

    public function __construct()
    {
        $selfName = basename(__FILE__);
        $this->ignoreList = array('.','..', $selfName);
        $this->dirs = fopen(DIRS, 'a');
        $this->files = fopen(FILES, 'a');
    }

    public function __destruct()
    {
        fclose($this->dirs);
        fclose($this->files);
    }

    public function countFiles($directory, $silent=false)
    {
        $number = 0;
        $entries = scandir($directory);
        if ($entries === false && !$silent) {
            throw new Exception("No Such Directory");
        }
        foreach($entries as $entry) {
            if(in_array($entry, $this->ignoreList)) {
                continue;
            }
            if (is_dir(rtrim($directory, '/') . '/' . $entry)) {
                fwrite($this->dirs, $directory.'/'.$entry."\n");
                ++$number;
                $number += $this->countFiles(rtrim($directory, '/') . '/' . $entry, $silent=true);
            } else {
                if (filesize($directory.'/'.$entry) < $this->sizeLimit) {
                    fwrite($this->files, $directory.'/'.$entry."\n");
                    ++$number;
                }
            }
        }
        return $number;
    }
}


function checkArchive($archiveName)
{
    if (file_exists($archiveName)) {
        return false;
    } else {
        return true;
    }
}


class DirZipArchive
{
    protected $startNum;
    protected $zip;
    protected $counter = 0;
    protected $sizeLimit = 52428800;  // 50 MB
    protected $totalSize = 0;
    protected $dirs;
    protected $files;

    public function __construct($archiveName, $startNum = 0)
    {
        $this->zip = new ZipArchive();
        $status = $this->zip->open($archiveName, ZIPARCHIVE::CREATE);

        if (gettype($this->zip) == 'integer') {  // if error upon opening the archive ...
            $error = ERRORS[$zip];
            throw new Exception($error);  // throw it within an exception
        }

        $this->startNum = $startNum;
        $this->dirs = fopen(DIRS, 'r');
        $this->files = fopen(FILES, 'r');
    }

    public function addDirs() {
        while(!feof($this->dirs))  {
            ++$this->counter;
            $this->totalSize += 4098;
            $directory = rtrim(fgets($this->dirs));
            $this->zip->addEmptyDir($directory);
        }
    }

    public function addFilesChunk()
    {
        while(!feof($this->files))  {
            $file = rtrim(fgets($this->files), "\n");
            if (($this->startNum > ++$this->counter) or !$file) {
                continue;
            }
            $this->totalSize += filesize($file);

            if ($this->totalSize > $this->sizeLimit) {
                return $this->counter;
            }
            $this->zip->addFile($file, $file);
        }
        return true;
    }

    public function __destruct()
    {
        fclose($this->dirs);
        fclose($this->files);
        $this->zip->close();
    }

}


function processPreCheckRequest() {
    try {
        $numberSuccess = 1;
        $counter = new FileCounter();
        $number = $counter->countFiles($_POST['directory']);
        $numberError = '';
    } catch (Exception $e) {
        unlink(DIRS);
        unlink(FILES);
        $numberSuccess = true;
        $number = 0;
        $numberError = $e->getMessage();
    }

    if (checkArchive($_POST['archive'])) {
        $checkArchiveSuccess = true;
    } else {
        $checkArchiveSuccess = false;
    }
    return json_encode(array('numberSuccess' => $numberSuccess ,
                             'number' => $number ,
                             'numberError' => $numberError ,
                             'checkArchiveSuccess' => $checkArchiveSuccess ,
                            ));
}


if (isset($_POST['compressPreCheck'])) {
    $jsonResult = processPreCheckRequest();
    die($jsonResult);
}

function processArchiveRequest() {
    if (isset($_POST['startNum']) && !empty($_POST['startNum'])) {
        $startNum = $_POST['startNum'];
    } else {
        $startNum = 0;
    }

    $archive = new DirZipArchive($_POST['archiveName'], $startNum);

    if ($startNum == 0) {
        $archive->addDirs();
    }
    $result = $archive->addFilesChunk();

    if ($result === true) {
        unlink(DIRS);
        unlink(FILES);
        return json_encode(array('success' => true,
                                 'error' => '',
                                 'startNum' => 0,
                                ));
    } else {
        return json_encode(array('success' => 0,
                                 'error' => '',
                                 'startNum' => $result,
                                ));
    }
}


if (isset($_POST['archive'])) {
    try {
        $jsonResult = processArchiveRequest();
        die($jsonResult);
    } catch (Exception $e) {
        unlink(DIRS);
        unlink(FILES);
        die(json_encode(array('success' => 0,
                              'error' => $e->getMessage(),
                              'startNum' => 0,
                             )));
    }
}


?>

<!DOCTYPE html>
<html lang="en">
<head>
  <title>Chunked ZIP Compression</title>
  <meta charset="utf-8">
  <meta name="archiveport" content="width=device-width, initial-scale=1">
  <script src="https://code.jquery.com/jquery-3.4.0.min.js" integrity="sha256-BJeo0qm959uMBGb65z40ejJYGSgR7REI4+CW1fNKwOg=" crossorigin="anonymous"></script>
  <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css">
  <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>
  <link rel="stylesheet" href="https://use.fontawesome.com/releases/v5.8.1/css/all.css" integrity="sha384-50oBUHEmvpQ+1lW4y57PTFmhCaXp0ML5d60M1M7uH2+nqUivzIebhndOJK28anvf" crossorigin="anonymous">
  <style>
    .progress-log{
        margin-bottom: 10px;
        max-height: 550px;
        overflow-x: hidden;
        overflow-y: scroll;
        -webkit-overflow-scrolling: touch;
    }

    .bg-info-custom{
        background-color: #b0f4e6;
    }
    .bg-warning-custom{
        background-color: #efca8c;
    }
    .bg-danger-custom{
        background-color: #f17e7e;
    }
    .bg-success-custom{
        background-color: #a9eca2;
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

    .input-group>.form-control:not(:last-child) {
        border-bottom-right-radius: 3px;
        border-top-right-radius: 3px;
    }

    .input-group>.input-group-prepend:not(:first-child)>.input-group-text {
        border-bottom-left-radius: 3px;
        border-top-left-radius: 3px;
    }
    
</style>

<script>
// global variables
var defaultDoneText = '<i class="fas fa-check fa-fw"></i> Submit';
var defaultFailText = '<i class="fas fa-times fa-fw"></i> Submit';

/**
 * [printMsg outputs given text in the progress log]
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
        liString = '<li class="list-group-item '+color+'">'+msg+'</li>';
    }

    $('#progress-log').append(liString);

    if (scroll) {
        var progressLog = document.getElementById("progress-log");
        progressLog.scrollTop = progressLog.scrollHeight;
    }
};


var handleEmptyResponse = function($button, jsonData, failText) {
    if (!$.trim(jsonData)){
        if (failText) {
            $button.html(failText);
            $button.prop("disabled", false);
        }
        printMsg("Empty response was returned", true, 'bg-danger-custom');
    }
};

/**
 * [handleErrors outputs message about the occured error]
 * @param  {object} jqXHR       [jQuery XHR object]
 * @param  {string} exception   [exception returned by ajax on fail]
 * @param  {object} excludeList [array of errors to ignore]
 * @return {null}
 */
var handleErrors = function (jqXHR, exception, excludeList) {
    // default parameters
    excludeList = excludeList || [];

    // if the error is in excludeList, quit the function
    var stopFunction = false;
    excludeList.forEach(function(element) {
        if (typeof(element) == 'number') {
            if (jqXHR.status == element) {
                stopFunction = true;
            }
        } else if (typeof(element) == 'string') {
            if (exception == element) {
                stopFunction = true;
            }
        }
    });

    if (stopFunction) {
        return;
    }

    if (jqXHR.status === 0) {
        msg = 'Failed To Connect. Network Error';
    } else if (jqXHR.status == 503) {
        msg = 'Service Unavailable. [503]';
    } else if (jqXHR.status == 404) {
        msg = 'Requested page not found. [404]';
    } else if (jqXHR.status == 500) {
        msg = 'Internal Server Error [500].';
    } else if (exception === 'parsererror') {
        msg = 'Requested JSON parse failed.';
    } else if (exception === 'timeout') {
        msg = 'Time out error.';
    } else if (exception === 'abort') {
        msg = 'Ajax request aborted.';
    } else {
        msg = 'Uncaught Error.\n' + jqXHR.responseText;
    }

    printMsg(msg, true, 'bg-danger-custom');
};


/**
 * [sendArchiveRequest sends a request to archive ZIP archive and processes the response]
 * @param  {string} archiveName  [path to zip file]
 * @param  {string} destDir      [destination directory]
 * @param  {integer} maxArchiveTime [maximum time to process archiveion]
 * @param  {integer} totalNum     [total number of files in archive]
 * @param  {integer} startNum     [number of file to start archiveing from]
 * @return {null}
 */
var sendArchiveRequest = function(archiveName, totalNum, startNum) {
    // default parameters
    startNum = startNum || 0;

    $.ajax({
        type: "POST",
        data: {archive: 'submit',
               archiveName: archiveName,
               startNum: startNum},

        success: function(response) {
            var jsonData = JSON.parse(response);
            
            handleEmptyResponse($('#btnArchive'), jsonData, defaultFailText);
                
            if (jsonData.success) {  // if success, show the success button and message
                $('#progress-bar').removeClass('progress-bar-striped bg-info progress-bar-animated').addClass('bg-success').text('100%').width('100%');
                $('#btnArchive').prop("disabled", false);
                $('#btnArchive').html(defaultDoneText);
                printMsg('Archive created successfully!', true, 'bg-success-custom');
            }
            
            // if the compression didn't complete in one turn, start from the last file
            else if (jsonData.startNum) {
                percentage = (jsonData.startNum/totalNum*100).toFixed() + '%';
                $('#progress-bar').text(percentage).width(percentage);
                // if the compression didn't complete but another file appeared to be the last one, continue the next iteration from it (of if there were no starting files before)
                startNum = jsonData.startNum;
                sendArchiveRequest(archiveName, totalNum, startNum);
            } else {  // if complete fail, show returned error
                $('#progress-bar').removeClass('progress-bar-striped bg-info progress-bar-animated').addClass('bg-danger');
                $('#btnArchive').html(defaultFailText);
                $('#btnArchive').prop("disabled", false);
                printMsg('An error happened upon creating the backup: <strong>'+jsonData.error+'</strong>', true, 'bg-danger-custom');
            }
        },
        error: function (jqXHR, exception) {
            handleErrors(jqXHR, exception); // handle errors except for 0 and 503
            $('#progress-bar').removeClass('progress-bar-striped bg-info progress-bar-animated').addClass('bg-danger');
            $('#btnArchive').html(defaultFailText);
            $('#btnArchive').prop("disabled", false);
        }
    });
};


var processArchiveForm = function(form) {
    // preparations
    form.preventDefault();
    var directory = $("#archive-form :input[name='folder-archive']")[0].value;
    if (!directory) {
        directory = '.';
    }
    var archiveName = $("#archive-form :input[name='archive-name']")[0].value;
    if (!archiveName) {
        if (directory == '.') {
            var currentDate = new Date();
            var date = currentDate.getDate();
            var month = currentDate.getMonth() + 1;
            var year = currentDate.getFullYear();
            var hour = currentDate.getHours();
            var minute = currentDate.getMinutes();
            var second = currentDate.getSeconds();

            archiveName = "wp-files-"+year+"-"+month+"-"+date+"_"+hour+":"+minute+":"+second+".zip";
        } else {
            archiveName = directory.substring(directory.lastIndexOf("/")) + '.zip';  // directory name (without parent directories) + .zip
        }
    }
    var loadingText = '<i class="fas fa-circle-notch fa-spin fa-fw"></i> Compressing...';
    $('#btnArchive').prop("disabled", true);
    $('#btnArchive').html(loadingText);
    printMsg('Starting Compression...', true, 'bg-info-custom');

    // send request to get total number of files in directory
    var compressPreCheck = $.ajax({
        type: "POST",
        data: {compressPreCheck: 'submit',
               directory: directory,
               archive: archiveName}
    })
    .done(function( response ) {
        var jsonData = JSON.parse(response);
        
        handleEmptyResponse($('btnArchive'), jsonData, defaultFailText);
            
        if (jsonData.numberSuccess && jsonData.checkArchiveSuccess) {
            $("#progress-container").removeClass('d-none').addClass('show').html('<div class="progress-bar progress-bar-striped bg-info progress-bar-animated" id="progress-bar" role="progressbar" style="width: 2%;">1%</div>');  // 1% is poorly visible with width=1%, so the width is 2 from the start
            sendArchiveRequest(archiveName, jsonData.number);
        } else {
            $('#btnArchive').html(defaultFailText);
            $('#btnArchive').prop("disabled", false);
            if (!jsonData.numberSuccess) {
                printMsg('An error happened upon compressing the directory: <strong>'+jsonData.numberError+'</strong>', true, 'bg-danger-custom');
            }
            if (!jsonData.checkArchiveSuccess) {
                printMsg('An error happened upon compressing the directory: <strong>'+archiveName+' already exists</strong>', true, 'bg-danger-custom');
            }
        }
    })
    .fail(function( jqXHR, exception ) {
        handleErrors(jqXHR, exception);
        $('#btnArchive').html(defaultFailText);
        $('#btnArchive').prop("disabled", false);
    });
};


$(document).ready(function() {
    $('#archive-form').submit(function(form) {
        processArchiveForm(form);
    });
});
    </script>
</head>
<body>
    
<div class="container mt-5 ml-5">
    
<div class="row justify-content-start mt-4" style="margin-left: 0%;">
    <form id="archive-form">
        <div class="form-group input-group mb-0">
            <ul class="list-group mr-3">
                <li class="list-group-item list-group-item-primary" style="height: 38px; padding: 0.3rem 1.25rem;">Create ZIP Archive</li>
            </ul>
            <div class="input-group-prepend">
                <div class="input-group-text input-group-text-info">Folder</div>
            </div>
            <input type="text" class="form-control" id="folder-archive" name="folder-archive" placeholder="wp-content">

            <div class="input-group-prepend">
                <div class="input-group-text input-group-text-info ml-3">Archive Name</div>
            </div>
            <input type="text" class="form-control" id="archive-name" name="archive-name" placeholder="archive.zip">
            <span class="input-group-btn ml-3">
                <button type="submit" class="btn btn-secondary" id="btnArchive">Submit</button>
            </span>
        </div>
    </form>

</div>

<div class="progress mt-3 d-none" style="height: 23px;" id="progress-container">
</div>

<div class="panel panel-primary mt-4" id="result-panel">
    <div class="panel-heading"><h3 class="panel-title">Progress Log</h3>
    </div>
    <div class="panel-body">
        <ul class="progress-log list-group scrollbar-secondary border-top border-bottom rounded" id="progress-log">
        </ul>
    </div>
</div>

</div>
</body>
</html>