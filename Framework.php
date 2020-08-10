<?php
/**
 * Framework : Extra Light PHP Framework
 * http://www.madebyfrog.com/framework/
 *
 * Copyright (c) 2007, Philippe Archambault
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 * @author Philippe Archambault <philippe.archambault@gmail.com>
 * @copyright 2007 Philippe Archambault
 * @package Framework
 * @version 1.6
 * @license http://www.opensource.org/licenses/mit-license.html MIT License
 *
 * UPDATES:
 * Vepa Halliyev (www.veppa.com) - 04.05.2008
 *   - removed validation. use as static class (13 sept 2008)
 *
 *   - Added validation function to view and controller. Requires valitation class in helper folder.
 *   - Inflator::slugify to create google friendly permalinks
 */
define('FRAMEWORK_STARTING_MICROTIME', get_microtime());

// all constants that you can define before to costumize your framework
if (!defined('DEBUG'))
	define('DEBUG', false);

if (!defined('CORE_ROOT'))
	define('CORE_ROOT', dirname(__FILE__));
if (!defined('APP_PATH'))
	define('APP_PATH', CORE_ROOT . DIRECTORY_SEPARATOR . 'app');
if (!defined('HELPER_PATH'))
	define('HELPER_PATH', CORE_ROOT . DIRECTORY_SEPARATOR . 'helpers');

