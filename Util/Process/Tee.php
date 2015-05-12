<?php
	/**
	 * Pipe command output to another file
	 *
	 * MIT License
	 *
	 * @author  Matt Saladna <matt@apisnetworks.com>
	 * @license http://opensource.org/licenses/MIT
	 * @version $Rev: 1760 $ $Date: 2015-05-12 13:28:47 -0400 (Tue, 12 May 2015) $
	 */
	class Util_Process_Tee
	{
		/**
		 * Tee file pointer
		 * @var resource 
		 */
		static $fp;
		/**
		 * This is a persistent request that
		 * will automatically resume a tee across
		 * multiple class instances.  
		 * 
		 ***** Remember to call _close() to release tee *****
		 * @var bool
		 */
		private $persist = false;
		
		/** 
		 * Process invoker instance
		 * @var Util_Process
		 */
		private  $proc;
		/** 
		 * Instance created tee file
		 * @var bool 
		 */
		private $owner = false;

		function __destruct() {
			if (IS_CLI)
				return $this->_close();
			$this->close();
		}

		private function __construct(Util_Process $proc) { $this->proc = $proc; }

		/**
		 * Get tee filename
		 * @private
		 * @return string
		 */
		private static function _get_file() {
			return '/tmp/'.Auth::get_driver()->getID();
		}

		/**
		 * Get lock filename
		 * @private
		 * @return string
		 */
		private static function _get_lock() {
			return '/tmp/'.Auth::get_driver()->getID().'-lock';
		}

		/**
		 * Log command output
		 * 
		 * @param Util_Process $proc command invoker
		 * @return Util_Process_Tee
		 */
		public static function watch(Util_Process $proc)
		{
			$tee = new Util_Process_Tee($proc);
			// file is opened, resume
			if (!is_resource(self::$fp))
				$tee->init();
			
			$proc->addCallback(array($tee, 'rlog'));
			return $tee;
		}

		/**
		 * Resume a tee in progress or setup a persistent request
		 * 
		 * @param Util_Process $proc
		 * @return Util_Process_Tee
		 */
		public static function auto(Util_Process $proc)
		{
			$tee = self::watch($proc);
			$tee->setPersistence(true);
			return $tee;
		}

		/**
		 * Initialize tee file
		 * 
		 * @return bool mutex lock acquired 
		 */
		public function init() {
			$flags = LOCK_EX|LOCK_NB;
			$wb = true;
			$mode = 'w';
			touch($this->_get_file());
			if (file_exists($this->_get_lock())) {
				$mode = 'a';
			}
			$fp = fopen($this->_get_file(), $mode);
			$i=0;
			while (!flock($fp, $flags, $wb)) {
				usleep(500);
				$i++;
				if ($i > 100) { warn("Gave up waiting!") ; break; fclose($fp); return false; }
			}
			if (IS_CLI) {
				chown($this->_get_file(), 'nobody');
			}
			if (file_exists($this->_get_lock())) {
				unlink($this->_get_lock());
			}
			self::$fp = &$fp;
			$this->owner = true;
			// IE Fix - LF does not produce line-break
			stream_filter_register('string.unix2dos','Util_Filter_Unix2dos');
			stream_filter_append($fp, 'string.unix2dos', STREAM_FILTER_WRITE);
			return true;
		}

		/**
		 * Call a command by using Util_Process::exec()
		 * 
		 * @see Util_Process::exec()
		 * @return array
		 */
		public function exec($cmd, $args = null, $exits = array(0), $opts = array()) {
			//if (!is_resource(self::$fp)) { return false; }
			if (!isset($this->proc)) {
				return error("cannot exec with delegated obj (".__CLASS__."::auto())");
			}
			if (!is_resource(self::$fp)) return array('success' => false);
			$resp = call_user_func_array(array($this->proc, 'run'), func_get_args());
			return $resp;
		}

		/**
		 * Read tee file
		 * 
		 * offset:  (int)    total bytes read
		 * length:  (int)    bytes read this request 
		 * data:    (string) 
		 * eof:     (bool)   
		 * 
		 * error:   (null)   compatibility with apnscp ajax invocation
		 * success: (bool)   compatibility with apnscp ajax invocation
		 * 
		 * @param int $offset last byte offset
		 * @return array
		 */
		public static function read($offset)
		{
			$tee_lock = self::_get_lock();
			$tee = self::_get_file();

			// new request
			$block = true; $op = LOCK_EX|LOCK_NB;
			if ($offset == -1) {
				$offset = 0;
				if (file_exists($tee_lock)) {
					 if (filemtime($tee_lock) < $_SERVER['REQUEST_TIME']) {
						unlink($tee_lock); 
						unlink($tee);
					 }
				} else if (file_exists($tee)) {
					$fp = fopen($tee, 'r');
					$unlocked = flock($fp, $op, $block);
					// stale tee lock
					if ($unlocked) {
						unlink($tee);
					}
					fclose($fp);
				}
			}

			if (!$offset) {
				for ($i = 0; ; $i++) {
					if (file_exists($tee)) break;
					if ($i > 1000) return '';
					usleep(10000);
				}
			} else if (!file_exists($tee)) {
				return '';
			}

			$fp = fopen($tee,'r');
			stream_set_blocking($fp, 1);
			$stream = stream_get_contents($fp, -1, $offset);
			$len = strlen($stream);

			$complete =  !file_exists($tee_lock)  && flock($fp, $op, $block) && feof($fp);
			fclose($fp);
			$offset += $len;
			if ($complete) unlink($tee);
			return array(
				'offset' => $offset,
				'data'   => $stream,
				'eof'    => $complete,
				'error'  => '',
				'success'=> true,
				'length' => $len
			);
		}

		/**
		 * Persist tee between invocations
		 * 
		 * @param bool $persist
		 */
		public function setPersistence($persist = true)
		{
			$this->persist = $persist;

			if ($this->persist) {
				DataStream::get()->setOption(apnscpObject::USE_TEE);
				touch(self::_get_lock());
				if (IS_CLI) chown(self::_get_lock(), 'nobody');

			} else if (file_exists(self::_get_lock())) {
				DataStream::get()->clearOption(apnscpObject::USE_TEE);
				unlink (self::_get_lock());
			}
		}

		/**
		 * Log output
		 * 
		 * @param string $msg
		 */
		public function log($msg, $args = array()) 
        {
    		$args = func_get_args();
        	array_shift($args);
            if ($args) {
                $msg = vsprintf($msg, $args);
            }
			return $this->rlog($msg."\n");
		}

		/**
		 * Log output verbatim
		 * 
		 * @param string $msg
		 */
		public function rlog($msg)
		{
			fwrite(self::$fp, $msg);

		}

		/**
		 * Close tee and set persistance lock  
		 */
		public function close()
		{
			$this->_close(true);
		}

		/**
		 * Close tee and unset/set persistance lock
		 * 
		 * @private
		 * @param bool $unlink remove tee lock
		 */
		private function _close($unlink = false) {
			if (!$this->owner) return false;
			if (IS_CLI && $this->persist) {
				touch(self::_get_lock());
				chown(self::_get_lock(), 'nobody');
			}
			if (is_resource(self::$fp)) {
				fclose(self::$fp);
				self::$fp = null;
			}
			if ($unlink && file_exists(self::_get_lock())) {
				unlink(self::_get_lock());
			}

		}
	}
?>
