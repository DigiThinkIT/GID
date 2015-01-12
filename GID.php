<?php
/*
The MIT License (MIT)

Copyright (c) 2014 DigiThinkIT

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in
all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
THE SOFTWARE.
*/

class GID {

	const pass = 'pass';

	private static $_inited = False;
	private static $_on_start_path = NULL;
	private static $_on_end_path = NULL;
	private static $_uri = array();
	private static $_dbug = false;

	public static $config = array();
	public static $path = NULL;
	public static $views_path = NULL;
	public static $pages_path = NULL;
	public static $domain = '';
	public static $path_prefix = '';

	public static function debug($on) {
		GID::$_dbug = $on;
	}

	public static function debug_check($check) {
		if ( $check === true ) {
			return GID::$_dbug !== false;
		}

		return is_array(GID::$_dbug) && array_search($check, GID::$_dbug);
	}

	public static function URL($path, $include_domain = True) {
		$base = '';
		if ( array_key_exists('domain', GID::$config ) ) {
			$base = (GID::is_secure()?'https://':'http://') . GID::$config['domain'];
		}

		return $base . GID::$path_prefix . $path;
	}

	public static function is_secure() {
		return isset($_SESSION['HTTPS']);
	}

	public static function redirect($url) {
		if ( SESSION::started() ) {
			SESSION::close();
		}
		header("Location: $url");
		exit();
	}

	public static function URI() {
		$uri_info = GID::parse_uri();
		# remove prefix from url to keep app paths clean and relative to $path_prefix
		if ( $uri_info['call_parts'][0] == GID::$path_prefix ) {
			array_shift($uri_info['call_parts']);
		}
		$uri_path = '/' . implode('/', $uri_info['call_parts']);
		return $uri_path;
	}

	public static function get_value_else($array, $key, $default = NULL) {
		if ( array_key_exists($key, $array) ) {
			return $array[$key];
		} 
			
		return $default;
	}

	public static function init($config = array()) {
		if ( !GID::$_inited ) {
			if ( array_key_exists('app_path', $config) ) {
				GID::$path = realpath($config['app_path']);
			} else {
				GID::$path = realpath(__DIR__);
			}

			if ( array_key_exists('domain', $config) ) {
				GID::$domain = $config['domain'];
			} else {
				GID::$domain = $_SERVER['HTTP_HOST'];
				$config['domain'] = GID::$domain;
			}

			if ( array_key_exists('path_prefix', $config) ) {
				GID::$path_prefix = $config['path_prefix'];
			}

			GID::$views_path = GID::get_value_else($config, 'views_path', GID::fs_path(GID::$path, 'views'));
			GID::$pages_path = GID::get_value_else($config, 'pages_path', GID::fs_path(GID::$path, 'pages'));

			set_include_path(get_include_path() . PATH_SEPARATOR . GID::fs_path(GID::$path, 'includes'));

			GID::$_on_start_path = GID::fs_path(GID::$path, 'on_start.php');
			GID::$_on_end_path = GID::fs_path(GID::$path, 'on_end.php');
			$config_path = GID::fs_path(GID::$path, 'config.php');

			if ( file_exists(GID::$_on_start_path) ) { include_once(GID::$_on_start_path); }
			if ( file_exists($config_path) ) { include_once $config_path; }

			register_shutdown_function('GID::shutdown');

			$uri_info = GID::parse_uri();

			#echo '<pre>'.print_r($uri_info,1).'</pre>';

			$uri_path = GID::URI();//implode('/', $uri_info['call_parts']);

			$call_stack = GID::uri_action(strtolower($_SERVER['REQUEST_METHOD']), $uri_path);
			#echo '<pre>'.print_r($call_stack,1).'</pre>';
			foreach($call_stack as $action) {
				if ( GID::debug_check('uri') ) {
					echo '<pre>';
					print_r($action);
					echo '</pre>';
				}

				if ( is_string($action) ) {
					list($script, $cls, $method) = preg_split('/\\s+|\\->/s', $action);
					$args = array();
				} else if ( is_array($action) ) {
					$action_part = array_shift($action);
					list($script, $cls, $method) = preg_split('/\\s+|\\->/s', $action_part);
					$args = $action;
				}

				require_once GID::fs_path(GID::$pages_path, $script);
				
				$instance = new $cls();
				
				$result = call_user_func_array(array($instance, $method), $args);

				// only pass to next script in call stack if current script returns true
				if ( $result == GID::pass ) {
					continue;
				} else {
					break;
				}
			}

			GID::$_inited = True;
	
		}
	}

