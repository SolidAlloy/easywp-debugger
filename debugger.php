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

define('VERSION', '2.3.2');

// Change it to a more secure password.
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

// send error reports regarding easywp-cron failures to the following addresses
define('MAIL_RECIPIENT', 'artyom.perepelitsa@namecheap.com, olesya.nikolaeva@namecheap.com');

// find the website root directory if debugger is uploaded to wp-admin
$curDir = dirname(__FILE__);
if (basename($curDir) == 'wp-admin') {
    define('WEB_ROOT', dirname($curDir).'/');
} else {
    define('WEB_ROOT', $curDir.'/');
}


/*
    !!! PHP classes section !!!
*/


/**
 * Modified class from wp-content/object-cache.php.
 * The only original method left is flush().
 * The methods to set, get, and delete keys were added.
 */
class Redis_Object_Cache
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
                    $plugin_dir = WEB_ROOT.'wp-content/mu-plugins';
                    if (file_exists('/var/www/wptbox/wp-content/mu-plugins')
                        && is_link('/var/www/wptbox/wp-content/mu-plugins')) {
                            // pass
                    } else {
                        $target_pointer = WEB_ROOT."../../easywp-plugin/mu-plugins";
                        $link_name = '/var/www/wptbox/wp-content/mu-plugins';
                        symlink($target_pointer, $link_name);
                    }
                    $predis = $plugin_dir . '/wp-nc-easywp/plugin/Http/Redis/includes/predis.php';
                    if (!file_exists($predis)) {
                        $this->redis_connected = false;
                        return;
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

    /**
     * Fetch the value of the key from Redis
     *
     * @param  string $key The key to search for
     * @return mixed       Value assigned to the key or false on failure
     */
    public function fetch($key)
    {
        return $this->redis->get($key);
    }

    /**
     * Store the key and value for TTL seconds
     *
     * @param  string  $key   Key to the value
     * @param  mixed   $value Value to store in the database
     * @param  integer $ttl   Time-to-live in seconds
     * @return bool           True on success
     */
    public function store($key, $value, $ttl)
    {
        return $this->redis->setEx($key, $ttl, $value);
    }

    /**
     * Delete a key from cache
     *
     * @param  string $key Key to delete
     * @return bool        True on success
     */
    public function delete($key)
    {
        return $this->redis->del($key);
    }

    /**
     * Get TTL of a key
     *
     * @param  string $key Key of which to check TTL
     * @return integer     TTL of the key, -1 if no TTL, -2 if no key
     */
    public function ttl($key)
    {
        return $this->redis->ttl($key);
    }

    /**
     * Check if the key exists in cache
     * @param  string $key Key to search for
     * @return bool        true if the key exists
     */
    public function exists($key)
    {
        return $this->redis->exists($key);
    }

    /**
     * Return keys matching the pattern, using '*' as a wildcard
     *
     * @param  string $pattern           String containing a pattern and wildcards
     * @return array of strings          The keys that match a certain pattern
     */
    public function keys($pattern)
    {
        return $this->redis->keys($pattern);
    }
}

/**
 * Factory class that can operate both APCu and Redis caches through a signle interface
 */
class EasyWP_Cache
{
    /**
     * Type of cache the class is managing at the moment
     *
     * @var string
     */
    protected $handler = '';

    /**
     * Redis class instance if redis handler is chosen
     *
     * @var object
     */
    protected $redis;

    /**
     * Chooses the handler and instantiates the Redis_Object_Cache class if Redis is found.
     */
    public function __construct()
    {
        if (extension_loaded('apcu')) {
            $this->handler = 'apcu';
        } else {
            $this->redis = new Redis_Object_Cache();
            if ($this->redis->redis_status()) {
                $this->handler = 'redis';
            } else {
                throw new Exception("APCu and Redis not found");
            }
        }
    }

    /**
     * Returns the current handler
     *
     * @return string Current cache handler. Either "apcu" or "redis"
     */
    public function getHandler()
    {
        return $this->handler;
    }

    /**
     * Fetches the value assigned to the key in the cache
     *
     * @param  mixed $key  Key to search for
     * @return mixed       Value assigned to the key
     */
    public function fetch($key)
    {
        if ($this->handler == 'apcu') {
            return apcu_fetch($key);
        } else {
            return $this->redis->fetch($key);
        }
    }

    /**
     * Saves a key-value sequence in the cache for TTL seconds
     *
     * @param  mixed   $key   Key to store in the cache
     * @param  mixed   $value Value to be assigned to the key
     * @param  integer $ttl   Number in seconds for which the key will be stored in the cache
     * @return bool           Success of setting the key
     */
    public function store($key, $value, $ttl)
    {
        if ($this->handler == 'apcu') {
            return apcu_store($key, $value, $ttl);
        } else {
            return $this->redis->store($key, $value, $ttl);
        }
    }

    /**
     * Deletes the key from cache
     *
     * @param  mixed $key Key to delete from cache
     * @return bool       Success of the deletion
     */
    public function delete($key)
    {
        if ($this->handler == 'apcu') {
            return apcu_delete($key);
        } else {
            return $this->redis->delete($key);
        }
    }

    /**
     * Gets an array of keys based on a pattern, using wildcards
     *
     * @param  string $pattern           String containing a pattern to search for
     * @return array of strings          Keys matching the pattern
     */
    public function keys($pattern)
    {
        if ($this->handler == 'apcu') {
            $pattern = '/'.str_replace('\*', '.*', preg_quote($pattern)).'/';  // transform wildcard pattern into a PCRE regex
            $keysArray = [];
            foreach (new APCUIterator($pattern) as $apcuCache) {
                array_push($keysArray, $apcuCache['key']);
            }
            return $keysArray;
        } else {
            return $this->redis->keys($pattern);
        }
    }

    /**
     * Gets info about the keys that must not be removed during the flush() execution
     *
     * @return  array       Array with cache keys as keys and [value, ttl] as values
     */
    protected function getNeededKeys()
    {
        $keysDict = [];
        // Get an array of all the keys that must not be deleted
        $keys = $this->keys('*~failedTries:*');
        $keys = array_merge($keys, $this->keys('*~blocked:*'));
        $keys = array_merge($keys, $this->keys('*~blocksNumber:*'));
        // Add information about each key from the array and put it into a dictionary
        foreach ($keys as $key) {
            $keysDict[$key] = [
                $this->fetch($key),
                $this->ttl($key),
            ];
        }
        return $keysDict;
    }

