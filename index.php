<?php
if (!function_exists('error_get_last_message')) { function error_get_last_message() { $error = error_get_last(); return $error['message']; }}
if (!function_exists('is_dn')) { function is_dn($dn) {
    if (!is_string($dn) || (false==($parts = preg_split('/(?<!\\\\),/', $dn)))) return false;
    foreach($parts as $part) if (!preg_match('#^\w+=([^,+"\\\\<>;=/]|\\\\[,+"\\\\<>;=/])+$#',$part)) return false;
    return true;
}}

/**
 * LDAPaaS - LDAP as a Service
 * 
 * This compact implementation allows simple orchestration of LDAP instances   
 * 
 * @author MrTrick
 * @copyright 2015 MrTrick
 * @license MIT
 * @version 0.0.1
 * @url http://github.com/mrtrick/ldapaas
 * @dependency ZendFramework 1.12
 */
class LDAPaaS {
    const VERSION = '0.0.1';
    
    /** @var Zend_Log */
    protected $log = null;
    /** @var Zend_Config */
    protected $config;
    /** @var string */
    protected $path;
    
    //------------------------------------------------------------------------------
    // Constructor
    //------------------------------------------------------------------------------
    
    public function __construct() {
        //Configuration
        require_once('Zend/Config/Ini.php');
        $config = new Zend_Config_Ini('defaults.ini', null, true);
        $config->merge(new Zend_Config_Ini('config.ini', null, false));
        $config->setReadOnly();
        $this->config = $config;
        
        //Sanity check; that all required configuration items have been defined
        $_conf = $config->toArray();
        $_check = function($v, $k) {if ($v === "REQUIRED") throw new UnexpectedValueException("Must override config item \"$k\"", 500);};
        array_walk_recursive($_conf, $_check);
        
        //Logging
        require_once('Zend/Log.php');
        $log = Zend_Log::factory($config->log);
        
        //Check access to the LDAP instance directory
        if (!is_writable($config->ldap->path)) throw new RuntimeException("Not enough access to LDAP instance directory", 500);
        $this->path = realpath($config->ldap->path);
    }

    //------------------------------------------------------------------------------
    // Routes
    //------------------------------------------------------------------
    
    protected static $routes = array(
        'GET /'                 => 'routeIndex',
        'DELETE /'              => 'routeReset',
        'PUT /'                 => 'routeCreate',
        'GET /(?P<name>\w+)'    => 'routeRead',
        'DELETE /(?P<name>\w+)' => 'routeDelete',
        'POST /(?P<name>\w+)/restart'  => 'routeRestart',
        'POST /(?P<name>\w+)/purge' => 'routePurge'
    );
    
    public function route(Zend_Controller_Request_Http $request) {        
        //Require a user for all routes
        $user = $request->getServer('REMOTE_USER', $request->getServer('PHP_AUTH_USER'));
        if (!$user or !preg_match("/^\\w+$/", $user)) throw new InvalidArgumentException("Invalid user", 403);
        $request->setParam('user', $user);
        
        //Allow the method determination to be overridden by a 'method' parameter
        $method = $request->getParam('method', $request->getMethod());
        
        //Find a route handler for the method and path
        $route = $method.' /'.trim($request->getPathInfo(), '/');
        foreach(self::$routes as $pattern=>$method) {
            if (preg_match('#^'.$pattern.'$#', $route, $params)) {
                //Push any parameters into the request
                $request->setParams($params);
                
                //And run it
                return $this->$method($request);
            }
        }
        //No route matched? 
        throw new InvalidArgumentException("Route not found", 404);
    }
    
    protected function routeCreate(Zend_Controller_Request_Http $request) {
        $user = $request->getParam('user');         //Authenticated user
        $port = $this->getNextPort();               //Next available port
        $host = $this->config->ldap->host ? $this->config->ldap->host : gethostname();                      
                                                    //The LDAP server, which due to proxies etc may be different to the HTTP server
        $name = $user.$port;                        //Create name as userPORT
        $base_dn = $request->get('base_dn');        //Using the given base_dn
        
        return $this->create($name, $user, $host, $port, $base_dn);
    }
    