	public static function uri_action($method, $uri_path) {
		$call_stack = array();
		$uri_combo = $method . ' ' . $uri_path;
		foreach(GID::$_uri as $pattern => $action) {
			#echo "<pre>[$pattern] | $uri_combo</pre>";
			if ( preg_match($pattern, $uri_combo, $match) ) {
				#echo "<pre>ACTION: ".print_r($action,1)."</pre>";
				#echo "<pre>";
				#print_r($match);
				#echo "</pre>";
				if ( is_array($action) ) {
					for($i=1; $i < sizeof($action); $i++) {
						$action[$i] = $match[$action[$i]];
					}
				}
				$call_stack[] = $action;
			}
		}
		return $call_stack;
	}
	
	public static function shutdown() {

		ob_flush();
		if ( file_exists(GID::$_on_end_path) ) { include_once(GID::$_on_end_path); }
	}

	public static function fs_path() {
		$parts = func_get_args();
		return join(DIRECTORY_SEPARATOR, $parts);
	}

	public static function map($map) {
		foreach($map as $key => $action) {

			if (strtolower($key) == '*') {
				$pattern = '/.*/si';
			} else {
				list($method, $pattern) = preg_split('/\\s+/', $key);
				$method = strtolower($method);
				$parts = explode('/', trim($pattern));
				$asm = array();
				foreach($parts as $p) {
					if ( strlen($p) > 0 && $p[0] == ':' ) {
						$asm[] = '(?P<'.substr($p, 1).'>[a-z0-9\-_]*)';
					} else if ( $p == '*' ) {
						$asm[] = '.*';
					} else {
						$asm[] = $p;
					}
				}
	
				$pattern = '/^(' . $method . ')\s+' . implode('\\/', $asm) . '\\/?' . '$/si' ;
			}
			GID::$_uri[$pattern] = $action;

		}

		#print_r(GID::$_uri);
	}

	public static function parse_uri() {
		$path = array();
		if (isset($_SERVER['REQUEST_URI'])) {
			$request_path = explode('?', $_SERVER['REQUEST_URI']);

			$path['base'] = rtrim(dirname($_SERVER['SCRIPT_NAME']), '\/');
			$path['call_utf8'] = substr(urldecode($request_path[0]), strlen($path['base']) + 1);
			$path['call'] = utf8_decode($path['call_utf8']);
			if ($path['call'] == basename($_SERVER['PHP_SELF'])) {
 				$path['call'] = '';
			}
			$path['call_parts'] = explode('/', $path['call']);
			
			if ( sizeof($request_path) > 1 ) {
				$path['query_utf8'] = urldecode($request_path[1]);
				$path['query'] = utf8_decode(urldecode($request_path[1]));
				$vars = explode('&', $path['query']);
				foreach ($vars as $var) {
					$t = explode('=', $var);
					$path['query_vars'][$t[0]] = $t[1];
				}
			} else {
				$path['query_utf8'] = NULL;
				$path['query'] = NULL;
				$path['query_vars'] = array();
			}
		}
		return $path;
	}


	/**
	 * Converts human readable byte sizes to bytes: EX: 1MB = 1024 bytes
	 */
	public static function humanToBytes($human) {
		$size = strtolower(substr($human, -2)); 
		$number = ((int)substr($human, 0, strlen($human)-2));
		if ( $size == 'kb' ) {
			return $number * 1024;
		} else if ( $size = 'mb' ) {
			return $number * 1024 * 1024;
		} else if ( $size = 'gb' ) {
			return $number * 1024 * 1024 * 1024;
		}
		
		return false;
	}

