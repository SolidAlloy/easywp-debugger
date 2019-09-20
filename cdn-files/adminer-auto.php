<?php

session_start();

function define_find()
{  // https://github.com/interconnectit/Search-Replace-DB/blob/master/index.php#L726
    $filename = '../wp-config.php';
    if (file_exists($filename) && is_file($filename) && is_readable($filename)) {
        $file_content = file_get_contents($filename);
    }
    preg_match_all( '/define\s*?\(\s*?([\'"])(DB_NAME|DB_USER|DB_PASSWORD|DB_HOST|DB_CHARSET|DB_COLLATE)\1\s*?,\s*?([\'"])([^\3]*?)\3\s*?\)\s*?;/si', $file_content, $defines );
    if ( ( isset( $defines[ 2 ] ) && ! empty( $defines[ 2 ] ) ) && ( isset( $defines[ 4 ] ) && ! empty( $defines[ 4 ] ) ) ) {
        foreach( $defines[ 2 ] as $key => $define ) {
            switch( $define ) {
                case 'DB_NAME':
                    $name = $defines[ 4 ][ $key ];
                    break;
                case 'DB_USER':
                    $user = $defines[ 4 ][ $key ];
                    break;
                case 'DB_PASSWORD':
                    $pass = $defines[ 4 ][ $key ];
                    break;
                case 'DB_HOST':
                    $host = $defines[ 4 ][ $key ];
                    break;
                case 'DB_CHARSET':
                    $char = $defines[ 4 ][ $key ];
                    break;
                case 'DB_COLLATE':
                    $coll = $defines[ 4 ][ $key ];
                    break;
            }
        }
    }
    return array(
        'host' => $host,
        'name' => $name,
        'user' => $user,
        'pass' => $pass,
        'char' => $char,
        'coll' => $coll
    );
}

if ($_SESSION['debugger_adminer']) {
	$creds = define_find();
} else {
	$creds = array(
		'host' => '',
		'name' => '',
		'user' => '',
		'pass' => '',
		'char' => '',
		'coll' => ''
    );
}
$_GET["db"] = $creds['name'];
$_GET["username"] = $creds['user'];
$_GET["server"] = $creds['host'];

function adminer_object()
{  // https://www.adminer.org/en/extension/
    class AdminerSoftware extends Adminer {
        function name()
        {
            global $creds;
            return $creds['name'];
        }
        function credentials()
        {
            global $creds;
            return array($creds['host'], $creds['user'], $creds['pass']);
        }
        function database()
        {
            global $creds;
            return $creds['name'];
        }
        function login($login, $password)
        {
            if ($_SESSION['debugger_adminer']) {
                return true;
            } else {
                return false;
            }
        }
    }

    return new AdminerSoftware;
}
include './adminer.php';
