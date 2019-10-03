<?php
$content = file_get_contents('https://cron.nctool.me/');
if ($content) {
    echo $content;
} else {
    echo 'fail';
}