	/**
	 * Formats bytes to human redable sizes.
	 */
	public static function bytesToHuman($bytes, $precision = 2, $power = 1024)
	{
		$unit = array('B','KB','MB','GB','TB','PB','EB');
		return @round(
			$bytes / pow($power, ($i = floor(log($bytes, $power)))), $precision
		).' '.$unit[$i];
	}
}

class Sorting {

	public static function Dependency(&$ordered_list, $key, $dependencies) {
		
		$max = -1;
		foreach($dependencies as $depends) {
			$idx = array_search($depends, $ordered_list);
			if ( $idx !== false && $idx > $max ) { $max = $idx; }
		}
		
		if ( $max > -1 ) {
			#echo "insert $key at $max<br/>";
			array_splice($ordered_list, $max+1, 0, array($key));
		} else {
			#echo "insert $key at start<br/>";
			array_unshift($ordered_list, $key);
		}
	}

}

/**
 * Simple template helper class
 */
class T {

	private static $_vars = array();
	private static $_calls = 0;
	private static $_master = NULL;
	private static $_content = '';
	private static $_scripts = array();
	private static $_scripts_order = array();
	private static $_styles = array();
	private static $_styles_order = array();

	public static function add_style($name, $url, $dependencies=array()) {
		T::$_styles[$name] = $url;
		Sorting::Dependency(T::$_styles_order, $name, $dependencies);
	}

	public static function add_script($name, $url, $dependencies=array()) {
		T::$_scripts[$name] = $url;
		Sorting::Dependency(T::$_scripts_order, $name, $dependencies);
	}

	public static function URL($path, $use_domain = true) {
		echo GID::URL($path, $use_domain);
	}

	public static function set($var, $value) {
		T::$_vars[$var] = $value;
	}

	public static function master($master) {
		T::$_master = $master;
	}
	
	public static function view($view, $extra_vars = array()) {

		if ( $view == null || strlen(trim($view)) == 0 ) {
			echo "Invalid view name: Empty";
		}

		$saved = array();
		foreach($extra_vars as $key => $val) {
			if ( array_key_exists($key, T::$_vars) ) {
				$saved[$key] = T::$_vars[$key];
			}
			T::$_vars[$key] = $val;
		}

		T::$_calls++;
		ob_start();
		require GID::fs_path(GID::$views_path, $view . '.phtml');
		$content = ob_get_clean();

		foreach($saved as $key => $val) {
			T::$_vars[$key] = $val;
		}
		
		if ( T::$_calls == 1 ) {
			T::$_content = $content;
			require GID::fs_path(GID::$views_path, T::$_master . '.phtml');
		} else {
			echo $content;
		}
		T::$_calls--;
	}

	public static function &val($var, $default=false) {
		if ( array_key_exists($var, T::$_vars) ) {
			return T::$_vars[$var];
		} else {
			return $default;
		}
	}

	public static function out($var, $default=false, $callback=NULL) {
		if ( is_callable($callback) ) {
			$val = T::val($var, $default);
			$val = $callback($val);
			if ( is_string($val) ) {
				echo $val;
			} else {
				print_r($val);
			}
		} else {
			return T::get($var, true, $default);
		}
	}

	public static function get($var, $echo=True, $default = False) {
		if ( array_key_exists($var, T::$_vars) ) {
			if ( $echo ) {
				if ( is_string(T::$_vars[$var]) ) {
					echo T::$_vars[$var];
				} else {
					print_r(T::$_vars[$var]);
				}
			}
			return T::$_vars[$var];
		}

		if ( $echo ) {

			echo $default; 
		} else {

			return $default;

		}
	}