if (!defined('DOMAIN'))
	define('DOMAIN', dirname($_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME']));
if (!defined('BASE_URL'))
	define('BASE_URL', 'http://' . DOMAIN . '/?/');

if (!defined('DEFAULT_CONTROLLER'))
	define('DEFAULT_CONTROLLER', 'index');
if (!defined('DEFAULT_ACTION'))
	define('DEFAULT_ACTION', 'index');

// setting error display depending on debug mode or not
error_reporting((DEBUG ? E_ALL ^ E_NOTICE : 0));

// no more quotes escaped with a backslash
// if(PHP_VERSION < 6)
// turn off quotes if it is on
if (function_exists('get_magic_quotes_gpc') && function_exists('set_magic_quotes_runtime') && @get_magic_quotes_runtime())
{
	@set_magic_quotes_runtime(0);
}

// start session 
if (!isset($_SESSION))
{
	// session_start();
}

if (!defined('DEFAULT_TIMEZONE'))
{
	define('DEFAULT_TIMEZONE', 'GMT');
}
@ini_set('date.timezone', DEFAULT_TIMEZONE);
if (function_exists('date_default_timezone_set'))
{
	date_default_timezone_set(DEFAULT_TIMEZONE);
}
else
{
	putenv('TZ=' . DEFAULT_TIMEZONE);
}

/**
 * The Dispatcher main Core class is responsible for mapping urls /
 * routes to Controller methods. Each route that has the same number of directory
 * components as the current requested url is tried, and the first method that
 * returns a response with a non false / non null value will be returned via the
 * Dispatcher::dispatch() method. For example:
 *
 * A route string can be a literal url such as '/pages/about' or contain
 * wildcards (:any or :num) and/or regex like '/blog/:num' or '/page/:any'.
 *
 * Dispatcher::addRoute(array(
 *  '/' => 'page/index',
 *  '/about' => 'page/about,
 *  '/blog/:num' => 'blog/post/$1',
 *  '/blog/:num/comment/:num/delete' => 'blog/deleteComment/$1/$2'
 * ));
 *
 * Visiting /about/ would call PageController::about(),
 * visiting /blog/5 would call BlogController::post(5)
 * visiting /blog/5/comment/42/delete would call BlogController::deleteComment(5,42)
 *
 * The dispatcher is used by calling Dispatcher::addRoute() to setup the route(s),
 * and Dispatcher::dispatch() to handle the current request and get a response.
 */
final class Dispatcher
{

	private static $routes = array();
	private static $params = array();
	private static $status = array();
	private static $requested_url = null;

	public static function addRoute($route, $destination = null)
	{
		if ($destination != null && !is_array($route))
		{
			$route = array($route => $destination);
		}
		self::$routes = array_merge(self::$routes, $route);
	}

	public static function splitUrl($url)
	{
		return preg_split('/\//', $url, -1, PREG_SPLIT_NO_EMPTY);
	}

	public static function dispatch($requested_url = null)
	{
		Flash::init();

		if ($requested_url === null)
		{
			$requested_url = self::getCurrentUrl();
		}


		// this is only trace for debuging
		self::$status['requested_url'] = $requested_url;

		// make the first split of the current requested_url
		self::$params = self::splitUrl($requested_url);

		// do we even have any custom routing to deal with?
		if (count(self::$routes) === 0)
		{
			return self::executeAction(self::getController(), self::getAction(), self::getParams());
		}

		// is there a literal match? If so we're done
		if (isset(self::$routes[$requested_url]))
		{
			self::$params = self::splitUrl(self::$routes[$requested_url]);
			return self::executeAction(self::getController(), self::getAction(), self::getParams());
		}

		// loop through the route array looking for wildcards
		foreach (self::$routes as $route => $uri)
		{
			// convert wildcards to regex
			if (strpos($route, ':') !== false)
			{
				$route = str_replace(':any', '(.+)', str_replace(':num', '([0-9]+)', $route));
			}
			// does the regex match?
			if (preg_match('#^' . $route . '$#', $requested_url))
			{
				// do we have a back-reference?
				if (strpos($uri, '$') !== false && strpos($route, '(') !== false)
				{
					$uri = preg_replace('#^' . $route . '$#', $uri, $requested_url);
				}
				self::$params = self::splitUrl($uri);
				// we fund it, so we can break the loop now!
				break;
			}
		}

		return self::executeAction(self::getController(), self::getAction(), self::getParams());
	}

// dispatch

	public static function getCurrentUrl()
	{
		if (self::$requested_url === null)
		{
			self::setCurrentUrl();
		}

		return self::$requested_url;
	}

	// set current url here if we need to use current url before dispatch for language
	// and predispatch actions
	public static function setCurrentUrl($requested_url = null)
	{
		// if no url passed, we will get the first key from the _GET array
		// that way, index.php?/controller/action/var1&email=example@example.com
		// requested_url will be equal to: /controller/action/var1
		if ($requested_url === null)
		{

			if (self::$requested_url !== null)
			{
				return self::$requested_url;
			}

			// previously was 
			$requested_url_old = $_SERVER['QUERY_STRING'];
			$pos = strpos($requested_url_old, '&');
			if ($pos !== false)
			{
				$requested_url_old = substr($requested_url_old, 0, $pos);
			}

			$base_name = dirname($_SERVER['SCRIPT_NAME']);
			$requested_url = $_SERVER['REQUEST_URI'];
			$requested_url = substr($requested_url, strlen($base_name));

			$pos = strpos($requested_url, '?');
			if ($pos !== false)
			{
				$requested_url = substr($requested_url, 0, $pos);
			}

			// rawurldecode non latin caracters in url 
			$requested_url = rawurldecode($requested_url);

			// if no rewrite used and urls are in old format like domain.com/?admin/items/&enabled=0&email=admin@test.com 
			// then check if $requested_url_old has no = char in it and has bigger length than  $requested_url then use it
			if (strpos($requested_url_old, '=') === false && strlen($requested_url_old) > strlen($requested_url))
			{
				//echo '<!-- $requested_url=$requested_url_old : ['.$requested_url.']=['.$requested_url_old.'] -->';
				$requested_url = $requested_url_old;
			}
		}

		// requested url MUST start with a slash (for route convention)
		$requested_url = '/' . trim($requested_url, '/') . '/';
		/* if (strpos($requested_url, '/') !== 0) {
		  $requested_url = '/' . $requested_url;
		  } */
		if (strpos($requested_url, '.') !== false)
		{
			$requested_url = rtrim($requested_url, '/');
		}

		$requested_url = str_replace('//', '/', $requested_url);


		self::$requested_url = $requested_url;

		return self::$requested_url;
	}

	public static function getController()
	{
		return isset(self::$params[0]) ? self::$params[0] : DEFAULT_CONTROLLER;
	}

	public static function getAction()
	{
		return isset(self::$params[1]) ? self::$params[1] : DEFAULT_ACTION;
	}

	public static function getParams()
	{
		return array_slice(self::$params, 2);
	}

	public static function getStatus($key = null)
	{
		return ($key === null) ? self::$status : (isset(self::$status[$key]) ? self::$status[$key] : null);
	}

	public static function executeAction($controller, $action, $params)
	{
		//echo "executeAction($controller, $action, $params)";

		self::$status['controller'] = $controller;
		self::$status['action'] = $action;
		self::$status['params'] = implode(', ', $params);

		$controller_class = Inflector::camelize($controller);
		$controller_class_name = $controller_class . 'Controller';

		// get a instance of that controller
		if (class_exists($controller_class_name))
		{
			$controller = new $controller_class_name();
		}
		else
		{
			//page_not_found();
		}
		if (!$controller instanceof Controller)
		{
			throw new Exception("Class '{$controller_class_name}' does not extends Controller class!");
		}

		// execute the action
		$controller->execute($action, $params);
	}

	// return test.xx.com
	public static function getServer()
	{
		return strtolower($_SERVER['SERVER_NAME']);
	}

	// return /yazi/test.html
	public static function getScript($is_file = true)
	{
		//return strtolower($_SERVER['SCRIPT_NAME']);
		//$return = implode('/',array_slice(self::$params, 1));

		$return = implode('/', self::getParams());

		if (strpos($return, '.') === false)
		{
			if ($is_file)
			{
				$return .= '/index.html';
			}
			else
			{
				$return .= '/';
			}
		}

		return $return;
	}

}

// end Dispatcher class

/**
 * Used for database table objects. read, sate, update, delete.
 *
 * @author Vepa Halliyev - veppa.com (updates)
 * @version 1.0
 *
 *
 * Updates:
 * 02.05.2008 Added $col static variable to add security to table columns and prevent unsuspected errors.
 * 02.06.2008 Updated class to use simple point for queries. Use query to connenct just before query done.
 * 			  Added functionality to handle multiple connections. master-slave. slaves have to specify explicitly
 *
 */
class Record
{

	const PARAM_BOOL = 5;
	const PARAM_NULL = 0;
	const PARAM_INT = 1;
	const PARAM_STR = 2;
	const PARAM_LOB = 3;
	const PARAM_STMT = 4;
	const PARAM_INPUT_OUTPUT = -2147483648;
	const PARAM_EVT_ALLOC = 0;
	const PARAM_EVT_FREE = 1;
	const PARAM_EVT_EXEC_PRE = 2;
	const PARAM_EVT_EXEC_POST = 3;
	const PARAM_EVT_FETCH_PRE = 4;
	const PARAM_EVT_FETCH_POST = 5;
	const PARAM_EVT_NORMALIZE = 6;
	const FETCH_LAZY = 1;
	const FETCH_ASSOC = 2;
	const FETCH_NUM = 3;
	const FETCH_BOTH = 4;
	const FETCH_OBJ = 5;
	const FETCH_BOUND = 6;
	const FETCH_COLUMN = 7;
	const FETCH_CLASS = 8;
	const FETCH_INTO = 9;
	const FETCH_FUNC = 10;
	const FETCH_GROUP = 65536;
	const FETCH_UNIQUE = 196608;
	const FETCH_CLASSTYPE = 262144;
	const FETCH_SERIALIZE = 524288;
	const FETCH_PROPS_LATE = 1048576;
	const FETCH_NAMED = 11;
	const ATTR_AUTOCOMMIT = 0;
	const ATTR_PREFETCH = 1;
	const ATTR_TIMEOUT = 2;
	const ATTR_ERRMODE = 3;
	const ATTR_SERVER_VERSION = 4;
	const ATTR_CLIENT_VERSION = 5;
	const ATTR_SERVER_INFO = 6;
	const ATTR_CONNECTION_STATUS = 7;
	const ATTR_CASE = 8;
	const ATTR_CURSOR_NAME = 9;
	const ATTR_CURSOR = 10;
	const ATTR_ORACLE_NULLS = 11;
	const ATTR_PERSISTENT = 12;
	const ATTR_STATEMENT_CLASS = 13;
	const ATTR_FETCH_TABLE_NAMES = 14;
	const ATTR_FETCH_CATALOG_NAMES = 15;
	const ATTR_DRIVER_NAME = 16;
	const ATTR_STRINGIFY_FETCHES = 17;
	const ATTR_MAX_COLUMN_LEN = 18;
	const ATTR_EMULATE_PREPARES = 20;
	const ATTR_DEFAULT_FETCH_MODE = 19;
	const ERRMODE_SILENT = 0;
	const ERRMODE_WARNING = 1;
	const ERRMODE_EXCEPTION = 2;
	const CASE_NATURAL = 0;
	const CASE_LOWER = 2;
	const CASE_UPPER = 1;
	const NULL_NATURAL = 0;
	const NULL_EMPTY_STRING = 1;
	const NULL_TO_STRING = 2;
	const ERR_NONE = '00000';
	const FETCH_ORI_NEXT = 0;
	const FETCH_ORI_PRIOR = 1;
	const FETCH_ORI_FIRST = 2;
	const FETCH_ORI_LAST = 3;
	const FETCH_ORI_ABS = 4;
	const FETCH_ORI_REL = 5;
	const CURSOR_FWDONLY = 0;
	const CURSOR_SCROLL = 1;
	const MYSQL_ATTR_USE_BUFFERED_QUERY = 1000;
	const MYSQL_ATTR_LOCAL_INFILE = 1001;
	const MYSQL_ATTR_INIT_COMMAND = 1002;
	const MYSQL_ATTR_READ_DEFAULT_FILE = 1003;
	const MYSQL_ATTR_READ_DEFAULT_GROUP = 1004;
	const MYSQL_ATTR_MAX_BUFFER_SIZE = 1005;
	const MYSQL_ATTR_DIRECT_QUERY = 1006;

	public static $__CONNS__ = false;
	public static $__CONNS_IMPLODE__ = false;
	public static $__QUERIES__ = array();
	public static $__QUERY_COUNT__ = 0;
	public static $__CONNECTIONS__ = array();
	private $cols = array(); // column names for secure data inserts

	//public $locale_db; // database connection for different languages. tr,en ...

	/* final public static function connection($connection)
	  {
	  self::getConnection($con_type) = $connection;
	  } */

	/**
	 * Gets database connection to execute queries. Always use this function to get db object
	 *
	 * @param string $type database connection type. default is master, can choose if has several connections
	 * @return PDO DB connection object
	 */
	final public static function getConnection($con_type)
	{

		if (!self::$__CONNS__[$con_type])
		{
			// chek if connection has several alternatives. connect to one
			if (isset(self::$__CONNECTIONS__[$con_type][0]))
			{
				// choose random connection
				$con_id = rand(0, count(self::$__CONNECTIONS__[$con_type]));
				$con = self::$__CONNECTIONS__[$con_type][$con_id];
			}
			else
			{
				$con = self::$__CONNECTIONS__[$con_type];
			}

			if (!$con)
			{
				// if it is read then check normal connection 
				if (strpos($con_type, 'READ_') === 0)
				{
					return self::getConnection(substr($con_type, 5));
				}

				throw new Exception("Connection type '{$con_type}' not found!");
			}

			// check if same connection is already connected
			//echo $con_type;
			$key = implode(',', $con);
			if (!self::$__CONNS_IMPLODE__[$key])
			{
				// connect to db

				extract($con);


				try
				{
					// if no connection estabilish one
					if (USE_PDO)
					{
						//PDO::setAttribute(self::ATTR_TIMEOUT,2);
						//echo '['.PDO::getAttribute(self::ATTR_TIMEOUT).']';
						$__FROG_CONN__ = new PDO($DB_DSN, $DB_USER, $DB_PASS);
						$__FROG_CONN__->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
					}
					else
					{
						require_once CORE_ROOT . '/libraries/DoLite.php';
						$__FROG_CONN__ = new DoLite($DB_DSN, $DB_USER, $DB_PASS);
					}
				}
				catch (Exception $e)
				{
					header_503();
					//echo 'Ops.. something went wrong with our servers. Please let us know if we havent fixed it in one hour. ';
					//echo (is_dev()?$e->getMessage():'DB connection error');
					echo 'DB connection error';
					exit;
				}

				self::$__CONNS_IMPLODE__[$key] = $__FROG_CONN__;
				self::$__CONNS_IMPLODE__[$key]->exec("set names 'utf8'");

				if (DEBUG)
				{
					$__FROG_CONN__->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
				}
			}

			self::$__CONNS__[$con_type] = self::$__CONNS_IMPLODE__[$key];
		}

		if (!self::$__CONNS__[$con_type])
		{
			throw new Exception('Invalid db connection');
		}

		return self::$__CONNS__[$con_type];
	}

	final public static function logQuery($sql, $con_type = 'master')
	{
		self::$__QUERIES__[$con_type][] = $sql;
		self::$__QUERY_COUNT__++;
	}

	final public static function getQueryLog()
	{
		return self::$__QUERIES__;
	}

	final public static function getQueryCount()
	{
		return self::$__QUERY_COUNT__;
	}

	final public static function query($sql, $values = false, $con_type = 'master')
	{
		self::logQuery($sql, $con_type);

		Benchmark::cp();

		$sql_lower = strtolower($sql);
		// there is risk that column name can contain any of this keys: insert,delete,update :(
		$is_update = ( strpos($sql_lower, 'insert') === 0 || strpos($sql_lower, 'delete') === 0 || strpos($sql_lower, 'update ') === 0);


		if (is_array($values))
		{
			$stmt = self::getConnection($con_type)->prepare($sql);
			$stmt->execute($values);
			//Benchmark::cp($sql . ':' . $con_type . ':' . implode(', ', $values));
			Benchmark::cp(self::sqlDebug($sql, $values));
			if ($is_update)
			{
				$r = true;
			}
			else
			{
				$r = @$stmt->fetchAll(self::FETCH_OBJ);
			}
		}
		else
		{
			$r = self::getConnection($con_type)->exec($sql) !== false;
			Benchmark::cp($sql . ':' . $con_type);
		}

		return $r;
	}

	/**
	 * execute statment and return statment pointer object for iteration with while loop
	 * 
	 * use as
	 * while($game = $stmt->fetch(Record::FETCH_OBJ)) { ...}
	 * 
	 * @param type $sql
	 * @param type $values
	 * @param type $con_type
	 * @return type
	 */
	final public static function queryStatement($sql, $values = false, $con_type = 'master')
	{
		self::logQuery($sql, $con_type);

		Benchmark::cp();

		if (is_array($values))
		{
			$stmt = self::getConnection($con_type)->prepare($sql);
			$stmt->execute($values);
			Benchmark::cp(self::sqlDebug($sql, $values));
			$r = $stmt;
		}
		else
		{
			$r = self::getConnection($con_type)->exec($sql) !== false;
			Benchmark::cp($sql . ':' . $con_type);
		}

		return $r;
	}

	public static function queryOne($sql, $values = false, $con_type = 'master')
	{
		self::logQuery($sql, $con_type);

		Benchmark::cp();

		if (is_array($values))
		{
			$stmt = self::getConnection($con_type)->prepare($sql . ' LIMIT 0,1');
			$stmt->execute($values);
			Benchmark::cp(self::sqlDebug($sql, $values));
			$r = $stmt->fetchAll(self::FETCH_OBJ);
			$r = $r[0];
		}
		else
		{
			$r = self::getConnection($con_type)->exec($sql) !== false;
			Benchmark::cp($sql . ':' . $con_type);
		}

		return $r;
	}

	final public static function write($sql, $values = false, $con_type = 'master')
	{
		return self::query($sql, $values, $con_type);
	}

	final public static function read($sql, $values = false, $con_type = 'master')
	{
		return self::query($sql, $values, 'READ_' . $con_type);
	}

	final public static function queryArraySingle($sql, $values = false, $con_type = 'master', $field = 'id')
	{
		// always read
		$r = array();
		$result = self::read($sql, $values, $con_type);
		if ($result)
		{
			foreach ($result as $record)
			{
				$r[] = $record->{$field};
			}
		}
		return $r;
	}

	final public static function fetchColumn($sql, $values = array(), $con_type = 'master')
	{
		Benchmark::cp();

		// aslways read
		$con_type = 'READ_' . $con_type;

		self::logQuery($sql, $con_type);

		$stmt = self::getConnection($con_type)->prepare($sql);
		$stmt->execute($values);

		Benchmark::cp(self::sqlDebug($sql, $values));

		return $stmt->fetchColumn();
	}

	final public static function tableNameFromClassName($class_name)
	{
		try
		{
			if (class_exists($class_name) && defined($class_name . '::TABLE_NAME'))
				return TABLE_PREFIX . constant($class_name . '::TABLE_NAME');
		}
		catch (Exception $e)
		{
			return TABLE_PREFIX . Inflector::underscore($class_name);
		}
	}

	final public static function classNameFromClassName($class_name)
	{
		try
		{
			if (class_exists($class_name))
				return $class_name;
		}
		catch (Exception $e)
		{
			return 'stdClass';
		}
	}

	final public static function escape($value, $con_type = 'master')
	{
		return self::getConnection($con_type)->quote($value);
	}

	final public static function lastInsertId($con_type = 'master')
	{
		return self::getConnection($con_type)->lastInsertId();
	}

	public function __construct($data = false, $locale_db = null)
	{
		//$this->locale_db = $locale_db;
		if (is_array($data))
		{
			$this->setFromData($data);
		}
	}

	public function setFromData($data)
	{
		foreach ($data as $key => $value)
		{
			$this->$key = $value;
		}
	}

	/**
	 * Generates a insert or update string from the supplied data and execute it
	 *
	 * @return boolean
	 */
	public function save($key = 'id', $con_type = 'master')
	{
		Benchmark::cp();

		if (!$this->beforeSave())
			return false;

		$value_of = array();

		if (empty($this->{$key}))
		{

			// unset index value
			unset($this->{$key});

			if (!$this->beforeInsert())
			{
				return false;
			}

			$columns = $this->getColumns();

			// escape and format for SQL insert query
			foreach ($columns as $column)
			{
				if ($this->isColumn($column))
				{
					$value_of[$column] = self::getConnection($con_type)->quote($this->$column);
				}
			}

			$sql = 'INSERT INTO ' . self::tableNameFromClassName(get_class($this)) . ' ('
					. implode(', ', array_keys($value_of)) . ') VALUES (' . implode(', ', array_values($value_of)) . ')';
			//Flash::set('error', $sql);
			$return = self::getConnection($con_type)->exec($sql) !== false;
			$this->{$key} = self::lastInsertId($con_type);

			self::logQuery($sql, $con_type);
			Benchmark::cp($sql . ':' . $con_type);

			if (!$this->afterInsert())
			{
				return false;
			}
		}
		else
		{

			if (!$this->beforeUpdate())
			{
				return false;
			}

			$columns = $this->getColumns();

			// escape and format for SQL update query
			foreach ($columns as $column)
			{
				if ($this->isColumn($column))
				{
					$value_of[$column] = $column . '=' . self::getConnection($con_type)->quote($this->$column);
				}
			}

			unset($value_of[$key]);

			$sql = 'UPDATE ' . self::tableNameFromClassName(get_class($this)) . ' SET '
					. implode(', ', $value_of) . ' WHERE ' . $key . ' = ' . self::getConnection($con_type)->quote($this->{$key});
			//Flash::set('error', $sql);
			$return = self::getConnection($con_type)->exec($sql) !== false;

			self::logQuery($sql, $con_type);
			Benchmark::cp($sql . ':' . $con_type);

			if (!$this->afterUpdate())
			{
				return false;
			}
		}

		// Run it !!...
		return $return;
	}

	private function isColumn($key)
	{
		// check if it is defined as column
		if (isset(self::$cols))
		{
			return (isset($this->$key) && self::$cols[$key]);
		}
		else
		{
			return isset($this->$key);
		}
	}

	/**
	 * Generates a delete string and execute it
	 *
	 * @param string $table the table name
	 * @param string $where the query condition
	 * @return boolean
	 */
	public function delete($key = 'id', $con_type = 'master')
	{
		Benchmark::cp();

		if (!$this->beforeDelete())
			return false;

		$sql = 'DELETE FROM ' . self::tableNameFromClassName(get_class($this))
				. ' WHERE ' . $key . '=' . self::getConnection($con_type)->quote($this->{$key});

		// Run it !!...
		$return = self::getConnection($con_type)->exec($sql) !== false;
		if ($return && !$this->afterDelete())
		{
			$this->save($key, $con_type);
			return false;
		}

		self::logQuery($sql, $con_type);
		Benchmark::cp($sql . ':' . $con_type);

		return $return;
	}

	public function beforeSave()
	{
		return true;
	}

	public function beforeInsert()
	{
		return true;
	}

	public function beforeUpdate()
	{
		return true;
	}

	public function beforeDelete()
	{
		return true;
	}

	public function afterSave()
	{
		return true;
	}

	public function afterInsert()
	{
		return true;
	}

	public function afterUpdate()
	{
		return true;
	}

	public function afterDelete()
	{
		return true;
	}

	/**
	 * return a array of all columns in the table
	 * it is a good idea to rewrite this method in all your model classes
	 * used in save() for creating the insert and/or update sql query
	 */
	public function getColumns()
	{
		if (isset($this->cols))
		{
			$cols = $this->cols;
		}
		else
		{
			$cols = get_object_vars($this);
		}
		return array_keys($cols);
	}

	public static function filterCols($data, $fields)
	{
		if (!is_array($fields))
		{
			$fields = explode(',', $fields);
		}

		foreach ($fields as $field)
		{
			if (isset($data[$field]))
			{
				$return[$field] = $data[$field];
			}
		}
		return $return;
	}

	public function setColumns($cols)
	{
		$this->cols = $cols;
	}

	public static function insert($class_name, $data, $con_type = 'master')
	{
		Benchmark::cp();

		$keys = array();
		$values = array();

		foreach ($data as $key => $value)
		{
			$keys[] = $key;
			$values[] = self::getConnection($con_type)->quote($value);
		}

		$sql = 'INSERT INTO ' . self::tableNameFromClassName($class_name) . ' (' . join(', ', $keys) . ') VALUES (' . join(', ', $values) . ')';

		self::logQuery($sql, $con_type);

		// Run it !!...
		$return = self::getConnection($con_type)->exec($sql) !== false;

		Benchmark::cp(self::sqlDebug($sql, $values));

		return $return;
	}

	public static function update($class_name, $data, $where, $values = array(), $con_type = 'master')
	{
		Benchmark::cp();

		$setters = array();

		// prepare request by binding keys
		foreach ($data as $key => $value)
		{
			$setters[] = $key . '=' . self::getConnection($con_type)->quote($value);
		}

		$sql = 'UPDATE ' . self::tableNameFromClassName($class_name) . ' SET ' . join(', ', $setters) . ' WHERE ' . $where;

		self::logQuery($sql, $con_type);

		$stmt = self::getConnection($con_type)->prepare($sql);
		$return = $stmt->execute($values);


		Benchmark::cp(self::sqlDebug($sql, $values));

		return $return;
	}

	/**
	 * Update number by increasing or decreasing by number
	 *
	 * @param $class_name
	 * @param $data array('x'=>-1,'y'=>+3,...)
	 * @param $where
	 * @param $values
	 * @param $con_type
	 * @return unknown_type
	 */
	public static function increaseWhere($class_name, $data, $where, $values = array(), $con_type = 'master')
	{
		Benchmark::cp();

		$setters = array();

		// prepare request by binding keys
		foreach ($data as $key => $value)
		{
			$setters[] = $key . '=' . $key . ' ' . $value;
		}

		$sql = 'UPDATE ' . self::tableNameFromClassName($class_name) . ' SET ' . join(', ', $setters) . ' WHERE ' . $where;

		self::logQuery($sql, $con_type);

		$stmt = self::getConnection($con_type)->prepare($sql);
		$return = $stmt->execute($values);

		Benchmark::cp(self::sqlDebug($sql, $values));

		return $return;
	}

	public static function deleteWhere($class_name, $where, $values = array(), $con_type = 'master')
	{
		Benchmark::cp();

		$sql = 'DELETE FROM ' . self::tableNameFromClassName($class_name) . ' WHERE ' . $where;

		self::logQuery($sql, $con_type);

		$stmt = self::getConnection($con_type)->prepare($sql);
		$return = $stmt->execute($values);

		Benchmark::cp(self::sqlDebug($sql, $values));

		return $return;
	}

	/**
	 * note: lazy finder or getter method. Practical when you need something really
	 *       simple no join or anything will only generate simple select * from table ...
	 * @param string $class_name
	 * @param string $id
	 * @param string $key
	 * @param string $con_type
	 * @param string $fields
	 * @return Object
	 */
	public static function findByIdFrom($class_name, $id, $key = 'id', $con_type = 'master', $fields = '*')
	{
		return self::findOneFrom($class_name, $key . '=?', array($id), $con_type, $fields);
	}

	/**
	 * Get multiple records matching multiple ids. good for selecting multiple items for deletion
	 * 
	 * @param type $class_name
	 * @param type $id
	 * @param type $key
	 * @param type $con_type
	 * @param type $fields
	 * @return type
	 */
	public static function findManyByIdFrom($class_name, $id, $key = 'id', $con_type = 'master', $fields = '*')
	{
		if (!is_array($id))
		{
			return self::findByIdFrom($class_name, $id, $key, $con_type, $fields);
		}

		$ids_ = self::quoteArray($id, $con_type);
		if (count($ids_) < 1)
		{
			// no valid id provided return empty result
			return array();
		}
		else if (count($ids_) === 1)
		{
			// only one id
			$sql = $key . '=' . $ids_[0];
		}
		else
		{
			// multiple ids
			$sql = $key . ' IN (' . implode(',', $ids_) . ')';
		}

		return self::findAllFrom($class_name, $sql, array(), $con_type, $fields);
	}

	public static function findOneFrom($class_name, $where = false, $values = array(), $con_type = 'master', $fields = '*')
	{
		Benchmark::cp();

		// always read
		$con_type = 'READ_' . $con_type;

		$sql = 'SELECT ' . $fields . ' FROM ' . self::tableNameFromClassName($class_name) . ($where ? ' WHERE ' . $where : '') . ' LIMIT 0,1';

		$stmt = self::getConnection($con_type)->prepare($sql);
		$stmt->execute($values);


		self::logQuery($sql, $con_type);
		Benchmark::cp(self::sqlDebug($sql, $values));

		return $stmt->fetchObject(self::classNameFromClassName($class_name));
	}

	public static function findAllFrom($class_name, $where = false, $values = array(), $con_type = 'master', $fields = '*')
	{
		Benchmark::cp();

		// always read
		$con_type = 'READ_' . $con_type;

		$sql = 'SELECT ' . $fields . ' FROM ' . self::tableNameFromClassName($class_name) . ($where ? ' WHERE ' . $where : '');

		$stmt = self::getConnection($con_type)->prepare($sql);
		$stmt->execute($values);

		self::logQuery($sql, $con_type);
		//Benchmark::cp(self::sqlDebug($sql, $values));
		Benchmark::cp(self::sqlDebug($sql, $values));


		$objects = array();
		while ($object = $stmt->fetchObject(self::classNameFromClassName($class_name)))
		{
			$objects[] = $object;
		}

		return $objects;
	}

	/**
	 * use this for faster queries if there are more than 10,000 records. it is 6-10 times faster than selecting all
	 * 
	 * @param string $class_name
	 * @param string $where
	 * @param array $values
	 * @param string $con_type
	 * @param string $fields
	 * @param string $use_id
	 * @return array
	 */
	public static function findAllFromUseIds($class_name, $where = false, $values = array(), $con_type = 'master', $fields = '*', $use_id = null)
	{
		if (is_null($use_id))
		{
			// id not set then return directly requested fields
			return self::findAllFrom($class_name, $where, $values, $con_type, $fields);
		}

		// get only ids
		$records = self::findAllFrom($class_name, $where, $values, $con_type, $use_id);

		// append all requested fields
		self::appendObject($records, $use_id, $class_name, $use_id, '', $con_type, $fields);

		// rebuild object array with requested fields
		$return = array();
		foreach ($records as $r)
		{
			$return[] = $r->{$class_name};
		}

		return $return;
	}

	public static function countFrom($class_name, $where = false, $values = array(), $con_type = 'master')
	{
		Benchmark::cp();

		// always read
		$con_type = 'READ_' . $con_type;

		$sql = 'SELECT COUNT(*) AS nb_rows FROM ' . self::tableNameFromClassName($class_name) . ($where ? ' WHERE ' . $where : '');

		$stmt = self::getConnection($con_type)->prepare($sql);
		$stmt->execute($values);

		self::logQuery($sql, $con_type);
		Benchmark::cp(self::sqlDebug($sql, $values));

		return (int) $stmt->fetchColumn();
	}

	public static function quote($string, $con_type = 'master')
	{
		return self::getConnection($con_type)->quote($string);
	}

	/**
	 * Add quotes to array item, remove duplicates and empty ones 
	 *  
	 * @param array $array
	 * @param string $con_type
	 * @return array
	 */
	public static function quoteArray($array, $con_type = 'master')
	{
		$ids = array_unique($array);
		// add quoted values
		$ids_ = array();
		foreach ($ids as $id)
		{
			if (strlen($id))
			{
				$ids_[] = self::quote($id, $con_type);
			}
		}

		return $ids_;
	}

	/**
	 * append other object by given id 
	 * update: added multi result, extra  query, clean
	 * 
	 * @param array $records
	 * @param string $field get id from this field
	 * @param string $class_name object that is added
	 * @param string $class_field id field name for searched object
	 * @param string $alt_name name of property that will be appended
	 * @param string $con_type
	 * @param string $fields
	 * @param bool $clean  to clean appended objects to stdClass
	 * @param bool|string $multi to return multiple results as array, you can set key if you want values to be indexed array
	 * @param string $sql_extra extra query string
	 * @param string $sql_after extra string after query
	 */
	public static function appendObject($records, $field, $class_name, $class_field = 'id', $alt_name = '', $con_type = 'master', $fields = '*', $clean = false, $multi = false, $sql_extra = '', $sql_after = '')
	{

		if (!$records)
		{
			return;
		}

		$records = Record::checkMakeArray($records);

		// get ids from records
		$ids = array();
		foreach ($records as $r)
		{
			if (strlen($r->{$field}))
			{
				$ids[] = $r->{$field};
			}
		}

		$ids_ = self::quoteArray($ids, $con_type);
		if (!$ids_)
		{
			return false;
		}

		$objects = self::findAllFrom($class_name, $sql_extra . ' ' . $class_field . ' IN (' . implode(',', $ids_) . ') ' . $sql_after, array(), $con_type, $fields);
		
		unset($ids);
		unset($ids_);

		if (!$objects)
		{
			return false;
		}


		$objects_arr = array();

		foreach ($objects as $o)
		{
			if ($clean)
			{
				$o = self::cleanObject($o);
			}
			if ($multi)
			{
				if (strlen($multi) && isset($o->{$multi}))
				{
					// use given field as key
					$objects_arr[$o->{$class_field}][$o->{$multi}] = $o;
				}
				else
				{
					$objects_arr[$o->{$class_field}][] = $o;
				}
			}
			else
			{
				// appnd only first value. good fir selecting first image
				if (!isset($objects_arr[$o->{$class_field}]))
				{
					$objects_arr[$o->{$class_field}] = $o;
				}
			}
		}
		
		unset($objects);

		if (!$alt_name)
		{
			$alt_name = $class_name;
		}

		// assign retrieved objects 
		foreach ($records as $r)
		{
			$r->{$alt_name} = $objects_arr[$r->{$field}];
		}
		
		unset($objects_arr);		
	}

	/**
	 * convert object to an array of objects, used when append values to all objects in array
	 * 
	 * @param object|array $records
	 * @return array
	 */
	public static function checkMakeArray($records)
	{
		if (!is_array($records))
		{
			$records = array($records);
		}

		return $records;
	}

	/**
	 * Remove private vars from object 
	 * 
	 * @param type $obj
	 * @return type
	 */
	public static function cleanObject($obj)
	{
		$remove = 'cols';

		if (is_array($obj))
		{
			$new_obj = array();
			foreach ($obj as $k => $o)
			{
				$new_obj[$k] = Record::cleanObject($o);
			}
			unset($obj);
			return $new_obj;
		}
		elseif (is_object($obj))
		{
			//unset($obj->{$remove});
			$new_obj = new stdClass();
			foreach ($obj as $k => $o)
			{
				if ($k === $remove)
				{
					continue;
				}
				$new_obj->{$k} = Record::cleanObject($o);
			}
			unset($obj);
			return $new_obj;
		}

		// do nothing
		return $obj;
	}

	public static function sqlDebug($sql_string, $params = null)
	{
		if (!empty($params))
		{
			$indexed = $params == array_values($params);
			foreach ($params as $k => $v)
			{
				if (is_object($v))
				{
					if ($v instanceof \DateTime)
					{
						$v = $v->format('Y-m-d H:i:s');
					}
					else
					{
						continue;
					}
				}
				elseif (is_string($v))
				{
					$v = "'$v'";
				}
				elseif ($v === null)
				{
					$v = 'NULL';
				}
				elseif (is_array($v))
				{
					$v = implode(',', $v);
				}

				if ($indexed)
				{
					$sql_string = preg_replace('/\?/', $v, $sql_string, 1);
				}
				else
				{
					if ($k[0] != ':')
					{
						$k = ':' . $k; //add leading colon if it was left out
					}
					$sql_string = str_replace($k, $v, $sql_string);
				}
			}
		}
		return $sql_string;
	}

}

/**
 * The template object takes a valid path to a template file as the only argument
 * in the constructor. You can then assign properties to the template, which
 * become available as local variables in the template file. You can then call
 * display() to get the output of the template, or just call print on the template
 * directly thanks to PHP 5's __toString magic method.
 *
 * echo new View('my_template',array(
 *  'title' => 'My Title',
 *  'body' => 'My body content'
 * ));
 *
 * my_template.php might look like this:
 *
 * <html>
 * <head>
 *  <title><?php echo $title;?></title>
 * </head>
 * <body>
 *  <h1><?php echo $title;?></h1>
 *  <p><?php echo $body;?></p>
 * </body>
 * </html>
 *
 * Using view helpers:
 *
 * use_helper('HelperName', 'OtherHelperName');
 */
class View
{

	private $file;  // String of template file
	private $vars = array(); // Array of template variables
	static private $snippets = array(); // Array of snippet filenames

	/**
	 * Assign the template path
	 *
	 * @param string $file Template path (absolute path or path relative to the templates dir)
	 * @return void
	 */
	public function __construct($file, $vars = false)
	{

		$this->file = APP_PATH . '/views/' . ltrim($file, '/') . '.php';

		// check if view is in spec. language
		if (isset($vars['VIEW_LNG']))
		{
			// check for language spc. view
			$file = APP_PATH . '/views/' . ltrim($file, '/') . '-' . $vars['VIEW_LNG'] . '.php';
			if (file_exists($file))
			{
				$this->file = $file;
			}
		}


		$template_file = Theme::file($file);
		if (file_exists($template_file))
		{
			$this->file = $template_file;
		}


		if (!file_exists($this->file))
		{
			throw new Exception("View '{$this->file}' not found!");
		}

		if ($vars !== false)
		{
			$this->vars = $vars;
		}
	}

	/**
	 * Assign specific variable to the template
	 *
	 * @param mixed $name Variable name
	 * @param mixed $value Variable value
	 * @return void
	 */
	public function assign($name, $value = null)
	{
		if (is_array($name))
		{
			array_merge($this->vars, $name);
		}
		else
		{
			$this->vars[$name] = $value;
		}
	}

// assign

	/**
	 * Display template and return output as string
	 *
	 * @return string content of compiled view template
	 */
	public function render()
	{
		ob_start();

		extract($this->vars, EXTR_SKIP);
		include $this->file;

		$content = ob_get_clean();
		return $content;
	}

	/**
	 * Render given file as snippet. Used to render same code several times in a loop in different files
	 *
	 * @param string $file name
	 * @param array $vars of variables tu be used in snippet
	 * @return string content of compiled view template
	 */
	public static function renderAsSnippet($file, $vars = false)
	{
		// check if snippet loaded before
		if (!isset(self::$snippets['file'][$file]))
		{
			$_file = APP_PATH . '/views/' . ltrim($file, '/') . '.php';

			$template_file = Theme::file($file);
			if (file_exists($template_file))
			{
				$_file = $template_file;
			}


			// check if file exists
			if (!file_exists($_file))
			{
				throw new Exception("View '{$file}' not found!");
			}

			self::$snippets['file'][$file] = file_get_contents($_file);
		}

		ob_start();
		if ($vars)
		{
			extract($vars, EXTR_SKIP);
		}
		eval('?>' . self::$snippets['file'][$file]);
		$content = ob_get_clean();

		return $content;
	}

	/**
	 * Display the rendered template
	 */
	public function display()
	{
		echo $this->render();
	}

	/**
	 * Render the content and return it
	 * ex: echo new View('blog', array('title' => 'My title'));
	 *
	 * @return string content of the view
	 */
	public function __toString()
	{
		return $this->render();
	}

	/**
	 * return validation object 
	 * 
	 * @return Validation
	 */
	public function validation()
	{
		return Validation::getInstance();
	}

	public function input()
	{
		return Input::getInstance();
	}

	public static function escape($var)
	{
		$_escape = 'htmlspecialchars';
		$_encoding = 'UTF-8';
		$_ent = ENT_COMPAT;

		// to prevent double encooding run html_entities_decode first 
		$var = html_entity_decode($var, $_ent, $_encoding);

		// then encode
		return htmlspecialchars($var, $_ent, $_encoding);

		/*
		  if(in_array($_escape, array('htmlspecialchars', 'htmlentities')))
		  {
		  return call_user_func($_escape, $var, ENT_COMPAT, $_encoding);
		  }
		  return call_user_func($_escape, $var);
		 */
	}

}

// end View class

/**
 * The Controller class should be the parent class of all of your Controller sub classes
 * that contain the business logic of your application (render a blog post, log a user in,
 * delete something and redirect, etc).
 *
 * In the Frog class you can define what urls / routes map to what Controllers and
 * methods. Each method can either:
 *
 * - return a string response
 * - redirect to another method
 */
class Controller
{

	protected $layout = false;
	protected $layout_vars = array();

	public function execute($action, $params)
	{
		// it's a private method of the class or action is not a method of the class
		if (substr($action, 0, 1) == '_' || !method_exists($this, $action))
		{
			throw new Exception("Action '{$action}' is not valid!");
		}
		call_user_func_array(array($this, $action), $params);
	}

	public function setLayout($layout)
	{
		$this->layout = $layout;
	}

	/**
	 * Set page meta content 
	 * 
	 * @param string $type css|javascript|javascript_inline or header, description, other meta tags 
	 * @param type $str
	 */
	function setMeta($type = 'javascript', $str)
	{
		$this->layout_vars['meta'];

		if (!$this->layout_vars['meta'])
		{
			$this->layout_vars['meta'] = new stdClass();
		}

		switch ($type)
		{
			case 'css':
				$this->layout_vars['meta']->css[$str] = '<link href="' . $str . '" rel="stylesheet" type="text/css" />';
				break;
			case 'css_other':
				$this->layout_vars['meta']->css[md5($str)] = $str;
				break;
			case 'javascript':
				$this->layout_vars['meta']->javascript[$str] = '<script type="text/javascript" src="' . $str . '"></script>';
				break;
			case 'javascript_other':
				$this->layout_vars['meta']->javascript[md5($str)] = $str;
				break;
			case 'header_other':
				$this->layout_vars['meta']->header_other .= $str;
				break;
			case 'body_class':
				$this->layout_vars['meta']->body_class[$str] = $str;
				break;
			default:
				$this->layout_vars['meta']->{$type} = $str;
		}
	}

	public function assignToLayout($var, $value = '')
	{
		if (is_array($var))
		{
			$this->layout_vars = array_merge($this->layout_vars, $var);
		}
		else
		{
			$this->layout_vars[$var] = $value;
		}
	}

	public function render($view = null, $vars = array())
	{
		// merge with predefined vars
		$this->assignToLayout($vars);

		if ($this->layout)
		{
			// render view and layout
			if (!is_null($view))
			{
				$this->layout_vars['content_for_layout'] = new View($view, $this->layout_vars);
			}
			return new View('../layouts/' . $this->layout, $this->layout_vars);
		}
		else
		{
			// render view 
			return new View($view, $this->layout_vars);
		}
	}

	public function display($view, $vars = array(), $exit = true)
	{
		echo $this->render($view, $vars);

		if ($exit)
		{
			exit;
		}
	}

	/**
	 * Display JSON data, encode if required
	 * 
	 * @param type $data
	 * @param bool $encode false, encode given data or not 
	 * @return type
	 */
	public function displayJSON($data, $encode = false)
	{
		if ($encode)
		{
			$data = TextTransform::jsonEncode($data);
		}

		header('Content-Type: application/json');
		echo $data;
		exit;
	}

	public function validation()
	{
		return Validation::getInstance();
	}

	public function input()
	{
		return Input::getInstance();
	}

}

// end Controller class

final class Observer
{

	static protected $events = array();

	public static function observe($event_name, $callback)
	{
		if (!isset(self::$events[$event_name]))
			self::$events[$event_name] = array();

		self::$events[$event_name][$callback] = $callback;
	}

	public static function stopObserving($event_name, $callback)
	{
		if (isset(self::$events[$event_name][$callback]))
			unset(self::$events[$event_name][$callback]);
	}

	public static function clearObservers($event_name)
	{
		self::$events[$event_name] = array();
	}

	public static function getObserverList($event_name)
	{
		return (isset(self::$events[$event_name])) ? self::$events[$event_name] : array();
	}

	/**
	 * If your event does not need to process the return values from any observers use this instead of getObserverList()
	 */
	public static function notify($event_name)
	{
		$args = array_slice(func_get_args(), 1); // removing event name from the arguments

		foreach (self::getObserverList($event_name) as $callback)
			call_user_func_array($callback, $args);
	}

}

/**
 * The AutoLoader class is an object oriented hook into PHP's __autoload functionality. You can add
 *
 * - Single Files AutoLoader::addFile('Blog','/path/to/Blog.php');
 * - Multiple Files AutoLoader::addFile(array('Blog'=>'/path/to/Blog.php','Post'=>'/path/to/Post.php'));
 * - Whole Folders AutoLoader::addFolder('path');
 *
 * When adding a whole folder each file should contain one class named the same as the file without ".php" (Blog => Blog.php)
 */
class AutoLoader
{

	protected static $files = array();
	protected static $folders = array();

	/**
	 * AutoLoader::addFile('Blog','/path/to/Blog.php');
	 * AutoLoader::addFile(array('Blog'=>'/path/to/Blog.php','Post'=>'/path/to/Post.php'));
	 * @param mixed $class_name string class name, or array of class name => file path pairs.
	 * @param mixed $file Full path to the file that contains $class_name.
	 */
	public static function addFile($class_name, $file = null)
	{
		if ($file == null && is_array($class_name))
		{
			self::$files = array_merge(self::$files, $class_name);
		}
		else
		{
			self::$files[$class_name] = $file;
		}
	}

	/**
	 * AutoLoader::addFolder('/path/to/my_classes/');
	 * AutoLoader::addFolder(array('/path/to/my_classes/','/more_classes/over/here/'));
	 * @param mixed $folder string, full path to a folder containing class files, or array of paths.
	 */
	public static function addFolder($folder)
	{
		if (!is_array($folder))
		{
			$folder = array($folder);
		}

		self::$folders = array_merge(self::$folders, $folder);
	}

	public static function load($class_name)
	{
		if (isset(self::$files[$class_name]))
		{
			if (file_exists(self::$files[$class_name]))
			{
				require self::$files[$class_name];
				return;
			}
		}
		else
		{
			foreach (self::$folders as $folder)
			{
				$folder = rtrim($folder, DIRECTORY_SEPARATOR);
				$file = $folder . DIRECTORY_SEPARATOR . $class_name . '.php';
				if (file_exists($file))
				{
					require $file;
					return;
				}
			}
		}
		throw new Exception("AutoLoader did not found file for '{$class_name}'!");
	}

}

// end AutoLoader class

if (!function_exists('__my_autoload'))
{
	AutoLoader::addFolder(array(APP_PATH . DIRECTORY_SEPARATOR . 'models',
		APP_PATH . DIRECTORY_SEPARATOR . 'controllers',
		HELPER_PATH));

	function __my_autoload($class_name)
	{
		//echo '[__my_autoload]';
		AutoLoader::load($class_name);
	}

	// check if spl_autoload_register() exists
	if (function_exists('spl_autoload_register'))
	{
		//echo '[spl_autoload_register]';
		spl_autoload_register('__my_autoload');
	}
	else
	{

		/* function __autoload($class_name)
		  {
		  //echo '[__autoload]';
		  __my_autoload($class_name);
		  } */
	}
}

/**
 * Flash service
 *
 * Purpose of this service is to make some data available across pages. Flash
 * data is available on the next page but deleted when execution reach its end.
 *
 * Usual use of Flash is to make possible that current page pass some data
 * to the next one (for instance success or error message before HTTP redirect).
 *
 * Flash::set('errors', 'Blog not found!');
 * Flass::set('success', 'Blog have been saved with success!');
 * Flash::get('success');
 *
 * You can only set 20 cookies per domain
 *
 * Flash service as a concep is taken from Rails. This thing is really useful!
 */
final class Flash
{

	const COOKIE_KEY = 'framework_flash_';
	const COOKIE_LIFE = 86400; // 1 day

	private static $_previous = array(); // Data that prevous page left in the Flash

	/**
	 * Return specific variable from the flash. If value is not found NULL is
	 * returned
	 *
	 * @param string $var Variable name
	 * @return mixed
	 */
	public static function get($var)
	{
		return isset(self::$_previous[self::COOKIE_KEY . $var]) ? base64_decode(self::$_previous[self::COOKIE_KEY . $var]) : null;
	}

	/**
	 * Add specific variable to the flash. This variable will be available on the
	 * next page unlease removed with the removeVariable() or clear() method
	 *
	 * @param string $var Variable name
	 * @param mixed $value Variable value
	 * @return void
	 */
	public static function set($var, $value)
	{
		$time = $_SERVER['REQUEST_TIME'] + self::COOKIE_LIFE;
		self::setCookie(self::COOKIE_KEY . $var, $value, $time);
	}

// set

	/**
	 * Call this function to clear flash. Note that data that previous page
	 * stored will not be deleted - just the data that this page saved for
	 * the next page
	 *
	 * @param none
	 * @return void
	 */
	public static function clear()
	{
		if (!empty($_COOKIE) && is_array($_COOKIE))
		{
			foreach ($_COOKIE as $key => $val)
			{
				if (strpos($key, self::COOKIE_KEY) !== false)
				{
					self::clearCookie($key);
				}
			}
		}
	}

// clear

	/**
	 * This function will read flash data from the $_SESSION variable
	 * and load it into $this->previous array
	 *
	 * @param none
	 * @return void
	 */
	public static function init()
	{
		// Get flash data...
		if (!empty($_COOKIE) && is_array($_COOKIE))
		{
			self::$_previous = $_COOKIE;
		}
		self::clear();
	}

	public static function getCookie($name)
	{
		return base64_decode($_COOKIE[$name]);
	}

	public static function clearCookie($name)
	{
		$time = $_SERVER['REQUEST_TIME'] - 3600;
		unset($_COOKIE[$name]);
		self::setCookie($name, '', $time);
	}

	public static function emptyCookie($name)
	{
		unset($_COOKIE[$name]);
		self::setCookie($name, '');
	}

	/**
	 * sets cookie. value base64 encoded.
	 *
	 * @param string $name
	 * @param string $value
	 * @param int $time 0: expires when browser closed, or time
	 * @param string $domain use NULL to set for current domain, '' for all domains (general), or any specific domain
	 */
	public static function setCookie($name, $value, $time = 0, $domain = '')
	{
		$value = base64_encode($value);

		if ($domain === '')
		{
			$domain = COOKIE_DOMAIN;
		}

		$_COOKIE[$name] = $value;

		setcookie($name, $value, $time, '/', $domain, (isset($_ENV['SERVER_PROTOCOL']) && (strpos($_ENV['SERVER_PROTOCOL'], 'https') || strpos($_ENV['SERVER_PROTOCOL'], 'HTTPS'))));
	}

}

// end Flash class

final class Inflector
{

	/**
	 *  Return an CamelizeSyntaxed (LikeThisDearReader) from something like_this_dear_reader.
	 *
	 * @param string $string Word to camelize
	 * @return string Camelized word. LikeThis.
	 */
	public static function camelize($string)
	{
		return str_replace(' ', '', ucwords(str_replace('_', ' ', $string)));
	}

	/**
	 * Return an underscore_syntaxed (like_this_dear_reader) from something LikeThisDearReader.
	 *
	 * @param  string $string CamelCased word to be "underscorized"
	 * @return string Underscored version of the $string
	 */
	public static function underscore($string)
	{
		return strtolower(preg_replace('/(?<=\\w)([A-Z])/', '_\\1', $string));
	}

	/**
	 * Return an Humanized syntaxed (Like this dear reader) from something like_this_dear_reader.
	 *
	 * @param  string $string CamelCased word to be "underscorized"
	 * @return string Underscored version of the $string
	 */
	public static function humanize($string)
	{
		return ucfirst(str_replace('_', ' ', $string));
	}

	/**
	 * return gogle friendly readible url
	 *
	 * @param  string $string
	 * @return string $string
	 */
	public static function slugify($str)
	{
		$str = preg_replace("/[^a-zA-Z0-9- ]/", "", $str);
		$str = strtolower(str_replace(" ", "-", trim($str)));
		// replace several --- chars
		$str = preg_replace('|-+|', '-', $str);
		return trim($str, '-');
	}

	public static function utf8Substr($str, $from, $len)
	{
		# utf8 substr
		# www.yeap.lv
		return preg_replace('#^(?:[\x00-\x7F]|[\xC0-\xFF][\x80-\xBF]+){0,' . $from . '}' .
				'((?:[\x00-\x7F]|[\xC0-\xFF][\x80-\xBF]+){0,' . $len . '}).*#s', '$1', $str);
	}

}

// ----------------------------------------------------------------
//   global function
// ----------------------------------------------------------------

/**
 * Load all functions from the helper file
 *
 * syntax:
 * use_helper('Cookie');
 * use_helper('Number', 'Javascript', 'Cookie', ...);
 *
 * @param  string helpers in CamelCase
 * @return void
 */
function use_helper()
{
	static $_helpers = array();

	$helpers = func_get_args();

	foreach ($helpers as $helper)
	{
		if (in_array($helper, $_helpers))
			continue;

		$helper_file = HELPER_PATH . DIRECTORY_SEPARATOR . $helper . '.php';

		if (!file_exists($helper_file))
		{
			throw new Exception("Helper file '{$helper}' not found!");
		}

		include $helper_file;
		$_helpers[] = $helper;
	}
}

/**
 * Load model class from the model file (faster then waiting for the __autoload function)
 *
 * syntax:
 * use_model('Blog');
 * use_model('Post', 'Category', 'Tag', ...);
 *
 * @param  string models in CamelCase
 * @return void
 */
function use_model()
{
	static $_models = array();

	$models = func_get_args();

	foreach ($models as $model)
	{
		if (in_array($model, $_models))
			continue;

		$model_file = APP_PATH . DIRECTORY_SEPARATOR . 'models' . DIRECTORY_SEPARATOR . $model . '.php';

		if (!file_exists($model_file))
		{
			throw new Exception("Model file '{$model}' not found!");
		}

		include $model_file;
		$_models[] = $model;
	}
}

/**
 * Load file cnotents once
 *
 * syntax:
 * use_file('Zend/Cache.php',...);
 *
 * @param  string files
 * @return void
 */
function use_file()
{
	static $_files = array();

	$files = func_get_args();

	foreach ($files as $file)
	{
		if ($_files[$file])
			continue;

		/* if ( ! file_exists($file)) {
		  throw new Exception("File '{$file}' not found!");
		  } */

		include $file;
		$_files[$file] = 1;
	}
}

/**
 * create a real nice url like http://www.example.com/controller/action/params#anchor
 *
 * you can put many params as you want,
 * if a params start with # it is considerated a Anchor
 *
 * get_url('controller/action/param1/param2') // I always use this method
 * get_url('controller', 'action', 'param1', 'param2');
 *
 * @param string conrtoller, action, param and/or #anchor
 * @return string
 */
function get_url()
{
	$base_url = BASE_URL;
	$append = '';

	$params = func_get_args();
	if (count($params) === 1)
	{
		$append = $params[0];
	}
	else
	{
		$url = '';
		foreach ($params as $param)
		{
			if (strlen($param))
			{
				$url .= $param{0} == '#' ? $param : '/' . $param;
			}
		}
		$append = preg_replace('/^\/(.*)$/', '$1', $url);
	}

	return $base_url . $append;
}

/**
 * Get the request method used to send this page
 *
 * @return string possible value: GET, POST or AJAX
 */
function get_request_method()
{
	if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] == 'XMLHttpRequest')
		return 'AJAX';
	else if (!empty($_POST))
		return 'POST';
	else
		return 'GET';
}

