<?php
/** class.db_handling.php
 *
 * @version 0.6
 * @author Simon Samtleben
 *
 *  [description]
 *
 *  A simple mysql handler.
 *  This class is a singleton!
 *
 * [changelog]
 *
 *		2009-09-11 by Simon Samtleben
 *		Feature: Escaping of %-chars in querys added.
 *
 *		2009-07-30 by Simon Samtleben:
 *		Bugfix: Used "real_connect" method to support utf-8.
 *
 *		2009-07-25 by Simon Samtleben:
 *		Added pop parameter to get_result method.
 *		Added method get_insert_id.
 *
 *
 * [method index]
 *
 *		private __construct()
 *		private __clone()
 *		public __destruct()
 *		public get_instance()
 *		public connect()
 *		public disconnect()
 *		public query()
 *		public get_insert_id()
 *		public get_result()
 *		public get_result_count()
 *		public get_error()
 *		public prepare()
 */

class db_handling
{
	private static $instance = null;
	private $db_config = null;
	private $mysqli = null;
	private $result = null;

	private function __construct($db_config = null)
	{
		if($db_config !== null)
		{
			$this->db_config = $db_config;
			$this->mysqli = mysqli_init();
			$this->mysqli->real_connect($db_config['db_host'], $db_config['db_user'], $db_config['db_pass'], $db_config['db']);
			$this->mysqli->set_charset("utf8");
		}
	}

	private function __clone() {}

	public function __destruct()
	{
		$this->disconnect();
		unset($this->mysqli, $this->db_config, $this->result);
	}

	/** Use instead of constructor to get instance of class.
	 *
	 * @param array $conf Connection information.
	 * @return object Instance of db_handling.
	 */
	public static function get_instance($conf = null)
	{
		if(self::$instance === null)
		{
			self::$instance = new db_handling($conf);
		}
		return self::$instance;
	}

	/** Connect to a mysql database using mysqli.
	 *
	 * @param array $db_config Connection information.
	 */
	public function connect($db_config = null)
	{
		$this->db_config = $db_config;
		$this->mysqli = mysqli_init();
		$this->mysqli->real_connect($db_config['db_host'], $db_config['db_user'], $db_config['db_pass'], $db_config['db']);
		$this->mysqli->set_charset("utf8");
	}

	/** Close database connection.
	 */
	public function disconnect()
	{
		if($this->mysqli !== null)
		{
			$this->mysqli->close();
		}
	}

	/** Run a query on database.
	 *
	 * @param string $query An sql query.
	 * @return bool True on suceess false on error.
	 */
	public function query($query)
	{
		$this->result = $this->mysqli->query($query);
		return ($this->result === false) ? false : true;
	}

	/** Returns id of last insert operation.
	 *
	 * @return int Id of last insert operation.
	 */
	public function get_insert_id()
	{
		return $this->mysqli->insert_id;
	}

	/** Prepares and returns result of an sql query.
	 *
	 * @param bool $pop Removes "0"-Element from array if only one result row.
	 * @return array Result information.
	 */
	public function get_result($pop = false)
	{
		$result = array();
		while($row = $this->result->fetch_array(MYSQLI_ASSOC))
		{
			$result[] = $row;
		}

		if($this->result->num_rows == 1 && $pop === true)
		{
			$result = $result[0];
		}

		// strip slashes:
		array_walk_recursive($result , create_function('&$temp', '$temp = stripslashes($temp);'));

		return $result;
	}

	/** Returns number of result rows.
	 *
	 * @return int Number of results.
	 */
	public function get_result_count()
	{
		return $this->result->num_rows;
	}

	/** Returns mysqli error message.
	 *
	 * @return string Error message.
	 */
	public function get_error()
	{
		return $this->mysqli->error;
	}

	/** Replaces placeholders in a query-string with according values.
	 *
	 * @param string $query The query string.
	 * @param array $values Values to put into query-string.
	 * @return string Prepared query string.
	 */
	public function prepare($query, $values)
	{
		// mask escaped signs:
		$query = str_replace('\%', '{#}', $query);

		if(substr_count($query, '%s') + substr_count($query, '%d') != count($values))
		{
			return false;
		}

		// sanitize query:
		$query = str_replace("'%s'", '%s', $query);
		$query = str_replace('"%s"', '%s', $query);
		$query = str_replace("'%d'", '%d', $query);
		$query = str_replace('"%d"', '%d', $query);

		// quote strings:
		$query = str_replace('%s', "'%s'", $query);

		// add slashes:
		foreach(array_keys($values) as $key)
		{
			$values[$key] = $this->mysqli->real_escape_string($values[$key]);
		}

		// replace placeholders whith values from array:
		$query = vsprintf($query, $values);

		// unmask:
		$query = str_replace('{#}', '%', $query);

		return $query;
	}
}