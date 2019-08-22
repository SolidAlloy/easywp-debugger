<?php
/**
 * Debugger is a consolidated PHP script which you can use to debug and fix a WordPress website.
 *
 * The structure of the file is as follows:
 *     - PHP constants
 *     - PHP classes
 *     - PHP functions
 *     - POST request processors
 *     - JQuery functions
 *     - CSS
 *     - HTML
 */


session_start();

/*
    !!! Constants section !!!
*/

define('VERSION', '2.0 beta');

define('PASSWORD', 'notsoeasywp');

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

define('DS', DIRECTORY_SEPARATOR);

// these two are required for the zip archivation to save the list of all directories and files inside a certain directory
define('DIRS', 'dirs.txt');
define('FILES', 'files.txt');


/*
    !!! PHP classes section !!!
*/


/**
 * Truncated class from wp-content/object-cache.php that can only flush all Redis caches
 */
class WP_Object_Cache
{
    /**
     * The Redis client.
     *
     * @var mixed
     */
    private $redis;

    /**
     * Track if Redis is available
     *
     * @var bool
     */
    private $redis_connected = false;

    /**
     * Holds the non-Redis objects.
     *
     * @var array
     */
    public $cache = [];

    /**
     * Name of the used Redis client
     *
     * @var bool
     */
    public $redis_client = null;

    /**
     * List of global groups.
     *
     * @var array
     */
    public $global_groups = [
        'blog-details',
        'blog-id-cache',
        'blog-lookup',
        'global-posts',
        'networks',
        'rss',
        'sites',
        'site-details',
        'site-lookup',
        'site-options',
        'site-transient',
        'users',
        'useremail',
        'userlogins',
        'usermeta',
        'user_meta',
        'userslugs',
    ];

    /**
     * List of groups not saved to Redis.
     *
     * @var array
     */
    public $ignored_groups = ['counts', 'plugins'];

    /**
     * Prefix used for global groups.
     *
     * @var string
     */
    public $global_prefix = '';

    /**
     * Prefix used for non-global groups.
     *
     * @var string
     */
    public $blog_prefix = '';

    /**
     * Instantiate the Redis class.
     *
     * Instantiates the Redis class.
     *
     * @param null $persistent_id To create an instance that persists between requests, use persistent_id to specify a unique ID for the instance.
     */
    public function __construct()
    {
        global $blog_id, $table_prefix;

        $parameters = [
            'scheme' => 'tcp',
            'host' => '127.0.0.1',
            'port' => 6379,
        ];

        foreach (['scheme', 'host', 'port', 'path', 'password', 'database'] as $setting) {
            $constant = sprintf('WP_REDIS_%s', strtoupper($setting));
            if (defined($constant)) {
                $parameters[$setting] = constant($constant);
            }
        }

        if (defined('WP_REDIS_GLOBAL_GROUPS') && is_array(WP_REDIS_GLOBAL_GROUPS)) {
            $this->global_groups = WP_REDIS_GLOBAL_GROUPS;
        }

        if (defined('WP_REDIS_IGNORED_GROUPS') && is_array(WP_REDIS_IGNORED_GROUPS)) {
            $this->ignored_groups = WP_REDIS_IGNORED_GROUPS;
        }

        $client = defined('WP_REDIS_CLIENT') ? WP_REDIS_CLIENT : null;

        if (class_exists('Redis') && strcasecmp('predis', $client) !== 0) {
            $client = defined('HHVM_VERSION') ? 'hhvm' : 'pecl';
        } else {
            $client = 'predis';
        }

        try {

            if (strcasecmp('hhvm', $client) === 0) {

                $this->redis_client = sprintf('HHVM Extension (v%s)', HHVM_VERSION);
                $this->redis        = new Redis();

                // Adjust host and port, if the scheme is `unix`
                if (strcasecmp('unix', $parameters['scheme']) === 0) {
                    $parameters['host'] = 'unix://' . $parameters['path'];
                    $parameters['port'] = 0;
                }

                $this->redis->connect($parameters['host'], $parameters['port']);
            }

            if (strcasecmp('pecl', $client) === 0) {

                $this->redis_client = sprintf('PECL Extension (v%s)', phpversion('redis'));
                $this->redis        = new Redis();

                if (strcasecmp('unix', $parameters['scheme']) === 0) {
                    $this->redis->connect($parameters['path']);
                } else {
                    $this->redis->connect($parameters['host'], $parameters['port']);
                }
            }

            if (strcasecmp('pecl', $client) === 0 || strcasecmp('hhvm', $client) === 0) {
                if (isset($parameters['password'])) {
                    $this->redis->auth($parameters['password']);
                }

                if (isset($parameters['database'])) {
                    $this->redis->select($parameters['database']);
                }
            }

            if (strcasecmp('predis', $client) === 0) {

                $this->redis_client = 'Predis';

                // Require PHP 5.4 or greater
                if (version_compare(PHP_VERSION, '5.4.0', '<')) {
                    throw new Exception;
                }

                // Load bundled Predis library
                if (!class_exists('Predis\Client')) {
                    // Restore symlink if it is broken
                    $plugin_dir = 'wp-content/mu-plugins';
                    if (file_exists('/var/www/wptbox/wp-content/mu-plugins')
                        && is_link('/var/www/wptbox/wp-content/mu-plugins')) {
                            // pass
                    } else {
                        $target_pointer = "../../easywp-plugin/mu-plugins";
                        $link_name = '/var/www/wptbox/wp-content/mu-plugins';
                        symlink($target_pointer, $link_name);
                    }
                    $predis = $plugin_dir . '/wp-nc-easywp/plugin/Http/Redis/includes/predis.php';
                    if (!file_exists($predis)) {
                        die(json_encode(array(
                            'redis_success' => 0,
                            'varnish_success' => 0,
                            'errors' => array('Failed to find Redis. Are you using Debugger on EasyWP? Try fixing the EasyWP plugin.')
                        )));
                    }
                    require_once $plugin_dir . '/wp-nc-easywp/plugin/Http/Redis/includes/predis.php';
                    Predis\Autoloader::register();
                }

                $options = [];

                if (defined('WP_REDIS_CLUSTER')) {
                    $parameters         = WP_REDIS_CLUSTER;
                    $options['cluster'] = 'redis';
                }

                if (defined('WP_REDIS_SERVERS')) {
                    $parameters             = WP_REDIS_SERVERS;
                    $options['replication'] = true;
                }

                if ((defined('WP_REDIS_SERVERS') || defined('WP_REDIS_CLUSTER')) && defined('WP_REDIS_PASSWORD')) {
                    $options['parameters']['password'] = WP_REDIS_PASSWORD;
                }

                $this->redis = new Predis\Client($parameters, $options);
                $this->redis->connect();

                $this->redis_client .= sprintf(' (v%s)', Predis\Client::VERSION);

            }

            // Throws exception if Redis is unavailable
            $this->redis->ping();

            $this->redis_connected = true;

        } catch (Exception $exception) {

            // When Redis is unavailable, fall back to the internal back by forcing all groups to be "no redis" groups
            $this->ignored_groups = array_unique(array_merge($this->ignored_groups, $this->global_groups));

            $this->redis_connected = false;

        }

        /**
         * This approach is borrowed from Sivel and Boren. Use the salt for easy cache invalidation and for
         * multi single WP installs on the same server.
         */
        if (!defined('WP_CACHE_KEY_SALT')) {
            define('WP_CACHE_KEY_SALT', '');
        }

        // Assign global and blog prefixes for use with keys
        if (function_exists('is_multisite')) {
            $this->global_prefix = (is_multisite() || defined('CUSTOM_USER_TABLE') && defined('CUSTOM_USER_META_TABLE')) ? '' : $table_prefix;
            $this->blog_prefix   = (is_multisite() ? $blog_id : $table_prefix);
        }
    }

    /**
     * Is Redis available?
     *
     * @return bool
     */
    public function redis_status()
    {
        return $this->redis_connected;
    }

    /**
     * Invalidate all items in the cache.
     *
     * @param   int $delay Number of seconds to wait before invalidating the items.
     * @return  bool       Returns TRUE on success or FALSE on failure.
     */
    public function flush($delay = 0)
    {
        $delay = abs(intval($delay));

        if ($delay) {
            sleep($delay);
        }

        $result      = false;
        $this->cache = [];

        if ($this->redis_status()) {
            $result = $this->parse_redis_response($this->redis->flushdb());

            if (function_exists('do_action')) {
                do_action('redis_object_cache_flush', $result, $delay);
            }
        }

        return $result;
    }

    /**
     * Convert Redis responses into something meaningful
     *
     * @param  mixed $response
     * @return mixed
     */
    protected function parse_redis_response($response)
    {
        if (is_bool($response)) {
            return $response;
        }

        if (is_numeric($response)) {
            return $response;
        }

        if (is_object($response) && method_exists($response, 'getPayload')) {
            return $response->getPayload() === 'OK';
        }

        return false;
    }
}

/**
 * This class accesses the website database even if the website is down and
 * retrieves values required for flushing Varnish cache, and other values.
 */
class DBconn {
    // Regexes to find DB details in wp-config.php
    protected $patterns = array('/DB_NAME\', \'(.*)\'/',
                              '/DB_USER\', \'(.*)\'/',
                              '/DB_PASSWORD\', \'(.*)\'/',
                              '/DB_HOST\', \'(.*)\'/',
                              '/table_prefix = \'(.*)\'/');
    protected $db_details = array();
    public $errors = array();
    public $connected = false;
    protected $mysqlConn;

    public function __construct()
    {
        $this->db_details = $this->get_db_login();  // get db details from wp-config.php
        if (!$this->db_details) {  // in case of fail, return empty instance (with $connected = fail)
            return;
        }
        $this->mysqlConn = new mysqli($this->db_details['host'],
                                      $this->db_details['user'],
                                      $this->db_details['pass'],
                                      $this->db_details['name']);
        if ($this->mysqlConn->connect_errno) {
            array_push($this->errors, "<strong>Database connection failed:</strong> " . $this->mysqlConn->connect_error);
        } else {
            $this->connected = true;
        }
    }

    public function __destruct()
    {
        if ($this->connected) {
            $this->mysqlConn->close();
        }
    }

    /**
     * [getVarnishDetails gets values necessary to build Varnish purge request]
     * @return array [Varnish parameters]
     */
    public function getVarnishDetails()
    {
        $failedAnswer = array('schema'=> false, 'x_purge_method'=>false, 'varnishIp'=>false);
        if (!$this->connected) {
            return $failedAnswer;
        }
        $varnish_query = "SELECT * FROM `".$this->db_details['prefix']."options` WHERE `option_name` LIKE 'easywp_plugin_slug'";
        $result = $this->mysqlConn->query($varnish_query);
        $row = $result->fetch_array(MYSQLI_NUM);
        if ($row) {
            $db_data = json_decode($row[2]);
            return array(
                           'schema'=> $db_data->varnish->schema ,
                           'x_purge_method'=> $db_data->varnish->default_purge_method ,
                           'varnishIp'=> $db_data->varnish->ip ,
                        );
        } else {
            return $failedAnswer;
        }
    }

    /**
     * [getHomeUrl is a replacement for home_url() function needed for the VarnishCache class]
     * @return string [WordPress home URL]
     */
    public function getHomeUrl()
    {

        if (!$this->connected) {
            return '';
        }
        $home_query = "SELECT * FROM `" . $this->db_details['prefix'] . "options` WHERE `option_name` LIKE 'home'";
        $result = $this->mysqlConn->query($home_query);
        $row = $result->fetch_array(MYSQLI_NUM);
        if ($row) {
            return $row[2];
        } else {
            return '';
        }
    }

    /**
     * [get_db_login returns an array of db login details and db prefix]
     * @return array [DB details]
     */
    private function get_db_login()
    {
        $db_details = array(
                              'name'   => '' ,
                              'user'   => '' ,
                              'pass'   => '' ,
                              'host'   => '' ,
                              'prefix' => '' ,
                           );

        $wp_config = fopen('wp-config.php', 'r');
        if (!$wp_config) {
            array_push($this->errors, "Failed to open wp-config.php");
            return array();
        }

        $last = end($this->patterns);  // get last element to know where the end of the array
        $pattern = reset($this->patterns);  // return to the first regex

        // Fill $db_details array with values from wp-config.php
        while(!feof($wp_config)) {
            $line = fgets($wp_config);
            preg_match($pattern, $line, $matches);  // check each line of wp-config.php
            if ($matches) {  // and when the match is found
                $key = key($db_details);
                $db_details[$key] =  $matches[1];  // add the found detail to the current key of the array
                next($db_details);  // switch to next key of the array
                if ($pattern == $last) {
                    break;
                } else {
                $pattern = next($this->patterns);  // once one detail is found, start searching with the next regex
                }
            }
        }
        fclose($wp_config);
        return $db_details;
    }

    /**
     * [activateTheme sets a WordPress theme in the database]
     * @param  string $theme [name of the theme]
     * @return boolean       [success of the activation]
     */
    public function activateTheme($theme)
    {
        if (!$this->connected) {
            return false;
        }
        $act_theme_query = "UPDATE `" . $this->db_details['prefix'] . "options` SET option_value = '" . $theme . "' WHERE `option_name` = 'template' or `option_name` = 'stylesheet'";
        $result = $this->mysqlConn->query($act_theme_query);
        if ($result) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * [getSiteUrl returns website URL from the database]
     * @return string [website URL]
     */
    public function getSiteUrl()
    {
        if (!$this->connected) {
            return false;
        }
        $siteurlQuery = "SELECT `option_value` FROM `" . $this->db_details['prefix'] . "options` WHERE `option_name`='siteurl'";
        $result = $this->mysqlConn->query($siteurlQuery);
        $row = $result->fetch_array(MYSQLI_NUM);
        if ($row) {
            return $row[0];
        } else {
            return '';
        }
    }

}

/**
 *  Truncated class from wp-nc-easywp/plugin/Http/Varnish/VarnishCache.php
 *  Only the functions required for clearAll() were implemented. They were adjusted
 *  to work even if the website is down.
 */
class VarnishCache
{
    private $dbConn;  // DBdata class instance
    public $errors = array();
    private $tried_localhost = false;
    