/**
 * Redirect this page to the url passed in param
 * 
 * @param string $url
 * @param boolean $permanent ads header HTTP/1.1 301 Moved Permanently
 */
function redirect($url, $permanent = false)
{
	if ($permanent)
	{
		// Permanent redirection
		header("HTTP/1.1 301 Moved Permanently");
	}

	header('Location: ' . $url);
	exit;
}

function trigger_error_with_context($error_msg, $error_type, $context = 1)
{
	$stack = debug_backtrace();
	for ($i = 0; $i < $context; $i++)
	{
		if (false === ($frame = next($stack)))
		{
			break;
		}
		$error_msg .= ", from " . $frame['function'] . ':' . $frame['file'] . ' line ' . $frame['line'];
	}
	return trigger_error($error_msg, $error_type);
}

/**
 * Alias for redirect
 */
function redirect_to($url)
{
	header('Location: ' . $url);
	exit;
}

/**
 * Encodes HTML safely for UTF-8. Use instead of htmlentities.
 */
function html_encode($string)
{
	return htmlentities($string, ENT_QUOTES, 'UTF-8');
}

/**
 * Display a 404 page not found and exit
 */
function page_not_found()
{
	header_404();
	echo new View('404');
	exit;
}

function header_404()
{
	ob_start();
	header('HTTP/1.0 404 Not Found');
}