	/**
	 * Transforms a date value from one format to another
	 */
	public static function dateTransform($date, $old_format, $new_format, $add = null) {
		if ( $date == null ) {
			return null;
		}

		$t = DateTime::createFromFormat($old_format, $date);
		if ( $add != null ) {
			$t->add(DateInterval::createFromDateString($add));
		}

		return $t->format($new_format);
	}

	public static function has($var) {
		return array_key_exists($var, T::$_vars);
	}

	public static function content() {
		echo T::$_content;
	}

	public static function styles() {
		foreach(T::$_styles_order as $key) {
			$url = T::$_styles[$key];
			echo '<link href="'.$url.'" type="text/css" rel="stylesheet"/>'."\n";
		}
	}

	public static function scripts() {
		foreach(T::$_scripts_order as $key) {
			$url = T::$_scripts[$key];
			echo '<script src="'.$url.'" type="text/javascript"></script>'."\n";
		}
	}	
}

class Filter {
	private $_field;
	private $_allow_null = false;
	private $_allow_empty = false;
	private $_value = null;

	public function __construct($field) {
		$this->_field = $field;
		if ( !array_key_exists($this->_field, F::$fields) ) {
			F::$fields[$this->_field] = null;
		}

		$this->_is_array = is_array(F::$fields[$this->_field]);

		$this->_value = &F::$fields[$this->_field];
	}

	public function is_email($error) {
		
		$value = F::$fields[$this->_field];

		if ( $this->_allow_null && $value == null ) {
			return $this;
		}

		if ( $this->_allow_empty && empty($value) ) {
			return $this;
		}

		if ( !filter_var($value, FILTER_VALIDATE_EMAIL) ) {
			F::$errors[$this->_field][] = $error;
		}

		return $this;
	}

	private function _is_date($value, $format, $error, $key) {

		if ( $this->_allow_null && $value == null ) {
			return $this;
		}

		if ( $this->_allow_empty && empty($value) ) {
			return $this;
		}
		
		if ( is_array($format) ) {
			$fail = true;
			foreach($format as $fmt) {
				$test = DateTime::createFromFormat($fmt, $value);
				if ( ($test && $test->format($fmt) == $value) ) {
					$fail = false;
				}
			}

			if ( $fail ) {
				F::$errors[$this->_field . $key][] = $error;
			}
		} else {
			$test = DateTime::createFromFormat($format, $value);
			if ( !($test && $test->format($format) == $value) ) {
				F::$errors[$this->_field . $key][] = $error;
			}
		}
	}	

	public function is_date($format = 'm/d/Y', $error = "Date format is mm/dd/yyyy") {
		if ( $this->_is_array ) {
			foreach($this->_value as $key => $val) {
				$this->_is_date($val, $format, $error, '_' . $key);
			} 
		} else {
			$this->_is_date($this->_value, $format, $error, '');
		}

		return $this;
	}

	private function _sanitize($value) {
		return trim(filter_var(strip_tags($value), FILTER_SANITIZE_STRING));
	}

	public function sanitize() {

		if ( $this->_is_array ) {
			foreach($this->_value as $key => $val) {
				$this->_value[$key] = $this->_sanitize($val);
			}
		} else {
			$this->_value = $this->_sanitize($this->_value);
		}		

		return $this;
	}
	
	public function allow_null($allow=true) {
		$this->_allow_null = $allow;
		return $this;
	}

	public function allow_empty($allow=true) {
		$this->_allow_empty = $allow;
		return $this;
	}

	private function _not_null($value, $error, $key) {
		if ( $value == null ) {
			F::$errors[$this->_field . $key][] = $error;
		}
	}

	public function not_null($error = "Value is required") {
		$this->_allow_null = false;

		if ( $this->_is_array ) {
			foreach($this->_value as $key => $val) {
				$this->_not_null($val, $error, '_'.$key);
			}
		} else {
			$this->_not_null($this->_value, $error, '');
		}

		return $this;
	}