    public function __construct ()
    {
        $this->dbConn = new DBconn;
        if ($this->dbConn->errors) {
            $this->errors = array_merge($this->errors, $this->dbConn->errors);
        }
    }

    /**
     * [getServiceName returns name of the cluster pod]
     * @return string [name of the cluster pod]
     */
    private function getServiceName()
    {
        $frontend_svc = getenv('SERVICE_NAME');
        if($frontend_svc) {
            // 'wordpress-frontend.easywp.svc.cluster.local'
            return $frontend_svc;
        }
        $podname = getenv('HOSTNAME');
        if ($podname) {
            $regex = '/-([^-]+)-/m';
            $res   = preg_match_all($regex, $podname, $matches, PREG_SET_ORDER, 0);

            if ($res) {
                $id = $matches[0][1];
                return "svc-{$id}.default.svc.cluster.local";
            }
        }
        return '';
    }

    /**
     * [collectMultipleReplicas returns hosts to purge Varnish cache from]
     * @return array [hosts to purge Varnish cache from]
     */
    private function collectMultipleReplicas(): array
    {
        $svc = $this->getServiceName();
        if ($svc) {
            $ips = gethostbynamel($svc);
            return array_map(function ($ip) {
                return "http://{$ip}";
            }, $ips);
        }
        $home_url = $this->dbConn->getHomeUrl();
        if ($home_url) {
            return [$home_url];
        } else {
            array_push($this->errors, "Failed to fetch data from wp_options.home");
            return array();
        }
    }

    /**
     * [purgeUrl send request to a host to purge Varnish cache from it]
     * @param  string $url    [the host to purge Varnish cache from]
     * @param  string $schema ["http://" or "https://"]
     * @return boolean        [success of the purge request]
     */
    private function purgeUrl ($url, $schema=null)
    {
        try {
            $parsedUrl = parse_url($url);
            $dbData = $this->dbConn->getVarnishDetails();
            if(!$dbData['schema']) {
                array_push($this->errors, "Failed to fetch data from wp_options.easywp_plugin_slug");
            }
            // get the schema
            if (!$schema) {
                $schema = $dbData['schema'] ?: 'http://';
            }
        
            // get default purge method
            $x_purge_method = $dbData['x_purge_method'] ?: 'default';
        
            // default regexp
            $regex = '';
        
            if (isset($parsedUrl['query']) && ($parsedUrl['query'] == 'vhp-regex')) {
                $regex          = '.*';
                $x_purge_method = 'regex';
            }
        
            // varnish ip
            $varnishIp = $dbData['varnishIp'] ?: '127.0.0.1';
        
            // path
            $path = $parsedUrl['path']??'';
        
            // setting host
            $hostHeader = $parsedUrl['host'];
            $podname    = getenv('HOSTNAME');
            $host       = $hostHeader;
            if (empty($podname)) {
                $host = $varnishIp??$hostHeader;
            }
        
            if (isset($parsedUrl['port'])) {
                $hostHeader = "{$host}:{$parsedUrl[ 'port' ]}";
            }
        
            $headers = [
                'host' => $hostHeader,
                'X-Purge-Method' => $x_purge_method,
            ];
        
            // final url
            $urlToPurge = "{$schema}{$host}{$path}{$regex}";
            
            // send PURGE request and check the response
            $ch = curl_init();
            $timeout = 10;
            curl_setopt($ch,CURLOPT_URL,$urlToPurge);
            curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
            curl_setopt($ch,CURLOPT_CONNECTTIMEOUT,$timeout);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PURGE");
            $data = curl_exec($ch);
        
            if(curl_errno($ch)) {
                // sometimes, https scheme in the database is incorrect and purge fails because of it
                if (strpos(curl_error($ch), 'port 443: Connection refused')) {
                    return $this->purgeUrl($url, 'http://');
                } else {
                    array_push($this->errors, 'Varnish - Curl error: ' . curl_error($ch));
                }
            } elseif (strpos($data, 'Full cache cleared') !== false) {
                $result = true;
            } else {
                $res = preg_match("/<title>(.*)<\/title>/siU", $data, $title_matches);
                if ($res) {
                    $title = preg_replace('/\s+/', ' ', $title_matches[1]);
                    $title = trim($title);
                    // if purging Varnish at third-party IP didn't help, try purging localhost
                    if ($title == '405 This IP is not allowed to send BAN/PURGE requests.' && $this->tried_localhost == false) {
                        $this->tried_localhost = true;
                        return $this->purgeUrl('127.0.0.1');
                    } else {
                        array_push($this->errors, 'Varnish - ' . $title);
                    }
                } else {
                    $result = false;
                }
            }
            curl_close($ch);
            return $result;
        } catch (Exception $e) {
            array_push($this->errors, $e);
            return false;
        }
    }

    /**
     * [clearAll Purges all Varnish caches of the website and returns an array 
     * of true/false for each Varnish URL]
     * @return null
     */
    public function clearAll()
    {
        $results = array();
        $urls = $this->collectMultipleReplicas();
        foreach ($urls as $url) {
            $result = $this->purgeUrl($url);
            if ($result) {
                array_push($results, true);
            } else {
                array_push($results, false);
            }
        }
        return $results;
    }
}

/**
 * FileCounter counts files and directories in a directory and puts the list of them in TXT files
 */
class FileCounter
{
    protected const SIZE_LIMIT = 52428800;  // 50 MB
    protected $ignoreList;
    protected $directory;

    public function __construct()
    {
        $selfName = basename(__FILE__);
        $this->ignoreList = array('.','..', $selfName);
        $this->dirs = fopen(DIRS, 'a');  // dirs.txt
        $this->files = fopen(FILES, 'a');  // files.txt
    }

    public function __destruct()
    {
        fclose($this->dirs);
        fclose($this->files);
    }

    /**
     * [countFiles puts the list of files and directories inside certain directory in a TXT files and returns the total number of files and directories]
     * @param  string  $directory [path to the directory where files need to be counted]
     * @param  boolean $silent    [do not throw Exception if silent]
     * @return integer            [number of files and directories]
     */
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
                if (filesize($directory.'/'.$entry) < self::SIZE_LIMIT) {
                    fwrite($this->files, $directory.'/'.$entry."\n");
                    ++$number;
                }
            }
        }
        return $number;
    }
}

/**
 * DirZipArchive compresses files into a zip archive until the size limit is reached
 */
class DirZipArchive
{
    protected $startNum;
    protected $zip;
    protected $counter = 0;
    protected const SIZE_LIMIT = 52428800;  // 50 MB
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

    /**
     * [addDirs adds directories from dirs.txt to the archive]
     */
    public function addDirs()
    {
        while(!feof($this->dirs))  {
            ++$this->counter;
            $this->totalSize += 4098;
            $directory = rtrim(fgets($this->dirs));
            $this->zip->addEmptyDir($directory);
        }
    }