function header_200()
{
	ob_start();
	header('HTTP/1.1 200 OK');
}

function header_nocache()
{
	header("Expires: Sat, 26 Jul 1997 05:00:00 GMT"); // Date in the past
	header('Cache-Control: no-store, no-cache, must-revalidate'); // HTTP/1.1
	//header('Cache-Control: pre-check=0, post-check=0, max-age=0');
	//header('Pragma: no-cache');
}

function header_cache($cache_browser = null)
{
	if (is_null($cache_browser))
	{
		// cache for 1 month
		$cache_browser = 3600 * 24 * 30;
	}
	// nothing to save then cache this result in browser
	header("Expires: " . gmdate("D, j M Y H:i:s", REQUEST_TIME + $cache_browser) . " GMT"); // Date in the future
	header("Pragma: cache");
	header("Cache-Control: public, max-age=" . $cache_browser, true);
}

function header_503()
{
	header('HTTP/1.1 503 Service Temporarily Unavailable');
	header('Status: 503 Service Temporarily Unavailable');
	header('Retry-After: 7200');
	header('X-Powered-By:');
}

function page_503()
{
	ob_start();
	header_503();
	?>
	<!DOCTYPE HTML PUBLIC "-//IETF//DTD HTML 2.0//EN">
	<html>
		<head>
			<title>503 Service Temporarily Unavailable</title>
		</head>
		<body>
			<div style="text-align: center; margin: 40px;">

				<h2>503 Service Temporarily Unavailable</h2>


			</div>
		</body>
	</html>
	<?php
	exit;
}