	private function _not_empty($value, $error, $key) {
		if ( empty($value)  ) {

			F::$errors[$this->_field . $key][] = $error;
		}
	}

	public function not_empty($error = "Value can't be empty") {
		$this->_allow_empty = false;

		if ( $this->_is_array ) {
			foreach($this->_value as $key=>$val) {
				$this->_not_empty($val, $error, '_'.$key);
			}
		} else {
			$this->_not_empty($this->_value, $error, '');
		}

		return $this;
	}

	public function depends_on($field, $error, $only_when_set=false) {
		$other_value = F::get_field($field);
		$value = F::get_field($this->_field);

		if ( $only_when_set && $value && !$other_value ) {
			F::$errors[$field][] = $error;
		}

		if ( !$only_when_set && !$other_value ) {
			F::$errors[$field][] = $error;
		}
		
		return $this;
	}

	public function match_field($field, $error) {
		$other_field = F::get_field($field);
		$value = F::get_field($this->_field);

		if ( $other_field != $value ) {
			F::$errors[$this->_field][] = $error;
		}

		return $this;
	}

	private function _custom($value, $args) {
		foreach($args as $arg) {
			$value = call_user_func($arg, $value);
		}
		return $value;	
	}

	/**
         * Filter field value with the provided callbacks
         */
	public function custom() {
		$args = func_get_args();

		if ( $this->_is_array ) {
			foreach($this->_value as $key => $val) {
				$this->_value[$key] = $this->_custom($val, $args);
			}
		} else {
			$this->_value = $this->_custom($this->_value, $args);
		}

		return $this;
	}

	private function _max_length($value, $length, $error, $truncate, $key) {
		if ( strlen($value) > $length ) {
			if ($error != null) {
				F::$errors[$this->_field . $key][] = $error;
			}

			if ($truncate) {
				$value = substr($value, 0, $length);
			}

			return $value;
		}

		return $value;
	}

	public function max_length($length, $error = null, $truncate = false) {
		
		if ( $this->_is_array ) {
			foreach($this->_value as $key => $val) {
				$this->_value[$key] = $this->_max_length($val, $length, $error, $truncate, '_'.$key);
			}
		} else {
			$this->_value = $this->_max_length($this->_value, $length, $error, $truncate, '');
		}

		return $this;
	}

	public function _min_length($value, $length, $error, $key) {

		if ( (!isset($value) || strlen($value) == 0 ) && ($this->_allow_null || $this->_allow_empty) ) {
			return; // early exit when allowing non values
		}

		if ( strlen($value) < $length ) {
			F::$errors[$this->_field . $key][] = $error;
		}

	}
	
	public function min_length($length, $error) {
		
		if ( $this->_is_array ) {
			foreach($this->_value as $key => $val) {
				$this->_min_length($val, $length, $error, '_'.$key);
			}
		} else {
			$this->_min_length($this->_value, $length, $error, '');
		}

		return $this;
	}

	private function _min($value, $min, $error, $key) {
		if ( $value < $min ) {
			$value = $min;
			if ( $error != null ) {
				F::$errors[$this->_field . $key][] = $error;
			}
		}

		return $value;
	}

	public function min($min, $error = null) {
		if ( $this->_is_array ) {
			foreach($this->_value as $key => $val) {
				$this->_value[$key] = $this->_min($val, $min, $error, '_'.$key);
			}
		} else {
			$this->_value = $this->_min($this->_value, $min, $error, '');
		}

		return $this;
	}

	private function _max($value, $max, $error, $key) {
		if ( $value > $max ) {
			$value = $max;
			if ( $error != null ) {
				F::$errors[$this->_field . $key][] = $error;
			}
		}

		return $value;
	}

	public function max($max, $error = null) {
		if ( $this->_is_array ) {
			foreach($this->_value as $key => $val) {
				$this->_value[$key] = $this->_max($val, $max, $error, '_'.$key);
			}
		} else {
			$this->_value = $this->_max($this->_value, $max, $error, '');
		}

		return $this;
	}