    protected function routeRead(Zend_Controller_Request_Http $request) {
        $user = $request->getParam('user');
        $name = $request->getParam('name');
  
        $instance = $this->read($name);
        if ($user !== $instance->user) throw new InvalidArgumentException("Access to this instance is forbidden", 403);
        
        return $instance;
    }
    
    protected function routeDelete(Zend_Controller_Request_Http $request) {
        $user = $request->getParam('user');
        $name = $request->getParam('name');
        
        $instance = $this->read($name);
        if ($user !== $instance->user) throw new InvalidArgumentException("Access to this instance is forbidden", 403);
        
        return $this->delete($name);
    }
    
    protected function routeIndex(Zend_Controller_Request_Http $request) {
        $user = $request->getParam('user');
        return $this->readMany($user);
    }
    
    protected function routeReset(Zend_Controller_Request_Http $request) {
        $user = $request->getParam('user');
        return $this->deleteMany($user);
    }
    
    protected function routeRestart(Zend_Controller_Request_Http $request) {
        $user = $request->getParam('user');
        $name = $request->getparam('name');
        
        $instance = $this->read($name);
        if ($user !== $instance->user) throw new InvalidArgumentException("Access to this instance is forbidden", 403);
        
        return $this->restart($name);
    }
    
    protected function routePurge(Zend_Controller_Request_Http $request) {
        $user = $request->getParam('user');
        $name = $request->getParam('name');
        
        $instance = $this->read($name);
        if ($user !== $instance->user) throw new InvalidArgumentException("Access to this instance is forbidden", 403);
        
        return $this->purge($name);
    }
    
    //------------------------------------------------------------------------------
    // Utility Functions
    //------------------------------------------------------------------------------
    
    public function getLog() { return $this->log; }
    
    /**
     * Find the next TCP port not in use
     * @throws RuntimeException If a port cannot be obtained
     * @return int Port number
     */
    protected function getNextPort() {
        $start = $this->config->ldap->ports->start;
        $finish = $start + $this->config->ldap->ports->max;
        
        //Get a list of all ports in use in the range
        exec('netstat -ntl', $outputs, $ret);
        if ($ret !== 0) throw new RuntimeException("Could not check ports", 500, new Exception(implode("\n", $outputs)));
        $_extract = function($line) { return preg_match('/:(\\d+)\s+/',$line,$m) ? $m[1] : false; };
        $_filter = function($port) use ($start, $finish) { return $port && $port >= $start && $port <= $finish; };
        $ports = array_flip(array_filter(array_unique(array_map($_extract, $outputs)), $_filter));
        
        //Return the first available port
        for($port=$start; $port<$finish; $port++) if (!array_key_exists($port, $ports)) return $port;
        
        //Couldn't find any within the allowable range?
        throw new RuntimeException("Could not find an available port", 500, new Exception("All ports in use between $start and $finish"));
    }
    