function convert_size($num)
{
	if ($num >= 1073741824)
		$num = round($num / 1073741824 * 100) / 100 . ' gb';
	else if ($num >= 1048576)
		$num = round($num / 1048576 * 100) / 100 . ' mb';
	else if ($num >= 1024)
		$num = round($num / 1024 * 100) / 100 . ' kb';
	else
		$num .= ' b';
	return $num;
}

/**
 * Shortens a number and attaches K, M, B, etc. accordingly
 * 
 * @param int $number
 * @param int $precision
 * @param array $divisors
 * @return string
 */
function number_shorten($number, $precision = 3, $divisors = null)
{
	// Setup default $divisors if not provided
	if (!isset($divisors))
	{
		$divisors = array(
			pow(1000, 0) => '', // 1000^0 == 1
			pow(1000, 1) => 'K', // Thousand
			pow(1000, 2) => 'M', // Million
			pow(1000, 3) => 'B', // Billion
			pow(1000, 4) => 'T', // Trillion
			pow(1000, 5) => 'Qa', // Quadrillion
			pow(1000, 6) => 'Qi', // Quintillion
		);
	}

	// Loop through each $divisor and find the
	// lowest amount that matches
	foreach ($divisors as $divisor => $shorthand)
	{
		if (abs($number) < ($divisor * 1000))
		{
			// We found a match!
			break;
		}
	}

	// We found our match, or there were no matches.
	// Either way, use the last defined value for $divisor.
	return number_format($number / $divisor, $precision) . $shorthand;
}