	public function range($min, $max, $error = null) {
		$this->min($min, $error);
		$this->max($max, $error);
		return $this;
	}

	public function default_value($default_value) {

		if ( F::$fields[$this->_field] == null ) {
			F::$fields[$this->_field] = $default_value;
		}

		return $this;
	}

	/**
	 * Filters all values not in the provided enum values.
	 * You may pass a list of enum arguments or a single array with your enum values.
	 * @param mixed Values to filter by or a single array containing all values to filter by.
	 * @return Filter
	 */
	public function enum() {
		$args = func_get_args();
		if ( sizeof($args) == 1 && is_array($args[0]) ) {
			$args = $args[0];
		}

		$value = F::$fields[$this->_field];
		if ( !in_array($value, $args) ) {
			F::$fields[$this->_field] = null;
		}
		return $this;
	}

	public function cast($type, $error = null) {
		if ( !settype(F::$fields[$this->_field], $type) ) {
			if ( $error != null ) {
				F::$errors[$this->_field][] = $error;
			}
		}

		return $this;
	}

	public function is_file($error="Upload missing") {

		if ( !array_key_exists($this->_field, $_FILES) ) {
			F::$errors[$this->_field][] = $error;
		}

		return $this;
	}

	public function max_file_size($size, $error = null) {

		$size_bytes = GID::humanToBytes($size);
		if ( array_key_exists($this->_field, $_FILES) && $_FILES[$this->_field]['size'] > $size_bytes ) {
			if ( $error == null ) {
				$error = 'Upload can not be larger than ' . GID::bytesToHuman($size);
			}
			F::$errors[$this->_field][] = $error;
		}
		
		return $this;
	}

	/**
	 * Tests uploaded file for the provided mime types: ex: array('jpg' => 'image/jpg', 'png' => 'image/png')
	 */
	public function file_mime($allowed, $error) {
		$finfo = new finfo(FILEINFO_MIME_TYPE);

		if ( false === $ext = array_search(
			$finfo->file($_FILES[$this->_field]['tmp_name']),
			$allowed, true)) {
			F::$errors[$this->field][] = $error;
		}

		return $this;

	}

}

/**
 * Simple Form helper class
 */
class F {

	public static $fields = array();
	public static $filters = array();
	public static $errors = array();
	public static $method = null;

	public static function init($method_type) {
		F::$method = $method_type;
		F::$fields = array();
		F::$errors = array();
	}

	public static function __callStatic($name, $arguments) {
		if ( substr($name, 0, 7) == 'filter_' ) {
			return F::filter(substr($name, 7));
		}

		if ( substr($name, 0, 6) == 'field_' ) {
			$field = substr($name, 6);
			if ( sizeof($arguments) != 0 ) {
				F::set_field($field, $arguments[0]);
			}
			return F::get_field($field);
		}

		throw new BadMethodCallException("Method $name not found");
	}

	public static function set_field($name, $value) {
		F::$fields[$name] = $value;
	}

	public static function get_field($name) {
		return F::$fields[$name];
	}

	/**
	 * Sets the fields we want to do work on. The values will be taken from $_GET or $_POST depending not F::init was called.
	 */
	public static function fields() {
		if ( F::$method == 'post' ) {
			$method = $_POST;
		} else {
			$method = $_GET;
		}

		$fields = func_get_args();

		foreach($fields as $field) {
			if ( array_key_exists($field, $method) ) {
				if ( $method[$field] != null ) {
					F::$fields[$field] = $method[$field];
				} else {
					F::$fields[$field] = '';
				}
			} else {
				F::$fields[$field] = null;
			}
		}
	}

	public static function add_field($name) {
		F::fields($name);
	}

	public static function filter($field) {
		F::$filters[$field] = new Filter($field);
		return F::$filters[$field];
	}

