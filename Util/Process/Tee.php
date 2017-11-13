<?php

    /**
     * Pipe command output to another file
     *
     * MIT License
     *
     * @author  Matt Saladna <matt@apisnetworks.com>
     * @license http://opensource.org/licenses/MIT
     * @version $Rev: 2450 $ $Date: 2016-08-17 15:15:41 -0400 (Wed, 17 Aug 2016) $
     */
    class Util_Process_Tee
    {
        /**
         * Tee file pointer
         *
         * @var resource
         */
        private $fp;
        /**
         * This is a persistent request that
         * will automatically resume a tee across
         * multiple class instances.
         *
         ***** Remember to call _close() to release tee *****
         *
         * @var bool
         */
        private $persist = false;

        /** @var  string tee output filename */
        private $file;

        /**
         * Process invoker instance
         *
         * @var Util_Process
         */
        private $proc;
        /**
         * Instance created tee file
         *
         * @var bool
         */
        private $owner = false;

        public function __construct(array $options = array())
        {
            if (isset($options['process'])) {
                $this->setProcess($options['process']);
            }
            if (isset($options['tee'])) {
                $this->setTeeFile($options['tee']);
            } else {
                if (isset($options['path'])) {
                    $this->setTeePath($options['path']);
                }
            }
            if (isset($options['persist'])) {
                $this->setPersistence($options['persist']);
            }
        }

        /**
         * Read tee file
         *
         * Do not run as root, no access rights are checked
         * Filter $file for untrusted users
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
        public static function read($file, $offset = -1)
        {
            $tee_lock = self::_get_lock();
            $tee = $file;
            // @todo verify $file
            if (!$tee) {
                return array();
            }
            // new request
            $block = true;
            $op = LOCK_EX | LOCK_NB;
            if ($offset == -1) {
                $offset = 0;
                if (file_exists($tee_lock)) {
                    if (filemtime($tee_lock) < $_SERVER['REQUEST_TIME']) {
                        unlink($tee_lock);
                        unlink($tee);
                    }
                } else {
                    if (file_exists($tee_lock)) {
                        $fp = fopen($tee, 'r');
                        $unlocked = flock($fp, $op, $block);
                        // stale tee lock
                        if ($unlocked) {
                            unlink($tee);
                        }
                        fclose($fp);
                    }
                }
            }

            if (!$offset) {
                for ($i = 0; ; $i++) {
                    if (file_exists($tee)) {
                        break;
                    }
                    if ($i > 1000) {
                        return '';
                    }
                    usleep(10000);
                }
            } else {
                if (!file_exists($tee)) {
                    return '';
                }
            }

            $fp = fopen($tee, 'r');
            stream_set_blocking($fp, 1);
            $stream = stream_get_contents($fp, -1, $offset);
            $len = strlen($stream);

            $complete = !file_exists($tee_lock) && flock($fp, $op, $block) && feof($fp);
            fclose($fp);
            $offset += $len;
            if ($complete) {
                unlink($tee);
            }
            return array(
                'offset'  => $offset,
                'data'    => $stream,
                'eof'     => $complete,
                'error'   => '',
                'success' => true,
                'length'  => $len
            );
        }

        private static function _make_lock($file)
        {
            return $file . '-lock';
        }

        /**
         * Get lock filename
         *
         * @private
         * @return string
         */
        private static function _get_lock()
        {
            // only trust authenticated users
            // sync between backend and frontend
            return sys_get_temp_dir() . '/' . Auth::get_driver()->getID() . '-lock';
        }

        public function setProcess(Util_Process $proc)
        {
            $this->proc = $proc;
            if (function_exists('pcntl_signal')) {
                $this->proc->setOption('timeout', 10);
            }

            $this->proc->addCallback(array($this, 'init'), 'exec.tee');
            $this->proc->addCallback(array($this, 'rlog'), 'read.tee');
            $this->proc->addCallback(array($this, 'deinit'), 'close.tee');

        }

        /**
         * Use file for output
         *
         * @param $file
         * @return bool
         */
        public function setTeeFile($file)
        {
            if (!file_exists($file)) {
                touch($file);
            }
            $this->file = $file;
            return true;
        }

        /**
         * Generate a discretionary tee file
         *
         * @param string $dir
         * @return bool|void
         */
        public function setTeePath($dir = '')
        {
            if (!$dir) {
                $dir = sys_get_temp_dir();
            } else {
                if (!is_dir($dir)) {
                    return error("path `%s' is not a directory", $dir);
                }
            }
            $path = tempnam($dir, 'tee');
            chmod($path, 0600);
            if (posix_getuid() == 0 && defined('WS_UID')) {
                /// apnscp
                chown($path, constant('WS_UID'));
            }
            $this->setTeeFile($path);
            return $path;
        }

        /**
         * Initialize tee file
         *
         * @return bool mutex lock acquired
         */
        public function init()
        {
            if (!$this->file) {
                return error("no tee file set!");
            }
            $persist = $this->persist;
            $lock = $this->_get_lock();
            if (!$persist && file_exists($lock)) {
                fatal("lock `%s' present, is another tee running?", $lock);
            }
            $flags = LOCK_EX | LOCK_NB;
            if (!file_exists($lock)) {
                touch($lock);
                $fp = fopen($lock, 'w');
            } else {
                $fp = fopen($lock, 'a');
            }
            $wb = false;
            for ($i = 0; $i < 100 || $wb; $i++) {
                flock($fp, $flags, $wb);
                usleep(500);
            }
            if ($wb) {
                return error("unable to acquire lock on `%s'", $lock);
            }

            $of = $this->file;
            if (!$persist && file_exists($of)) {
                unlink($of);
            }
            touch($of);
            if (defined('IS_CLI') && constant('IS_CLI')) {
                chown($of, constant('WS_UID'));
            }
            chmod($of, 0600);

            $fp = fopen($of, $persist ? 'a' : 'w');
            $this->fp = &$fp;
            $this->owner = true;
            // IE Fix - LF does not produce line-break
            stream_filter_register('string.unix2dos', 'Util_Filter_Unix2dos');
            stream_filter_append($fp, 'string.unix2dos', STREAM_FILTER_WRITE);
            return true;
        }

        /**
         * Persist tee between invocations
         *
         * @param bool $persist
         */
        public function setPersistence($persist = true)
        {
            $this->persist = $persist;
            if (!$persist) {
                $this->deinit();
            }

            DataStream::get()->setOption(apnscpObject::USE_TEE);
            $this->proc->deleteCallback('close', 'tee');
        }

        public function deinit()
        {
            DataStream::get()->clearOption(apnscpObject::USE_TEE);
            $tee = $this->file;
            $lock = self::_get_lock();
            // tee must be removed by agent confirming IO
            // has drained
            if (file_exists($lock)) {
                unlink($lock);
            }
            return true;
        }

        public function __destruct()
        {
            // let frontend close
            $this->_close(!IS_CLI);
        }

        public function getLock()
        {
            return self::_get_lock();
        }

        /**
         * Call a command by using Util_Process::exec()
         *
         * @see Util_Process::exec()
         * @return array
         */
        public function exec($cmd, $args = null, $exits = array(0), $opts = array())
        {
            //if (!is_resource(self::$fp)) { return false; }
            if (!isset($this->proc)) {
                return error("cannot exec with delegated obj (" . __CLASS__ . "::auto())");
            }
            $this->proc = new Util_Process();
            if (!is_resource($this->fp)) {
                return array('success' => false);
            }
            $resp = call_user_func_array(array($this->proc, 'run'), func_get_args());
            return $resp;
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
            return $this->rlog($msg . "\n");
        }

        /**
         * Log output verbatim
         *
         * @param string $msg
         */
        public function rlog($msg, $bytes = 0, $pipeid = null)
        {
            fwrite($this->fp, $msg);
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
        private function _close($unlink = false)
        {
            if (!$this->owner) {
                return false;
            }
            if (IS_CLI && $this->persist) {
                touch(self::_get_lock());
                chown(self::_get_lock(), 'nobody');
            }
            if (is_resource($this->fp)) {
                fclose($this->fp);
                $this->fp = null;
            }
            if ($unlink && file_exists(self::_get_lock())) {
                unlink(self::_get_lock());
            }

        }
    }

?>