// information about time and memory

function memory_usage($real = false)
{
	$return = @memory_get_peak_usage($real);
	if (!$return)
	{
		$return = @memory_get_usage($real);
	}
	return convert_size($return);
}

function execution_time()
{
	return sprintf("%01.4f", get_microtime() - FRAMEWORK_STARTING_MICROTIME);
}

function get_microtime()
{
	$time = explode(' ', microtime());
	return doubleval($time[0]) + $time[1];
}

function odd_even()
{
	static $odd = true;
	return ($odd = !$odd) ? 'even' : 'odd';
}

function even_odd()
{
	return odd_even();
}

/**
 * Provides a nice print out of the stack trace when an exception is thrown.
 *
 * @param Exception $e Exception object.
 */
function framework_exception_handler($e)
{
	if (!DEBUG)
		page_not_found();

	header_404();

	echo '<style>h1,h2,h3,p,td {font-family:Verdana; font-weight:lighter;}</style>';
	echo '<p>Uncaught ' . get_class($e) . '</p>';
	echo '<h1>' . $e->getMessage() . '</h1>';

	$traces = $e->getTrace();
	if (count($traces) > 1)
	{
		echo '<p><b>Trace in execution order:</b></p>' .
		'<pre style="font-family:Verdana; line-height: 20px">';

		$level = 0;
		foreach (array_reverse($traces) as $trace)
		{
			++$level;

			if (isset($trace['class']))
				echo $trace['class'] . '&rarr;';

			$args = array();
			if (!empty($trace['args']))
			{
				foreach ($trace['args'] as $arg)
				{
					if (is_null($arg))
						$args[] = 'null';
					else if (is_array($arg))
						$args[] = 'array[' . sizeof($arg) . ']';
					else if (is_object($arg))
						$args[] = get_class($arg) . ' Object';
					else if (is_bool($arg))
						$args[] = $arg ? 'true' : 'false';
					else if (is_int($arg))
						$args[] = $arg;
					else
					{
						$arg = htmlspecialchars(substr($arg, 0, 64));
						if (strlen($arg) >= 64)
							$arg .= '...';
						$args[] = "'" . $arg . "'";
					}
				}
			}
			echo '<b>' . $trace['function'] . '</b>(' . implode(', ', $args) . ')  ';
			echo 'on line <code>' . (isset($trace['line']) ? $trace['line'] : 'unknown') . '</code> ';
			echo 'in <code>' . (isset($trace['file']) ? $trace['file'] : 'unknown') . "</code>\n";
			echo str_repeat("   ", $level);
		}
		echo '</pre>';
	}
	echo "<p>Exception was thrown on line <code>"
	. $e->getLine() . "</code> in <code>"
	. $e->getFile() . "</code></p>";

	$dispatcher_status = Dispatcher::getStatus();
	$dispatcher_status['request method'] = get_request_method();
	debug_table($dispatcher_status, 'Dispatcher status');
	if (!empty($_GET))
		debug_table($_GET, 'GET');
	if (!empty($_POST))
		debug_table($_POST, 'POST');
	if (!empty($_COOKIE))
		debug_table($_COOKIE, 'COOKIE');
	debug_table($_SERVER, 'SERVER');
}

