<?php
/**
 * Generator of secure debugger files. It generates a random password and filename for the file, than makes a visitor download it.
 */

/**
 * Generate a random string of certain length from the given list of characters.
 *
 * @param  string  $possibleChars String containing the characters that can be used in the random string
 * @param  integer $length        Length of the random string
 * @return string                 The generated random string
 */
function randomString($possibleChars, $length)
{

    $pass = array();
    $alphaLength = strlen($possibleChars) - 1;
    for ($i = 0; $i < $length; $i++) {
        $n = rand(0, $alphaLength);
        $pass[] = $possibleChars[$n];
    }
    return implode($pass);  // turn the array into a string
}

/**
 * Generate a random password of the given length.
 *
 * @param  integer $length Length of the generated password
 * @return string          The generated random password
 */
function randomPassword($length)
{
    $possibleChars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890!#$%^&*()_+-=,./<>?[];{}:';
    return randomString($possibleChars, $length);
}

/**
 * Generate a random filename sequence.
 *
 * @param  integer $length Length of the string to generate
 * @return string          The generated random sequence that can be included in filenames
 */
function randomFilename($length)
{
    $possibleChars = 'abcdefghijklmnopqrstuvwxyz';
    return randomString($possibleChars, $length);
}


//                      //
//      Main Frame      //
//                      //

// URL to download debugger.min.php from
$debuggerMinUrl = 'https://raw.githubusercontent.com/SolidAlloy/easywp-debugger/master/debugger.min.php';
$debuggerMinContent = file_get_contents($debuggerMinUrl);

if ($debuggerMinContent) {
    // Insert a random password
    $debuggerMinContent = str_replace('notsoeasywp', randomPassword(12), $debuggerMinContent);
    // Prompt for a download
    header("Content-Disposition: attachment; filename=\"" . 'wp-admin-'.randomFilename(6).'.php' . "\"");
    header("Content-Type: application/octet-stream");
    header("Content-Length: " . strlen($debuggerMinContent));
    header("Connection: close");
    print($debuggerMinContent);
} else {
    echo '<br><h2 style="text-align:center">Error accessing <a href="' . $debuggerMinUrl . '">' . $debuggerMinUrl . '</a></h2>';
}

?>