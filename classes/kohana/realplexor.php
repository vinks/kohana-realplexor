<?php defined('SYSPATH') OR die('No direct access allowed.');
/**
 * Dklab_Realplexor PHP API for Kohana.
 *
 * @version 1.31
 */
class Kohana_Realplexor {
	
	protected static $_instance;

	/**
	 * Singleton pattern
	 *
	 * @return Realplexor
	 */
	public static function instance($group = 'default')
	{
		if ( ! isset(Realplexor::$_instance))
		{
			// Load the configuration for this type
			$config = Kohana::$config->load('realplexor')->get($group);
				
			Fire_Helper::log($config, 'Config');
				
			// Create a new realplexor instance
			Realplexor::$_instance = new Realplexor($config);
		}

		return Realplexor::$_instance;
	}

	/**
	 * Loads configuration options.
	 *
	 * @return  void
	 */
	public function __construct($config = array())
	{
		// Save the config in the object
		$this->_config = $config;
        	
		// Set namespace
		$this->_namespace = $config['namespace'];
		
		if ($config['login'])
        	$this->logon($config['login']);
	}
	
	/**
	 * Set login and password to access Realplexor (if the server needs it).
	 * This method does not check credentials correctness.
	 *
	 * @param string $login
	 * @param string $password
	 * @return void
	 */
	public function logon($login)
	{
		// All keys must always be login-prefixed!
		$this->_namespace = $login . '_' . $this->_namespace;
	}

	/**
	 * Send data to realplexor.
	 * 
	 * Example usage:
	 * ~~~ Send data to one channel
	 * Realplexor::instance()->send("Alpha", array("here" => "is", "any" => array("structured", "data")));
	 * ~~~ Send data to multiple channels at once.
	 * Realplexor::instance()->send(array("Alpha", "Beta"), "any data");
	 * ~~~ Send data limiting receivers.
	 * Realplexor::instance()->send("Alpha", "any data", array($id1, $id2, ...));
	 *
	 * @param mixed $ids		Target IDs in form of: array(id1 => cursor1, id2 => cursor2, ...)
	 *								of array(id1, id2, id3, ...). If sending to a single ID,
	 *								you may pass it as a plain string, not array.
	 * @param mixed $data		Data to be sent (any format, e.g. nested arrays are OK).
	 * @param array $showonly	Send this message to only those who also listen any of these IDs.
	 *								This parameter may be used to limit the visibility to a closed
	 *								number of cliens: give each client an unique ID and enumerate
	 *								client IDs in $showonly to not to send messages to others.
	 * @return void
	 */
	public function send($ids, $data, $showonly = null)
	{
		$data = json_encode($data);
		
		$pairs = array();
		
		foreach ((array)$ids as $id => $cursor)
		{
			if (is_int($id)) {
				$id = $cursor; // this is NOT cursor, but ID!
				$cursor = null;
			}
			
			if (!preg_match('/^\w+$/', $id))
				throw new Exception('Identifier must be alphanumeric, "' . $id . '" given');
				
			$id = $this->_namespace . $id;
			
			if ($cursor !== null)
			{
				if (!is_numeric($cursor))
					throw new Exception('Cursor must be numeric, "' . $cursor . '" given');
					
				$pairs[] = $cursor . ':' . $id;
			}
			else
			{
				$pairs[] = $id;
			}
		}
		if (is_array($showonly))
		{
			foreach ($showonly as $id) {
				$pairs[] = '*' . $this->_namespace . $id;
			}
		}
		
		$this->_send(join(',', $pairs), $data);
	}

	/**
	 * Return list of online IDs (keys) and number of online browsers
	 * for each ID. (Now "online" means "connected just now", it is
	 * very approximate; more precision is in TODO.)
	 *
	 * @param array $prefixes   If set, only online IDs with these prefixes are returned.
	 * @return array              List of matched online IDs (keys) and online counters (values).
	 */
	public function onlinecounters($prefixes = null)
	{
		// Add namespace.
		$prefixes = $prefixes !== null ? (array) $prefixes : array();
		
		if (strlen($this->_namespace))
		{
			if (!$prefixes)
				$prefixes = array(''); // if no prefix passed, we still need namespace prefix
			
			foreach ($prefixes as $i => $idp)
				$prefixes[$i] = $this->_namespace . $idp;
		}
		
		// Send command.
		$resp = $this->_sendcmd('online' . ($prefixes ? ' ' . join(' ', $prefixes) : ''));
		
		if (!strlen(trim($resp)))
			return array();
			
		// Parse the result and trim namespace.
		$result = array();
		foreach (explode("\n", $resp) as $line)
		{
			@list ($id, $counter) = explode(" ", $line);
			
			if (!strlen($id))
				continue;
				
			if (strlen($this->_namespace) && strpos($id, $this->_namespace) === 0)
				$id = substr($id, strlen($this->_namespace));
				
			$result[$id] = $counter;
		}
		
		return $result;
	}

	/**
	 * Return list of online channels IDs.
	 * 
	 * Example usage:
	 * ~~~ Get the list of all listened channels.
	 * $list = Realplexor::instance()->online();
	 * ~~~ Get the list of online channels which names are started with "id_" only. 
	 * $list = Realplexor::instance()->(array('id_'));
	 *
	 * @param array $prefixes	If set, only online IDs with these prefixes are returned.
	 * @return array			List of matched online IDs.
	 */
	public function online($prefixes = null)
	{
		return array_keys($this->onlinecounters($prefixes));
	}