	public static function input($name, $label, $type="text", $override=array(), $extra='', $echo=true) {
		$build = array("name" => $name, "id" => $name, "type" => $type);
		if ( array_key_exists($name, F::$fields) ) {		
			$build["value"] = F::$fields[$name];
		}

		$build = array_merge($build, $override);
		$parts = array();
		foreach($build as $k => $v) {
			$parts[] = "$k=\"$v\"";
		}
		$parts = implode(" ", $parts);
		ob_start()
?><div class="field field_<?php echo $name ?>"><label for="<?php echo $build['id']; ?>"><?php echo $label ?></label><input <?php echo $parts ?> /><?php echo $extra;
		if ( array_key_exists($name, F::$errors) && sizeof(F::$errors[$name]) ) {
?><div class="error"><?php echo implode('<br/>', F::$errors[$name]) ?></div><?php
        	}
		?></div><?php
		$content = ob_get_clean();
		if ( $echo ) {
			echo $content;
		} else {
			return $content;
		}
	}

	public static function text($name, $label, $override=array(), $extra='', $echo=true) {
		return F::input($name, $label, 'text', $override, $extra, $echo); 
	}

	public static function checkbox($name, $label, $value, $is_default=false, $type="checkbox", $override=array()) {
		$build = array("name" => $name, "id" =>$name, "type" => $type, "value" => $value);
		if ( array_key_exists($name, F::$fields) && $value == F::$fields[$name]  ) {
			$build["checked"] = "checked";
		} else if ( $is_default ) {
			F::$fields[$name] = $value;
			$build["checked"] = "checked";
		}

		$build = array_merge($build, $override);
		$parts = array();
		foreach($build as $k => $v) {
			$parts[] = "$k=\"$v\"";
		}
		$parts = implode(" ", $parts);
?><div class="field field_<?php echo $name ?>"><input <?php echo $parts ?> /><label for="<?php echo $build['id']; ?>"><?php echo $label; ?></label><?php
		if ( array_key_exists($name, F::$errors) && sizeof($F::$errors[$name]) ) {
?><div class="error"><?php echo implode('<br/>', F::$errors[$name]) ?></div><?php
		}
		?></div><?php
	}

	public static function textarea($name, $label, $override=array()) {
		$build = array("name" => $name, "id" => $name);
		if ( array_key_exists($name, F::$fields) ) {		
			$value = F::$fields[$name];
		} else {
			$value = '';
		}

		$build = array_merge($build, $override);
		$parts = array();
		foreach($build as $k => $v) {
			$parts[] = "$k=\"$v\"";
		}
		$parts = implode(" ", $parts);
?><div class="field field_<?php echo $name ?>"><label for="<?php echo $build['name']; ?>"><?php echo $label ?></label><textarea <?php echo $parts ?>><?php echo $value; ?></textarea><?php
		if ( array_key_exists($name, F::$errors) && sizeof(F::$errors[$name]) ) {
?><div class="error"><?php echo implode('<br/>', F::$errors[$name]) ?></div><?php
        	}
		?></div><?php
		
	}

	public static function select($name, $label, $options, $default_key = null, $override=array(), $extra='', $echo=true) {
		if ( !$override ) {
			$override = array();
		}

		if ( !$extra ) {
			$extra = '';
		}

		$build = array("name" => $name, "id" => $name);
		if ( array_key_exists($name, F::$fields) ) {		
			$value = F::$fields[$name];
		} else {
			$value = '';
		}

		$options_render = '';
		$options_list = array();
		foreach($options as $k=>$v) {
			$selected = '';
			if ( (!$value && $k == $default_key) || ($value && $k == $value) ) {
				$selected = ' selected';
			}

			//$value_att = '';
			//if ( $k ) { 
				$value_att = 'value="'.$k.'"';
			//}

			$options_list[] = '<option '.$selected.' '.$value_att.'>'.$v.'</option>';
		}
		$options_render = implode('', $options_list);

		$build = array_merge($build, $override);
		$parts = array();
		foreach($build as $k => $v) {
			$parts[] = "$k=\"$v\"";
		}
		$parts = implode(" ", $parts);

		ob_start();
?><div class="field field_<?php echo $name ?>"><label for="<?php echo $build['name']; ?>"><?php echo $label ?></label><select <?php echo $parts ?>><?php echo $options_render; ?></select><?php
		echo $extra;

		if ( array_key_exists($name, F::$errors) && sizeof(F::$errors[$name]) ) {
?><div class="error"><?php echo implode('<br/>', F::$errors[$name]) ?></div><?php
        	}
		?></div><?php
		$content = ob_get_clean();

		if ( $echo ) {
			echo $content;
		} else {
			return $content;
		}
	}