    /**
     * Create an LDAP instance
     * @param string $name Instance name
     * @param string $user
     * @param string $host
     * @param int $port
     * @param string $base_dn
     * @param string $admin_password
     * @throws InvalidArgumentException If any parameters are invalid
     * @throws RuntimeException If creating the instance fails
     * @return StdClass The instance details
     */
    protected function create($name, $user, $host, $port, $base_dn, $admin_password=null) {
        //Validate inputs
        if (!$name or !preg_match("/^\\w+$/", $name)) throw new InvalidArgumentException("Invalid name '$name'", 403);
        if (!$user or !preg_match("/^\\w+$/", $user)) throw new InvalidArgumentException("Invalid user", 403);
        if (!$host) throw new InvalidArgumentException("Invalid host", 403);
        if (!$port or !is_numeric($port)) throw new InvalidArgumentException("Invalid port", 403);
        if (!$base_dn or !is_dn($base_dn)) throw new InvalidArgumentException("Invalid base_dn parameter", 403);
        
        //Get / generate other details
        $path = $this->path.'/'.$name;
        $password = is_null($admin_password) ? substr(base64_encode(md5(microtime())),0,15) : $admin_password;
        
        //Create a directory for that instance
        if (file_exists($path)) throw new RuntimeException("Folder for '$name' already exists", 500);
        else if (!@mkdir($path)) throw new RuntimeException("Could not create folder for '$name'", 500, new Exception(error_get_last_message()));
        
        //Create an install file for that instance
        $inf = <<<INF
[General]
FullMachineName=$host
ServerRoot=$path
ConfigDirectoryAdminID=admin
ConfigDirectoryAdminPwd=$password

[slapd]
ServerPort=$port
ServerIdentifier=$name
Suffix=$base_dn
RootDN=cn=Directory Manager
RootDNPwd=$password
sysconfdir=$path/etc
localstatedir=$path/var
inst_dir=$path/slapd-$name
config_dir=$path/etc/dirsrv/slapd-$name
datadir=$path/usr/share
initconfig_dir=$path
run_dir=$path/run
sbin_dir=$path
db_dir=$path/db
ldif_dir=$path/ldif
bak_dir=$path/bak
INF;
        if (!@file_put_contents($path.'/install.inf', $inf)) 
            throw new RuntimeException("Could not create install file for '$name'", 500, new Exception(error_get_last_message()));
        
        //Store the instance details
        $details = (object)compact('name','user','host','port','base_dn','password');
        if (!@file_put_contents($path.'/details.json', json_encode($details))) 
            throw new RuntimeException("Could not store details for '$name'", 500, new Exception(error_get_last_message()));
        
        //Run the installer
        exec("setsid /usr/sbin/setup-ds.pl --file=$path/install.inf --silent --logfile=$path/setup.log 2>&1", $output, $res);
        if ($res !== 0)
            throw new RuntimeException("Could not create instance", 500, new Exception(implode("\n",$output)));
        
        return $details;
    } 
    
    /**
     * Read the instance details
     * @param string $name Instance name
     * @throws InvalidArgumentException If the name is invalid
     * @throws RuntimeException If the instance cannot be read
     * @return StdClass Instance details
     */
    protected function read($name) {
        //Validate inputs
        if (!$name or !preg_match("/^\\w+$/", $name)) throw new InvalidArgumentException("Invalid name '$name'", 403);
        
        //Find the instance
        $path = $this->path.'/'.$name;
        if (!file_exists($path)) throw new RuntimeException("'$name' not found", 404);
        
        //Read the details
        if (!is_readable($path.'/details.json')) throw new RuntimeException("Could not read '$name'", 500, new Exception("File missing or unreadable"));
        $content = @file_get_contents($path.'/details.json');
        if (!$content) throw new RuntimeException("Could not read '$name'", 500, new Exception(error_get_last_message()));
        $details = json_decode($content);
        if ($details === false) throw new RuntimeException("Could not read '$name'", 500, new Exception(json_last_error_msg()));
        
        return $details;
    }
    
    /**
     * Delete the instance
     * @param string $name Instance name
     * @throws InvalidArgumentException If the name is invalid
     * @throws RuntimeException If the instance cannot be deleted 
     */
    protected function delete($name) {
        //Validate inputs
        if (!$name or !preg_match("/^\\w+$/", $name)) throw new InvalidArgumentException("Invalid name '$name'", 403);
        
        //Find the instance
        $path = $this->path.'/'.$name;
        if (!file_exists($path)) throw new RuntimeException("Could not find folder for '$name'", 500);
        
        //Stop it
        exec("/usr/sbin/stop-dirsrv -d $path $name 2>&1", $output, $res);
        if ($res !== 0)
            throw new RuntimeException("Could not stop instance", 500, new Exception(implode("\n",$output)));
        
        //Remove the instance folder
        exec("rm $path -r", $output, $res);
        if ($res !== 0)
            throw new RuntimeException("Could not remove instance", 500, new Exception(implode("\n",$output)));
        
        return (object)array('success'=>true);
    }
    