function debug_table($array, $label, $key_label = 'Variable', $value_label = 'Value')
{
	echo '<h2>' . $label . '</h2>';
	echo '<table cellpadding="3" cellspacing="0" style="width: 800px; border: 1px solid #ccc">';
	echo '<tr><td style="border-right: 1px solid #ccc; border-bottom: 1px solid #ccc;">' . $key_label . '</td>' .
	'<td style="border-bottom: 1px solid #ccc;">' . $value_label . '</td></tr>';

	foreach ($array as $key => $value)
	{
		if (is_null($value))
			$value = 'null';
		else if (is_array($value))
			$value = 'array[' . sizeof($value) . ']';
		else if (is_object($value))
			$value = get_class($value) . ' Object';
		else if (is_bool($value))
			$value = $value ? 'true' : 'false';
		else if (is_int($value))
			$value = $value;
		else
		{
			$value = htmlspecialchars(substr($value, 0, 64));
			if (strlen($value) >= 64)
				$value .= ' &hellip;';
		}

		echo '<tr><td><code>' . htmlspecialchars($key) . '</code></td><td><code>' . $value . '</code></td></tr>';
	}
	echo '</table>';
}

function debug_dump($array, $label, $key_label = 'Variable', $value_label = 'Value')
{
	echo '<h2>' . $label . '</h2>';
	echo '<pre>';

	foreach ($array as $key => $value)
	{
		echo '<tr><td><code> ' . $key . ' </code></td><td><code> ';

		if (is_null($value))
			echo 'null';
		else if (is_array($value))
			var_dump($value);
		else if (is_object($value))
			var_dump($value);
		else if (is_bool($value))
			echo $value ? 'true' : 'false';
		else if (is_int($value))
			echo $value;
		else
		{
			$value = htmlspecialchars(substr($value, 0, 64));
			if (strlen($value) >= 64)
				$value .= ' &hellip;';
			echo $value;
		}
		echo ' </code></td></tr>';
	}
	echo '</pre>';
}