    /**
     * Flushes apcu/redis cache except for specific keys
     *
     * @return bool     Success of the flush
     */
    public function flush()
    {
        // gather all keys that must not be removed in a dict with key, value, and ttl of each key
        $keysDict = $this->getNeededKeys();

        if ($this->handler == 'apcu') {
            $result = apcu_clear_cache();
        } else {
            $result = $this->redis->flush();
        }

        foreach($keysDict as $key=>$info) {
            $this->store($key, $info[0], $info[1]);  // set keys again after the flush
        }

        return $result;
    }

    /**
     * Gets TTL of a key
     *
     * @param  string $key Key to check the TTL of.
     * @return integer     TTL of the key, -2 if key doesn't exist.
     */
    public function ttl($key)
    {
        if ($this->handler == 'apcu') {
            $keyInfo = apcu_key_info($key);
            if ($keyInfo) {
                return $keyInfo['creation_time'] + $keyInfo['ttl'] - time();
            } else {
                return -2;
            }
        } else {
            return $this->redis->ttl($key);
        }
    }

    /**
     * Checks if the key exists
     *
     * @param  string $key Key to search for
     * @return bool        True if the key exists
     */
    public function exists($key)
    {
        if ($this->handler == 'apcu') {
            return apcu_exists($key);
        } else {
            return $this->redis->exists($key);
        }
    }
}


/**
 * This class accesses the website database even if the website is down and
 * retrieves values required for flushing Varnish cache, and other values.
 */
class DBconn {
    // Regexes to find DB details in wp-config.php
    protected $patterns = array(
        '/DB_NAME\s*?[\'"]\s*?,\s*?[\'"](.*)[\'"]/',
        '/DB_USER\s*?[\'"]\s*?,\s*?[\'"](.*)[\'"]/',
        '/DB_PASSWORD\s*?[\'"]\s*?,\s*?[\'"](.*)[\'"]/',
        '/DB_HOST\s*?[\'"]\s*?,\s*?[\'"](.*)[\'"]/',
        '/table_prefix\s*?=\s*?[\'"](.*)[\'"]/'
    );
    protected $db_details = array();
    public $errors = array();
    public $connected = false;
    protected $mysqlConn;

    public function __construct()
    {
        $this->db_details = $this->get_db_login();  // get db details from wp-config.php
        if (!$this->db_details) {  // in case of fail, return empty instance (with $connected = false)
            return;
        }
        $this->mysqlConn = new mysqli($this->db_details['host'],
                                      $this->db_details['user'],
                                      $this->db_details['pass'],
                                      $this->db_details['name']);
        if ($this->mysqlConn->connect_errno) {
            array_push($this->errors, "<strong>Database connection failed:</strong> " . $this->mysqlConn->connect_error);
        } elseif ($this->checkConnection()) {
            $this->connected = true;
        } else {
            array_push($this->errors, "<strong>Database connection failed:</strong> The database is corrupted or the prefix in wp-config.php doesn't match.");
        }
    }

    public function __destruct()
    {
        if ($this->connected) {
            $this->mysqlConn->close();
        }
    }

    /**
     * Get values necessary to build Varnish purge request
     *
     * @return array    Varnish parameters
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
     * Replacement for home_url() function needed for the VarnishCache class
     *
     * @return string    WordPress home URL
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
     * Get an array of db login details and db prefix.
     *
     * @return array    DB details
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

        $wp_config = fopen(WEB_ROOT.'wp-config.php', 'r');
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
        if ($db_details['prefix']) {
            return $db_details;
        } else {
            array_push($this->errors, "<strong>Database connection failed:</strong> Table prefix was not found in wp-config.php");
        }
    }

    private function checkConnection()
    {
        $home_query = "SELECT * FROM `" . $this->db_details['prefix'] . "options` WHERE `option_name` LIKE 'home'";
        $result = $this->mysqlConn->query($home_query);
        if ($result) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Set a WordPress theme in the database.
     *
     * @param  string $theme    Name of the theme
     * @return boolean          Success of the activation
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
     * Get website URL from the database.
     *
     * @return string    Website URL
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
     * Get the name of the cluster pod.
     *
     * @return string    Name of the cluster pod
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
     * Get hosts to purge Varnish cache from.
     *
     * @return array    Hosts to purge Varnish cache from
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
     * Send request to a host to purge Varnish cache from it.
     *
     * @param  string  $url      The host to purge Varnish cache from
     * @param  string  $schema   "http://" or "https://"
     * @return boolean           Success of the purge request
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
            array_push($this->errors, $e->getMessage());
            return false;
        }
    }

    /**
     * Purge all Varnish caches of the website and return an array of true/false for each Varnish URL.
     *
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
     * Put the list of files and directories inside certain directory in a TXT files and return the total number of files and directories.
     *
     * @param  string  $directory    Path to the directory where files need to be counted
     * @param  boolean $silent       Do not throw Exception if silent
     * @return integer               Number of files and directories
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
     * Add directories from dirs.txt to the archive.
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
     * Add files from files.txt to the archive until the size limit is reached.
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


/**
 * Send an email from the default server mailbox to a recipient defined at the start of the file.
 *
 * @param  string $emailBody Body of the email
 * @param  string $endpoint  Endpoint of the easywp-cron API
 * @return null
 */
function sendMail($emailBody, $endpoint)
{
    $domain = $_SERVER['HTTP_HOST'];
    $pathToFile = $_SERVER["REQUEST_URI"];
    $subject = 'Debugger: error accessing endpoint /'.$endpoint;
    $emailBody = "Reporter: ".$domain.$pathToFile."\n"."Debugger Version: ".VERSION."\n".$emailBody;
    $headers = "X-Mailer: PHP/".phpversion();
    if (mail(MAIL_RECIPIENT, $subject, $emailBody, $headers)) {
        return true;
    } else {
        return false;
    }
}


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
    $cache = new EasyWP_Cache();
    return $cache->flush();
}

/**
 * Clear OPcache, Redis, and Varnish caches.
 *
 * @return array    Success of purging and errors if any
 */
function clearAll()
{
    if (!file_exists('/var/www/wptbox')) {
        return array('redis_success' => false,
                     'varnish_success' => false,
                     'easywp' => false,
                     'errors' => array("It is not EasyWP, is it?",)
                    );
    }

    $varnish_cache = new VarnishCache();
    $varnish_results = $varnish_cache->clearAll();
    // Set to false if any element of array is false, otherwise true
    $varnish_success = in_array(false, $varnish_results, true) ? false : true;
    $errors = $varnish_cache->errors;

    try {
        $redis_success = flushRedis();
    } catch (Exception $e) {
        $redis_success = false;
        array_push($errors, 'Failed to find Redis. Are you using Debugger on EasyWP? Try fixing the EasyWP plugin.');
    }

    flushOPcache();

    return array('redis_success' => $redis_success,
                 'varnish_success' => $varnish_success,
                 'easywp' => true,
                 'errors' => $errors);
}

