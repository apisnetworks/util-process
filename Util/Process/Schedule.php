<?php
	/**
	 * Scheduled process support via at
	 *
	 * MIT License
	 *
	 * @author  Matt Saladna <matt@apisnetworks.com>
	 * @license http://opensource.org/licenses/MIT
	 * @version $Rev: 1786 $ $Date: 2015-05-28 00:15:38 -0400 (Thu, 28 May 2015) $
	 */
	class Util_Process_Schedule extends Util_Process
	{
		
		private $_time;
		
		/**
		 * Schedule a process to be run at
		 * a specific time
		 */
		
		public function __construct($arg1, $arg2 = null, $arg3 = null) {
			if (!is_null($arg2)) {
				// signature 1: $format, $time, DateTimeZone $tz = null
				$this->__constructSig1($arg1, $arg2, $arg3);
			} else if (!is_object($arg1)) {
				// signature 2: $time
				$this->__constructSig2($arg1);
			} else if ($arg1 instanceof DateTime) {
				// signature 3: DateTime $d
				$this->__constructSig3($arg1);
			} else {
				fatal("unparseable timespec arguments provided %s/%s/%s", $arg1, $arg2, $arg3);
			}
			$d = $this->_time;
			$this->_parse($d);
			parent::__construct();
		}
		
		public function __constructSig1($format, $time, DateTimeZone $tz = null)
		{
			$this->_time = DateTime::createFromFormat($format, $time, $tz);
			if (!$this->_time) {
				fatal("unparseable date/time spec `%s' from format `%s'", 
					$time, $format);
			}
		}
		
		public function __constructSig2($time) {
			$this->_time = new DateTime($time);
			if (!$this->_time) {
				fatal("unparseable date/time spec `%s'", $time);
			}
		}
		
		public function __constructSig3(DateTime $d)
		{
			$this->_time = $d;
		}
		
		private function _parse(DateTime $d) {
			return $d->format("H:i m/d/Y");
		}
		
		public function run($cmd, $args = null)
		{
			$spec = $this->_parse($this->_time);
			if (false === $spec) {
				return error("unparseable timespec `%s'", $this->_time);
			}
			$safecmd = sprintf("echo %s | at %s 2> /dev/null", 
				escapeshellarg($cmd), 
				$spec
			);
			$args = func_get_args();
			$args[0] = $safecmd;
            return call_user_func_array(array('Util_Process_Safe', 'exec'), $args);

		}
        
        final public static function exec($cmd, $args = null, $exits = array(0), $opts = array()) {
            return error("cannot statically call exec() with Util_Proc_Schedule");
        }
	}
?>