	/**
	 * Return all Realplexor events (e.g. ID offline/offline changes)
	 * happened after $from cursor.
	 *
	 * @param string $from		Start watching from this cursor.
	 * @param array $prefixes	Watch only changes of IDs with these prefixes.
	 * @return array			List of array("event" => ..., "cursor" => ..., "id" => ...).
	 */
	public function watch($from = 0, $prefixes = null)
	{
		$prefixes = $prefixes !== null ? (array) $prefixes : array();
			
		if (!preg_match('/^[\d.]+$/', $from))
			throw new Exception("Position value must be numeric, \"$from\" given");

		// Add namespaces.
		if (strlen($this->_namespace))
		{
			if (!$prefixes)
				$prefixes = array(''); // if no prefix passed, we still need namespace prefix
				
			foreach ($prefixes as $i => $idp) {
				$prefixes[$i] = $this->_namespace . $idp;
			}
		}
		
		// Execute.
		$resp = $this->_sendcmd("watch $from" . ($prefixes? " " . join(" ", $prefixes) : ""));
		
		if (!trim($resp))
			return array();
		
		$resp = explode("\n", trim($resp));
		
		// Parse.
		$events = array();
		foreach ($resp as $line)
		{
			if (!preg_match('/^ (\w+) \s+ ([^:]+):(\S+) \s* $/sx', $line, $m))
			{
				trigger_error("Cannot parse the event: \"$line\"");
				continue;
			}
			
			list ($event, $pos, $id) = array($m[1], $m[2], $m[3]);
			
			// Cut off namespace.
			if ($from && strlen($this->_namespace) && strpos($id, $this->_namespace) === 0)
				$id = substr($id, strlen($this->_namespace));
				
			$events[] = array(
				'event' => $event,
				'pos'   => $pos,
				'id'    => $id,
			);
		}
		
		return $events;
	}

	/**
	 * Send IN command.
	 *
	 * @param string $cmd   Command to send.
	 * @return string       Server IN response.
	 */
	private function _sendcmd($cmd)
	{
		return $this->_send(null, "$cmd\n");
	}

	/**
	 * Send specified data to IN channel. Return response data.
	 * Throw Exception in case of error.
	 *
	 * @param string $identifier  If set, pass this identifier string.
	 * @param string $data        Data to be sent.
	 * @return string             Response from IN line.
	 */
	private function _send($identifier, $body)
	{
		// Build HTTP request.
		$headers = "X-Realplexor: {$this->_config['identifier']}="
			. ($this->_config['login'] ? $this->_config['login'] . ":" . $this->_config['password'] . '@' : '')
			. ($identifier? $identifier : "")
			. "\r\n";
			
		$data = ""
			. "POST / HTTP/1.1\r\n"
			. "Host: " . $this->_config['host'] . "\r\n"
			. "Content-Length: " . $this->_strlen($body) . "\r\n"
			. $headers
			. "\r\n"
			. $body;
			
			
		// Proceed with sending.
		$old = ini_get('track_errors');
		ini_set('track_errors', 1);
		
		$result = null;
		try {
			$host = $this->_config['port'] == 443 ? "ssl://" . $this->_config['host'] : $this->_config['host'];
			
			$fs = @fsockopen($host, $this->_config['port'], $errno, $errstr, $this->_config['timeout']);
			
			if (!$fs)
				throw new Exception("Error #$errno: $errstr");
				
			if (@fwrite($fs, $data) === false)
				throw new Exception($php_errormsg);
				
			if (!@stream_socket_shutdown($fs, STREAM_SHUT_WR))
			{
				throw new Exception($php_errormsg);
				break;
			}
					
			$result = @stream_get_contents($fs);
			
			if ($result === false)
				throw new Exception($php_errormsg);
				
			if (!@fclose($fs))
				throw new Exception($php_errormsg);
				
			ini_set('track_errors', $old);
			
		} catch (Exception $e) {
			ini_set('track_errors', $old);
			throw $e;
		}
		
		// Analyze the result.
		if ($result)
		{
			@list ($headers, $body) = preg_split('/\r?\n\r?\n/s', $result, 2);
			
			if (!preg_match('{^HTTP/[\d.]+ \s+ ((\d+) [^\r\n]*)}six', $headers, $m))
				throw new Exception("Non-HTTP response received:\n" . $result);
			
			if ($m[2] != 200)
				throw new Exception("Request failed: " . $m[1] . "\n" . $body);
				
			if (!preg_match('/^Content-Length: \s* (\d+)/mix', $headers, $m))
				throw new Exception("No Content-Length header in response headers:\n" . $headers);
				
			$needLen = $m[1];
			$recvLen = $this->_strlen($body);
			
			if ($needLen != $recvLen)
				throw new Exception("Response length ($recvLen) is different than specified in Content-Length header ($needLen): possibly broken response\n");
				
			return $body;
		}
		
		return $result;
	}

	/**
	 * Wrapper for mbstring-overloaded strlen().
	 *
	 * @param string $body
	 * @return int
	 */
	private function _strlen($body)
	{
		return function_exists('mb_orig_strlen')? mb_orig_strlen($body) : strlen($body);
	}

	
}