	public static function file($name, $label, $override=array(), $extra='', $echo=true) {
		return F::input($name, $label, 'file', $override, $extra, $echo);
	}

	public static function password($name, $label, $override=array(), $extra='', $echo=true) {
		return F::input($name, $label, 'password', $override, $extra, $echo);
	}

	public static function submit($label, $extra='', $echo=true) {
		ob_start()
?><div class="submit"><input type="submit" value="<?php echo $label ?>" /><?php echo $extra;?></div><?php
		$content = ob_get_clean();

		if ( $echo ) {
			echo $content;
		} else {
			return $content;
		}
	}

	public static function on_submit() {
		return strtoupper($_SERVER['REQUEST_METHOD']) == strtoupper(F::$method);
	}

	public static function is_valid() {
		return sizeof(F::$errors) == 0;
	}

	public static function file_save($field, $save_path) {
		return move_uploaded_file(
			$_FILES[$field]['tmp_name'],
			$save_path);
	}
}

class SESSION {

	private static $_started = false;
	
	public static function started() {
		return SESSION::$_started;
	}

	public static function start($session_name=null) {
		SESSION::$_started = true;

		$session_cookie_path = '/';
		if ( array_key_exists('session_cookie_path', GID::$config) ) {
			$session_cookie_path = GID::$config['session_cookie_path'];
		}

		$session_cookie_lifetime = 3600;
		if ( array_key_exists('session_cookie_lifetime', GID::$config) ) {
			$session_cookie_lifetime = GID::$config['session_cookie_lifetime'];
		}

		$session_cookie_secure = False;
		if ( array_key_exists('session_cookie_secure', GID::$config) ) {
			$session_cookie_secure = GID::$config['session_cookie_secure'];
		}

		$session_cookie_http_only = False;
		if ( array_key_exists('session_cookie_http_only', GID::$config) ) {
			$session_cookie_http_only = GID::$config['session_cookie_http_only'];
		}

		if ( array_key_exists('session_domain', GID::$config) ) {
			session_set_cookie_params($session_cookie_lifetime, $session_cookie_path, GID::$config['session_domain'], $session_cookie_secure, $session_cookie_http_only);
		}

		if ( $session_name != null ) {
			session_name($session_name);
		}

		session_start();

		$GID = '__GID__';
		if ( array_key_exists('session_cookie_GID', GID::$config) ) {
			$GID = GID::$config['session_cookie_GID'];
		}
		# refreshes cookie timeout on return of user to site
		if ( array_key_exists($GID, $_COOKIE) ) {
			setcookie($GID, $_COOKIE[$GID], time() + $session_cookie_lifetime, $session_cookie_path);
		}
	}

	public static function close() {
		session_write_close();
		SESSION::$_started = false;
	}

	public static function destroy() {
		session_destroy();
		SESSION::$_started = false;
	}
	
}

class SECURE {

	public static function unique($size) {
		return mcrypt_create_iv($size, MCRYPT_DEV_URANDOM);
	}

	public static function password_hash($password) {
		$salt = mcrypt_create_iv(16, MCRYPT_DEV_URANDOM);
		return crypt($password, '$6$rounds=5000$'.$salt.'$');
	}

	public static function password_check($password, $hash) {
		return $hash == crypt($password, $hash);
	}

}
