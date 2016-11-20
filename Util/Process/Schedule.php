<?php
	/**
	 * Scheduled process support via at
	 *
	 * MIT License
	 *
	 * @author  Matt Saladna <matt@apisnetworks.com>
	 * @license http://opensource.org/licenses/MIT
	 * @version $Rev: 2567 $ $Date: 2016-11-19 23:19:37 -0500 (Sat, 19 Nov 2016) $
	 */
	class Util_Process_Schedule extends Util_Process
	{
		// atd spool dir
		const SPOOL_DIR = '/var/spool/at';
		const AT_CMD = 'at';

		private $_time;
		private $_id;
		
		/**
		 * Schedule a process to be run at
		 * a specific time
		 */
		
		public function __construct($arg1 = 'now', $arg2 = null, $arg3 = null) {
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
			/**
			 * atd only accepts UTC for timezone
			 * ensure tz is converted to compatible UTC zone
			 *
			 * **NOTE** there is a bug with 3.1.8 < atd <= 3.1.13-22
			 * in RedHat with parsing UTC timezones:
			 * https://bugzilla.redhat.com/show_bug.cgi?id=1328832
			 */
			return $d->setTimezone(new DateTimeZone('UTC'))
				->format("H:i \U\T\C m/d/Y");
		}

		public function setID($id) {
			if (!is_readable(self::SPOOL_DIR)) {
				return warn("ID support unavailable: cannot access atd spool `%s', verify permissions?");
			}
			$this->setEnvironment('__APNSCP_ATD_ID', $id);
			$this->_id = $id;
		}

		public function run($cmd, $args = null)
		{
			if ($this->_id && false !== ($procid = $this->idPending($this->_id))) {
				return error("pending process `%s' already scheduled with id `%s'",
					basename($procid),
					$this->_id
				);
			}
			$spec = $this->_parse($this->_time);
			if (false === $spec) {
				return error("unparseable timespec `%s'", $this->_time);
			}
			if (static::AT_CMD == "batch") {
				$spec = null;
			}
			$safecmd = sprintf("echo %s | " . static::AT_CMD . "  %s 2> /dev/null",
				escapeshellcmd($cmd),
				$spec
			);
			$args = func_get_args();
			$args[0] = $safecmd;
			$safe = new Util_Process_Safe();
			$safe->setEnvironment($this->getEnvironment());
			return call_user_func_array(array($safe, 'run'), $args);
		}

		/**
		 * Program already exists in atd queue with ID
		 *
		 * @param string $id
		 * @return bool
		 */
		public function idPending($id) {
			$spooldir = self::SPOOL_DIR;
			// a* is at
			// b* is batch
			// =* is running
			$files = glob($spooldir . '/[ab=]*');
			foreach ($files as $f) {
				$contents = file_get_contents($f);
				if (!preg_match('/^__APNSCP_ATD_ID=(.*?)(?:; export __APNSCP_ATD_ID)?$/m', $contents, $matches)) {
					continue;
				}
				if ($matches[1] === $id) {
					return $f;
				}
			}
			return false;
		}

        final public static function exec($cmd, $args = null, $exits = array(0), $opts = array()) {
            return error("cannot statically call exec() with Util_Proc_Schedule");
        }
	}
?>