set_exception_handler('framework_exception_handler');

/**
 * This function will strip slashes if magic quotes is enabled so
 * all input data ($_GET, $_POST, $_COOKIE) is free of slashes
 */
function fix_input_quotes()
{
	$_GET = strip_magic_quotes($_GET, true);
	$_POST = strip_magic_quotes($_POST, true);
	$_COOKIE = strip_magic_quotes($_COOKIE, true);
}

// Strip slashes from an array.
function strip_magic_quotes($array, $force = false)
{
	if ($force || (function_exists('get_magic_quotes_gpc') && @get_magic_quotes_gpc()))
	{
		return stripslashes_array($array);
	}
	return $array;
}

function stripslashes_array($array)
{
	return is_array($array) ? array_map('stripslashes_array', $array) : stripslashes($array);
}

// fix_input_quotes

if (function_exists('get_magic_quotes_gpc') && @get_magic_quotes_gpc())
{
	fix_input_quotes();
}

/* general functions */

function ife($condition, $true, $false = '')
{
	if ($condition)
	{
		return $true;
	}
	else
	{
		return $false;
	}
}

// benchmark
Benchmark::cp('START(framework end)');

//Benchmark::$active=true;
//Benchmark::setNoCache(true);


function display_benchmark($dev_only = true)
{
	if (!$dev_only || ($dev_only && is_dev()) || (Config::option('debug_mode') && AuthUser::hasPermission(User::PERMISSION_ADMIN)))
	{
		echo Benchmark::report();
		//debug_dump(Record::$__QUERIES__, '__QUERIES__');
	}

	//AuthUser::isLoggedIn(false);
	//var_dump(AuthUser::$user);
}

//register_shutdown_function('display_benchmark');
