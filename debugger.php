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
     * @param int $delay Number of seconds to wait before invalidating the items.
     * @return  bool            Returns TRUE on success or FALSE on failure.
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
     * @param mixed $response
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
    private $patterns = array('/DB_NAME\', \'(.*)\'/',
                              '/DB_USER\', \'(.*)\'/',
                              '/DB_PASSWORD\', \'(.*)\'/',
                              '/DB_HOST\', \'(.*)\'/',
                              '/table_prefix = \'(.*)\'/');
	private $db_details = array();
    public $errors = array();
	public $connected = false;
	private $mysqlConn;
	
    public function __construct() {
        $this->db_details = $this->get_db_login();  // get db details from wp-config.php
        if (!$this->db_details) {  // in case of fail, return empty instance (with $connected = fail)
			return;
		}
        $this->mysqlConn = new mysqli($this->db_details['host'],
									  $this->db_details['user'],
									  $this->db_details['pass'],
									  $this->db_details['name']);
        if ($this->mysqlConn->connect_errno) {
            array_push($this->errors, "Database connection failed: " . $mysqli->connect_error);
        } else {
            $this->connected = true;
        }
	}
	
	public function __destruct() {
		if ($this->connected) {
			$this->mysqlConn->close();
		}
	}
    
    /**
     * [getVarnishDetails gets values necessary to build Varnish purge request]
     * @return [array] [Varnish parameters]
     */
	public function getVarnishDetails() {
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
     * @return [string] [WordPress home URL]
     */
	public function getHomeUrl() {
		
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
     * @return [array] [DB details]
     */
    private function get_db_login() {
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
     * @param  [string] $theme [name of the theme]
     * @return [boolean]        [success of the activation]
     */
	public function activateTheme($theme) {
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
    
    public function __construct () {
        $this->dbConn = new DBconn;
        if ($this->dbConn->errors) {
            $this->errors = array_merge($this->errors, $this->dbConn->errors);
        }
    }
    
    /**
     * [getServiceName returns name of the cluster pod]
     * @return [string] [name of the cluster pod]
     */
    private function getServiceName() {
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
     * @return [array] [hosts to purge Varnish cache from]
     */
    private function collectMultipleReplicas(): array {
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
     * @param  [string] $url [host to purge Varnish cache from]
     * @return [boolean]      [success of the purge request]
     */
    private function purgeUrl ($url) {
        try {
            $parsedUrl = parse_url($url);
			$dbData = $this->dbConn->getVarnishDetails();
			if(!$dbData['schema']) {
				array_push($this->errors, "Failed to fetch data from wp_options.easywp_plugin_slug");
			}
            // get the schema
            $schema = $dbData['schema'] ?: 'http://';
        
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
        		array_push($this->errors, 'Varnish - Curl error: ' . curl_error($ch));
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
    
    public function clearAll() {
		/*
		Purges all Varnish caches of the website and returns an array
		of true/false for each Varnish URL
		*/
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


function flushRedis() {
    $wp_object_cache = new WP_Object_Cache();
    return $wp_object_cache->flush();
}

/**
 * [clearAll clear OPcache, Redis, and Varnish caches]
 * @return [array] [success of purging and errors if any]
 */
function clearAll() {
    $redis_success = flushRedis() ? 1 : 0;

    $varnish_cache = new VarnishCache();
    $varnish_results = $varnish_cache->clearAll();
	// Set to false if any element of array is false, otherwise true
	$varnish_success = in_array(false, $varnish_results, true) ? 0 : 1;

	flushOPcache();

	return array('redis_success' => $redis_success,
	             'varnish_success' => $varnish_success,
	             'errors' => $varnish_cache->errors);
}

/**
 * [wpConfigClear removes display_errors and debug mode if found in wp-config.php]
 * @return [boolean] [success of removing debug from wp-config.php]
 */
function wpConfigClear() {
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
 * @return [boolean] [success of enabling debug]
 */
function wpConfigPut() {
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
 * @param  [string] $src [object to move]
 * @param  [string] $dst [destination folder]
 * @return [null]
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
 * [rrmdir remove folders and files recursively]
 * @param  [string] $dir [directory where files must be removed]
 * @return [null]
 */
function rrmdir($dir)
{
    if (is_dir($dir)) {
        $objects = scandir($dir); 
        foreach ($objects as $object) {
            if ($object != "." && $object != "..") { 
                if (is_dir($dir."/".$object)) {
                    rrmdir($dir."/".$object);
                } else {
                    unlink($dir."/".$object);
                }
            } 
        }
    rmdir($dir); 
    } 
}

/**
 * [extractZipFromUrl uploads an archive, extracts it, and removes the zip file]
 * @param  [string] $url         [URL to download the archive from]
 * @param  [string] $path        [path to put the archive to]
 * @param  [string] $archiveName [name of the archive]
 * @return [boolean]              [success of the extraction]
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
 * @return [boolean] [success of the replacement]
 */
function replaceDefaultFiles()
{
    $url = 'http://wordpress.org/latest.zip';
    $file = 'wordpress.zip';
    if (!extractZipFromUrl($url, './', 'wordpress.zip')) {
		return false;
	}
    
    rmove('wordpress', '.'); // 'wordpress' directory is created after extracting the archive
    rrmdir('wordpress');
    return true;
}

/**
 * [themeExists checks if the theme folder exists in wp-content/themes]
 * @param  [string] $themesPath [path to the themes folder]
 * @param  [string] $themeName  [theme name]
 * @return [boolean]             [theme exists]
 */
function themeExists($themesPath, $themeName) {
	$themes = scandir($themesPath);
	if (in_array($themeName, $themes)) {
		return true;
	} else {
		return false;
	}
}

/**
 * [findLatest2019 gets version number of the latest 2019 theme]
 * @return [string] [version number]
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
 * @return [boo] [description]
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
 * @return [boolean] [success of the activation]
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
 * @return [boolean] [success of the symlink creation]
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
 * @return [boolean] [success of the file creation]
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
 * @param  [string] $dir [path to folder]
 * @return [null]
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
 * [uploadAdminerFiles uploads adminer-auto files to wp-admin]
 * @return [boolean] [success of the upload]
 */
function uploadAdminerFiles()
{
	$file1 = 'wp-admin/adminer-auto.php';  // custom extension file to bypass login form
	$file2 = 'wp-admin/adminer.php';  // default Adminer (MySQL-only & English-only)
	$file3 = 'wp-admin/adminer.css';  // Adminer Material Theme https://github.com/arcs-/Adminer-Material-Theme
	$result1 = file_put_contents($file1, file_get_contents('https://res.cloudinary.com/ewpdebugger/raw/upload/v1562956069/adminer-auto_nk2jck.php'));
	$result2 = file_put_contents($file2, file_get_contents('https://res.cloudinary.com/ewpdebugger/raw/upload/v1559401351/adminer.php'));
	$result3 = file_put_contents($file3, file_get_contents('https://res.cloudinary.com/ewpdebugger/raw/upload/v1559401351/adminer.css'));
	if ($result1 && $result2 && $result3) {
		return true;
	} else {
		unlink($file1);
		unlink($file2);
		unlink($file3);
		return false;
	}
}

/**
 * [unzipArchive extracts a zip archive in chunks. Returns true on completion and last
 *     extracted file if the allowed time is exceeded]
 * @param  [string] $archiveName [path to the zip file]
 * @param  [string] $destDir     [destination directory]
 * @param  [integer] $startNum    [filenumber to start extraction from]
 * @return [boolean|array]              [true on extraction completion; array
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
 * @param  [string] $archiveName [path to zip file]
 * @return [array]              [pathnames of files inside an archive]
 */
function viewArchive($archiveName) {
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


function checkDestDir($destDir)
{
    if (file_exists($destDir)) {
        if (is_writable($destDir)) {
            return true;
        } else {
            return false;
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
 * @param  [string] $archiveName [path to zip file]
 * @return [integer]              [number of files in zip file]
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
 * @param  [string] $archiveName [path to zip file]
 * @return [null]
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
 * @param  [string] $archiveName [path to zip file]
 * @return [null]
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


/*
    !!! POST request processors section !!! 
*/


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

if (authorized()) {
    if (isset($_POST['flush'])) {
    	/* flushes Varnish, Redis, and opcache caches */
        $results = clearAll();
        echo json_encode($results);
        exit;
    }

    if (isset($_POST['debugOn'])) {
    	/* enables errors on-screen */
        $debug_result = wpConfigPut() ? 1 : 0;
    	die(json_encode(array('debug_on_success' => $debug_result)));
    }

    if (isset($_POST['debugOff'])) {
    	/* disables on-screen errors */
        $debug_result = wpConfigClear() ? 1 : 0;
        die(json_encode(array('debug_off_success' => $debug_result)));
    }

    if (isset($_POST['replace'])) {
    	/* replaces WordPress default files (latest version of WordPress) */
    	$result = replaceDefaultFiles() ? 1 : 0;
        echo json_encode(array('replace_success' => $result));
        exit;
    }

    if (isset($_POST['activate'])) {
    	/* uploads latest version of the 2019 theme and activates it */
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

    if (isset($_POST['fixPlugin'])) {
    	/* fixes the EasyWP plugin if its files are not fully present on the website */
        $symLink = createEasyWpSymLink() ? 1 : 0;
        $objectCache = createObjectCache() ? 1 : 0;
        echo json_encode(array('symLink' => $symLink, 'objectCache' => $objectCache));
        exit();
    }

    if (isset($_POST['selfDestruct'])) {
    	/* removes debugger.php and additional files from the server, disables debug */
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

    if (isset($_POST['fixFileSystem'])) {
    	statAllFiles('/var/www/wptbox');
    	echo json_encode(array('success' => 1));
    	exit();
    }

    if (isset($_POST['adminerOn'])) {
    	/* uploads adminer-auto files and sets the cookie to access Adminer */
    	if (uploadAdminerFiles()) {
    		$_SESSION['debugger_adminer'] = true;
    		die(json_encode(array('success' => 1)));
    	} else {
    		die(json_encode(array('success' => 0)));
    	}
    }

    if (isset($_POST['adminerOff'])) {
    	/* removes adminer-auto files and unsets the cookie */
    	unlink('wp-admin/adminer-auto.php');
    	unlink('wp-admin/adminer.php');
    	unlink('wp-admin/adminer.css');
    	unset($_SESSION['debugger_adminer']);
    	die(json_encode(array('success' => 1)));
    }

    if (isset($_POST['checkDestDir'])) {
        $destDir = $_POST['destDir'];
        if (checkDestDir($destDir)) {
            die(json_encode(array('success' => 1)));
        } else {
            die(json_encode(array('success' => 0)));
        }
    }

    if (isset($_POST['archiveName']) && isset($_POST['action'])) {
        $archiveName = $_POST['archiveName'];
        
        if ($_POST['action'] == 'extract') {
            unzipArchivePost($archiveName);
        } elseif ($_POST['action'] == 'view') {
            viewArchivePost($archiveName);
        }
    }

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
}  // end of "if(authorized())"

?>
 
<!DOCTYPE html>
<html lang="en">
    <head>


<!-- *                                      -->
<!-- *    !!! JQuery functions section !!!  -->
<!-- *                                      -->

<script src="https://code.jquery.com/jquery-3.4.0.min.js" integrity="sha256-BJeo0qm959uMBGb65z40ejJYGSgR7REI4+CW1fNKwOg=" crossorigin="anonymous"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/js-cookie@2/src/js.cookie.min.js"></script>

<?php if (authorized()): ?>
<script>

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

</script>
<?php else: ?>
<script>

var printMsg = function(msg) {
    $('#password-invalid').text(msg);
    $('#password-invalid').removeClass('d-none').addClass('show');
};

</script>
<?php endif; ?>

<script>  // the section being loaded regardless of authorized()

// global variables
var defaultDoneText = '<i class="fas fa-check fa-fw"></i> Submit';
var defaultFailText = '<i class="fas fa-times fa-fw"></i> Submit';


var handleEmptyField = function(fieldValue) {
    if (fieldValue === "") {
        printMsg('Please enter the password');
        return true;
    } else {
        return false;
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

</script>
<?php if (authorized()): ?>
<script>

var sendFlushRequest = function() {
    $.ajax({
        type: "POST",
        timeout: 20000,
        data: {flush: 'submit'},
        success: function(response) {
            var jsonData = JSON.parse(response);                
            handleEmptyResponse($('#btnFlush'), jsonData);
            if (jsonData.redis_success == "1") {
                printMsg('Redis Flushed Successfully!', false, 'bg-success-custom');
            } else {
                printMsg('Redis Flush Failed!', false, 'bg-warning-custom');
            }
            if (jsonData.varnish_success == "1") {
                printMsg('Varnish Flushed Successfully!', true, 'bg-success-custom');
            } else {
                printMsg('Varnish Flush Failed!', true, 'bg-warning-custom');
            }
            if (jsonData.errors.length !== 0) {
                jsonData.errors.forEach(function(item, index, array) {
                    printMsg(item, true, 'bg-danger-custom');
                });
            }
        },
        error: function (jqXHR, exception) {  // on error
            handleErrors(jqXHR, exception);
        }
    });
};


var sendDebugOnRequest = function() {
    $.ajax({
        type: "POST",
        timeout: 20000,
        data: {debugOn: 'submit'},
        success: function(response) {
            var jsonData = JSON.parse(response);
            handleEmptyResponse($("#btnDebugOn"), jsonData);
            if (jsonData.debug_on_success == "1") {
                printMsg('Debug Enabled Successfully!', true, 'bg-success-custom');
            } else {
                printMsg('Enabling Debug Failed!', true, 'bg-warning-custom');
            }
            sendFlushRequest();
        },
        error: function (jqXHR, exception) {  // on error
            handleErrors(jqXHR, exception);
        }
    });
};


var sendDebugOffRequest = function() {
    $.ajax({
        type: "POST",
        timeout: 20000,
        data: {debugOff: 'submit'},
        success: function(response) {
            var jsonData = JSON.parse(response);
            handleEmptyResponse($("#btnDebugOff"), jsonData);
            if (jsonData.debug_off_success == "1") {
                printMsg('Debug Disabled Successfully!', true, 'bg-success-custom');
            } else {
                printMsg('Disabling Debug Failed!', true, 'bg-warning-custom');
            }
            sendFlushRequest();
        },
        error: function (jqXHR, exception) {  // on error
            handleErrors(jqXHR, exception);
        }
    });
};


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
            var jsonData = JSON.parse(response);
            handleEmptyResponse($button, jsonData, failText);
            if (jsonData.replace_success == "1") {
                $button.html(doneText);
                printMsg('Files Replaced Successfully!', true, 'bg-success-custom');
            } else {
                $button.html(failText);
                printMsg('Files Replacing Failed!', true, 'bg-warning-custom');
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
            var jsonData = JSON.parse(response);
            handleEmptyResponse($button, jsonData, failText);
            if (jsonData.replace == "1" && jsonData.activate == "1") {
                $button.html(doneText);
            } else {
                $button.html(failText);
            }
            if (jsonData.replace == "1") {
                printMsg('Theme Uploaded Successfully!', false, 'bg-success-custom');
            } else {
                printMsg('Theme Upload Failed!', false, 'bg-warning-custom');
            }
            if (jsonData.activate == "1") {
                printMsg('Theme Activated Successfully!', true, 'bg-success-custom');
            } else {
                printMsg('Theme Activation Failed!', true, 'bg-warning-custom');
            }
            if (jsonData.errors.length !== 0) {
                jsonData.errors.forEach(function(item, index, array) {
                    printMsg(item, true, 'bg-danger-custom');
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


var sendAdminerOnRequest = function() {
    $.ajax({
        type: "POST",
        data: {adminerOn: 'submit'},
        timeout: 40000,
        success: function(response) {
            var jsonData = JSON.parse(response);
            handleEmptyResponse($("#btnAdminerOff"), jsonData);
            if (jsonData.success == "1") {
                printMsg('Adminer Enabled Successfully!', true, 'bg-success-custom');
                // open Adminer in a new tab in 1 second after the success message is shown
                setTimeout(function() { window.open("wp-admin/adminer-auto.php"); }, 1000);
            } else {
                printMsg('Adminer Failed!', true, 'bg-warning-custom');
            }
        },
        error: function (jqXHR, exception) {
            handleErrors(jqXHR, exception);
        }
    });
};


var sendAdminerOffRequest = function() {
    $.ajax({
        type: "POST",
        data: {adminerOff: 'submit'},
        timeout: 40000,
        success: function(response) {
            var jsonData = JSON.parse(response);
            handleEmptyResponse($("btnAdminerOff"), jsonData);
            if (jsonData.success == "1") {
                printMsg('Adminer Disabled Successfully!', true, 'bg-success-custom');
                Cookies.remove('adminer');
            } else {
                printMsg('Adminer Disabling Failed!', true, 'bg-warning-custom');
            }
        },
        error: function (jqXHR, exception) {
            handleErrors(jqXHR, exception);
        }
    });
};


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
            var jsonData = JSON.parse(response);
            handleEmptyResponse($button, jsonData, failText);
            if (jsonData.success == "1") {
                $button.html(doneText);
                $button.prop("disabled", false);
                printMsg('FileSystem Has Been Fixed!', true, 'bg-success-custom');
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


var sendFixPluginRequest = function() {
    $.ajax({
        type: "POST",
        timeout: 20000,
        data: {fixPlugin: 'submit'},
        success: function(response) {
            var jsonData = JSON.parse(response);
            handleEmptyResponse($('#btnFixPlugin'), jsonData);
            if (jsonData.symLink == "1") {
                printMsg('Symlink Created Successfully!', false, 'bg-success-custom');
            } else {
                printMsg('Symlink Creation Failed!', false, 'bg-warning-custom');
            }
            if (jsonData.objectCache == "1") {
                printMsg('object-cache.php Created Successfully!', true, 'bg-success-custom');
            } else {
                printMsg('object-cache.php Creation Failed!', true, 'bg-warning-custom');
            }
            sendFlushRequest();
        },
        error: function (jqXHR, exception) {
            handleErrors(jqXHR, exception);
        }
    });
};


var sendSelfDestructRequest = function() {
    $.ajax({
        type: "POST",
        timeout: 20000,
        data: {selfDestruct: 'submit'},
        success: function(response) {
            var jsonData = JSON.parse(response);
            handleEmptyResponse($("#btnSelfDestruct"), jsonData);
            if (jsonData.success == "1") {
                printMsg('debugger.php Deleted Successfully!', true, 'bg-success-custom');
            }
        },
        error: function (jqXHR, exception) {
            handleErrors(jqXHR, exception);
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
            var jsonData = JSON.parse(response);
            
            handleEmptyResponse($('#btnExtract'), jsonData, defaultFailText);
                
            if (jsonData.success) {  // if success, show the success button and message
                $('#progress-bar').removeClass('progress-bar-striped bg-info progress-bar-animated').addClass('bg-success').text('100%').width('100%');
                $('#btnExtract').prop("disabled", false);
                $('#btnExtract').html(defaultDoneText);
                printMsg('Archive extracted successfully!', true, 'bg-success-custom');
                sendFlushRequest();
            }
            
            // if the extraction didn't complete in one turn, start from the last file
            else if (jsonData.startNum) {
                percentage = (jsonData.startNum/totalNum*100).toFixed() + '%';
                $('#progress-bar').text(percentage).width(percentage);
                // if the starting file was already sent before, skip it and show alert message
                if (startNum == jsonData.startNum) {
                    sendUnzipRequest(archiveName, destDir, maxUnzipTime, totalNum, startNum+1);
                    printMsg("The following file will not be extracted because it's too big: <strong>"+jsonData.failedFile+"</strong>", true, 'bg-warning-custom');
                } else {  // if the extraction didn't complete but another file appeared to be the last one, continue the next iteration from it (of if there were no starting files before)
                    startNum = jsonData.startNum;
                    printMsg('The connection was interrupted on <strong>'+jsonData.failedFile+'</strong>, resuming extraction from it.', true, 'bg-info-custom');
                    sendUnzipRequest(archiveName, destDir, maxUnzipTime, totalNum, startNum);
                }
            }
            
            else {  // if complete fail, show returned error
                $('#progress-bar').removeClass('progress-bar-striped bg-info progress-bar-animated').addClass('bg-danger');
                $('#btnExtract').html(defaultFailText);
                $('#btnExtract').prop("disabled", false);
                printMsg('An error happened upon extracting the backup: <strong>'+jsonData.error+'</strong>', true, 'bg-danger-custom');
            }
        },
        error: function (jqXHR, exception) {
            handleErrors(jqXHR, exception, [0, 503]); // handle errors except for 0 and 503
            if (jqXHR.status == 503 || jqXHR.status === 0) {
                if (maxUnzipTime == 10) {  // if the limit is already 10, nothing will help
                    printMsg('Even requests limited by 10 seconds return 503 or network errors', true, 'bg-danger-custom');
                    $('#btnExtract').html(defaultFailText);
                    $('#btnExtract').prop("disabled", false);
                    $('#progress-bar').removeClass('progress-bar-striped bg-info progress-bar-animated').addClass('bg-danger');
                } else {
                    if (jqXHR.status == 503) {
                        error = '503 Service Unavailable';
                    } else {
                        error = 'Failed To Connect. Network Error';
                    }
                    printMsg('Previous request returned <strong>"'+error+'"</strong>. Decreasing the time limit by 10 seconds and sending the request again.', true, 'bg-warning-custom');
                    maxUnzipTime -= 10;  // if the request returned 503 because overusing a limit, try decreasing the time limit
                    sendUnzipRequest(archiveName, destDir, maxUnzipTime, totalNum, startNum);
                }
            } else {
                $('#progress-bar').removeClass('progress-bar-striped bg-info progress-bar-animated').addClass('bg-danger');
            }
        }
    });
};


var processExtractForm = function(form) {
    // preparations
    form.preventDefault();
    var zipFile = $("#extract-form :input[name='zip-file-extract']")[0].value;
    var destDir = $("#extract-form :input[name='dest-dir']")[0].value;
    if (!destDir) {
        destDir = '.';
    }
    var defaultTimeLimit = 130; // 140-second limit raises 503 frequently on EasyWP in my experience
    var loadingText = '<i class="fas fa-circle-notch fa-spin fa-fw"></i> Extracting...';
    $('#btnExtract').prop("disabled", true);
    $('#btnExtract').html(loadingText);
    printMsg('Starting Extraction. First request sent. The next update is within ' + defaultTimeLimit + ' seconds.', true, 'bg-info-custom');

    var zipIsExtractable = false;
    var dirIsWritable = false;
    var activeAjaxRequests = 2;
    var totalNumber;

    var processExtraction = function(totalNumber) {
        if (zipIsExtractable && dirIsWritable) {
            $("#progress-container").removeClass('d-none').addClass('show').html('<div class="progress-bar progress-bar-striped bg-info progress-bar-animated" id="progress-bar" role="progressbar" style="width: 2%;">1%</div>');  // 1% is poorly visible with width=1%, so the width is 2 from the start
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
        var jsonData = JSON.parse(response);
        
        handleEmptyResponse($('btnExtract'), jsonData, defaultFailText);
            
        if (jsonData.success == "1") {
            zipIsExtractable = true;
            totalNumber = jsonData.number;
        } else {
            printMsg('An error happened upon extracting the backup: <strong>'+jsonData.error+'</strong>', true, 'bg-danger-custom');
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
        var jsonData = JSON.parse(response);
        
        handleEmptyResponse($('btnExtract'), jsonData, defaultFailText);
            
        if (jsonData.success == "1") {
            dirIsWritable = true;
        } else {
            printMsg('An error happened upon extracting the backup: <strong>Destination directory is not writable and we failed to create such directory</strong>', true, 'bg-danger-custom');
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


var processViewForm = function(form) {
    // preparations
    form.preventDefault();
    var loadingText = '<i class="fas fa-circle-notch fa-spin fa-fw"></i> Loading...';
    var archiveName = $("#view-form :input[name='zip-file-view']")[0].value;
    $('#btnView').prop("disabled", true);
    $('#btnView').html(loadingText);

    // send request to get filenames inside zip file
    $.ajax({
        type: "POST",
        data: {action: 'view',
               archiveName: archiveName},
        success: function(response) {  // on success
            var jsonData = JSON.parse(response);
            
            handleEmptyResponse($('#view-form'), jsonData, defaultFailText);
                
            if (jsonData.success == "1") {  // if success, show the success button and message
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
                printMsg('An error happened upon opening the backup: <strong>'+jsonData.error+'</strong>', true, 'bg-danger-custom');
            }
        },
        error: function (jqXHR, exception, message) {  // on error
            handleErrors(jqXHR, exception);
            $('#btnView').html(defaultFailText);
            $('#btnView').prop("disabled", false);
        }
    });
};


$(document).ready(function() {

    $("#btnFlush").click(function() {
        sendFlushRequest();
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
    
    $("#btnActivate").click(function() {
        sendActivateRequest($(this));
    });
    
    $("#btnAdminerOn").click(function() {
        sendAdminerOnRequest();
    });
    
    $("#btnAdminerOff").click(function() {
        sendAdminerOffRequest();
    });

    $("#btnFixFilesystem").click(function() {
        sendFixFilesystemRequest($(this));
    });

    $("#btnFixPlugin").click(function() {
        sendFixPluginRequest();
    });
    
    $("#btnSelfDestruct").click(function() {
        sendSelfDestructRequest();
    });

    $('#extract-form').submit(function(form) {
        processExtractForm(form);
    });
    
    $('#view-form').submit(function(form) {
        processViewForm(form);
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

            if (jsonData.success == "1") {
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


<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css">
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
    
    /*
        fix round borders for forms
     */
    
    .input-group>.form-control:not(:last-child) {
        border-bottom-right-radius: 3px;
        border-top-right-radius: 3px;
    }

    .input-group>.input-group-prepend:not(:first-child)>.input-group-text {
        border-bottom-left-radius: 3px;
        border-top-left-radius: 3px;
    }

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
    <div class="container mt-5">
        <div class="row justify-content-start">
            <div class="col-sm-2">
                <button type="button" class="btn btn-info" id="btnFlush">Flush Cache</button>
            </div>
            <div class="col-sm">
                <div class="btn-group" role="group" aria-label="Debug Group">
                    <button type="button" class="btn btn-success" id="btnDebugOn">Enable Debug</button>
                    <button type="button" class="btn btn-warning" id="btnDebugOff">Disable Debug</button>
                </div>
            </div>
            <div class="col-sm">
                <button type="button" class="btn btn-info" id="btnReplace">Replace Default Files</button>
            </div>
            <div class="col-sm">
                <button type="button" class="btn btn-info" id="btnActivate">Activate Clean 2019 Theme</button>
            </div>
        </div>
        <div class="row justify-content-start mt-4">
            <div class="col-5">
                <div class="btn-group" role="group" aria-label="Adminer Group">
                    <button type="button" class="btn btn-success" id="btnAdminerOn">Enable Adminer</button>
                    <button type="button" class="btn btn-secondary" id="btnAdminerGo" onclick="window.open('wp-admin/adminer-auto.php')">Go To Adminer</button>
                    <button type="button" class="btn btn-warning" id="btnAdminerOff">Disable Adminer</button>
                </div>
            </div>
            <div class="col-2">
                <button type="button" class="btn btn-info" id="btnFixFilesystem">Fix FileSystem</button>
            </div>
            <div class="col-sm">
                <button type="button" class="btn btn-info" id="btnFixPlugin">Fix EasyWP Plugin</button>
            </div>
            <div class="col-sm">
                <button type="button" class="btn btn-danger" id="btnSelfDestruct">Remove File From Server</button>
            </div>
        </div>
        <div class="row justify-content-start mt-4" style="margin-left: 0%;">
            <form id="extract-form">
                <div class="form-group input-group mb-0">

                    <div class="input-group-prepend">
                        <div class="input-group-text input-group-text-info">Extract a ZIP archive</div>
                    </div>
                    <input type="text" class="form-control" id="zip-file-extract" name="zip-file-extract" placeholder="file.zip">

                    <div class="input-group-prepend">
                        <div class="input-group-text input-group-text-info ml-3">To</div>
                    </div>
                    <input type="text" class="form-control" id="dest-dir" name="dest-dir" placeholder="destination/folder">
                    <span class="input-group-btn ml-3">
                        <button type="submit" class="btn btn-secondary" id="btnExtract">Submit</button>
                    </span>
                </div>
                <small id="dest-dir-help" class="form-text text-muted mb-1" style="margin-left: 425px;">Website root directory by default.</small>
            </form>

        </div>
        <div class="row justify-content-start mt-2" style="margin-left: 0%;">
            <form id="view-form">
                <div class="form-group input-group">
                    <div class="input-group-prepend">
                        <div class="input-group-text input-group-text-info">View content of a ZIP archive</div>
                    </div>
                    <input type="text" class="form-control form" id="zip-file-view" name="zip-file-view" placeholder="file.zip">
                    <span class="input-group-btn ml-3">
                        <button type="submit" class="btn btn-secondary" id="btnView">Submit</button>
                    </span>
                </div>
            </form>
        </div>

        <!-- progress bar on the third row -->
        <div class="progress mt-3 d-none" style="height: 23px;" id="progress-container">
        </div>

        <!-- progress log on the fourth row -->
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
<?php else: ?>
<body style="text-align: center;">
    <div style="padding-top: 25vh;">
        <form id="login-form" style="display: inline-block;">
            <input type="password" class="form-control mb-0" id="password" name="password" placeholder="Password" style="width: 200px;">
            <small id="password-invalid" class="form-text text-danger d-none"></small>
            <button type="submit" class="btn btn-primary mt-3">LOG IN</button>
        </form>
    </div>
</body>
<?php endif; ?>

</html>