    /**
     * [addFilesChunk adds files from files.txt to the archive until the size limit is reached]
     */
    public function addFilesChunk()
    {
        while(!feof($this->files))  {
            $file = rtrim(fgets($this->files), "\n");
            if (($this->startNum > ++$this->counter) or !$file) {  // skip all files below startNum and increment counter
                continue;
            }
            $this->totalSize += filesize($file);

            if ($this->totalSize > self::SIZE_LIMIT) {
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


/*
    !!! PHP functions section !!!
*/


function authorized()
{
    if (isset($_SESSION['debugger'])) {
        return true;
    } else {
        return false;
    }
}


function passwordMatch($password)
{
    if ($password == PASSWORD) {
        return true;
    } else {
        return false;
    }
}


function flushOPcache()
{
    if (function_exists('opcache_reset')) {
        opcache_reset();
    }
}


function flushRedis()
{
    $wp_object_cache = new WP_Object_Cache();
    return $wp_object_cache->flush();
}

/**
 * [clearAll clears OPcache, Redis, and Varnish caches]
 * @return array [success of purging and errors if any]
 */
function clearAll()
{
    if (!file_exists('/var/www/wptbox')) {
        return array('redis_success' => false,
                     'varnish_success' => false,
                     'easywp' => false,
                     'errors' => array("It is not EasyWP, is it?",
                    ));
    }

    $redis_success = flushRedis() ? 1 : 0;

    $varnish_cache = new VarnishCache();
    $varnish_results = $varnish_cache->clearAll();
    // Set to false if any element of array is false, otherwise true
    $varnish_success = in_array(false, $varnish_results, true) ? 0 : 1;

    flushOPcache();

    return array('redis_success' => $redis_success,
                 'varnish_success' => $varnish_success,
                 'easywp' => true,
                 'errors' => $varnish_cache->errors);
}

/**
 * [wpConfigClear removes display_errors and debug mode if found in wp-config.php]
 * @return boolean [success of removing debug from wp-config.php]
 */
function wpConfigClear()
{
    $wp_config = "wp-config.php";
    if (!is_writable($wp_config) or !is_readable($wp_config)) {
        return false;
    }
    $config = file_get_contents($wp_config);
    $config = str_replace("define('WP_DEBUG', true);", '', $config);
    $config = str_replace("define('WP_DEBUG_DISPLAY', true);", '', $config);
    $config = str_replace("@ini_set('display_errors', 1);", '', $config);
    file_put_contents($wp_config, $config);
    return true;
}

/**
 * [wpConfigPut enables debug and display_errors in wp-config.php]
 * @return boolean [success of enabling debug]
 */
function wpConfigPut()
{
    $wp_config = "wp-config.php";
    if (!is_writable($wp_config) or !is_readable($wp_config)) {
        return false;
    }
    $config = file_get_contents($wp_config);
    $config = preg_replace("/\/\* That's all, stop editing! Happy blogging\. \*\//i", "define('WP_DEBUG', true);\ndefine('WP_DEBUG_DISPLAY', true);\n@ini_set('display_errors', 1);\n/* That's all, stop editing! Happy blogging. */", $config);
    file_put_contents ($wp_config, $config);
    return true;
}

/**
 * [rmove moves folders and files recursively]
 * @param  string $src [object to move]
 * @param  string $dst [destination folder]
 * @return null
 */
function rmove($src, $dst)
{
    if (is_dir($src)) {
        if ($dst != '.' && !file_exists($dst)) {
            mkdir($dst);
        }
        $files = scandir ( $src );
        foreach ( $files as $file ) {
            if ($file != "." && $file != "..") {
                rmove ( "$src/$file", "$dst/$file" );
            }
        }
    } else {
        rename ($src, $dst);
    }
}

/**
 * [rrmdir removes folders and files recursively]
 * @param  string $dir [directory where files must be removed]
 * @param  array $failedRemovals [array of files and folders that failed to be removed]
 * @return array [array of files and folders that failed to be removed]
 */
function rrmdir($dir, $failedRemovals=[])
{
    if (is_dir($dir)) {
        $objects = scandir($dir);
        foreach ($objects as $object) {
            if ($object != "." && $object != "..") {
                if (is_dir($dir.DS.$object)) {
                    $failedRemovalsChild = rrmdir($dir.DS.$object, $failedRemovals);
                    if ($failedRemovalsChild) {
                        array_push($failedRemovals, $failedRemovalsChild); // add new failed removals to the existing ones
                    }
                } else {
                    if (!unlink($dir.DS.$object)) {
                        array_push($failedRemovals, $dir.DS.$object);
                    }
                }
            }
        }
        if (!rmdir($dir)) {
            array_push($failedRemovals, $dir);
        }
    }
    return $failedRemovals;
}

/**
 * [extractZipFromUrl uploads an archive, extracts it, and removes the zip file]
 * @param  string $url         [URL to download the archive from]
 * @param  string $path        [path to put the archive to]
 * @param  string $archiveName [name of the archive]
 * @return boolean             [success of the extraction]
 */
function extractZipFromUrl($url, $path, $archiveName)
{
    $archive = $path . $archiveName;
    if (!file_put_contents($archive, file_get_contents($url))) {
        return false;
    }

    $zip = new ZipArchive();
    $x = $zip->open($archive);
    if ($x === true) {
        $zip->extractTo($path);
        $zip->close();
        unlink($archive);
        return true;
    } else {
        unlink($archive);
        return false;
    }
}

/**
 * [replaceDefaultFiles replaces default WordPress files with the ones from the latest version]
 * @return boolean [success of the replacement]
 */
function replaceDefaultFiles()
{
    $url = 'http://wordpress.org/latest.zip';
    $file = 'wordpress.zip';
    if (!extractZipFromUrl($url, './', 'wordpress.zip')) {
        return false;
    }

    rmove('wordpress', '.');  // 'wordpress' directory is created after extracting the archive
    rrmdir('wordpress');
    return true;
}

/**
 * [themeExists checks if the theme folder exists in wp-content/themes]
 * @param  string $themesPath [path to the themes folder]
 * @param  string $themeName  [theme name]
 * @return boolean            [theme exists]
 */
function themeExists($themesPath, $themeName)
{
    $themes = scandir($themesPath);
    if (in_array($themeName, $themes)) {
        return true;
    } else {
        return false;
    }
}

/**
 * [findLatest2019 gets version number of the latest 2019 theme]
 * @return string [version number]
 */
function findLatest2019()
{
    $url = 'https://themes.svn.wordpress.org/twentynineteen/';
    $versionsPage = file_get_contents($url);
    if (!$versionsPage) {
        return '';
    }
    $res = preg_match_all('/>([\d\.]+)\/<\/a>/', $versionsPage, $matches);
    if ($res) {
        $latestVersion = end($matches[1]);
    } else {
        $latestVersion = '';
    }
    return $latestVersion;
}

/**
 * [replace2019 replaces files of the 2019 theme or uploads files if the folder doesn't exist]
 * @return boolean [success of the replacement]
 */
function replace2019()
{
    $themesFolderPath = 'wp-content/themes/';
    $themeName = 'twentynineteen';
    $themePath = $themesFolderPath . $themeName;
    $version = findLatest2019();
    if (!$version) {
        throw new Exception('Failed to find the latest version of 2019');
    }
    if (themeExists($themesFolderPath, $themeName)) {
        rrmdir($themePath);
    }
    $url = 'https://downloads.wordpress.org/theme/twentynineteen.' . $version . '.zip';
    if (extractZipFromUrl($url, $themesFolderPath, 'twentynineteen.zip')) {
        return true;
    } else {
        throw new Exception('Failed to upload the theme archive');
    }
}

/**
 * [activate2019 activates the twentynineteen theme in database]
 * @return boolean [success of the activation]
 */
function activate2019()
{
    $dbConn = new DBconn;
    if ($dbConn->errors) { // if db connection failed, return errors
        return $dbConn->errors;
    }
    if ($dbConn->activateTheme('twentynineteen')) {
        return true;
    } else {
        return false;
    }
}

/**
 * [createEasyWpSymLink creates the mu-plugins symlink or does nothing if the link already exists]
 * @return boolean [success of the symlink creation]
 */
function createEasyWpSymLink()
{
    $target_pointer = "../../easywp-plugin/mu-plugins";
    $link_name = '/var/www/wptbox/wp-content/mu-plugins';
    if (is_link($link_name)) {
        return true;
    }
    if (symlink($target_pointer, $link_name)) {
        return true;
    } else {
        return false;
    }
}

/**
 * [createObjectCache creates object-cache.php if missing]
 * @return boolean [success of the file creation]
 */
function createObjectCache()
{
    $filePath = 'wp-content/object-cache.php';
    $correctFileSum = '0d798e3e13049ca5f96c0a0b2b44f63211f70837';
    $cdnObjectCache = 'https://res.cloudinary.com/ewpdebugger/raw/upload/v1559401561/object-cache.php';

    if (file_exists($filePath)) {
        $fileSum = sha1_file($filePath);
        if ($fileSum == $correctFileSum) {
            return true;
        } else {
            unlink($filePath);
        }
    }

    if (file_put_contents($filePath, file_get_contents($cdnObjectCache))) {
        return true;
    } else {
        return false;
    }
}

/**
 * [statAllFiles runs stat on all files/folders in path]
 * @param  string $dir [path to folder]
 * @return null
 */
function statAllFiles($dir)
{
    $files = scandir($dir);
    foreach($files as $key => $value){
        $path = realpath($dir.DIRECTORY_SEPARATOR.$value);
        if(!is_dir($path)) {
            stat($path);
            clearstatcache($path);
        } else if($value != "." && $value != "..") {
            statAllFiles($path);
            stat($path);
            clearstatcache($path);
        }
    }
}


/**
 * [uploadAdminerFiles uploads files from URLs to storage]
 * @return boolean [success of the upload]
 */
function uploadFiles($filesAndSources) {
    $results = array();
    foreach ($filesAndSources as $file=>$source) {
        $result = file_put_contents($file, file_get_contents($source));
        array_push($results, $result);
    }
    // if any element of array is false, in_array will return false
    if (in_array(false, $results, true)) {
        foreach ($filesAndSources as $file=>$source) {
            unlink($file);
        }
        return false;
    } else {
        return true;
    }

}

/**
 * [unzipArchive extracts a zip archive in chunks. Returns true on completion and last
 *     extracted file if the allowed time is exceeded]
 * @param  string $archiveName [path to the zip file]
 * @param  string $destDir     [destination directory]
 * @param  integer $startNum    [filenumber to start extraction from]
 * @return boolean|array             [true on extraction completion; array
 *                                containing number and name of the failed file on fail]
 */
function unzipArchive($archiveName, $destDir, $startNum, $maxUnzipTime)
{
    $time_start = time();
    $archive = zip_open($archiveName);
    
    if (gettype($archive) == 'integer') {  // if error upon opening the archive ...
        $error = ERRORS[$archive];
        throw new Exception($error); // throw it within an exception
    }

    $counter = 0;
    while($entry = zip_read($archive)){
        
        if ($startNum > ++$counter) {  // skip files before startNum
            continue;
        }

        $name = zip_entry_name($entry);
        $size = zip_entry_filesize($entry);

        if (substr($name, -1) == '/') { // if directory
            $dir = $destDir.DS.$name;
            if (is_dir($dir)) {  // if destination directory exists
                // pass
            } elseif (file_exists($dir)) {  // if the destionation entry is not a directory
                unlink($dir);
                mkdir($dir);
            } else {  // if the destination entry doesn't exist
                mkdir($dir);
            }
        } else { // if file
            $unzipped = fopen($destDir.DS.$name,'wb');
            while($size > 0){

                if (time() - $time_start > $maxUnzipTime) {  // if the max time is exceeded
                    fclose($unzipped);
                    unlink($name);  // remove unfinished file
                    zip_close($archive);
                    return [$counter, $name];  // return number and name of the file
                }

                $chunkSize = ($size > 10240) ? 10240 : $size;
                $size -= $chunkSize;
                $chunk = zip_entry_read($entry, $chunkSize);
                if($chunk !== false) fwrite($unzipped, $chunk);
            }
            fclose($unzipped);
        }
    }
    zip_close($archive);  // when the archive is extracted, close it
    return true;  // and return true
}

/**
 * [viewArchive returns pathnames of all the files inside an archive]
 * @param  string $archiveName [path to zip file]
 * @return array              [pathnames of files inside an archive]
 */
function viewArchive($archiveName)
{
    $archive = zip_open($archiveName);
    if (gettype($archive) == 'integer') {  // if error upon opening the archive ...
        $error = ERRORS[$archive];
        throw new Exception($error); // throw it in Exception
    }
    $files = [];
    while($entry = zip_read($archive)){
        array_push($files, zip_entry_name($entry));
    }
    zip_close($archive);
    return $files;
}

/**
 * [checkDestDir checks if a directory exists and is writable. If no, it creates the directory]
 * @param  [type] $destDir [description]
 * @return [type]          [description]
 */
function checkDestDir($destDir)
{
    if (file_exists($destDir)) {
        if (is_writable($destDir)) {
            return true;
        } else {
            return false;  // if the directory is not writable, no need to try to create it
        }
    } else {
        $createSuccess = mkdir($destDir, 0755, true);  // create directory recursively
        if ($createSuccess) {
            return true;
        } else {
            return false;
        }
    }
}

/**
 * [countFiles returns number of files and folders inside an archive]
 * @param  string $archiveName  [path to zip file]
 * @return integer              [number of files in zip file]
 */
function countFiles($archiveName)
{
    $archive = zip_open($archiveName);

    if (gettype($archive) == 'integer') {  // if error upon opening the archive ...
        $error = ERRORS[$archive];
        throw new Exception($error); // throw it in Exception
    }

    $counter = 0;
    while($entry = zip_read($archive)){
        ++$counter;
    }
    zip_close($archive);
    return $counter;
}

/**
 * [unzipArchivePost wrapper for unzipArchive that returns its result as json array]
 * @param  string $archiveName [path to zip file]
 * @return null
 */
function unzipArchivePost($archiveName)
{
    if (isset($_POST['startNum']) && !empty($_POST['startNum'])) {
        $startNum = $_POST['startNum'];
    } else {
        $startNum = 0;
    }

    try {
        $result = unzipArchive($archiveName, $_POST['destDir'], $startNum, $_POST['maxUnzipTime']);  // try extracting archive
        if ($result === true) {
            die(json_encode(array('success' => 1,
                                  'error' => '',
                                  'startNum' => 0,
                                  'failedFile' => '')));
        } else {
            die(json_encode(array('success' => 0,
                                  'error' => '',
                                  'startNum' => $result[0],
                                  'failedFile' => $result[1])));
        }
    } catch (Exception $e) {
        die(json_encode(array('success' => 0,
                              'error' => $e->getMessage(),
                              'startNum' => 0,
                              'failedFile' => '')));
    }
}

/**
 * [viewArchivePost wrapper for viewArchive that returns its result as json array]
 * @param  string $archiveName [path to zip file]
 * @return null
 */
function viewArchivePost($archiveName)
{
    try {
        $files = viewArchive($archiveName);
    } catch (Exception $e) {
        die(json_encode(array('success' => 0,
                              'files' => [],
                              'error' => $e->getMessage())));
    }
    die(json_encode(array('success' => 1,
                          'files' => $files,
                          'error' => '')));
}

/**
 * [checkArchive checks if the archive the user wants to create already exists]
 * @param  string $archiveName [archive name]
 * @return boolean             [returns true if such a name is free]
 */
function checkArchive($archiveName)
{
    if (file_exists($archiveName)) {
        return false;
    } else {
        return true;
    }
}

/**
 * [processPreCheckRequest checks if the directory can be compressed and returns json with the result]
 * @return string [json-encoded array with the result of pre-check]
 */
function processPreCheckRequest()
{
    try {
        $numberSuccess = true;
        $counter = new FileCounter();
        $number = $counter->countFiles($_POST['directory']);  // try counting files
        $numberError = '';
    } catch (Exception $e) {
        unlink(DIRS);  // remove temporary files in case of fail
        unlink(FILES);
        $numberSuccess = false;
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

/**
 * [processArchiveRequest compresses the directory using input from the POST form and returns
 *  a json-encoded array with the result]
 * @return string [json-encoded result]
 */
function processArchiveRequest()
{
    if (isset($_POST['startNum']) && !empty($_POST['startNum'])) {
        $startNum = $_POST['startNum'];
    } else {
        $startNum = 0;
    }
    try {
        $archive = new DirZipArchive($_POST['archiveName'], $startNum);
    } catch (Exception $e) {
        unlink(DIRS);  // remove temporary files in case of complete fail
        unlink(FILES);
        return json_encode(array('success' => 0,
                                 'error' => $e->getMessage(),
                                 'startNum' => 0,
                                ));
    }

    if ($startNum == 0) {
        $archive->addDirs();
    }
    $result = $archive->addFilesChunk();

    if ($result === true) {
        unlink(DIRS);  // remove temporary files because they are not needed anymore
        unlink(FILES);
        return json_encode(array('success' => true,
                                 'error' => '',
                                 'startNum' => 0,
                                ));
    } else {
        return json_encode(array('success' => 0,
                                 'error' => '',
                                 'startNum' => $result,  // return the number of file on which the compression stopped
                                ));
    }
}

/**
 * [getVersionUrl retrieves a link to the last version of Debugger from GitHub]
 * @return string [link to the latest GitHub release of Debugger]
 */
function getVersionUrl()
{
    $url = 'https://github.com/SolidAlloy/easywp-debugger/releases/latest';
    $ch = curl_init();
    $timeout = 10;
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);  // the "/releases/latest" link will redirect to a link like "/releases/tag/1.0"
    curl_setopt($ch,CURLOPT_CONNECTTIMEOUT,$timeout);

    curl_exec($ch);

    if(curl_errno($ch)) {
        curl_close($ch);
        return false;
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if($httpCode == 200) {
        $redirectedUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);  // the "/releases/latest" link will redirect to a link like "/releases/tag/1.0"
        curl_close($ch);
        return $redirectedUrl;
    } else {
        curl_close($ch);
        return false;
    }
}

/**
 * [checkNewVersion checks if there is a new version of Debugger on GitHub]
 * @return bool ["true" if the version on GitHub is higher than the local one]
 */
function checkNewVersion()
{
    $url = getVersionUrl();
    if ($url) {
        $gitHubVersion = substr($url, strrpos($url, '/') + 1);  // get "1.0" from a link like "/releases/tag/1.0"
        return version_compare($gitHubVersion, VERSION, '>');
    } else {
        throw new Exception('Failed to fetch new version');
    }
}


/*
    !!! POST request processors section !!! 
*/


/* creates session and print success if the password matches */
if (isset($_POST['login'])) {
    if (passwordMatch($_POST['password'])) {
        $_SESSION['debugger'] = true;
        die(json_encode(array(
            'success' => 1,
        )));
    } else {
        die(json_encode(array(
            'success' => 0,
        )));
    }
}

// if the Debugger session is created, process POST requests
if (authorized()) {

    /* flushes Varnish, Redis, and opcache caches */
    if (isset($_POST['flush'])) {
        $results = clearAll();
        echo json_encode($results);
        exit;
    }

    /* enables errors on-screen */
    if (isset($_POST['debugOn'])) {
        $debug_result = wpConfigPut() ? 1 : 0;
        die(json_encode(array('debug_on_success' => $debug_result)));
    }

    /* disables on-screen errors */
    if (isset($_POST['debugOff'])) {
        $debug_result = wpConfigClear() ? 1 : 0;
        die(json_encode(array('debug_off_success' => $debug_result)));
    }

    /* replaces WordPress default files (latest version of WordPress) */
    if (isset($_POST['replace'])) {
        $result = replaceDefaultFiles() ? 1 : 0;
        echo json_encode(array('replace_success' => $result));
        exit;
    }

    /* uploads latest version of the 2019 theme and activates it */
    if (isset($_POST['activate'])) {
        $errors = array();

        try {
          replace2019();
          $replaceSuccess = true;
        } catch (Exception $e) {
            array_push($errors, $e->getMessage());
            $replaceSuccess = false;
        }

        $activateResult = activate2019();
        if ($activateResult === true) {
            $activateSuccess = true;
        } elseif ($activateResult === false) {
            $activateSuccess = false;
        } else {
            $activateSuccess = false;
            array_merge($errors, $activateResult);
        }

        echo json_encode(array('replace'=>$replaceSuccess,
                               'activate'=>$activateSuccess,
                               'errors'=>$errors));
        exit;
    }

    /* fixes the EasyWP plugin if its files are not fully present on the website */
    if (isset($_POST['fixPlugin'])) {
        $symLink = createEasyWpSymLink() ? 1 : 0;
        $objectCache = createObjectCache() ? 1 : 0;
        echo json_encode(array('symLink' => $symLink, 'objectCache' => $objectCache));
        exit();
    }

    /* removes debugger.php and additional files from the server, disables debug */
    if (isset($_POST['selfDestruct'])) {
        session_destroy();
        $files = array('wp-admin/adminer-auto.php',
                       'wp-admin/adminer.php',
                       'wp-admin/adminer.css',
                        __FILE__);
        foreach($files as $file) {
            unlink($file);
        }

        wpConfigClear();  // disable debug and clear cache silently because if it fails, nothing else can be done anyway
        clearAll();

        die(json_encode(array('success' => 1)));
    }

    /* fix filesystem not being able to find some files by running stat() on all files */
    if (isset($_POST['fixFileSystem'])) {
        statAllFiles('/var/www/wptbox');
        echo json_encode(array('success' => 1));
        exit();
    }

    /* uploads adminer-auto files and sets a session to access Adminer */
    if (isset($_POST['adminerOn'])) {
        $adminerFilesAndSources = array('wp-admin/adminer-auto.php' => 'https://res.cloudinary.com/ewpdebugger/raw/upload/v1562956069/adminer-auto_nk2jck.php' ,
                                        'wp-admin/adminer.php' => 'https://res.cloudinary.com/ewpdebugger/raw/upload/v1559401351/adminer.php' ,
                                        'wp-admin/adminer.css' => 'https://res.cloudinary.com/ewpdebugger/raw/upload/v1559401351/adminer.css' ,
                                        );
        if (uploadFiles($adminerFilesAndSources)) {
            $_SESSION['debugger_adminer'] = true;
            die(json_encode(array('success' => 1)));
        } else {
            die(json_encode(array('success' => 0)));
        }
    }

    /* removes adminer-auto files and unsets the session */
    if (isset($_POST['adminerOff'])) {
        unlink('wp-admin/adminer-auto.php');
        unlink('wp-admin/adminer.php');
        unlink('wp-admin/adminer.css');
        unset($_SESSION['debugger_adminer']);
        die(json_encode(array('success' => 1)));
    }

    /* prints success=true if the destination directory of extraction exists or has been successfully created */
    if (isset($_POST['checkDestDir'])) {
        $destDir = $_POST['destDir'];
        if (checkDestDir($destDir)) {
            die(json_encode(array('success' => true)));
        } else {
            die(json_encode(array('success' => false)));
        }
    }

    /* if something needs to be done with an archive */
    if (isset($_POST['archiveName']) && isset($_POST['action'])) {
        $archiveName = $_POST['archiveName'];
        
        if ($_POST['action'] == 'extract') {  // extract archive
            unzipArchivePost($archiveName);
        } elseif ($_POST['action'] == 'view') {  // show content of archive
            viewArchivePost($archiveName);
        }
    }

    /* counts files and directories in a directory and returns their total number */
    if (isset($_POST['filesNumber'])) {
        try {
            $number = countFiles($_POST['filesNumber']);
        } catch (Exception $e) {
            die(json_encode(array('success' => 0,
                                  'number' => 0,
                                  'error' => $e->getMessage())));
        }
        die(json_encode(array('success' => 1,
                              'number' => $number,
                              'error' => '')));
    }

    /* checks if the archive name is free and if the source directory exists */
    if (isset($_POST['compressPreCheck'])) {
        $jsonResult = processPreCheckRequest();
        die($jsonResult);
    }

    /* compresses the directory */
    if (isset($_POST['archive'])) {
        // this wrapper function is needed so that the destructor of the DirZipArchive instance inside processArchiveRequest() is called properly. If die() is called right away, the destructor will not be called.
        $jsonResult = processArchiveRequest();
        die($jsonResult);
    }

    /* checks if there is a newer version on Github */
    if (isset($_POST['checkVersion'])) {
        try {
            if (checkNewVersion()) {
                $success = true;
                $new = true;
            } else {
                $success = true;
                $new = false;
            }
        } catch (Exception $e) {
            $success = false;
            $new = false;
        }

        die(json_encode(array('success' => $success,
                              'new' => $new,
                             )));
    }

    /* gets WordPress siteurl and upload the wp-admin-auto.php file */
    if (isset($_POST['autoLogin'])) {

        // get site URL
        $dbConn = new DBconn;
        if (!$dbConn->errors) {
            $siteUrl = $dbConn->getSiteUrl();
            $errors = array();
        } else {
            $siteUrl = '';
            $errors = $dbConn->errors;
        }

        // if there is site URL, proceed with uploading the wp-admin-auto file
        if ($siteUrl) {
            $autoLoginFilesAndSources = array('wp-admin-auto.php' => 'https://res.cloudinary.com/ewpdebugger/raw/upload/v1564828803/wp-admin-auto_av0omr.php' ,
                                              );
            if (uploadFiles($autoLoginFilesAndSources)) {  // if there is site URL and the file is uploaded successfully, everything is good
                $success = true;  
                $file = true;
            } else {
                $success = false;
                $file = false;
            }
        } else {
            $success = false;
            $file = null;  // didn't try to upload the file at all
        }

        die(json_encode(array('success' => $success ,
                              'siteurl' => $siteUrl ,
                              'file'    => $file ,
                              'errors'  => $errors ,
                             )));
    }

    /* deletes wp-admin-auto.php file */
    if (isset($_POST['deleteAutoLogin'])) {
        if (unlink('wp-admin-auto.php')) {
            die(json_encode(array('success' => true)));
        } else {
            die(json_encode(array('success' => false)));
        }
    }

    /* deletes a file or folder from the hosting storage */
    if (isset($_POST['delete'])) {
        $entry = $_POST['entry'];
        if (!file_exists($entry)) {  // if entry does not exist
            die(json_encode(array('success' => false ,
                                  'error' => 'No Such File' ,
                                  'failed_files' => array() ,
                                 )));
        }
        if (is_dir($entry)) {  // if entry is a directory, delete the directory recursively
            $failedFiles = rrmdir($entry);
            if ($failedFiles) {  // if some files/folders failed to be removed, send the list of failed removals
                $success = false;
            } else {
                $success = true;
            }
            die(json_encode(array('success' => $success ,
                                  'error' => '' ,
                                  'failed_files' => $failedFiles ,
                                 )));
        } else {  // if entry is a file
            if(unlink($entry)) {  // try removing the file
                die(json_encode(array('success' => true ,
                                      'error' => '' ,
                                      'failed_files' => array() ,
                                     )));
            } else {
                die(json_encode(array('success' => false ,
                                      'error' => '' ,
                                      'failed_files' => array($entry) ,
                                     )));
            }
        }
    }

}  // end of "if( authorized() )"

?>
 
<!DOCTYPE html>
<html lang="en">
<head>


<!-- *                                      -->
<!-- *    !!! JQuery functions section !!!  -->
<!-- *                                      -->

<!-- JQuery -->
<script src="https://code.jquery.com/jquery-3.4.0.min.js" integrity="sha256-BJeo0qm959uMBGb65z40ejJYGSgR7REI4+CW1fNKwOg=" crossorigin="anonymous"></script>
<!-- Bootstrap tooltips -->
<script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.4/umd/popper.min.js"></script>
<!-- Bootstrap core JavaScript -->
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>

<?php if (authorized()): ?>
<script>

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
        liString = '<li class="list-group-item '+color+'" style="height: 40px; padding-top: 7px; white-space: nowrap;">'+msg+'</li>';
    }

    $('#progress-log').append(liString);
    
    if (!small) {
        // make the text block higher if the text is wrapped to multiple lines
        lastLi = $('#progress-log > li').last();
        // sometimes innerWidth is 0.5 pixel more than scrollWidth, the text shouldn't be overflown in this case, so +1 is added
        if (lastLi[0].scrollWidth > lastLi.innerWidth()+1) {
            var additionalRows = Math.floor( lastLi[0].scrollWidth / lastLi.innerWidth() );
            lastLi.css({
                "white-space": "normal",
                "word-wrap": "break-word",
                "height": (40+27*additionalRows).toString()+"px",
            });
        }
    }

    if (scroll) {
        var progressLog = document.getElementById("progress-log");
        progressLog.scrollTop = progressLog.scrollHeight;
    }
};

</script>
<?php else: ?>
<script>

/**
 * [printMsg outputs given text in the box under password field if the user is not authorized. It has two
 *  versions for the main and the login page so that one function can use it to display text differently]
 * @param  {string} msg [string to print]
 * @return {null}
 */
var printMsg = function(msg) {
    $('#password-invalid').text(msg);
    $('#password-invalid').removeClass('d-none').addClass('show');
};

</script>
<?php endif; ?>

<script>  // the section being loaded regardless of authorized()

// global variables
var colors = {
    navLight: '#cbdce9',
    navDark: '#abc7dd',
};
var defaultDoneText = '<i class="fas fa-check fa-fw"></i> Submit';
var defaultFailText = '<i class="fas fa-times fa-fw"></i> Submit';

/**
 * [handleEmptyField asks to enter password if the field is empty]
 * @param  {string} fieldValue [string entered in the password field]
 * @return {boolean}           [true if the password field is empty]
 */
var handleEmptyField = function(fieldValue) {
    if (fieldValue === "") {
        printMsg('Please enter the password');
        return true;
    } else {
        return false;
    }
};

/**
 * [handleEmptyResponse shows warning if no JSON was found in the response]
 * @param  {object} $button  [button which state should be changed to failed]
 * @param  {object} jsonData [parsed json string]
 * @param  {string} failText [text to insert into the button]
 * @return {null}
 */
var handleEmptyResponse = function($button, jsonData, failText) {
    if (!$.trim(jsonData)){
        if (failText) {
            $button.html(failText);
            $button.prop("disabled", false);
        }
        printMsg("Empty response was returned", true, 'danger-progress');
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

    printMsg(msg, true, 'danger-progress');
};

/**
 * [prependZero puts 0 before the number if it is less than 10. Doesn't work on numbers above 99]
 * @param  {integer} num [number where 0 should be put]
 * @return {string}     [number with 0 at the beginning]
 */
var prependZero = function(num) {
    return ('0' + num).slice(-2);
};

/**
 * [getArchiveName generates a default name of the archive to create]
 * @return {string} [generated name of the archive]
 */
var getArchiveName = function() {
    var currentDate = new Date();
    var date = prependZero(currentDate.getDate());
    var month = prependZero(currentDate.getMonth() + 1);
    var year = currentDate.getFullYear();
    var hour = prependZero(currentDate.getHours());
    var minute = prependZero(currentDate.getMinutes());
    var second = prependZero(currentDate.getSeconds());

    archiveName = "wp-files_"+year+"-"+month+"-"+date+"_"+hour+":"+minute+":"+second+".zip";
    return archiveName;
};

/**
 * [setMaxheight sets maximum height of progress log so that it always stays within the window]
 */
var setMaxheight = function(){
    var progressLog = $("#progress-log");
    var winHeight = $(window).height();
    winHeight -= 210;
    progressLog.css({'max-height' : winHeight + "px"});
};


/**
 * Makes the correct menu item active when navbar gets switched to another view
 * @return {null}
 */
var transferActiveTab = function() {
    $activeTab = $('.tab-pane.active'); // find active tab
    $('.'+$activeTab.attr('aria-labelledby')).addClass('active');  // make menu item responsible for this tab active
};

</script>
<?php if (authorized()): ?>
<script>

/**
 * [sendFlushRequest sends POST request to flush Varnish and Redis caches]
 * @param  {bool} verbose Determines whether to tell that the hosting is not EasyWP.
 * @return {null}
 */
var sendFlushRequest = function(verbose) {
    verbose = verbose || false;
    $.ajax({
        type: "POST",
        timeout: 20000,
        data: {flush: 'submit'},
        success: function(response) {
            var jsonData;
            try {
                jsonData = JSON.parse(response);
            } catch (e) {
                printMsg('The returned value is not JSON', true, 'danger-progress');
                return;
            }
            handleEmptyResponse($('#btnFlush'), jsonData);
            if (verbose || jsonData.easywp) {
                if (jsonData.redis_success) {
                    printMsg('Redis Flushed Successfully!', false, 'success-progress');
                } else {
                    printMsg('Redis Flush Failed!', false, 'warning-progress');
                }
                if (jsonData.varnish_success) {
                    printMsg('Varnish Flushed Successfully!', true, 'success-progress');
                } else {
                    printMsg('Varnish Flush Failed!', true, 'warning-progress');
                }
                if (jsonData.errors.length !== 0) {
                    jsonData.errors.forEach(function(item, index, array) {
                        printMsg(item, true, 'danger-progress');
                    });
                }
            }
        },
        error: function (jqXHR, exception) {  // on error
            handleErrors(jqXHR, exception);
        }
    });
};

/**
 * [sendDebugOnRequest sends POST request to enable on-screen errors and wp-debug on the website]
 * @return {null}
 */
var sendDebugOnRequest = function() {
    $.ajax({
        type: "POST",
        timeout: 20000,
        data: {debugOn: 'submit'},
        success: function(response) {
            var jsonData;
            try {
                jsonData = JSON.parse(response);
            } catch (e) {
                printMsg('The returned value is not JSON', true, 'danger-progress');
                return;
            }
            handleEmptyResponse($("#btnDebugOn"), jsonData);
            if (jsonData.debug_on_success) {
                printMsg('Debug Enabled Successfully!', true, 'success-progress');
            } else {
                printMsg('Enabling Debug Failed!', true, 'warning-progress');
            }
            sendFlushRequest();
        },
        error: function (jqXHR, exception) {  // on error
            handleErrors(jqXHR, exception);
        }
    });
};

/**
 * [sendDebugOffRequest sends POST request to disable on-screen errors and wp-debug on the website]
 * @return {null}
 */
var sendDebugOffRequest = function() {
    $.ajax({
        type: "POST",
        timeout: 20000,
        data: {debugOff: 'submit'},
        success: function(response) {
            var jsonData;
            try {
                jsonData = JSON.parse(response);
            } catch (e) {
                printMsg('The returned value is not JSON', true, 'danger-progress');
                return;
            }
            handleEmptyResponse($("#btnDebugOff"), jsonData);
            if (jsonData.debug_off_success) {
                printMsg('Debug Disabled Successfully!', true, 'success-progress');
            } else {
                printMsg('Disabling Debug Failed!', true, 'warning-progress');
            }
            sendFlushRequest();
        },
        error: function (jqXHR, exception) {  // on error
            handleErrors(jqXHR, exception);
        }
    });
};

/**
 * [sendReplaceRequest sends POST request to replace default WordPress files with files of the latest version]
 * @param  {object} $button [button which state should be changed in the process]
 * @return {null}
 */
var sendReplaceRequest = function($button) {
    var loadingText = '<i class="fas fa-circle-notch fa-spin fa-fw"></i> Replacing...';
    var doneText = '<i class="fas fa-check fa-fw"></i> Replace Default Files';
    var failText = '<i class="fas fa-times fa-fw"></i> Replace Default Files';
    $button.prop("disabled", true);
    $button.html(loadingText);
    $.ajax({
        type: "POST",
        data: {replace: 'submit'},
        timeout: 300000,
        success: function(response) {
            $button.prop("disabled", false);
            var jsonData;
            try {
                jsonData = JSON.parse(response);
            } catch (e) {
                $button.html(failText);
                printMsg('The returned value is not JSON', true, 'danger-progress');
                return;
            }
            handleEmptyResponse($button, jsonData, failText);
            if (jsonData.replace_success) {
                $button.html(doneText);
                printMsg('Files Replaced Successfully!', true, 'success-progress');
            } else {
                $button.html(failText);
                printMsg('Files Replacing Failed!', true, 'warning-progress');
            }
            sendFlushRequest();
        },
        error: function (jqXHR, exception) {
            handleErrors(jqXHR, exception);
            $button.html(failText);
            $button.prop("disabled", false);
        }
    });
};

/**
 * [sendActivateRequest uploads and activates the 2019 WordPress theme]
 * @param  {object} $button [button which state should be changed in the process]
 * @return {null}
 */
var sendActivateRequest = function($button) {
    var loadingText = '<i class="fas fa-circle-notch fa-spin fa-fw"></i> Activating...';
    var doneText = '<i class="fas fa-check fa-fw"></i> Activate Clean 2019 Theme';
    var failText = '<i class="fas fa-times fa-fw"></i> Activate Clean 2019 Theme';
    $button.prop("disabled", true);
    $button.html(loadingText);
    $.ajax({
        type: "POST",
        data: {activate: 'submit'},
        timeout: 90000,
        success: function(response) {
            $button.prop("disabled", false);
            var jsonData;
            try {
                jsonData = JSON.parse(response);
            } catch (e) {
                $button.html(failText);
                printMsg('The returned value is not JSON', true, 'danger-progress');
                return;
            }
            handleEmptyResponse($button, jsonData, failText);
            if (jsonData.replace == "1" && jsonData.activate == "1") {
                $button.html(doneText);
            } else {
                $button.html(failText);
            }
            if (jsonData.replace == "1") {
                printMsg('Theme Uploaded Successfully!', false, 'success-progress');
            } else {
                printMsg('Theme Upload Failed!', false, 'warning-progress');
            }
            if (jsonData.activate == "1") {
                printMsg('Theme Activated Successfully!', true, 'success-progress');
            } else {
                printMsg('Theme Activation Failed!', true, 'warning-progress');
            }
            if (jsonData.errors.length !== 0) {
                jsonData.errors.forEach(function(item, index, array) {
                    printMsg(item, true, 'danger-progress');
                });
            }
            sendFlushRequest();
        },
        error: function (jqXHR, exception) {
            handleErrors(jqXHR, exception);
            $button.html(failText);
            $button.prop("disabled", false);
        }
    });
};

/**
 * [sendAdminerOnRequest uploads adminer-auto files and creates a session to access Adminer]
 * @return {null}
 */
var sendAdminerOnRequest = function() {
    $.ajax({
        type: "POST",
        data: {adminerOn: 'submit'},
        timeout: 40000,
        success: function(response) {
            var jsonData;
            try {
                jsonData = JSON.parse(response);
            } catch (e) {
                printMsg('The returned value is not JSON', true, 'danger-progress');
                return;
            }
            handleEmptyResponse($("#btnAdminerOff"), jsonData);
            if (jsonData.success) {
                printMsg('Adminer Enabled Successfully!', true, 'success-progress');
                // open Adminer in a new tab in 1 second after the success message is shown
                setTimeout(function() { window.open("wp-admin/adminer-auto.php"); }, 1000);
            } else {
                printMsg('Adminer Failed!', true, 'warning-progress');
            }
        },
        error: function (jqXHR, exception) {
            handleErrors(jqXHR, exception);
        }
    });
};

/**
 * [sendAdminerOffRequest removes adminer-auto files and unsets the session]
 * @return {null}
 */
var sendAdminerOffRequest = function() {
    $.ajax({
        type: "POST",
        data: {adminerOff: 'submit'},
        timeout: 40000,
        success: function(response) {
            var jsonData;
            try {
                jsonData = JSON.parse(response);
            } catch (e) {
                printMsg('The returned value is not JSON', true, 'danger-progress');
                return;
            }
            handleEmptyResponse($("btnAdminerOff"), jsonData);
            if (jsonData.success) {
                printMsg('Adminer Disabled Successfully!', true, 'success-progress');
            } else {
                printMsg('Adminer Disabling Failed!', true, 'warning-progress');
            }
        },
        error: function (jqXHR, exception) {
            handleErrors(jqXHR, exception);
        }
    });
};

/**
 * [sendFixFilesystemRequest runs stats on all files in the root directory to resolve the filesystem bug]
 * @param  {object} $button [button which state should be changed in the process]
 * @return {null}
 */
var sendFixFilesystemRequest = function($button) {
    var loadingText = '<i class="fas fa-circle-notch fa-spin fa-fw"></i> Fixing...';
    var doneText = '<i class="fas fa-check fa-fw"></i> Fix FileSystem';
    var failText = '<i class="fas fa-times fa-fw"></i> Fix FileSystem';
    $button.prop("disabled", true);
    $button.html(loadingText);
    $.ajax({
        type: "POST",
        data: {fixFileSystem: 'submit'},
        timeout: 300000,
        success: function(response) {
            var jsonData;
            try {
                jsonData = JSON.parse(response);
            } catch (e) {
                $button.html(failText);
                $button.prop("disabled", false);
                printMsg('The returned value is not JSON', true, 'danger-progress');
                return;
            }
            handleEmptyResponse($button, jsonData, failText);
            if (jsonData.success) {
                $button.html(doneText);
                $button.prop("disabled", false);
                printMsg('FileSystem Has Been Fixed!', true, 'success-progress');
            }
            sendFlushRequest();
        },
        error: function (jqXHR, exception) {
            handleErrors(jqXHR, exception);
            $button.html(failText);
            $button.prop("disabled", false);
        }
    });
};

/**
 * [sendFixPluginRequest creates a symlink if it is not created and uploads object-cache.php if it is missing]
 * @return {null}
 */
var sendFixPluginRequest = function() {
    $.ajax({
        type: "POST",
        timeout: 20000,
        data: {fixPlugin: 'submit'},
        success: function(response) {
            var jsonData;
            try {
                jsonData = JSON.parse(response);
            } catch (e) {
                printMsg('The returned value is not JSON', true, 'danger-progress');
                return;
            }
            handleEmptyResponse($('#btnFixPlugin'), jsonData);
            if (jsonData.symLink) {
                printMsg('Symlink Created Successfully!', false, 'success-progress');
            } else {
                printMsg('Symlink Creation Failed!', false, 'warning-progress');
            }
            if (jsonData.objectCache) {
                printMsg('object-cache.php Created Successfully!', true, 'success-progress');
            } else {
                printMsg('object-cache.php Creation Failed!', true, 'warning-progress');
            }
            sendFlushRequest();
        },
        error: function (jqXHR, exception) {
            handleErrors(jqXHR, exception);
        }
    });
};

/**
 * [sendDeleteAutoLoginRequest removes wp-admin-auto.php from the hosting storage]
 * @return {null}
 */
var sendDeleteAutoLoginRequest = function() {
    $.ajax({
        type: 'POST',
        data: {deleteAutoLogin: 'submit'},
        timeout: 40000,
        success: function(response) {
            var jsonData;
            try {
                jsonData = JSON.parse(response);
            } catch (e) {
                printMsg('The returned value is not JSON', true, 'danger-progress');
                return;
            }
            handleEmptyResponse($("#btnAutoLogin"), jsonData);
            if (!jsonData.success) {
                printMsg('Failed to remove wp-admin-auto.php. Please do it manually', true, 'warning-progress');
            }
        },
        error: function (jqXHR, exception) {
            handleErrors(jqXHR, exception);
        }
    });
};

/**
 * [sendAutoLoginRequest uploads wp-admin-auto.php and opens it in a new tab]
 * @return {null}
 */
var sendAutoLoginRequest = function() {
    $.ajax({
        type: "POST",
        data: {autoLogin: 'submit'},
        timeout: 40000,
        success: function(response) {
            var jsonData;
            try {
                jsonData = JSON.parse(response);
            } catch (e) {
                printMsg('The returned value is not JSON', true, 'danger-progress');
                return;
            }
            handleEmptyResponse($("#btnAutoLogin"), jsonData);
            if (jsonData.success) {
                printMsg('Success! You will be redirected in a second', true, 'success-progress');
                // open wp-admin-auto in a new tab in 1 second after the success message is shown
                setTimeout(function() { window.open(jsonData.siteurl+"/wp-admin-auto.php"); }, 1000);
                setTimeout(function() { sendDeleteAutoLoginRequest(); }, 3000);
            } else {
                if (jsonData.file === false) {  // it can also be true and null
                    printMsg('Failed to upload wp-admin-auto.php.', true, 'warning-progress'); 
                }   
                if (!jsonData.siteurl) {    
                    printMsg('Failed to find siteurl', true, 'warning-progress');   
                }   
                if (jsonData.errors.length !== 0) { 
                    jsonData.errors.forEach(function(item, index, array) {  
                        printMsg(item, true, 'danger-progress');   
                    }); 
                }
            }
        },
        error: function (jqXHR, exception) {
            handleErrors(jqXHR, exception);
        }
    });
};

/**
 * [sendSelfDestructRequest removes debugger.php and additional files]
 * @return {null}
 */
var sendSelfDestructRequest = function() {
    $.ajax({
        type: "POST",
        timeout: 20000,
        data: {selfDestruct: 'submit'},
        success: function(response) {
            var jsonData;
            try {
                jsonData = JSON.parse(response);
            } catch (e) {
                printMsg('The returned value is not JSON', true, 'danger-progress');
                return;
            }
            handleEmptyResponse($("#btnSelfDestruct"), jsonData);
            if (jsonData.success) {
                printMsg('debugger.php Deleted Successfully!', true, 'success-progress');
            }
        },
        error: function (jqXHR, exception) {
            handleErrors(jqXHR, exception);
        }
    });
};

/**
 * [sendVersionCheckRequest checks if the version on GitHub is higher than the current one]
 * @return {null}
 */
var sendVersionCheckRequest = function() {
    $.ajax({
        type: "POST",
        timeout: 20000,
        data: {checkVersion: 'submit'},
        success: function(response) {
            var jsonData;
            try {
                jsonData = JSON.parse(response);
            } catch (e) {
                $("#version-fail").removeClass('d-none').addClass('show');
                return;
            }
            if (!$.trim(jsonData)){
                $("#version-fail").removeClass('d-none').addClass('show');
            }
            if (jsonData.success) {
                if (jsonData.new) {
                    $("#version-new").removeClass('d-none').addClass('show');
                } else {
                    // do nothing if GitHub version is equal or lower
                }
            } else {
                $("#version-fail").removeClass('d-none').addClass('show');
            }
        },
        error: function (jqXHR, exception) {
            $("#version-fail").removeClass('d-none').addClass('show');
        }
    });
};


/**
 * [sendUnzipRequest sends a request to extract ZIP archive and processes the response]
 * @param  {string} archiveName  [path to zip file]
 * @param  {string} destDir      [destination directory]
 * @param  {integer} maxUnzipTime [maximum time to process extraction]
 * @param  {integer} totalNum     [total number of files in archive]
 * @param  {integer} startNum     [number of file to start extracting from]
 * @return {null}
 */
var sendUnzipRequest = function(archiveName, destDir, maxUnzipTime, totalNum, startNum) {
    // default parameters
    startNum = startNum || 0;

    $.ajax({
        type: "POST",
        data: {action: 'extract',
               archiveName: archiveName,
               destDir: destDir,
               maxUnzipTime: maxUnzipTime,
               startNum: startNum},
        success: function(response) {
            var jsonData;
            try {
                jsonData = JSON.parse(response);
            } catch (e) {
                $('#btnExtract').html(defaultFailText);
                $('#btnExtract').prop("disabled", false);
                printMsg('The returned value is not JSON', true, 'danger-progress');
                return;
            }

            handleEmptyResponse($('#btnExtract'), jsonData, defaultFailText);

            if (jsonData.success) {  // if success, show the success button and message
                $('#progress-bar').removeClass('progress-bar-striped bg-info progress-bar-animated').addClass('bg-success').text('100%').width('100%');
                $('#btnExtract').prop("disabled", false);
                $('#btnExtract').html(defaultDoneText);
                printMsg('Archive extracted successfully!', true, 'success-progress');
                sendFlushRequest();
            }

            // if the extraction didn't complete in one turn, start from the last file
            else if (jsonData.startNum) {
                percentage = (jsonData.startNum/totalNum*100).toFixed() + '%';
                $('#progress-bar').text(percentage).width(percentage);
                // if the starting file was already sent before, skip it and show alert message
                if (startNum == jsonData.startNum) {
                    sendUnzipRequest(archiveName, destDir, maxUnzipTime, totalNum, startNum+1);
                    printMsg("The following file will not be extracted because it's too big: <strong>"+jsonData.failedFile+"</strong>", true, 'warning-progress');
                } else {  // if the extraction didn't complete but another file appeared to be the last one, continue the next iteration from it (of if there were no starting files before)
                    startNum = jsonData.startNum;
                    printMsg('The connection was interrupted on <strong>'+jsonData.failedFile+'</strong>, resuming extraction from it.', true, 'info-progress');
                    sendUnzipRequest(archiveName, destDir, maxUnzipTime, totalNum, startNum);
                }
            }

            else {  // if complete fail, show returned error
                $('#progress-bar').removeClass('progress-bar-striped bg-info progress-bar-animated').addClass('bg-danger');
                $('#btnExtract').html(defaultFailText);
                $('#btnExtract').prop("disabled", false);
                printMsg('An error happened upon extracting the backup: <strong>'+jsonData.error+'</strong>', true, 'danger-progress');
            }
        },
        error: function (jqXHR, exception) {
            handleErrors(jqXHR, exception, [0, 503]); // handle errors except for 0 and 503
            if (jqXHR.status == 503 || jqXHR.status === 0) {
                if (maxUnzipTime == 10) {  // if the limit is already 10, nothing will help
                    printMsg('Even requests limited by 10 seconds return 503 or network errors', true, 'danger-progress');
                    $('#btnExtract').html(defaultFailText);
                    $('#btnExtract').prop("disabled", false);
                    $('#progress-bar').removeClass('progress-bar-striped bg-info progress-bar-animated').addClass('bg-danger');
                } else {
                    if (jqXHR.status == 503) {
                        error = '503 Service Unavailable';
                    } else {
                        error = 'Failed To Connect. Network Error';
                    }
                    printMsg('Previous request returned <strong>"'+error+'"</strong>. Decreasing the time limit by 10 seconds and sending the request again.', true, 'warning-progress');
                    maxUnzipTime -= 10;  // if the request returned 503 because overusing a limit, try decreasing the time limit
                    sendUnzipRequest(archiveName, destDir, maxUnzipTime, totalNum, startNum);
                }
            } else {
                $('#progress-bar').removeClass('progress-bar-striped bg-info progress-bar-animated').addClass('bg-danger');
            }
        }
    });
};

/**
 * [processExtractForm does pre-checks and starts the extraction]
 * @param  {object} form [extraction form]
 * @return {null}
 */
var processExtractForm = function(form) {
    // preparations
    form.preventDefault();
    var zipFile = $("#zip-file-extract").val();
    var destDir = $("#dest-dir").val();
    if (!destDir) {
        destDir = '.';
    }
    var defaultTimeLimit = 60; // a higher limit raises 503 frequently on EasyWP in my experience
    var loadingText = '<i class="fas fa-circle-notch fa-spin fa-fw"></i> Extracting...';
    $('#btnExtract').prop("disabled", true);
    $('#btnExtract').html(loadingText);
    printMsg('Starting Extraction. First request sent. The next update is within ' + defaultTimeLimit + ' seconds.', true, 'info-progress');

    var zipIsExtractable = false;
    var dirIsWritable = false;
    var activeAjaxRequests = 2;
    var totalNumber;

    var processExtraction = function(totalNumber) {
        if (zipIsExtractable && dirIsWritable) {
            $("#progress-row").removeClass('d-none').addClass('show');
            $("#progress-container").html('<div class="progress-bar progress-bar-striped bg-info progress-bar-animated" id="progress-bar" role="progressbar" style="width: 2%;">1%</div>');  // 1% is poorly visible with width=1%, so the width is 2 from the start
            sendUnzipRequest(zipFile, destDir, defaultTimeLimit, totalNumber);
        } else {
            $('#btnExtract').html(defaultFailText);
            $('#btnExtract').prop("disabled", false);
        }
    };

    // send request to get total number of files in zip archive
    var getFilesNumber = $.ajax({
        type: "POST",
        data: {filesNumber: zipFile}
    })
    .done(function( response ) {
        var jsonData;
        try {
            jsonData = JSON.parse(response);
        } catch (e) {
            $('#btnExtract').html(defaultFailText);
            $('#btnExtract').prop("disabled", false);
            printMsg('The returned value is not JSON', true, 'danger-progress');
            return;
        }

        handleEmptyResponse($('btnExtract'), jsonData, defaultFailText);

        if (jsonData.success) {
            zipIsExtractable = true;
            totalNumber = jsonData.number;
        } else {
            printMsg('An error happened upon extracting the backup: <strong>'+jsonData.error+'</strong>', true, 'danger-progress');
        }
    })
    .fail(function( jqXHR, exception, message ) {
        handleErrors(jqXHR, exception);
        $('#btnExtract').html(defaultFailText);
        $('#btnExtract').prop("disabled", false);
    })
    .always(function() {
        activeAjaxRequests--;
        if (activeAjaxRequests == 0) {
            processExtraction();
        }
    });

    var checkDestDir = $.ajax({
        type: 'POST',
        data: {checkDestDir: 'submit',
               destDir: destDir}
    })
    .done(function( response ) {
        var jsonData;
        try {
            jsonData = JSON.parse(response);
        } catch (e) {
            $('#btnExtract').html(defaultFailText);
            $('#btnExtract').prop("disabled", false);
            printMsg('The returned value is not JSON', true, 'danger-progress');
            return;
        }

        handleEmptyResponse($('btnExtract'), jsonData, defaultFailText);

        if (jsonData.success) {
            dirIsWritable = true;
        } else {
            printMsg('An error happened upon extracting the backup: <strong>Destination directory is not writable and we failed to create such directory</strong>', true, 'danger-progress');
        }
    })
    .fail(function( jqXHR, exception, message ) {
        handleErrors(jqXHR, exception);
    })
    .always(function() {
        activeAjaxRequests--;
        if (activeAjaxRequests == 0) {
            processExtraction();
        }
    });

};

/**
 * [processViewForm shows a list of files inside the archive]
 * @param  {object} form [view-archive form]
 * @return {null}
 */
var processViewForm = function(form) {
    // preparations
    form.preventDefault();
    var loadingText = '<i class="fas fa-circle-notch fa-spin fa-fw"></i> Loading...';
    var archiveName = $("#zip-file-view").val();
    $('#btnView').prop("disabled", true);
    $('#btnView').html(loadingText);

    // send request to get filenames inside zip file
    $.ajax({
        type: "POST",
        data: {action: 'view',
               archiveName: archiveName},
        success: function(response) {  // on success
            var jsonData;
            try {
                jsonData = JSON.parse(response);
            } catch (e) {
                $('#btnView').html(defaultFailText);
                $('#btnView').prop("disabled", false);
                printMsg('The returned value is not JSON', true, 'danger-progress');
                return;
            }

            handleEmptyResponse($('#btnView'), jsonData, defaultFailText);

            if (jsonData.success) {  // if success, show the success button and message
                $('#btnView').prop("disabled", false);
                $('#btnView').html(defaultDoneText);
                jsonData.files.forEach(function(item, index, array) {
                    printMsg(item, false, null, true); // no auto-scroll, without color, small size
                });
                // scroll to bottom after all the messages are output
                var progressLog = document.getElementById("progress-log");
                progressLog.scrollTop = progressLog.scrollHeight;
            }
            else {  // if complete fail, show returned error
                $('#btnView').html(defaultFailText);
                $('#btnView').prop("disabled", false);
                printMsg('An error happened upon opening the backup: <strong>'+jsonData.error+'</strong>', true, 'danger-progress');
            }
        },
        error: function (jqXHR, exception, message) {  // on error
            handleErrors(jqXHR, exception);
            $('#btnView').html(defaultFailText);
            $('#btnView').prop("disabled", false);
        }
    });
};


/**
 * [sendArchiveRequest sends a request to compress a directory and processes the response]
 * @param  {string} archiveName     [path to zip file]
 * @param  {integer} totalNum       [total number of files in a directory]
 * @param  {integer} startNum       [the number of file to start compressing from]
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
            var jsonData;
            try {
                jsonData = JSON.parse(response);
            } catch (e) {
                $('#btnArchive').html(defaultFailText);
                $('#btnArchive').prop("disabled", false);
                printMsg('The returned value is not JSON', true, 'danger-progress');
                return;
            }
            
            handleEmptyResponse($('#btnArchive'), jsonData, defaultFailText);
                
            if (jsonData.success) {  // if success, show the success button and message
                $('#progress-bar').removeClass('progress-bar-striped bg-info progress-bar-animated').addClass('bg-success').text('100%').width('100%');
                $('#btnArchive').prop("disabled", false);
                $('#btnArchive').html(defaultDoneText);
                printMsg('Archive created successfully!', true, 'success-progress');
            }

            // if the compression didn't complete in one turn, start from the last file
            else if (jsonData.startNum) {
                percentage = (jsonData.startNum/totalNum*100).toFixed() + '%';
                $('#progress-bar').text(percentage).width(percentage);
                startNum = jsonData.startNum;
                sendArchiveRequest(archiveName, totalNum, startNum);
            } else {  // if complete fail, show returned error
                $('#progress-bar').removeClass('progress-bar-striped bg-info progress-bar-animated').addClass('bg-danger');
                $('#btnArchive').html(defaultFailText);
                $('#btnArchive').prop("disabled", false);
                printMsg('An error happened upon creating the backup: <strong>'+jsonData.error+'</strong>', true, 'danger-progress');
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

/**
 * [processArchiveForm does pre-checks and starts compressing the directory]
 * @param  {object} form [compressing form]
 * @return {null}
 */
var processArchiveForm = function(form) {
    // preparations
    form.preventDefault();
    var directory = $("#folder-archive").val();
    if (!directory) {
        directory = '.';
    }
    var archiveName = $("#archive-name").val();
    if (!archiveName) {
        if (directory == '.') {
            archiveName = getArchiveName();
        } else {
            archiveName = directory.substring(directory.lastIndexOf("/")) + '.zip';  // directory name (without parent directories) + .zip
        }
    }
    var loadingText = '<i class="fas fa-circle-notch fa-spin fa-fw"></i> Compressing...';
    $('#btnArchive').prop("disabled", true);
    $('#btnArchive').html(loadingText);
    printMsg('Starting Compression...', true, 'info-progress');

    // send request to get total number of files in directory
    var compressPreCheck = $.ajax({
        type: "POST",
        data: {compressPreCheck: 'submit',
               directory: directory,
               archive: archiveName}
    })
    .done(function( response ) {
        var jsonData;
        try {
            jsonData = JSON.parse(response);
        } catch (e) {
            $('#btnArchive').html(defaultFailText);
            $('#btnArchive').prop("disabled", false);
            printMsg('The returned value is not JSON', true, 'danger-progress');
            return;
        }
        
        handleEmptyResponse($('#btnArchive'), jsonData, defaultFailText);
            
        if (jsonData.numberSuccess && jsonData.checkArchiveSuccess) {
            $("#progress-row").removeClass('d-none').addClass('show');
            $("#progress-container").html('<div class="progress-bar progress-bar-striped bg-info progress-bar-animated" id="progress-bar" role="progressbar" style="width: 2%;">1%</div>');  // 1% is poorly visible with width=1%, so the width is 2 from the start
            sendArchiveRequest(archiveName, jsonData.number);
        } else {
            $('#btnArchive').html(defaultFailText);
            $('#btnArchive').prop("disabled", false);
            if (!jsonData.numberSuccess) {
                printMsg('An error happened upon compressing the directory: <strong>'+jsonData.numberError+'</strong>', true, 'danger-progress');
            }
            if (!jsonData.checkArchiveSuccess) {
                printMsg('An error happened upon compressing the directory: <strong>'+archiveName+' already exists</strong>', true, 'danger-progress');
            }
        }
    })
    .fail(function( jqXHR, exception ) {
        handleErrors(jqXHR, exception);
        $('#btnArchive').html(defaultFailText);
        $('#btnArchive').prop("disabled", false);
    });
};


var processDeleteForm = function(form) {
    // preparations
    form.preventDefault();
    var entry = $("#delete-entry").val();
    var loadingText = '<i class="fas fa-circle-notch fa-spin fa-fw"></i> Deleting...';
    $('#btnDelete').prop("disabled", true);
    $('#btnDelete').html(loadingText);

    // send request to get filenames inside zip file
    $.ajax({
        type: "POST",
        data: {delete: 'submit',
               entry: entry},
        success: function(response) {  // on success
            var jsonData;
            try {
                jsonData = JSON.parse(response);
            } catch (e) {
                $('#btnDelete').html(defaultFailText);
                $('#btnDelete').prop("disabled", false);
                printMsg('The returned value is not JSON', true, 'danger-progress');
                return;
            }

            handleEmptyResponse($('#btnDelete'), jsonData, defaultFailText);

            if (jsonData.success) {  // if success, show the success button and message
                $('#btnDelete').prop("disabled", false);
                $('#btnDelete').html(defaultDoneText);
                printMsg(entry+' deleted successfully!', true, 'success-progress');
            } else if (jsonData.error) {
                $('#btnDelete').html(defaultFailText);
                $('#btnDelete').prop("disabled", false);
                printMsg('An error happened upon deleting the entry: <strong>'+jsonData.error+'</strong>', true, 'danger-progress');
            } else if (jsonData.failed_files) {
                $('#btnDelete').html(defaultFailText);
                $('#btnDelete').prop("disabled", false);
                printMsg('The following files and folders could not be removed: ', false, 'danger-progress');
                jsonData.failed_files.forEach(function(item, index, array) {
                    printMsg(item, false, null, true); // no auto-scroll, without color, small size
                });
                printMsg('', true, 'danger-progress', true); // empty message, red color, small size
            }
            sendFlushRequest();
        },
        error: function (jqXHR, exception, message) {  // on error
            handleErrors(jqXHR, exception);
            $('#btnDelete').html(defaultFailText);
            $('#btnDelete').prop("disabled", false);
        }
    });
};

$(document).ready(function() {

    setMaxheight();

    $(window).resize(function(){
        setMaxheight();
        transferActiveTab();
    });

    $('#easywp-tab').on("click", function() {
        $('#easywp-icon-path').css({ fill: colors.navDark });
    });

    //  "on focusout" doesn't work properly in all cases, so this is a woraround
    $('.nav-link').not('#easywp-tab').on("click", function() {
        if ($(window).width() > 1018) {
            $('#easywp-icon-path').css({ fill: colors.navLight });
        }
    });

    // default "active" removal got broken for hamburger meu for some reason
    $('.nav-link').on("click", function() {
        $('.animated-ham-icon').toggleClass('open');  // the navbar will collapse because of data-toggle="collapse" but bootstrap doesn't handle animated buttons, so this it has to be animated manually

        $otherLinks = $('.nav-link').not(this);

        $otherLinks.attr({
            'aria-selected': 'false',
        });

        $otherLinks.parent().removeClass('active');
        $otherLinks.removeClass('active');
    });

    $('#archive-name').val(getArchiveName());  // show default name of a backup in the field

    $("#folder-archive").on("input", function(){
        var $archiveField = $("#archive-name");
        var folder = $(this).val();
        if (folder) {
            $archiveField.val(folder+'.zip');  // change the name of archive making it similar to the name of directory
        } else {
            $archiveField.val(getArchiveName());  // show default name of a backup in the field
        }
    });

    $('.navbar-toggler').on('click', function () {
        $('.animated-ham-icon').toggleClass('open');
    });    $("#folder-archive").on("input", function(){
        $("#archive-name").attr('placeholder', $(this).val()+'.zip');  // change the name of archive making it similar to the name of directory
    });

    sendVersionCheckRequest();

    $('#extract-form').submit(function(form) {
        processExtractForm(form);
    });

    $('#archive-form').submit(function(form) {
        processArchiveForm(form);
    });

    $('#view-form').submit(function(form) {
        processViewForm(form);
    });

    $('#delete-form').submit(function(form) {
        processDeleteForm(form);
    });    

    $("#btnFlush").click(function() {
        sendFlushRequest(true);  // verbose turned on
    });

    $("#btnDebugOn").click(function() {
        sendDebugOnRequest();
    });

    $("#btnDebugOff").click(function() {
        sendDebugOffRequest();
    });

    $("#btnReplace").click(function() {
        sendReplaceRequest($(this));
    });

    $("#btnAdminerOn").click(function() {
        sendAdminerOnRequest();
    });

    $("#btnAdminerOff").click(function() {
        sendAdminerOffRequest();
    });

    $("#btnActivate").click(function() {
        sendActivateRequest($(this));
    });

    $("#btnFixFilesystem").click(function() {
        sendFixFilesystemRequest($(this));
    });

    $("#btnFixPlugin").click(function() {
        sendFixPluginRequest();
    });

    $("#btnAutoLogin").click(function() {
        sendAutoLoginRequest();
    });

    $("#btnSelfDestruct").click(function() {
        sendSelfDestructRequest();
    });

});

</script>
<?php else: ?>
<script>

var processLoginform = function(form) {
    form.preventDefault();
    $('#password-invalid').removeClass('show').addClass('d-none');
    var password = $("#login-form :input[name='password']")[0].value;
    if (handleEmptyField(password)) {
        return;
    }
    $.ajax({
        type: "POST",
        data: {login: 'submit',
               password: password
        },
        timeout: 40000,
        success: function(response) {
            var jsonData = JSON.parse(response);
            
            handleEmptyResponse($(''), jsonData);

            if (jsonData.success) {
                location.reload(true);
            } else {
                printMsg('Invalid password');
            }
        },
        error: function (jqXHR, exception) {
            handleErrors(jqXHR, exception);
        }
    });
};

$(document).ready(function() {
    $('#login-form').submit(function(form) {
        processLoginform(form);
    });
});

</script>
<?php endif; ?>

    

<!-- *                                      -->
<!-- *    !!! CSS section !!!               -->
<!-- *                                      -->


<!-- Font Awesome -->
<link rel="stylesheet" href="https://use.fontawesome.com/releases/v5.8.1/css/all.css" integrity="sha384-50oBUHEmvpQ+1lW4y57PTFmhCaXp0ML5d60M1M7uH2+nqUivzIebhndOJK28anvf" crossorigin="anonymous">
<!-- Bootstrap core CSS -->
<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css">
<!-- Material Design Bootstrap -->
<link href="https://cdnjs.cloudflare.com/ajax/libs/mdbootstrap/4.8.8/css/mdb.min.css" rel="stylesheet">

<style type="text/css">

    :root {
        --nav-light: #cbdce9;
        --nav-dark: #abc7dd;
        --stylish-light: #cbdce9;
        --unique-light: #eaf1f6;
        --stylish-red-light: #fbf2f4;
        --stylish-warning: #ecd779;
        --stylish-success: #b2ec87;
        --stylish-color-active: #5E626E;
        --danger-progress: #e68e8e;
        --success-progress: #cdf5b8;
        --warning-progress: #f2e5a7;
        --info-progress: #aec9de;

    }


    /* Progress Log Adjustments */

    .progress-log{
        margin-bottom: 10px;
        overflow-x: hidden;
        overflow-y: scroll;
        -webkit-overflow-scrolling: touch;
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

    /* End of Progress Log Adjustments */


    .version-notification {
        bottom: 70px;
        position: fixed;
        right: 50px;
    }


    .tab-content {
        margin-left: auto;
        margin-right: auto;
        padding-left: 50px;
        padding-right: 50px;
    }

    .nav-link {
        padding: .5rem 0px;
    }

    .navbar-dark .navbar-nav .nav-link {
        color: var(--nav-dark);
    }

    .nav-tabs {  /* override default MDBootstrap behavior */
        border-bottom: 0;
    }

    @media screen and (min-width: 1019px) {  /* for desktop navbar */

        /* show desktop navbar only on large screens */
        .navbar-hamburger {
            display: none !important;
        }

        .nav-tabs .nav-item.show .nav-link, .nav-tabs .nav-link.active {
            color: var(--nav-dark);
            background-color: transparent;
            border-color: transparent transparent var(--nav-dark);
            border-bottom: 4px solid !important;
            font-size: 20px;
            font-weight: bold;
        }

        .nav-tabs .nav-link {
            border: 1px solid transparent;
            border-top-left-radius: .25rem;
            border-top-right-radius: .25rem;
            color: var(--nav-light);
            font-size: 20px;
        }

        .nav-tabs .nav-link:focus, .nav-tabs .nav-link:hover {  /* override default MDBootstrap behavior */
            border-color: transparent;
        }

        .icon-easywp {
            fill: var(--nav-dark);
        }

    }

    @media screen and (max-width: 1018px) {  /* for hamburger navbar */

        /* show hamburger only on small screens */
        .navbar-desktop {
            display: none !important;
        }

        /* override default MDBootstrap behavior: remove radius and white border at the bottom */
        .nav-tabs .nav-link {
            border: none;
            border-top-left-radius: 0;
            border-top-right-radius: 0;
        }
        
        /* default color of all menu items */
        .navbar-dark .navbar-brand, .navbar.navbar-dark .navbar-nav .nav-item .nav-link {
            color: var(--nav-light);
        }

        /* default behavior for navbar-brand is to change to the white color. This snippet removes hover animation at all */
        .navbar-dark .navbar-brand:hover {
            color: var(--nav-light);
        }

        /* switch to darker color on hover for the active menu item */
        .navbar.navbar-dark .navbar-nav .nav-item.active>span>.nav-link:hover {
            color: var(--nav-dark);
        }
        
        /* switch to darker color on hover for all other (inactive) menu items */
        .navbar.navbar-dark .navbar-nav .nav-item>span>.nav-link:hover {
            color: var(--nav-dark);
        }

        /* switch the easywp icon to darker color on hover for the easywp menu item */
        #easywp-tab:hover .icon-easywp {
            fill: var(--nav-dark);
        }
        
        /* override animation (default color is white for MDBootstrap). The second selector is the easywp icon that doesn't get animated along with the text */
        .navbar.navbar-dark .navbar-nav .nav-item .nav-link, #easywp-tab .icon-easywp {
            transition-property: all;
            transition-duration: 0.35s;
            transition-timing-function: ease;
            transition-delay: 0s;
        }

        /* make background color lighter for the active item */
        .nav-tabs .nav-item.show .nav-link, .nav-tabs .nav-link.active {
            background-color: var(--stylish-color-active);
        }

        .icon-easywp {
            fill: var(--nav-light);
        }

    }

    /**
     * Style for the svg icon which is the only one different from the other icons 
     * the were downloaded from awesomefont. These style block makes it similar to 
     * the awesomefont icons.
     */
    .icon-easywp {
        display: inline-block;
        width: 1em;
        height: 1em;
        stroke-width: 0;
        stroke: currentColor;
        /* fill: currentColor; */
        margin-bottom: 3px;  /* this margin lines up the icon with awesomefont icons */
        margin-left: 3px;
    }

    .btn-nav {
        padding: .42rem 1.07rem;
    }


    /* Custom Colors */

    .btn.stylish-color {
        color: var(--stylish-light);
    }

    .btn.unique-color {
        color: var(--unique-light);
    }

    .btn-red {
        color: var(--stylish-red-light);
    }

    .btn.stylish-warning {
        background-color: var(--stylish-warning);
        color: var(--stylish-color);
    }

    .btn.stylish-success {
        background-color: var(--stylish-success);
        color: var(--stylish-color);
    }

    .input-group-text-info {
        background-color: var(--stylish-light);
        border: 1px solid var(--nav-dark);
    }

    .success-progress {
        background-color: var(--success-progress);
    }

    .warning-progress {
        background-color: var(--warning-progress);
    }

    .danger-progress {
        background-color: var(--danger-progress);
    }

    .info-progress {
        background-color: var(--info-progress)
    }

    /* End of Custom Colors */

    .btn-group {
        margin: .375rem;
    }

    .btn.btn-form {
        margin-bottom: 0rem;
        margin-left: 0rem;
        margin-right: 0rem;
        margin-top: 1rem;
        font-size: .81rem;
        padding: .6rem 1.6rem;
    }

    .input-group-prepend, .form-control {
        margin-top: 1rem;
    }

    .p-col {  /* makes the same padding as the col class */

        padding-left: 15px;
        padding-right: 15px;
    }

    input {
        min-width: 150px;
    }

    .input-group {
        margin-top: 1rem;
    }

    @media screen and (max-width: 1340px) {
        .hide-backups {
            display: none !important;
        }
    }

    /* custom queries*/

    .col-xl-smaller-6, .col-xl-smaller-12 {
        position: relative;
        width: 100%;
        padding-right: 15px;
        padding-left: 15px;
    }

    @media screen and (min-width: 1132px) {
        .col-xl-smaller-6 {
            flex: 0 0 50%;
            max-width: 50%;
        }

        .col-xl-smaller-12 {
            flex: 0 0 100%;
            max-width: 100%;
        }

        .tab-content {
            margin-top: 84px;
        }

        .tab-content #files-backups {
            margin-top: -26px;
        }
    }

    @media screen and (max-width: 1131px) {
        .tab-content {
            margin-top: 3em;
        }
    }

    /* decrease paddings in two times once adminer button don't fit into the screen width */
    @media screen and (max-width: 400px) {
        .btn {
            padding: .42rem 1.07rem;
        }
    }

    /* group button on the easywp tab into a column once they are not fit into one row */
    @media screen and (max-width: 553px) {
        .easywp-row {
            flex-direction: column;
        }
    }

    /* end of custom queries */


    /* hamburger animation */

    .animated-ham-icon {
        width: 30px;
        height: 20px;
        position: relative;
        margin: 0px;
        -webkit-transform: rotate(0deg);
        -moz-transform: rotate(0deg);
        -o-transform: rotate(0deg);
        transform: rotate(0deg);
        -webkit-transition: .5s ease-in-out;
        -moz-transition: .5s ease-in-out;
        -o-transition: .5s ease-in-out;
        transition: .5s ease-in-out;
        cursor: pointer;
    }

    .animated-ham-icon span {
        display: block;
        position: absolute;
        height: 3px;
        width: 100%;
        border-radius: 9px;
        opacity: 1;
        left: 0;
        -webkit-transform: rotate(0deg);
        -moz-transform: rotate(0deg);
        -o-transform: rotate(0deg);
        transform: rotate(0deg);
        -webkit-transition: .25s ease-in-out;
        -moz-transition: .25s ease-in-out;
        -o-transition: .25s ease-in-out;
        transition: .25s ease-in-out;
    }

    .animated-ham-icon span {
        background: var(--nav-light);
    }

    .animated-ham-icon span:nth-child(1) {
        top: 0px;
        -webkit-transform-origin: left center;
        -moz-transform-origin: left center;
        -o-transform-origin: left center;
        transform-origin: left center;
    }

    .animated-ham-icon span:nth-child(2) {
        top: 10px;
        -webkit-transform-origin: left center;
        -moz-transform-origin: left center;
        -o-transform-origin: left center;
        transform-origin: left center;
    }

    .animated-ham-icon span:nth-child(3) {
        top: 20px;
        -webkit-transform-origin: left center;
        -moz-transform-origin: left center;
        -o-transform-origin: left center;
        transform-origin: left center;
    }

    .animated-ham-icon.open span:nth-child(1) {
        -webkit-transform: rotate(45deg);
        -moz-transform: rotate(45deg);
        -o-transform: rotate(45deg);
        transform: rotate(45deg);
        top: 0px;
        left: 8px;
    }

    .animated-ham-icon.open span:nth-child(2) {
        width: 0%;
        opacity: 0;
    }

    .animated-ham-icon.open span:nth-child(3) {
        -webkit-transform: rotate(-45deg);
        -moz-transform: rotate(-45deg);
        -o-transform: rotate(-45deg);
        transform: rotate(-45deg);
        top: 21px;
        left: 8px;
    }

    /* end of hamburger animation */

</style>


<!-- *                                      -->
<!-- *    !!! HTML section !!!              -->
<!-- *                                      -->

    <?php if (authorized()): ?>
        <title>Debugger</title>
    <?php else: ?>
        <title>Debugger Login</title>
    <?php endif; ?>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="data:image/x-icon;base64,iVBORw0KGgoAAAANSUhEUgAAADIAAAAyCAYAAAAeP4ixAAAAAXNSR0IArs4c6QAAAARnQU1BAACxjwv8YQUAAAAJcEhZcwAADsMAAA7DAcdvqGQAAAKkSURBVGhD7dhLyE1RGMbxz/1S7rmXIilFmTAwURiYUVJMDEwoSSGJfDMjJQkDSibKJTKQwsRAkaTESK4ZKSXkkvv/HexavT1nn7X2WeuU7Kd+s7XX2m9nn/2utQfatGnT5r/IMEwqYBz6mgP4U8BXzEFfMgMfoW4kh3PoS05B3UAuv7EMRbMYP6FuIKc7GIJiuQG1cAnrUSRroBYs5RlGIWuG4wnUgiXtQtZshVqo8gPPa7yHuq4bu24KsmQ83kItVHmNbhmDVTiBL1DzKEeRJYegFgjFFBJmJi5AzeV9xwL0FOuy1m3VAqHUQqrE7hCuoqdYl1UTe76QkZgXmI5OOQI1p7cCjWLd1bqsmtTzhVjj9GNeYT9GI4xtQB/Cj/ceYCiSY91VTajEFFK5j8kIE9ujNiMp1lXVRJ2kFGKuw+cl1NjQG4xFVKybWldVE3WSWohZiTDHoMZ5BxEV66ZqgjpNCjmOMNugxnmfYEeJ2tgJrUkXblKIbUDDrIMap5xEbWzrfBfq4jpNCrmMMJugxilb0DVLEfvarTQpZB/C7IEa5z1C9Gv4LNQknaQW8hmzEca6txrrrUZ0ZsH+VGoiJbWQ7Qhj/82YjeQ1JCflS0lsIXazvgjLINT4kB0VFiI5tpV4ATWp5wuZi1uBK7D/hH+cLPMR81Wm65uqLhugJvV8IbGZgJiT5wdMRU+5DTV5qEkh9qs9hprP24ueswS/oBaopBRiJ0V7zGIbr+2a/Y65cU5DLVKxo7C9FjuxR3Qn7FSY+pVyI7JlGuw5VQuVdA/ZP9TthlqsFNtdLEf22Pb+KdSiJVxCsayFWjS3b7CzftHchFo8p8MonkWw7YK6gRzeYSL6EjvdqZvIYQf6Fvseex4XMzuDEWjTpk2bNv9CBgb+Ah5CpqHklKu9AAAAAElFTkSuQmCC" rel="icon" type="image/x-icon" />
</head>

<?php if (authorized()): ?>
<body>

<svg aria-hidden="true" style="position: absolute; width: 0; height: 0; overflow: hidden;" version="1.1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">
<defs>
<symbol id="icon-easywp" viewBox="0 0 20 20">
<title>easywp</title>
<path id="easywp-icon-path" d="M19.736 12.277c0-0.748-0.287-1.099-0.749-1.099-0.143 0-0.271 0.032-0.367 0.096-0.701 1.162-1.323 1.672-2.374 1.672-0.908 0-1.53-0.366-1.88-1.067 1.084-2.022 1.832-3.678 1.832-5.955 0-3.933-2.231-5.924-6.199-5.924s-6.199 1.99-6.199 5.924c0 2.277 0.749 3.933 1.832 5.955-0.351 0.701-0.972 1.067-1.88 1.067-1.052 0-1.673-0.51-2.374-1.672-0.096-0.064-0.223-0.096-0.367-0.096-0.462 0-0.749 0.35-0.749 1.099 0 1.815 1.609 3.312 3.936 3.312 0.606 0 1.243-0.096 1.944-0.35-0.924 2.293-1.944 2.771-3.314 2.436-0.351 0.175-0.462 0.541-0.462 0.828 0 0.764 0.797 1.497 2.438 1.497 2.358 0 4.207-1.481 4.924-4.634 0.080-0.080 0.143-0.143 0.271-0.143s0.191 0.064 0.271 0.143c0.717 3.153 2.565 4.634 4.924 4.634 1.641 0 2.438-0.732 2.438-1.497 0-0.287-0.112-0.653-0.462-0.828-1.37 0.334-2.39-0.143-3.314-2.436 0.701 0.255 1.339 0.35 1.944 0.35 2.326 0 3.936-1.497 3.936-3.312z"></path>
</symbol>
</defs>
</svg>

<div class="container-fluid">

    <div class="row justify-content-end stylish-color shadow navbar-desktop">
        <div class="col-6">
            <ul class="nav nav-tabs nav-justified" id="nav-tab" role="tablist">
                <li class="nav-item">
                    <a class="nav-link easywp-tab active" id="easywp-tab" data-toggle="tab" href="#easywp" role="tab" aria-controls="easywp" aria-selected="true"><svg class="icon-easywp"><use xlink:href="#icon-easywp"></use></svg><span class="name"> EasyWP</span></a>
                </li>
                <li class="nav-item">
                    <a class="nav-link debug-tab" id="debug-tab" data-toggle="tab" href="#debug" role="tab" aria-controls="debug" aria-selected="false"><i class="fas fa-cog fa-fw">&nbsp;</i> Debug</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link files-tab" id="files-tab" data-toggle="tab" href="#files-backups" role="tab" aria-controls="files" aria-selected="false"><i class="fas fa-file-alt fa-fw">&nbsp;</i>Files<span class="hide-backups">&amp;Backups</span></a>
                </li>
                <li class="nav-item">
                    <a class="nav-link database-tab" id="database-tab" data-toggle="tab" href="#database" role="tab" aria-controls="database" aria-selected="false"><i class="fas fa-database fa-fw">&nbsp;</i>Database</a>
                </li>
            </ul>
        </div>
        <div class="col my-auto">
            <button type="button" class="btn btn-nav btn-red float-right ml-5 mr-3" id="btnSelfDestruct"><i class="fas fa-trash fa-fw">&nbsp;</i> Remove File From Server</button>
            <button type="button" class="btn btn-nav unique-color float-right" id="btnAutoLogin"><i class="fas fa-user fa-fw">&nbsp;</i> Log into wp-admin</button>
        </div>
    </div>

    <!--Navbar-->
    <nav class="navbar navbar-dark stylish-color navbar-hamburger">

        <!-- Navbar brand -->
        <span class="navbar-brand" style="font-weight: bold;">EasyWP Debugger</span>

        <!-- Collapse button -->
        <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#menuContent" aria-controls="menuContent" aria-expanded="false" aria-label="Toggle navigation">
            <div class="animated-ham-icon">
                <span></span>
                <span></span>
                <span></span>
            </div>
        </button>

        <!-- Collapsible content -->
        <div class="collapse navbar-collapse" id="menuContent">

            <!-- Links -->
            <ul class="navbar-nav mr-auto nav-tabs" id="nav-tab" role="tablist">
                <li class="nav-item">
                    <!-- span with data-toggle is wrapper because bootstrap doesn't support mupltiple data-toggle on one element. The data-toogles on each menu item is "collapse" and "tab". -->
                    <span data-toggle="collapse" data-target="#menuContent"><a class="nav-link easywp-tab active" id="easywp-tab" data-toggle="tab" href="#easywp" role="tab" aria-controls="easywp" aria-selected="true"><svg class="icon-easywp"><use xlink:href="#icon-easywp"></use></svg><span class="name"> EasyWP</span></a></span>
                </li>
                <li class="nav-item">
                    <span data-toggle="collapse" data-target="#menuContent"><a class="nav-link debug-tab" id="debug-tab" data-toggle="tab" href="#debug" role="tab" aria-controls="debug" aria-selected="false"><i class="fas fa-cog fa-fw">&nbsp;</i> Debug</a></span>
                </li>
                <li class="nav-item">
                    <span data-toggle="collapse" data-target="#menuContent"><a class="nav-link files-tab" id="files-tab" data-toggle="tab" href="#files-backups" role="tab" aria-controls="files" aria-selected="false"><i class="fas fa-file-alt fa-fw">&nbsp;</i>Files&amp;Backups</a></span>
                </li>
                <li class="nav-item">
                    <span data-toggle="collapse" data-target="#menuContent"><a class="nav-link database-tab" id="database-tab" data-toggle="tab" href="#database" role="tab" aria-controls="database" aria-selected="false"><i class="fas fa-database fa-fw">&nbsp;</i>Database</a></span>
                </li>
                <li>
                    <div class="row">
                        <div class="col">
                            <button type="button" class="btn btn-nav unique-color float-left mr-5" id="btnAutoLogin"><i class="fas fa-user fa-fw">&nbsp;</i> Log into wp-admin</button>
                            <button type="button" class="btn btn-nav btn-red float-left" id="btnSelfDestruct"><i class="fas fa-trash fa-fw">&nbsp;</i> Remove File From Server</button>
                        </div>
                    </div>
                </li>
            </ul>
            <!-- Links -->

        </div>
        <!-- Collapsible content -->

    </nav>
    <!--/.Navbar-->

    <div class="row h-100">
        <div class="tab-content col-xl-smaller-6" id="tab-content">
            <div class="tab-pane fade show active" id="easywp" role="tabpanel" aria-labelledby="easywp-tab">
                <div class="row easywp-row text-center">
                    <div class="col">
                        <button type="button" class="btn unique-color" id="btnFlush">Flush Cache</button>
                    </div>
                    <div class="col">
                        <button type="button" class="btn unique-color" id="btnFixFilesystem">Fix Filesystem</button>
                    </div>
                    <div class="col">
                        <button type="button" class="btn unique-color" id="btnFixPlugin">Fix EasyWP Plugin</button>
                    </div>
                </div>
            </div>
            <div class="tab-pane fade" id="debug" role="tabpanel" aria-labelledby="debug-tab">
                <div class="row text-center">
                    <div class="col" style="margin-left: 6px;">
                        <div class="btn-group" role="group" aria-label="Debug Group">
                            <button type="button" class="btn stylish-success" id="btnDebugOn">Enable Debug</button>
                            <button type="button" class="btn stylish-warning" id="btnDebugOff">Disable Debug</button>
                        </div>
                    </div>
                </div>
                <div class="row mt-5 text-center">
                    <div class="col">
                        <button type="button" class="btn unique-color" id="btnReplace">Replace Default Files</button>
                    </div>
                    <div class="col">
                        <button type="button" class="btn unique-color" id="btnActivate">Activate Clean 2019 Theme</button>
                    </div>
                </div>
            </div>
            <div class="tab-pane fade" id="files-backups" role="tabpanel" aria-labelledby="files-tab">
                <div class="row">
                    <div class="col-xl-smaller-12 col-md-11">
                        <form id="extract-form">
                            <div class="form-group input-group mb-0">
                                <div class="input-group-prepend">
                                    <div class="input-group-text input-group-text-info">Extract a ZIP archive</div>
                                </div>
                                <input type="text" class="form-control mr-3" id="zip-file-extract" name="zip-file-extract" placeholder="file.zip" required>

                                <div class="input-group-prepend">
                                    <div class="input-group-text input-group-text-info">To</div>
                                </div>
                                <input type="text" class="form-control mr-3" id="dest-dir" name="dest-dir" placeholder="destination/folder">
                                <span class="input-group-btn">
                                    <button type="submit" class="btn btn-form stylish-color" id="btnExtract">Submit</button>
                                </span>
                            </div>
                            <small id="dest-dir-help" class="form-text text-muted" style="margin-left: 51.5%">Destination directory is the website root directory by default.</small>
                        </form>
                    </div>
                </div>

                <div class="row mt-4" id="archive-form-row">
                    <div class="col-xl-smaller-12 col-md-10">
                        <form id="archive-form">
                            <div class="form-group input-group">
                                <div class="input-group-prepend">
                                    <div class="input-group-text input-group-text-info">Compress</div>
                                </div>
                                <input type="text" class="form-control mr-3" id="folder-archive" name="folder-archive" placeholder="root-directory">

                                <div class="input-group-prepend">
                                    <div class="input-group-text input-group-text-info">To</div>
                                </div>
                                <input type="text" class="form-control mr-3" id="archive-name" name="archive-name" placeholder="">
                                <span class="input-group-btn">
                                    <button type="submit" class="btn btn-form stylish-color" id="btnArchive">Submit</button>
                                </span>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="row mt-4 mb-0 p-col">
                    <form id="view-form">
                        <!-- margins have to be duplicated because otherwise they are overriden by default form-group ones -->
                        <div class="form-group input-group mt-4 mb-0">
                            <div class="input-group-prepend">
                                <div class="input-group-text input-group-text-info">View content of a ZIP archive</div>
                            </div>
                            <input type="text" class="form-control mr-3" id="zip-file-view" name="zip-file-view" placeholder="file.zip" required>
                            <span class="input-group-btn">
                                <button type="submit" class="btn btn-form stylish-color" id="btnView">Submit</button>
                            </span>
                        </div>
                    </form>
                </div>

                <div class="row mt-5 p-col">
                    <form id="delete-form">
                        <div class="form-group input-group">
                            <div class="input-group-prepend">
                                <div class="input-group-text input-group-text-info">Delete folder/file</div>
                            </div>
                            <input type="text" class="form-control mr-3" id="delete-entry" name="delete-file" placeholder="path/to/folder" required>
                            <span class="input-group-btn">
                                <button type="submit" class="btn btn-form stylish-color" id="btnDelete">Submit</button>
                            </span>
                        </div>
                    </form>
                </div>
            </div>
            <div class="tab-pane fade" id="database" role="tabpanel" aria-labelledby="database-tab">
                <div class="row justify-content-center">
                    <div class="col-xs-smaller-12">
                        <div class="btn-group" role="group" aria-label="Adminer Group">
                            <button type="button" class="btn stylish-success" id="btnAdminerOn">Enable Adminer</button>
                            <button type="button" class="btn stylish-color" id="btnAdminerGo" onclick="window.open('wp-admin/adminer-auto.php')">Go To Adminer</button>
                            <button type="button" class="btn stylish-warning" id="btnAdminerOff">Disable Adminer</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-smaller-6 mt-5">
            <div class="row mb-3 d-none" id="progress-row">
                <div class="col-12">
                    <div class="progress" style="height: 23px;" id="progress-container"></div>
                </div>
            </div>
            <div class="row">
                <div class="col-12 panel panel-primary" id="result-panel">
                    <div class="panel-heading">
                        <h3 class="panel-title">Progress Log</h3>
                    </div>
                    <div class="panel-body">
                        <ul class="progress-log list-group scrollbar-secondary border-top border-bottom rounded" id="progress-log">
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="alert alert-success alert-dismissible fade d-none version-notification" id="version-new" role="alert">
  <strong>New version is out!</strong> <br> Check it <a href="https://collab.namecheap.net/display/~artyomperepelitsa/EasyWP+Debugger" class="text-info">here</a>
  <button type="button" class="close" data-dismiss="alert" aria-label="Close">
    <span aria-hidden="true">&times;</span>
  </button>
</div>

<div class="alert alert-danger alert-dismissible fade d-none version-notification" id="version-fail" role="alert">
  <strong>Failed to check new version</strong>
  <button type="button" class="close" data-dismiss="alert" aria-label="Close">
    <span aria-hidden="true">&times;</span>
  </button>
</div>

<?php else: ?>
<body style="text-align: center;">
    <div style="padding-top: 25vh;">
        <form id="login-form" style="display: inline-block;">
            <input type="password" class="form-control mb-0" id="password" name="password" placeholder="Password" style="width: 200px;">
            <small id="password-invalid" class="form-text text-danger d-none"></small>
            <button type="submit" class="btn unique-color mt-3">LOG IN</button>
        </form>
    </div>
<?php endif; ?>

<!-- MDB core JavaScript -->
<script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/mdbootstrap/4.8.8/js/mdb.min.js"></script>

</body>

</html>