/**
 * Remove display_errors and debug mode if found in wp-config.php
 *
 * @return boolean    Success of removing debug from wp-config.php
 */
function wpConfigClear()
{
    $wp_config = WEB_ROOT."wp-config.php";
    if (!is_writable($wp_config) or !is_readable($wp_config)) {
        return false;
    }
    $config = file_get_contents($wp_config);
    $config = str_replace("define('WP_DEBUG', true);\n", '', $config);
    $config = str_replace("define('WP_DEBUG_DISPLAY', true);\n", '', $config);
    $config = str_replace("@ini_set('display_errors', 1);\n", '', $config);
    file_put_contents($wp_config, $config);
    return true;
}

/**
 * Enable debug and display_errors in wp-config.php
 *
 * @return boolean    Success of enabling debug
 */
function wpConfigPut()
{
    $wp_config = WEB_ROOT."wp-config.php";
    if (!is_writable($wp_config) or !is_readable($wp_config)) {
        return false;
    }
    $config = file_get_contents($wp_config);
    $config = preg_replace("/\/\* That's all, stop editing!/i", "define('WP_DEBUG', true);\ndefine('WP_DEBUG_DISPLAY', true);\n@ini_set('display_errors', 1);\n/* That's all, stop editing!", $config);
    file_put_contents ($wp_config, $config);
    return true;
}

/**
 * Move folders and files recursively
 *
 * @param  string $src    Object to move
 * @param  string $dst    Destination folder
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
 * Remove folders and files recursively
 * @param  string $dir              Directory where files must be removed
 * @param  array  $failedRemovals   Array of files and folders that failed to be removed
 * @return array                    Array of files and folders that failed to be removed
 */