    /**
     * Restart the instance
     * @param string $name  Instance name
     * @throws InvalidArgumentException If the name is invalid
     * @throws RuntimeException If the instance cannot be restarted
     */
    protected function restart($name) {
        //Validate inputs
        if (!$name or !preg_match("/^\\w+$/", $name)) throw new InvalidArgumentException("Invalid name '$name'", 403);
        
        //Find the instance
        $path = $this->path.'/'.$name;
        if (!file_exists($path)) throw new RuntimeException("Could not find folder for '$name'", 500);
        
        //Stop it
        exec("setsid /usr/sbin/restart-dirsrv -d $path $name 2>&1", $output, $res);
        //Result:
        //  0 - Process has been successfully halted and started again
        //  1 - Process has been successfully halted, but could not 
        //  2 - Process was not running, but has successfully been started
        //  3 - Process could not be stopped
        if ($res !== 0 and $res !== 2)
            throw new RuntimeException("Could not restart instance", 500, new Exception(implode("\n",$output)));
        
        return (object)array('success'=>true);
    }
    
    /**
     * Purge the instance (delete and recreate with the same settings)
     * @param string $name Instance name
     * @throws InvalidArgumentException If the name is invalid
     * @return StdClass Instance details @see create()
     */
    protected function purge($name) {
        //Validate inputs
        if (!$name or !preg_match("/^\\w+$/", $name)) throw new InvalidArgumentException("Invalid name '$name'", 403);
        
        //Read the instance configuration
        $instance = $this->read($name);
        
        //Delete the existing instance - throws on failure, continues on success
        $this->delete($name);
        
        //Create a new instance with the original configuration
        return $this->create($instance->name, $instance->user, $instance->host, $instance->port, $instance->base_dn, $instance->password);
    }
    
    protected function readMany($filter = '') {
        //Validate inputs
        if ($filter and !preg_match("/^\\w+$/", $filter)) throw new InvalidArgumentException("Invalid filter", 403);

        //Get the list of instance names matching the filter
        $offset = strlen($this->path) + 1;
        $names = array_map(function($path) use ($offset) { return substr($path, $offset); }, glob($this->path.'/'.$filter.'*'));

        //Read each instance
        $results = new stdClass;        
        foreach($names as $name) $results->$name = $this->read($name); 

        return $results;
    }
    
    protected function deleteMany($filter = '') {
        //Validate inputs
        if ($filter and !preg_match("/^\\w+$/", $filter)) throw new InvalidArgumentException("Invalid filter", 403);
        
        //Get the list of instance names matching the filter
        $offset = strlen($this->path) + 1;
        $names = array_map(function($path) use ($offset) { return substr($path, $offset); }, glob($this->path.'/'.$filter.'*'));
        
        //Delete each instance
        $results = new stdClass;        
        foreach($names as $name) $results->$name = $this->delete($name); 

        return $results;        
    }
    
    
}  
//-----------------------------------------------------------------------------

//Set up request/response objects
require_once('Zend/Controller/Request/Http.php');
require_once('Zend/Controller/Response/Http.php');
$request = new Zend_Controller_Request_Http();
$response = new Zend_Controller_Response_Http();
$response->setHeader('Content-Type', 'application/json');

//Route the incoming request, and send back the response 
try {
    chdir(__DIR__);
    $ldapaas = new LDAPaaS();
    
    $output = $ldapaas->route($request);
    $response->setBody(json_encode($output));
    $response->sendResponse();
} 
//If anything goes wrong, log it, and return an error!
catch(Exception $e) {
    //What kind of error occurred?
    $code = $e->getCode();
    $code = ($code >= 400 && $code <= 599) ? $code : 500; 
    
    //Log it
    $log = isset($ldapaas) && $ldapaas->getLog();
    if ($log) $log->err($e);
    else error_log( (string)$e );
    
    //Return it
    $response->setHttpResponseCode($code);
    $response->setBody(json_encode((object)array("code"=>$code, "error"=>$e->getMessage())));
    $response->sendResponse();
}