function rrmdir($dir, $failedRemovals=[])
{
    if (is_dir($dir)) {
        $objects = scandir($dir);
        foreach ($objects as $object) {
            if ($object != "." && $object != ".." && $object != basename(__FILE__)) {
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
 * Upload an archive, extract it, and remove the zip file.
 *
 * @param  string $url           URL to download the archive from
 * @param  string $path          Path to put the archive to
 * @param  string $archiveName   Name of the archive
 * @return boolean               Success of the extraction
 */
function extractZipFromUrl($url, $path, $archiveName)
{
    $archive = $path.DS.$archiveName;
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
 * Replace default WordPress files with the ones from the latest version.
 *
 * @return boolean    Success of the replacement
 */
function replaceDefaultFiles()
{
    $url = 'http://wordpress.org/latest.zip';
    $file = 'wordpress.zip';
    if (!extractZipFromUrl($url, WEB_ROOT, 'wordpress.zip')) {
        return false;
    }

    rmove(WEB_ROOT.'wordpress', WEB_ROOT);  // 'wordpress' directory is created after extracting the archive
    rrmdir(WEB_ROOT.'wordpress');
    return true;
}

/**
 * Check if the theme folder exists in wp-content/themes.
 *
 * @param  string $themesPath    Path to the themes folder
 * @param  string $themeName     Theme name
 * @return boolean               Theme exists
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
 * Get version number of the latest 2019 theme.
 *
 * @return string    Version number
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
 * Replace files of the 2019 theme or upload files if the folder doesn't exist.
 *
 * @return boolean    Success of the replacement.
 */
function replace2019()
{
    $themesFolderPath = WEB_ROOT.'wp-content/themes/';
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
 * Activate the twentynineteen theme in database.
 * @return boolean    Success of the activation.
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
 * Create the mu-plugins symlink or do nothing if the link already exists.
 *
 * @return boolean    Success of the symlink creation
 */
function createEasyWpSymLink()
{
    $target_pointer = WEB_ROOT."../../easywp-plugin/mu-plugins";
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
 * Create object-cache.php if missing.
 *
 * @return boolean    Success of the file creation.
 */
function createObjectCache()
{
    $filePath = WEB_ROOT.'wp-content/object-cache.php';
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
 * Run stat() on all files/folders in a path.
 * @param  string $dir    Path to folder
 * @return null
 */
function statAllFiles($dir)
{
    $files = scandir($dir);
    foreach($files as $key => $value){
        $path = realpath($dir.DS.$value);
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
 * Upload files from URLs to storage.
 *
 * @return boolean    Success of the upload
 */


/**
 * Upload files from URLs to storage.
 *
 * @param  array $filesAndSources    Dictionary of file=>link pairs
 * @return boolean                   Success of the upload
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
 * Extract a zip archive in chunks. Returns true on completion and last extracted file if the allowed time is exceeded.
 *
 * @param  string   $archiveName   Path to the zip file
 * @param  string   $destDir       Destination directory
 * @param  integer  $startNum      Filenumber to start extraction from
 * @return boolean|array           True on extraction completion or
 *                                     array containing number and name of the failed file on fail
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

        if (substr($name, -1) == '/') {  // if directory
            $dir = $destDir.DS.$name;
            if (is_dir($dir)) {  // if destination directory exists
                // pass
            } elseif (file_exists($dir)) {  // if the destionation entry is not a directory
                unlink($dir);
                mkdir($dir);
            } else {  // if the destination entry doesn't exist
                mkdir($dir);
            }
        } else {  // if file
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
 * Get pathnames of all the files inside an archive.
 *
 * @param  string $archiveName    Path to zip file
 * @return array                  Paths to the files inside the archive
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
 * Check if a directory exists and is writable. If no, create the directory.
 *
 * @param  string $destDir   Path to the destination directory.
 * @return bool              Success if the directory is writable.
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
 * Get number of files and folders inside an archive.
 *
 * @param  string $archiveName  Path to zip file.
 * @return integer              Number of files in zip file.
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
 * Wrapper for unzipArchive that returns its result as json array.
 *
 * @param  string $archiveName    Path to zip file.
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
        // if path is not absolute, prepend it with WEB_ROOT.
        if ($_POST['destDir'][0] == '/') {
            $destDir = $_POST['destDir'];
        } else {
            $destDir = WEB_ROOT.$_POST['destDir'];
        }

        $result = unzipArchive($archiveName, $destDir, $startNum, $_POST['maxUnzipTime']);  // try extracting archive
        if ($result === true) {
            die(json_encode(array('success' => true,
                                  'error' => '',
                                  'startNum' => 0,
                                  'failedFile' => '')));
        } else {
            die(json_encode(array('success' => false,
                                  'error' => '',
                                  'startNum' => $result[0],
                                  'failedFile' => $result[1])));
        }
    } catch (Exception $e) {
        die(json_encode(array('success' => true,
                              'error' => $e->getMessage(),
                              'startNum' => 0,
                              'failedFile' => '')));
    }
}

/**
 * Wrapper for viewArchive that returns its result as json array.
 *
 * @param  string $archiveName    Path to zip file.
 * @return null
 */
function viewArchivePost($archiveName)
{
    try {
        $files = viewArchive($archiveName);
    } catch (Exception $e) {
        die(json_encode(array('success' => false,
                              'files' => [],
                              'error' => $e->getMessage())));
    }
    die(json_encode(array('success' => true,
                          'files' => $files,
                          'error' => '')));
}

/**
 * Check if the archive the user wants to create already exists.
 *
 * @param  string $archiveName    Archive name.
 * @return boolean                True if such a name is free.
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
 * Check if the directory can be compressed and return json with the result.
 *
 * @return string    json-encoded array with the result of pre-check.
 */
function processPreCheckRequest()
{
    try {
        $numberSuccess = true;
        $counter = new FileCounter();

        // if path is not absolute, prepend it with WEB_ROOT.
        if ($_POST['directory'][0] == '/') {
            $directory = $_POST['directory'];
        } else {
            $directory = WEB_ROOT.$_POST['directory'];
        }

        $number = $counter->countFiles($directory);  // try counting files
        $numberError = '';
    } catch (Exception $e) {
        unlink(DIRS);  // remove temporary files in case of fail
        unlink(FILES);
        $numberSuccess = false;
        $number = 0;
        $numberError = $e->getMessage();
    }

    // if path is not absolute, prepend it with WEB_ROOT.
    if ($_POST['archive'][0] == '/') {
        $archive = $_POST['archive'];
    } else {
        $archive = WEB_ROOT.$_POST['archive'];
    }

    if (checkArchive($archive)) {
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
 * Compress the directory using input from the POST form and return a json-encoded array with the result.
 *
 * @return string    json-encoded result.
 */
function processArchiveRequest()
{
    if (isset($_POST['startNum']) && !empty($_POST['startNum'])) {
        $startNum = $_POST['startNum'];
    } else {
        $startNum = 0;
    }

    // if path is not absolute, prepend it with WEB_ROOT.
    if ($_POST['archiveName'][0] == '/') {
        $archiveName = $_POST['archiveName'];
    } else {
        $archiveName = WEB_ROOT.$_POST['archiveName'];
    }

    try {
        $archive = new DirZipArchive($archiveName, $startNum);
    } catch (Exception $e) {
        unlink(DIRS);  // remove temporary files in case of complete fail
        unlink(FILES);
        return json_encode(array('success' => false,
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
        return json_encode(array('success' => false,
                                 'error' => '',
                                 'startNum' => $result,  // return the number of file on which the compression stopped
                                ));
    }
}

/**
 * Retrieve a link to the last version of Debugger from GitHub.
 *
 * @return string    Link to the latest GitHub release of Debugger.
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
 * Check if there is a new version of Debugger on GitHub.
 *
 * @return bool    "true" if the version on GitHub is higher than the local one.
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

/**
 * Return the login URL of the website.
 *
 * @return string        URL of the login page.
 */
function getWpLoginUrl()
{
    $schema = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
    $domain = $_SERVER['HTTP_HOST'];
    return $schema."://".$domain."/wp-login.php";
}

/**
 * Check if the website returns code 200.
 *
 * @param  string $url   URL to check
 * @return bool          True if the URL returns code 200
 */
function websiteIsUp($url)
{
    $timeout = 10;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
    curl_setopt($ch,CURLOPT_CONNECTTIMEOUT,$timeout);

    curl_exec($ch);

    if(curl_errno($ch)) {
        curl_close($ch);
        return false;
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($httpCode == 200) {
        return true;
    } else {
        return false;
    }
}

/**
 * Install a plugin given by the URL.
 *
 * @param  string $url   URL to install the plugin from
 * @return bool          Success of the plugin installation
 */
function installPlugin($url)
{
    $pluginZip = substr($url, strrpos($url, '/') + 1); // get string after the last slash, containing the name of the zip file
    $permFile = WEB_ROOT.'wp-content/plugins/'.$pluginZip;
    $tmpFile = download_url($url, $timeout = 300);
    if (is_wp_error($tmpFile)) {
        return false;
    }
    copy($tmpFile, $permFile);
    unlink($tmpFile);
    WP_Filesystem();
    $unzipFile = unzip_file($permFile, WEB_ROOT.'wp-content/plugins');
    unlink($permFile);
    if (is_wp_error($unzipFile)) {
        return false;
    } else {
        return true;
    }
}

/**
 * Activate a WordPress plugin
 *
 * @param  string $pluginPath   Path-to-plugin-folder/path-to-plugin-main-file
 * @return bool                 Success of the activation
 */
function activatePlugin($pluginPath)
{
    $result = activate_plugin($pluginPath);
    if (is_wp_error($result)) {
        return false;
    } else {
        return true;
    }
}

/**
 * Deactivate a WordPress plugin.
 *
 * @param  string $pluginPath    Path-to-plugin-folder/path-to-plugin-main-file
 * @return bool                  Success of the deactivation
 */
function deactivatePlugin($pluginPath)
{
    $result = deactivate_plugins($pluginPath);
    if (is_wp_error($result)) {
        return false;
    } else {
        return true;
    }
}

/**
 * Remove a WordPress plugin.
 *
 * @param  string $pluginFolder   Name of the plugin folder
 * @return bool                   Success of the removal
 */
function deletePlugin($pluginFolder)
{
    $failedRemovals = rrmdir(WEB_ROOT.'wp-content/plugins/'.$pluginFolder);
    if ($failedRemovals) {
        return false;
    } else {
        return true;
    }
}

/**
 * login the user if the correct password is entered and rate-limit the connection otherwise.
 *
 * @param  string $password Password to log into debugger
 * @return bool             Success of the login
 */
function login($password)
{
    try {
        $cache = new EasyWP_Cache();
    } catch (Exception $e) {
        throw new Exception("Neither Redis nor APCu work on this hosting. Login restricted.");
    }

    if ($cache->getHandler() == 'redis') {
        $_SERVER['REMOTE_ADDR'] = $_SERVER['HTTP_X_FORWARDED_FOR'];
    }

    $failedTriesKey = "{$_SERVER['SERVER_NAME']}~failedTries:{$_SERVER['REMOTE_ADDR']}";
    $blockedKey = "{$_SERVER['SERVER_NAME']}~blocked:{$_SERVER['REMOTE_ADDR']}";
    $blocksNumberKey = "{$_SERVER['SERVER_NAME']}~blocksNumber:{$_SERVER['REMOTE_ADDR']}";

    $blocked = $cache->fetch($blockedKey);
    if ($blocked) {
        throw new Exception("You've exceeded the number of login attempts. We've blocked IP address {$_SERVER['REMOTE_ADDR']} for a few minutes.");
    }

    if (!passwordMatch($password)) {
        $failedTries = (int)$cache->fetch($failedTriesKey) + 1;
        $blocksNumber = (int)$cache->fetch($blocksNumberKey);
        if ($failedTries >= 10) {
            $cache->delete($failedTriesKey);  // reset the failed tries counter
            $cache->store($blockedKey, true, pow(2, $blocksNumber+1)*60);  // activate block for 2^(x+1) minutes: 2, 4, 8, 16 ...
            $cache->store($blocksNumberKey, $blocksNumber+1, 86400);  // store the total number of blocks for 24 hours
        } else {
            $cache->store($failedTriesKey, $failedTries, 1200);  // save failed tries + 1 to the key for 20 minutes
        }
        return false;
    } else {
        $cache->delete($failedTriesKey);
        $cache->delete($blocksNumberKey);
        return true;
    }
}


/**
 * Installs and activates the UsageDD plugin
 *
 * @return array        Success of enabling and error if there was one
 */
function usageEnable()
{
    require_once(WEB_ROOT.'wp-blog-header.php');
    require_once(WEB_ROOT.'wp-admin/includes/file.php');
    require_once(WEB_ROOT.'wp-admin/includes/plugin.php');
    if (installPlugin('https://downloads.wordpress.org/plugin/usagedd.zip')) {
        if (activatePlugin('usagedd/usagedd.php')) {
            $success = true;
            $error = '';
        } else {
            $success = false;
            $error = 'pluginActivation';
        }
    } else {
        $success = false;
        $error = 'pluginInstallation';
    }

    http_response_code(200);  // for some reason, it returns 404 by default after the core WP files inclusion

    return [
        'success' => $success,
        'error' => $error,
    ];
}

/**
 * Disables and removes the UsageDD plugin
 *
 * @return array       Success of enabling and error if there was one
 */
function usageDisable()
{
    require_once(WEB_ROOT.'wp-blog-header.php');
    require_once(WEB_ROOT.'wp-admin/includes/file.php');
    require_once(WEB_ROOT.'wp-admin/includes/plugin.php');
    if (deactivatePlugin('usagedd/usagedd.php')) {
        if (deletePlugin('usagedd')) {
            $success = true;
            $error = '';
        } else {
            $success = false;
            $error = 'pluginDeletion';
        }
    } else {
        $success == false;
        $error = 'pluginDeactivation';
    }

    http_response_code(200);  // for some reason, it returns 404 by default after the core WP files inclusion

    return [
        'success' => $success,
        'error' => $error,
    ];
}

/**
 * Removes all the additional files and itself
 *
 * @return null
 */
function selfDestruct()
{
    session_destroy();
    $files = array(WEB_ROOT.'wp-admin/adminer-auto.php',
                   WEB_ROOT.'wp-admin/adminer.php',
                   WEB_ROOT.'wp-admin/adminer.css',
                    __FILE__);
    foreach($files as $file) {
        if (file_exists($file)) {
            unlink($file);
        }
    }

    // disable debug, remove UsageDD, and clear cache silently because if it fails, nothing else can be done anyway
    wpConfigClear();
    usageDisable();
    clearAll();
}

// if Debugger is not removed by API in 2 hours for some reason, next time someone will access it, it will be removed automatically. The statement is here, before POST requests section, so that the file is removed before Debugger responds to a request.
if (time() - filemtime(__FILE__) > 9000) {
    selfDestruct();
    die(1);
}


/*
    !!! POST request processors section !!!
*/



/* creates session and print success if the password matches */
if (isset($_POST['login'])) {
    try {
        $success = login($_POST['password']);
        $error = '';
    } catch (Exception $exception) {
        $success = false;
        $error = $exception->getMessage();
    }
    if ($success) {
        $_SESSION['debugger'] = true;
    }
    die(json_encode(array(
        'success' => $success,
        'error' => $error,
    )));
}

if (isset($_POST['cronReport'])) {
    die(json_encode(array(
        'status' => sendMail($_POST['message'], $_POST['endpoint']),
    )));
}

/* removes debugger.php and additional files from the server, disables debug. Doesn't require login */
if (isset($_POST['selfDestruct']) || isset($_GET['selfDestruct'])) {
    selfDestruct();
    die(json_encode(array('success' => true)));
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
        $debug_result = wpConfigPut();
        die(json_encode(array('debug_on_success' => $debug_result)));
    }

    /* disables on-screen errors */
    if (isset($_POST['debugOff'])) {
        $debug_result = wpConfigClear();
        die(json_encode(array('debug_off_success' => $debug_result)));
    }

    /* replaces WordPress default files (latest version of WordPress) */
    if (isset($_POST['replace'])) {
        $result = replaceDefaultFiles();
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
        $symLink = createEasyWpSymLink();
        $objectCache = createObjectCache();
        echo json_encode(array('symLink' => $symLink, 'objectCache' => $objectCache));
        exit();
    }

    /* fix filesystem not being able to find some files by running stat() on all files */
    if (isset($_POST['fixFileSystem'])) {
        statAllFiles('/var/www/wptbox');
        echo json_encode(array('success' => true));
        exit();
    }

    /* uploads adminer-auto files and sets a session to access Adminer */
    if (isset($_POST['adminerOn'])) {
        $adminerFilesAndSources = array(
            WEB_ROOT.'wp-admin/adminer-auto.php' => 'https://res.cloudinary.com/ewpdebugger/raw/upload/v1562956069/adminer-auto_nk2jck.php' ,
            WEB_ROOT.'wp-admin/adminer.php'      => 'https://res.cloudinary.com/ewpdebugger/raw/upload/v1559401351/adminer.php' ,
            WEB_ROOT.'wp-admin/adminer.css'      => 'https://res.cloudinary.com/ewpdebugger/raw/upload/v1559401351/adminer.css' ,
        );
        if (uploadFiles($adminerFilesAndSources)) {
            $_SESSION['debugger_adminer'] = true;
            die(json_encode(array('success' => true)));
        } else {
            die(json_encode(array('success' => false)));
        }
    }

    /* removes adminer-auto files and unsets the session */
    if (isset($_POST['adminerOff'])) {
        unlink(WEB_ROOT.'wp-admin/adminer-auto.php');
        unlink(WEB_ROOT.'wp-admin/adminer.php');
        unlink(WEB_ROOT.'wp-admin/adminer.css');
        unset($_SESSION['debugger_adminer']);
        die(json_encode(array('success' => true)));
    }

    /* prints success=true if the destination directory of extraction exists or has been successfully created */
    if (isset($_POST['checkDestDir'])) {
        // if path is not absolute, prepend it with WEB_ROOT.
        if ($_POST['destDir'][0] == '/') {
            $destDir = $_POST['destDir'];
        } else {
            $destDir = WEB_ROOT.$_POST['destDir'];
        }

        if (checkDestDir($destDir)) {
            die(json_encode(array('success' => true)));
        } else {
            die(json_encode(array('success' => false)));
        }
    }

    /* if something needs to be done with an archive */
    if (isset($_POST['archiveName']) && isset($_POST['action'])) {
        // if path is not absolute, prepend it with WEB_ROOT.
        if ($_POST['archiveName'][0] == '/') {
            $archiveName = $_POST['archiveName'];
        } else {
            $archiveName = WEB_ROOT.$_POST['archiveName'];
        }


        if ($_POST['action'] == 'extract') {  // extract archive
            unzipArchivePost($archiveName);
        } elseif ($_POST['action'] == 'view') {  // show content of archive
            viewArchivePost($archiveName);
        }
    }

    /* counts files and directories in a directory and returns their total number */
    if (isset($_POST['filesNumber'])) {
        // if path is not absolute, prepend it with WEB_ROOT.
        if ($_POST['filesNumber'][0] == '/') {
            $archiveName = $_POST['filesNumber'];
        } else {
            $archiveName = WEB_ROOT.$_POST['filesNumber'];
        }

        try {
            $number = countFiles($archiveName);
        } catch (Exception $e) {
            die(json_encode(array('success' => true,
                                  'number' => 0,
                                  'error' => $e->getMessage())));
        }
        die(json_encode(array('success' => true,
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
            $autoLoginFilesAndSources = array(
                WEB_ROOT.'wp-admin/wp-admin-auto.php' => 'https://res.cloudinary.com/ewpdebugger/raw/upload/v1574073049/wp-admin-auto_ahlm25.php',
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
        if (unlink(WEB_ROOT.'wp-admin/wp-admin-auto.php')) {
            die(json_encode(array('success' => true)));
        } else {
            die(json_encode(array('success' => false)));
        }
    }

    /* deletes a file or folder from the hosting storage */
    if (isset($_POST['deleteEntry'])) {
        // if path is not absolute, prepend it with WEB_ROOT.
        if ($_POST['entry'][0] == '/') {
            $entry = $_POST['entry'];
        } else {
            $entry = WEB_ROOT.$_POST['entry'];
        }

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

    /* returns the name of the pod */
    if (isset($_POST['getPodName'])) {
        $podName = getenv('HOSTNAME') ?? '';
        die(json_encode(array('podName' => $podName)));
    }

    /* installs and activates the UsageDD plugin */
    if (isset($_POST['usageEnable'])) {
        $result = usageEnable();
        die(json_encode($result));
    }

    /* deactivates and removes the UsageDD plugin */
    if (isset($_POST['usageDisable'])) {
        $result = usageDisable();
        die(json_encode($result));
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
        msg = 'Network Error. Please try again.';
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


var sendCronReport = function(message, endpoint) {
    $.ajax({
        timeout: 10000,
        type: "POST",
        data: {
            cronReport: 'submit',
            message: message,
            endpoint: endpoint,
        }
    })
    .done(function(response) {
        console.log('Cron Report request was successful.');
    })
    .fail(function( jqXHR, exception ) {
        console.log('Cron Report request failed.');
    })
    .always(function() {
        cronDone = true;  // let the login processor know CronAPI is done
        if (loginSuccess) {  // if login was successful
            location.reload(true);
        }
    });
};

var cronDone = false;
var loginSuccess = false;


var sendCronRequest = function(endpoint) {
    var url;
    var postData;
    var method;
    var msg = '';

    if (endpoint == 'create') {
        url = 'https://cron.nctool.me/create';
        method = 'POST';
        postData = {
            'domain': window.location.hostname,
            'path': window.location.pathname,
        };
    } else if (endpoint == 'delete') {
        url = 'https://cron.nctool.me/delete/'+window.location.hostname;
        method = 'DELETE';
        postData = {
            'path': window.location.pathname,
        };
    } else {
        throw "Unknown endpoint for sendCronRequest()";
    }

    $.ajax({
        timeout: 15000,
        url: url,
        type: method,
        data: postData,
    })
    .done(function(jsonData) {
        if ($.trim(jsonData)) {
            if (jsonData.success == true || jsonData.message == 'Job is already created.') {
                // everything is ok, nothing to report
            } else {
                msg = 'Request to /'+endpoint+' failed.\nReason: '+jsonData.message+'\n';
            }
        } else {
            msg = 'Status code was 200 but no output was returned when trying to access /'+endpoint;
        }
        if (msg) {
            sendCronReport(msg, endpoint);
        } else {
            cronDone = true;  // let the login processor know CronAPI received a response
            if (loginSuccess) {  // if login was successful
                location.reload(true);
            }
        }
    })
    .fail(function(jqXHR, exception) {
        if (jqXHR.status === 0) {
            errMsg = 'Failed To Connect. Network Error.';
        } else if (jqXHR.status == 503) {
            errMsg = 'Service Unavailable. [503]';
        } else if (jqXHR.status == 404) {
            errMsg = 'Requested page not found. [404]';
        } else if (jqXHR.status == 500) {
            errMsg = 'Internal Server Error [500].';
        } else if (exception === 'parsererror') {
            errMsg = 'Requested JSON parse failed.';
        } else if (exception === 'timeout') {
            errMsg = 'Time out error.';
        } else if (exception === 'abort') {
            errMsg = 'Ajax request aborted.';
        } else {
            errMsg = 'Uncaught Error.\n' + jqXHR.responseText;
        }
        msg = 'Error when trying to access /'+endpoint+'\nError: '+errMsg+'\n';
        sendCronReport(msg, endpoint);
    });
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
                if (!jsonData.varnish_success && jsonData.errors.length !== 0) {
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
            if (jsonData.replace && jsonData.activate) {
                $button.html(doneText);
            } else {
                $button.html(failText);
            }
            if (jsonData.replace) {
                printMsg('Theme Uploaded Successfully!', false, 'success-progress');
            } else {
                printMsg('Theme Upload Failed!', false, 'warning-progress');
            }
            if (jsonData.activate) {
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
                if (window.location.pathname.includes('wp-admin')) {
                    setTimeout(function() { window.open("adminer-auto.php"); }, 1000);
                } else {
                    setTimeout(function() { window.open("wp-admin/adminer-auto.php"); }, 1000);
                }
            } else {
                printMsg('Adminer Failed!', true, 'warning-progress');
            }
        },
        error: function (jqXHR, exception) {
            handleErrors(jqXHR, exception);
        }
    });
};


var openAdminer = function() {
    if (window.location.pathname.includes('wp-admin')) {
        window.open("adminer-auto.php");
    } else {
        window.open("wp-admin/adminer-auto.php");
    }
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
            handleEmptyResponse($(".btnAutoLogin"), jsonData);
            if (jsonData.success) {
                printMsg('You will be redirected in a second.', true, 'success-progress');
                // open wp-admin-auto in a new tab in 1 second after the success message is shown
                setTimeout(function() { window.open(jsonData.siteurl+"/wp-admin/wp-admin-auto.php"); }, 1000);
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
        beforeSend: function() {
            sendCronRequest('delete');
            printMsg('debugger.php Deleted Successfully!', true, 'success-progress');
        },
        success: function(response) {
            var jsonData;
            try {
                jsonData = JSON.parse(response);
            } catch (e) {
                printMsg('The returned value is not JSON', true, 'danger-progress');
                return;
            }
            handleEmptyResponse($(".btnSelfDestruct"), jsonData);
        },
        error: function (jqXHR, exception) {
            handleErrors(jqXHR, exception);
        }
    });
};

/**
 * Gets the pod name from the server and redirects to grafana
 * @return null
 */
var sendSubResourcesRequest = function() {
    $.ajax({
        type: "POST",
        timeout: 20000,
        data: {getPodName: 'submit'},
        success: function(response) {
            var jsonData;
            try {
                jsonData = JSON.parse(response);
            } catch (e) {
                printMsg('The returned value is not JSON', true, 'danger-progress');
                return;
            }
            handleEmptyResponse($("#btnSubResources"), jsonData);
            if (jsonData.podName) {
                printMsg('You will be redirected in a second.', true, 'success-progress');
                setTimeout(function() { window.open("https://grafana.namecheapcloud.net/d/gr00yHhWk/pods?orgId=1&var-namespace=default&var-pod="+jsonData.podName); }, 1000);
            } else {
                printMsg('Fail. The the pod name was not found.', true, 'warning-progress');
            }
        },
        error: function (jqXHR, exception) {
            handleErrors(jqXHR, exception);
        }
    });
};


var checkWpAdmin = function(func, $button, idleText) {
    var url = window.location.protocol + '//' + window.location.hostname + '/wp-admin/';
    $.ajax({
        timeout: 10000,
        url: url,
        type: 'GET',
    })
    .done(function(htmlPage) {
        if ($.trim(htmlPage)) {
            if ( htmlPage.includes('<title>Log In') || htmlPage.includes('<title>Dashboard') ) {
                func($button, idleText);  // If website is up, it is safe to send the request.
                return;
            } else {
                msg = url + " was loaded successfully but it doesn't look like a wp-admin login page.";
            }
        } else {
            msg = url + ' gave no output';
        }
        printMsg('The website is down. Please fix it first.', false, 'warning-progress');
        printMsg(msg, true, 'danger-progress');
        $button.prop("disabled", false);
        $button.html(idleText);
    })
    .fail(function(jqXHR, exception) {
        if (jqXHR.status === 0) {
            errMsg = 'Failed To Connect. Network Error.';
        } else if (jqXHR.status == 503) {
            errMsg = 'Service Unavailable. [503]';
        } else if (jqXHR.status == 404) {
            errMsg = 'Requested page not found. [404]';
        } else if (jqXHR.status == 500) {
            errMsg = 'Internal Server Error [500].';
        } else if (exception === 'timeout') {
            errMsg = 'Time out error.';
        } else if (exception === 'abort') {
            errMsg = 'Ajax request aborted.';
        } else {
            errMsg = 'Uncaught Error.\n' + jqXHR.responseText;
        }
        msg = url + ' returned "' + errMsg + '"';
        printMsg('The website is down. Please fix it first.', false, 'warning-progress');
        printMsg(msg, true, 'danger-progress');
        $button.prop("disabled", false);
        $button.html(idleText);
    });
};


/**
 * Sends a request to upload and activate the UsageDD plugin
 *
 * @return {null}
 */
var sendUsageEnableRequest = function($button, idleText) {
    $.ajax({
        type: "POST",
        timeout: 20000,
        data: {usageEnable: 'submit'},
        success: function(response) {
            $button.prop("disabled", false);
            $button.html(idleText);

            var jsonData;
            try {
                jsonData = JSON.parse(response);
            } catch (e) {
                printMsg('The returned value is not JSON', true, 'danger-progress');

                return;
            }
            handleEmptyResponse($("#btnUsageOn"), jsonData);
            if (jsonData.success) {
                printMsg('The plugin has been installed. You can log into wp-admin now and check the resource usage of the website. Check this page to find out about what the numbers mean <a href="https://wordpress.org/plugins/usagedd/">https://wordpress.org/plugins/usagedd/</a>', true, 'success-progress');
            } else if (jsonData.error == 'pluginInstallation') {
                printMsg('The plugin installation failed. Please try installing <a href="https://wordpress.org/plugins/usagedd/">UsageDD</a> manually.', true, 'warning-progress');
            } else if (jsonData.error == 'pluginActivation') {
                printMsg("The plugin was installed but couldn't be activated. Please try activating it manually.", true, 'warning-progress');
            }
            sendFlushRequest();
        },
        error: function (jqXHR, exception) {
            handleErrors(jqXHR, exception);
            $button.prop("disabled", false);
            $button.html(idleText);
        }
    });
};


var enableUsage = function($button) {
    var loadingText = '<i class="fas fa-circle-notch fa-spin fa-fw"></i> Enabling...';
    var idleText = 'Enable UsageDD';
    $button.prop("disabled", true);
    $button.html(loadingText);
    checkWpAdmin(sendUsageEnableRequest, $button, idleText);
};


/**
 * Sends a request to deactivate and remove the UsageDD plugin
 *
 * @return {null}
 */
var sendUsageDisableRequest = function($button, idleText) {
    $.ajax({
        type: "POST",
        timeout: 20000,
        data: {usageDisable: 'submit'},
        success: function(response) {
            $button.prop("disabled", false);
            $button.html(idleText);

            var jsonData;
            try {
                jsonData = JSON.parse(response);
            } catch (e) {
                printMsg('The returned value is not JSON', true, 'danger-progress');
                return;
            }
            handleEmptyResponse($("#btnUsageOff"), jsonData);
            if (jsonData.success) {
                printMsg('The plugin has been disabled and removed.', true, 'success-progress');
            } else if (jsonData.error == 'pluginDeactivation') {
                printMsg("The plugin deactivation failed. Please try deactivating and removing it manually.", true, 'warning-progress');
            } else if (jsonData.error == 'pluginDeletion') {
                printMsg("The plugin was deactivated but couldn't be removed. Please try removing it manually.", true, 'warning-progress');
            }
            sendFlushRequest();
        },
        error: function (jqXHR, exception) {
            handleErrors(jqXHR, exception);
            $button.prop("disabled", false);
            $button.html(idleText);
        }
    });
};


var disableUsage = function($button) {
    var loadingText = '<i class="fas fa-circle-notch fa-spin fa-fw"></i> Disabling...';
    var idleText = 'Disable UsageDD';
    $button.prop("disabled", true);
    $button.html(loadingText);
    checkWpAdmin(sendUsageDisableRequest, $button, idleText);
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
        data: {deleteEntry: 'submit',
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

    $(".btnAutoLogin").click(function() {
        sendAutoLoginRequest();
    });

    $(".btnSelfDestruct").click(function() {
        sendSelfDestructRequest();
    });

    $("#btnSubResources").click(function() {
        sendSubResourcesRequest();
    });

    $('#btnUsageOn').click(function() {
        enableUsage($(this));
    });

    $('#btnUsageOff').click(function() {
        disableUsage($(this));
    });

});

</script>
<?php else: ?>
<script>

var processLoginform = function(form) {
    var loadingText = '<i class="fas fa-circle-notch fa-spin fa-fw"></i> Logging in...';
    var idleText = 'LOG IN';
    $button = $('#btnLogin');
    form.preventDefault();
    $button.prop("disabled", true);
    $button.html(loadingText);

    cronDone = false;  // if the first login attempt failed but CronAPI ran successfully, cronDone will be true, so it is necessary to reset it before calling CronAPI again
    sendCronRequest('create');

    $('#password-invalid').removeClass('show').addClass('d-none');
    var password = $("#password").val();
    if (handleEmptyField(password)) {
        $button.prop("disabled", false);
        $button.html(idleText);
        return;
    }
    $.ajax({
        type: "POST",
        data: {login: 'submit',
               password: password,
        },
        timeout: 40000,
        success: function(response) {
            var jsonData = JSON.parse(response);

            handleEmptyResponse($(''), jsonData);

            if (jsonData.success) {
                loginSuccess = true;  // let cronAPI know the login was successful
                if (cronDone) {  // if debugger received response from CronAPI
                    location.reload(true);
                }
            } else if (jsonData.error) {
                $button.prop("disabled", false);
                $button.html(idleText);
                printMsg(jsonData.error);
            } else {
                $button.prop("disabled", false);
                $button.html(idleText);
                printMsg('Invalid password');
            }
        },
        error: function (jqXHR, exception) {
            handleErrors(jqXHR, exception);
            $button.prop("disabled", false);
            $button.html(idleText);
        }
    });
};

$(document).ready(function() {
    $("#password").focus();  // Set focus on the password field

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
                    <a class="nav-link easywp-tab active" id="easywp-tab" data-toggle="tab" href="#easywp" role="tab" aria-controls="easywp" aria-selected="true"><svg class="icon-easywp"><use xlink:href="#icon-easywp"></use></svg><span class="name">&nbsp;EasyWP</span></a>
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
            <button type="button" class="btn btn-nav btn-red float-right ml-5 mr-3 btnSelfDestruct" id="btnSelfDestruct"><i class="fas fa-trash fa-fw">&nbsp;</i> Remove File From Server</button>
            <button type="button" class="btn btn-nav unique-color float-right btnAutoLogin" id="btnAutoLogin"><i class="fas fa-user fa-fw">&nbsp;</i> Log into wp-admin</button>
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
                            <button type="button" class="btn btn-nav unique-color float-left mr-5 btnAutoLogin" id="btnAutoLogin"><i class="fas fa-user fa-fw">&nbsp;</i> Log into wp-admin</button>
                            <button type="button" class="btn btn-nav btn-red float-left btnSelfDestruct" id="btnSelfDestruct"><i class="fas fa-trash fa-fw">&nbsp;</i> Remove File From Server</button>
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
                <div class="row text-center">
                    <div class="col-sm-4">
                        <button type="button" class="btn unique-color" id="btnFlush">Flush Cache</button>
                    </div>
                    <div class="col-sm-4">
                        <button type="button" class="btn unique-color" id="btnFixFilesystem">Fix Filesystem</button>
                    </div>
                    <div class="col-sm-4">
                        <button type="button" class="btn unique-color" id="btnFixPlugin">Fix EasyWP Plugin</button>
                    </div>
                </div>
                <div class="row mt-5 text-center">
                    <div class="col-sm-5">
                        <button type="button" class="btn stylish-color" id="btnSubResources">Subscription Resources</button>
                    </div>
                    <div class="col-sm-7">
                        <div class="btn-group" role="group" aria-label="Usage Group">
                            <button type="button" class="btn stylish-success" id="btnUsageOn">Enable UsageDD</button>
                            <button type="button" class="btn stylish-warning" id="btnUsageOff">Disable UsageDD</button>
                        </div>
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
                            <button type="button" class="btn stylish-color" id="btnAdminerGo" onclick="openAdminer()">Go To Adminer</button>
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
  <strong>New version is out!</strong> <br> Download it <a href="https://debugger.nctool.me/debugger-generator.php" class="text-info">here</a>
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
            <input type="password" class="form-control mb-0 mx-auto" id="password" name="password" placeholder="Password" style="width: 200px;">
            <small id="password-invalid" class="form-text text-danger d-none"></small>
            <button type="submit" class="btn unique-color mt-3" id="btnLogin">LOG IN</button>
        </form>
    </div>
<?php endif; ?>

<!-- MDB core JavaScript -->
<script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/mdbootstrap/4.8.8/js/mdb.min.js"></script>

</body>

</html>