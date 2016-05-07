<?php

	/**
	 * Util Process
	 * A general process utility
	 *
	 * MIT License
	 *
	 * @author  Matt Saladna <matt@apisnetworks.com>
	 * @license http://opensource.org/licenses/MIT
	 * @version $Rev: 1950 $ $Date: 2016-01-16 13:52:12 -0500 (Sat, 16 Jan 2016) $
	 */
	class Util_Process {
		/**
		 * @var string formatted command
		 */
		private $cmd;
		/**
		 * @var array arguments
		 */
		private $args;
		/**
		 *
		 * @var array|string permitted/regex exit values
		 */
		private $exits = '/^0$/';
		/**
		 * @var string binary name
		 */
		private $prog_name;

		/**
		 * @var object piped peer process
		 */
		private $proc_peer;
		/**
		 * @var string proc_open() instance
		 */
		private $proc_instance;

		/**
		 * @var array environment variables if null, use $_ENV
		 */
		private $_env = array(
			'PATH' => '/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin'
		);

		// new priority level
		private $_priority;
		// revert after exec
		private $_oldPriority;

		/**
		 * @var array process options
		 */
		protected $opts = array('run' => 1,
			'pipe'    => null,
			'mute_stderr'=> false,
			'mute_stdout' => false,
			'tv_sec'  => 5,
			'tv_usec' => null,
			'binary'  => 0,
			'tail'    => false,
			'fd' => array());

		private $descriptors;

		/**
		 * @var array pipes held by a process
		 */
		private $pipes;

		/**
		 * @var array valid times when a callback may be attached
		 */
		private $callbacks = array('read' => array(),'write' => array(),'close' => array(), 'exec' => array());

		/**
		 * @var int exit code
		 */
		private $exitStatus;
		/**
		 * @var array channel output (stdin, stderr, stdout, etc)
		 */
		private $channelData = array();

		/**
		 * @var array  lookup to translate name => fd (stdin => 0)
		 */
		private $pipeMap    = array();
		private $pipeline   = array();
		private $revPipeMap = array();

		public function __construct() {
			if (is_null($this->_env)) {
				$this->_env = $_ENV;
			}
			$this->setDescriptor(0, 'pipe', 'rb', 'stdin', array('close' => 1))->
				setDescriptor(1, 'pipe', 'wb', 'stdout')->
				setDescriptor(2, 'pipe', 'wb', 'stderr');
		}

		public function __destruct() {
			if (is_resource($this->proc_instance))
				proc_close($this->proc_instance);
		}

		/**
		 * Execute a program
		 *
		 * Optional parameters:
		 * 	run (bool) : execute process automatically
		 *  fd  (array): file descriptors consisting of an array of descriptor-specific options
		 *
		 *  fd options:
		 *  -----------
		 *   close (bool): close pipe on creation - useful with stdin
		 *
		 * @param string $cmd   command
		 * @param mixed  $args  format arguments
		 * @param array  $exits permitted exit values
		 * @param array  $opts  optional process options
		 * @return array
		 */
		public static function exec($cmd, $args = null, $exits = array(0), $opts = array()) {
			$c = get_called_class();
			$proc = new $c;
			return call_user_func_array(array($proc,'run'), func_get_args());
		}

		public function run($cmd, $args = null) {
			$args = func_get_args();
			$cmd  = array_shift($args);
			$this->_init($cmd, $args);
			if (!$this->opts['run']) return $this;
			$format = $this->getOption('format');
			return $this->process()->close()->format($format);
		}

		/**
		 * Attach another program's output to program input
		 *
		 * @param Util_Process $peer processs created by create()
		 * @return object
		 */
		public function pipeProcess(Util_Process &$peer) {
			$this->proc_peer = &$peer;
			$this->setEncoding('binary','stdin');
			$this->setDescriptorOption('stdin','close',0);
			return $this;
		}

		public function pipe($cmd) {
			$args = func_get_args();
			$peer = call_user_func_array(array('Util_Process','create'), $args);
			$this->pipeProcess($peer);
			return $this;
		}

		public function pipeDescriptor($read, $write) {
			$this->setDescriptorOption($write, 'close', 0);
			return$this->connectPipeline($read, $write);
		}


		private function _init($cmd, $args) {
			$this->cmd = $cmd;
			$this->_setArgs($args);

			$this->prog_name = basename(substr($cmd, 0, strpos($cmd, " ")));

			// process setup
			$prio = $this->getPriority();
			if ($prio && !pcntl_setpriority($prio)) {
				warn("failed to set priority %d", $prio);
			}
			if (!$this->getOption('run')) {
				return $this;
			}

			return $this->_run();

		}

		/**
		 * Force a delayed start
		 *
		 * Used in conjunction with setOption("run", false)
		 *
		 * @return bool|Util_Process
		 */
		public function forceRun() {
			if ($this->getOption('run')) {
				return true;
			}

			return $this->_run();
		}

		private function _run() {
			$pid = proc_open($this->cmd,
					$this->descriptors,
					$this->pipes,
					null,
					$this->_env
			);
			if (!is_resource($pid)) {
				return error("`%s': unable to open process", $this->prog_name);
			}
			$this->proc_instance = &$pid;
			return $this;
		}

		public function running() {
			$inst = proc_get_status($this->proc_instance);
			return is_array($inst) && $inst['running'];
		}
		/**
		 * Map a pipe resource to its descriptor
		 *
		 * @param  string|resource $resid
		 * @param  string          $descriptor
		 * @return bool
		 */
		private function addResourceMap($resid, $descriptor, $peerid = 0) {
			if (is_resource($resid))
				$resid = $this->resource2String($resid);
			$this->revPipeMap[$resid] = array('fd' => $descriptor, 'procid' => $peerid);
			return true;
		}

		/**
		 * Transform process data
		 * Format options:
		 * ---------------
		 * esprit: traditional apnscp esprit format
		 * 	stderr, stdout, output, error, return, errno fields
		 *
		 * @param  string $fmt data format
		 * @return mixed
		 */
		public function format($fmt = 'apnscp') {
			if (!$fmt) $fmt = 'apnscp';
            if ($fmt == 'apnscp') {
			    return $this->formatDataCallProc();
            }

            if (!is_callable($fmt)) {
                return error("formatter specified `%s' is not callable", $fmt);
            }
            return call_user_func($fmt);
		}

		/**
		 * Add environment exported to child process via putenv()
		 *
		 * @param $name environment variable name
		 * @param $val  value
		 * @return $this instance
		 */
		public function addEnvironment($name, $val)
		{
			if (isset($this->_env[$name])) {
				error("cannot overwrite environment var `%s'", $name);
				return $this;
			}
			$this->_env[$name] = $val;
			return $this;
		}

		/**
		 * Set environment variable overwriting if set
		 *
		 * @param mixed $name
		 * @param mixed $val
		 * @return $this instance
		 */
		public function setEnvironment($name, $val = null)
		{
			if (is_array($name) && is_null($val)) {
				$this->_env = array_merge($this->_env, $name);
			} else if (is_array($name) && !is_null($val)) {
				// multiple vars, same value
				$this->_env = array_merge($this->_env, array_fill_keys($name, $val));
			} else {
				$this->_env[$name] = $val;
			}

			return $this;
		}

		public function unsetEnvironment($var)
		{
			if (isset($this->_env[$var])) {
				unset($this->_env[$var]);
			}
			return $this;
		}

		public function getEnvironment()
		{
			return $this->_env;
		}

		public function setPriority($prio) {
			if (!function_exists('pcntl_setpriority')) {
				fatal('pcntl extension not loaded');
			}
			if ($prio < -20 || $prio > 19) {
				return error("invalid priority specified `%d'", $prio);
			}
			$this->_priority = intval($prio);
			// reset after exec
			$this->_oldPriority = pcntl_getpriority();
			return $this;
		}

		public function getPriority()
		{
			return $this->_priority;
		}
		/**
		 * Util_Process output into conventional call_proc() output
		 *
		 * @param  string $exit exit code
		 * @return array
		 */
 		private function formatDataCallProc() {
			$exit    = $this->getExit();
			$success = $this->exitSuccess();
			foreach($this->getPipeNames() as $pipe) {
				$opts = $this->getDescriptorOptions($pipe);
				if (isset($opts['close']))
					continue;
				$output[$pipe] = $this->getOutput($pipe);
			}
			if ($output['stderr']) {
				if (!$success) {
					if (!$this->getOption('mute_stderr'))
						error($this->prog_name.": ".$output['stderr']);
				} else if (!$this->getOption('mute_stderr') && is_debug()) {
					info($this->prog_name.": ".$output['stderr']);
				}
			}
			if ($this->getOption('mute_stdout')) {
				$output['stdout'] = null;
			}
			$response =  array(
				'output'       => $output['stdout'],
				'errno'        => $exit,
				'return'       => $exit,
				'error'        => $output['stderr'],
				'success'      => $success
			);
			return array_merge($output, $response);
		}

		/**
		 * Util_Process a program
		 *
		 * @todo add multiple pipe support (fd > 2)
		 * @todo rewrite pipe algorithm, distribute processing to peers
		 * @param $streams streams
		 * @param $maps    stream lookup table
		 * @param $procs   process list
		 * @return unknown_type
		 */

		public function process() {
			$read     = $write = $oob = array();
			$procs    = array();
			$prevpeer = null;
			$peer     = $this;
			$peeridx  = 0;

			$tv_sec  = $this->getOption('tv_sec');
			$tv_usec = $this->getOption('tv_usec');
			$BLKRD = 8192;       //   8k reads
			$BUFSZ = 4096*64;   // 128k buffer
			$T_LOOP  = 500;
			$T_READ  = 250;
			$T_WRITE = 500;
			$DEBUG   = is_debug();
			$streambytes = 0;
			if (!empty($this->callbacks['exec'])) {
				$this->callback('exec', $this->cmd);
			}
			/**
			 * @todo drop the "2" functions, replace with asXYZ()
			 * refactor the loop into smaller, manageable methods
			 */
			do {
	        	foreach ($peer->getDescriptors() as $descriptor) {

	        		$pipe   = $peer->getPipe($descriptor);
	        		$mode   = $peer->getDescriptorMode($descriptor);
	        		$type   = $peer->getDescriptorType($descriptor);
	        		$opts   = $peer->getDescriptorOptions($descriptor);
					$revkey = $this->resource2String($pipe);

					// use res ids as fd names
	        		if ($peeridx == 0) {
	        			$key = array_search($descriptor, $this->pipeMap);
	        			$this->removePipeMap($key);
	        			$this->addPipeMap($key, $revkey);
					}

	        		if (isset($opts['close']) && $opts['close']) {
	        			$peer->addResourceMap($revkey, $descriptor, $peeridx);
	            		$peer->pipeClose($pipe);
	            		continue;
	            	}

					$descriptor = $revkey;
					$this->setDescriptor($descriptor, $type, $mode, $descriptor, $opts);

	            	//$this->pipes[$descriptor] = $pipe;
	            	//var_dump($descriptor, $pipe);
	            	$this->addResourceMap($revkey, $descriptor, $peeridx);

	        		if ($mode[0] == 'r') {
	        			$write[]  = $pipe;
//	        			stream_set_blocking($pipe, 0);
//						stream_set_write_buffer($pipe,0);
	        		} else if ($mode[0] == 'w') {
	        			$read[] = $pipe;
//	        			stream_set_blocking($pipe, 1);

	        		} else
	        			warn($mode.": unsupported mode for fd ".$descriptor);
	        	}
				if ($prevpeer) {
					// connect stdout to stdin
		        	$this->connectPipeline($prevpeer->getPipe('stdin'),
		        		$peer->getPipe('stdout'));
				}

				$prevpeer = $peer;
				$procs[]  = $peer;
				$peeridx++;
			} while (false != ($peer = $peer->getPipePeer()));
			// quick lookup maps, transforms:
			// resource #12 -> 12 => resource #12
			//
			// resource:  resource created by fopen()
			// streamidx: position of the resource within stream_select() array
			$maps = array();

			ignore_user_abort(true);
			foreach (array('read','write','oob') as $name) {
				$fdarr = $$name;
		 		for ($i = 0, $szarr = sizeof($fdarr); $i < $szarr; $i++) {
		 			$idx   = $this->resource2String($fdarr[$i]);
		 			$res   = $fdarr[$i];
		 			$pljob = $this->lookupPipeline($idx);
		 			$fd    = $this->resource2Descriptor($res);
		 			$maps[$idx] = array(
		 				'streamidx' => $i,
		 				'residx'    => &$fd,
	 		 		    'type'      => $name,
		 				'procid'    => $this->revPipeMap[$idx]['procid'],
		 				'peerid'    => null,
		 				'pipe'      => $this->getPipe($fd),
		 				'buffer'    => null,
		 				'bufpos'    => 0
		 			);
		 			$this->pipeline[$peer]['bufferfree'] = $BUFSZ;
		 			if ($pljob) {
		 				if ($name == 'read') {
		 					$peer = $pljob['write'];
		 				} else {
		 					$peer = $pljob['read'];
		 				}
		 				$maps[$idx] = array_merge($maps[$idx],
		 					array(
		 						'peerid'   => $peer,
		 						'peer'     => $this->getPipe($peer),
		 						'buffer'   => &$this->pipeline[$peer]['buffer'],
		 						'wpbuf'    => &$this->pipeline[$peer]['wpbuf'],
		 						'bufferfree'=>&$this->pipeline[$peer]['bufferfree'],
		 						'rpbuf'    => &$this->pipeline[$peer]['rpbuf'],
		 					)
		 				);

		 			}
		 		}
			}
			$streams = array('read'   => $read,
							 'write'  => $write,
 	    					 'oob'    => $oob);
			$this->maps = &$maps;
			$this->streams = &$streams;

			$stream_names = array_keys($streams);

			$i = 0;
			for(; count($read) || count($write) ; $read  = $streams['read'],
				$write = $streams['write'], $i++) {
				if (connection_aborted()) {
					error_log("Killing");
					return $this->_kill();
				}

				$changed = stream_select($read, $write, $oob, $tv_sec, $tv_usec);
				if ($changed === false) {
					error("stream_select() error!");
					return $this;
				} else if (!$changed) {
					continue;
				}


				// enumerate the changed streams
				foreach ($stream_names as $name) {
					$chstreams = ${$name};
					foreach ($chstreams as $piperes) {
			    		// get fd number for resource
						$pipeid    = $this->resource2String($piperes);
						$pipeidx   = array_search($piperes, $this->pipes);
						$pipe      = $this->pipes[$pipeidx];
						$op        = 'read';
						$pipemap   = $maps[$pipeid];
						$pipekey   = $pipemap['residx'];
						//$pipe      = $pipemap['pipe'];
						//if ($DEBUG && IS_CLI) print "Ready - $piperes - $op\n";
						// do necessary clean-up

						//print "Incoming on $pipeid - $op\n";
						$total = $bytes = 0;
						$eof   = true;
						$data = array();
						if ($op == 'read') //&& !$pipebuf || !$pipebuf && sizeof($procs) > 1 && $streambytes > 0)
						{ // read
							//print "YAY";
							//if (isset($procs[1]) && $procs[1]->running()) {
								//print "Not closed... continuing...\n";
							//}
	   					    // direct output, store
							/*while (!feof($piperes)) {
	    						$data  = fread($piperes,$BLKRD);
	    						$bytes = strlen($data);
	    						$eof &= ($bytes > 0);
	    						$total += $bytes;*/
							stream_set_blocking($piperes, 0);
							$total = 0;
							$buffer = array();
							while (!feof ($piperes)) {
								$tmp = stream_get_contents($piperes,$BLKRD);
	    						$buffer[] = $tmp;
								$bytes = strlen($tmp);
								$total += $bytes;
								if ($bytes < 1) break;
							}
							//print "Read!";
							if ($total < 1) {
								fclose($this->pipes[$pipeidx]);
								unset($this->pipes[$pipeidx]);
								$streams['read'] = $this->pipes;
								//$this->streamClose($piperes, $streams, $maps);
								continue;
							}
							$buffer = join("",$buffer);
	    					$this->addOutput($pipeid, $buffer);
	    					if (!empty($this->callbacks['read'])) {
								$this->callback('read',
									$buffer,
									$bytes
								);
	    					}
	    					continue;
						}
					}
				}
			}
			foreach ($this->pipes as $pipe) fclose($pipe);
			return $this;
		}

		private function _kill() {
			$pstatus = proc_get_status($this->proc_instance);
			return posix_kill($pstatus['pid'], 9);
		}

		/**
		 * Terminate process
		 *
		 * @return int exit value
		 */
		public function close() {
		    $peer = $this->getPipePeer();
		    if ($peer)
				$peer->close();
			$exit = proc_close($this->proc_instance);
			// reset priority if adjusted
			if (null !== ($prio = $this->getPriority())) {
				$newprio = $this->_oldPriority;
				pcntl_setpriority($newprio);
				unset($this->_priority);
			}
			$this->setExit($exit);
			$this->callback('close', $exit);
			return $this;
		}
		/**
		 * Util_Process callback request
		 *
		 * @param string $when
		 * @param mixed  $args,... arguments
		 * @return unknown_type
		 */
		private function callback($when, $args) {
			if (!isset($this->callbacks[$when]))
				return error("callback `$when' is not registered");
			if (!$this->callbacks[$when])
				return false;

			$args = func_get_args();
			array_shift($args);
			$args[] = $this;

			foreach ($this->callbacks[$when] as $cb) {
				call_user_func_array(
					$cb['function'],
					array_merge($args, $cb['args'])
				);
			}
		}

		/**
		 *
		 * @param  string        $when      callback context {@link $this->callbacks}
		 * @param  object|string $function
		 * @return bool
		 */
		public function addCallback($function, $when = 'read') {
			$args = func_get_args();
			if (!isset($this->callbacks[$when]))
				return error($when.": invalid callback context");
			if (!is_callable($function))
				return error("callback not callable");
			$this->callbacks[$when][] = array(
				'function' => $function,
				'args'     => array_slice($args, 2)
			);
		}

		public function &getPipePeer() {
			return $this->proc_peer;
		}

		/**
		 * Read pipe input
		 *
		 * @param  int|resource $pipe
		 * @param  int          $length
		 * @param  int          $offset
		 * @return string
		 */
		public function pipeRead($pipe, $length = -1, $offset = 0) {
			$pipe = $this->getPipe($pipe);
			return stream_get_contents($pipe, $length, $offset);
		}

		/**
		 * Write to pipe
		 *
		 * @param  int|resource $pipe
		 * @param  string       $data
		 * @param  int          $length
		 * @return int                  number of bytes written
		 */
		public function pipeWrite($pipe, $data, $length = null) {
			$pipe = $this->getPipe($pipe);
			return fwrite($pipe, $data, $length);
		}

		/**
		 * Close pipe
		 *
		 * @param  int|resource $pipe
		 * @return bool
		 */
		public function pipeClose(&$pipe) {
			if (!is_resource($pipe))
	            $pipe = $this->getPipe($pipe);

			$fd   = $this->resource2Descriptor($pipe);
			unset($this->pipes[$fd]);
			$alias = array_search($pipe,$this->pipes);
			if (false !== $alias) unset($this->pipes[$alias]);
			for ($i = 0; $i < 5; usleep(500), $i++) {
				fclose($pipe);
				if (!is_resource($pipe)) {
					break;
				}
			}
			if (is_resource($pipe) && !feof($pipe)) {
				print stream_get_contents($pipe);
				error("pipe $pipe not closed yet!");
			}
		}

        /**
         *  named arguments
         *  first el in $args will be hash of arguments
         */
        protected function _setArgsNamed($args, $pos = null) {
           // map each format param by its numeric location
           // to substitute later as %n$
           $argtable = array();
           $n=1;
           foreach ($args[0] as $k => $v) {
               $argtable[$k] = $n;
               $n++;
           }

           $cmd = $this->cmd;

           $newstr = array();
           $start = 0;
           if (is_null($pos)) {
               $pos = strpos($cmd, "%(");
           }
           while (false !== $pos) {
               $len = $pos-$start;
               $newstr[] = substr($cmd, $start, $len);
               $pos += 2 /* %( */;
               $n = strpos($cmd, ")", $pos);
               if ($n === false) {
                   warn("malformed format var name");
                   break;
               }
               $symlen = $n - $pos;
               $sym = substr($cmd, $pos, $symlen);
               $pos += $symlen + 1 /* ) */;
               // lookup sym position
               if (isset($argtable[$sym])) {
                   $newstr[] = "%" . $argtable[$sym] . "$";
               } else {
                   warn("unknown format var `%s'", $sym);
               }
               $start = $pos;
               $pos = strpos($cmd, "%(", $pos);
           }
           $newstr[] = substr($cmd, $start);
           $cmd = implode("", $newstr);
           $this->args = array_slice($args, 1);
           $this->cmd = vsprintf($cmd, $args[0]);
           return true;
        }

		protected function _setArgs($args) {
			if (!isset($args[0])) return true;

			$cmd = $this->cmd;
            $fmt_args = array();
            // cmd commands symbolic format parameters
            // arguments always presented as a hash
            $pos = strpos($cmd, "%(");
            if ($pos !== false) {
                $this->_setArgsNamed($args, $pos);
                $cnt = 1;
            } else {
                $pos = strpos($cmd, "%");
                $cnt = 0;
                while ($pos !== false) {
                    $cnt++;
                    $pos++;
                    if ($cmd[$pos] == "%") {
                        $cnt--;
                        $pos++;
                    } else if (($d = ctype_digit($cmd[$pos]))) {
                        $cnt = max($cnt, $d);
                    }
                    $pos = strpos($cmd, "%", $pos);
                }
                // format specifiers exist
                if ($cnt) {
                    if (!is_array($args[0])) {
                        $fmt_args = array_slice($args, 0, $cnt);
                    } else {
                        // args provided as single array
                        $fmt_args = $args[0];
                        $cnt = 1;
                    }
                    $this->args = $fmt_args;
                    $args = array_slice($args, $cnt);
                }

                $this->cmd = vsprintf($cmd, $fmt_args);
            } 
            // all args satisfied
            if (empty($args)) {
                return true;
            }
            for ($i=0; $i < 2; $i++) {
                $var = array_pop($args);
                if ($var === null) {
                    break;
                }
                // check if $var is array of exit codes
                // or a regex with matching delimiters
                if (is_array($var) && isset($var[0]) || is_string($var) && $var[0] == $var[strlen($var)-1]) {
                    $this->exits = $var;
                } else if (is_array($var)) {
                    // options array
                    $this->opts = array_merge($this->opts, $var);
                } else {
                    break;
                }
            }

            $szargs = sizeof($args);
			if ($szargs > 0) {
	            $caller = Error_Reporter::get_caller(1,'/Module_Skeleton|Util_Process/');
                warn($caller."(): %u additional arguments ignored",
                    $szargs);
			}
            
            return true;            
		}


		/**
		 * Connect in to out
		 * @param $in  input stream
		 * @param $out output stream
		 * @return unknown_type
		 */
		private function connectPipeline(&$in, &$out) {

			$outkey = $this->resource2String($out);
			$inkey  = $this->resource2String($in);
			//print "Connecting $out output ($outkey) to $in ($inkey)\n";
			// buffer write position used by output pipe
			$wbufpos = 0;
			// buffer read position used by input
			$rbufpos = 0; // $BUFSZ
			$buffer = $free = null;
			$this->pipeline[$inkey]  = array(
				'read'  => $outkey,
				'ready' => true,
				'buffer'=> &$buffer,
				'wpbuf' => &$wbufpos,
				'rpbuf' => &$rbufpos,
				'bufferfree' => &$free
			);
			$this->pipeline[$outkey] = array(
				'ready' => false,
				'buffer'=> &$buffer,
				'write' => $inkey,
				'wpbuf' => &$wbufpos,
				'rpbuf' => &$rbufpos,
				'bufferfree'=>&$free
			);
			return true;
		}

		private function lookupPipeline($lookup) {
			return isset($this->pipeline[$lookup]) ?
				$this->pipeline[$lookup] : false;
		}

		public function setExits($exits = '/./') {
			$this->exits = $exits;
			return $this;
		}

		public function getExits() {
			return $this->exits;
		}

		public function exitSuccess() {
			if (!$this->exits)
				$this->exits = '/^0$/';
			if (ctype_digit($this->exits)) 
				$this->exits = (array)$this->exits;
			if (is_array($this->exits))
				$exits = '/^'.str_replace("-",'\-',implode("|",$this->exits)).'$/';
			else
				$exits = $this->exits;
			return preg_match($exits,$this->exitStatus);
		}

		/**
		 * Return process exit code
		 *
		 * @return int
		 */
		public function getExit() {
			return $this->exitStatus;
		}
		/**
		 * Return process command
		 *
		 * @return string
		 */
		public function getCommand() {
			return $this->cmd;
		}

		public function setEncoding($mode, $fd = null) {
			$mod = '';
			if ($this->proc_instance)
				return warn($this->prog_name.": process has started, encoding change ignored");
			if ($mode == 'binary')
				$mod = 'b';

			if ($fd) {
				$descriptors = array($fd);
			} else {
				$descriptors = array_keys($this->descriptors);
			}
			foreach ($descriptors as $fd) {
				$fd = $this->alias2Descriptor($fd);
				$fdmode = &$this->descriptors[$fd][1];
				$fdmode = $fdmode[0].$mod;
			}
			return $this;
		}

		public function setOption($name, $val = null) {
			if (is_null($val) && isset($this->opts[$name]))
				unset ($this->opts[$name]);
			else
				$this->opts[$name] = $val;
			return $this;
		}

		public function getOption($name) {
			return isset($this->opts[$name]) ?
				$this->opts[$name] : null;
		}

		/*
		 * Assign file descriptor
		 *
		 *
		 * @param  int    $fd    fd channel
		 * @param  string $type  descriptor type (pipe, file)
		 * @param  string $mode  file mode {@link http://php.net/fopen fopen() modes}
		 * @param  string $alias fd name
		 * @return object instance of self
		 */
		public function setDescriptor($fd, $type, $mode, $alias = '', $opts = array()) {
			if ($type !== 'pipe' && $type !== 'file')
				return error($type.": invalid fd type");
			if (!$alias)
				$alias = $fd;
			if ($this->getOption('binary'))
				$mode .= 'b';
			$this->descriptors[$fd]   = array($type, $mode);
			$this->channelData[$fd]   = array();
			$this->addPipeMap($alias, $fd);
			$this->opts['fd'][$fd] = array();
			foreach ($opts as $opt => $val) {
				$this->setDescriptorOption($fd, $opt, $val);
			}
			return $this;
		}

		public function setDescriptorOption($fd, $opt, $value) {
			$fd = $this->alias2Descriptor($fd);
			$this->opts['fd'][$fd][$opt] = $value;
			return $this;
		}

		public function getDescriptorOptions($fd) {
			$fd = $this->alias2Descriptor($fd);
			return isset($this->opts['fd'][$fd]) ?
				$this->opts['fd'][$fd] :
				array();

		}

		public function getDescriptorOption($fd, $opt) {
			$fd = $this->alias2Descriptor($fd);
			return isset($this->opts['fd'][$fd]) &&
				isset($this->opts['fd'][$fd][$opt]) ?
				$this->opts['fd'][$fd][$opt] :
				array();
		}

		public function getOutput($pipe) {
			$pipe = $this->alias2Descriptor($pipe);
			if (!isset($this->channelData[$pipe]))
				return "";
			return implode("",$this->channelData[$pipe]);
		}

		public function getDescriptors() {
			return array_keys($this->pipes);
		}
		/**
		 * Get mode assigned to descriptor
		 * @param  int|string $fd fd/alias
		 * @return string         fd mode (r,w,a)
		 */
		public function getDescriptorMode($fd) {
			$fd = $this->alias2Descriptor($fd);
			if (!isset($this->descriptors[$fd]))
				return error($fd.": descriptor not opened");

			return $this->descriptors[$fd][1];
		}

		/**
		 * Get type assigned to descriptor
		 * @param  int|string $fd fd/alias
		 * @return string         fd type (file, pipe)
		 */
		public function getDescriptorType($fd) {
			$fd = $this->alias2Descriptor($fd);
			if (!isset($this->descriptors[$fd]))
				return error($fd.": descriptor not opened");

			return $this->descriptors[$fd][0];
		}

		/**
		 * Add a lookup for fd alias => fd
		 * @param int    $fd    fd descriptor assigned by setDescriptor()
		 * @param string $name  alias
		 * @return unknown_type
		 */
		private function addPipeMap($alias, $fd) {
			$this->removePipeMap($fd);
			if (isset($this->pipeMap[$alias]))
				warn($alias.": overwriting alias, previous descriptor ".$this->pipeMap[$alias]);

			$this->pipeMap[$alias] = $fd;
		}

		/**
		 * Translate pipe resource into descriptor
		 * @param $pipe
		 * @return unknown_type
		 */
		private function resource2Descriptor($pipe) {
			if (is_null($pipe)) {
				return error(__METHOD__.": pipe cannot be null");
			}
			if (is_resource($pipe))
				$pipe = $this->resource2String($pipe);

			return $this->revPipeMap[$pipe]['fd'];
		}

		/**
		 * Translate alias into descriptor
		 *
		 * @param $pipe
		 * @return unknown_type
		 */
		private function alias2Descriptor($pipe) {
			if (is_resource($pipe))
				$pipe = $this->resource2Descriptor($pipe);
			else if (isset($this->pipes[$pipe]))
				return $pipe;
			else if (isset($this->descriptors[$pipe])) {
				return $pipe;
			}
			return $this->pipeMap[$pipe];
		}

		private function removePipeMap($fd) {
			if (isset($this->pipeMap[$fd])) {
				unset($this->pipeMap[$fd]);
				return true;
			}
			$fd = $this->getPipeNames($fd);
			if (false === ($key = array_search($fd, $this->pipeMap))) {
				unset($this->pipeMap[$key]);
			}

			return $key;
		}
		/**
		 * Get all defined pipe names, alias preferred
		 *
		 * @return array
		 */
		public function getPipeNames() {
			return array_keys($this->pipeMap);
		}
		/**
		 * Fetch pipe resource given a descriptor
		 *
		 * @param string|int $name pipe alias or fd
		 * @return resource
		 */
		private function &getPipe($name) {
			if (is_resource($name)) {
				return $name;
			}
			if (isset($this->pipes[$name])) {

				return $this->pipes[$name];
			}
			if (!isset($this->pipeMap[$name]))
				$name = array_search($name, $this->pipeMap);
			$key = $this->pipeMap[$name];
			if (isset($this->pipes[$key])) {
				$pipe = &$this->pipes[$key];
			} else
				$pipe = false;
			return $pipe;
		}
		/**
		 * Convert resource name to fd int
		 *
		 * Example: Resource #12 -> 12
		 * @param  resource $res resource created by fopen()
		 * @return int
		 */
		private function resource2String($res) {
			if (!is_resource($res)) {
				return error($res.": not a fd resource");
			}
			return substr(strstr((string)$res,"#"), 1);
		}


		private function setExit($code) {
			$this->exitStatus = $code;
		}
		/**
		 * Close and remove a stream from stream_select()
		 *
		 * @param mixed       $fdname fd name
		 * @param $streams    all streams
		 * @param $lookupMap  lookup map
		 * @return bool
		 */
		private function streamClose($fd, &$streams, $lookupMap) {
			$fd = $this->getPipe($fd);

			$key  = $this->resource2String($fd);
			if (!isset($lookupMap[$key])) {
				return false;
			}

			$streamInfo = $lookupMap[$key];
			$type = $streamInfo['type'];
			$resource   = &$fd;

			$idx  = array_search($resource, $streams[$type]);

			// Indexes change every time a stream is pruned from $streams
			if ($idx === false)
				warn($fd.": unknown stream");
			$this->pipeClose($resource);
			if ($type == "write" && is_resource($streamInfo['buffer'])) {
				fclose($streamInfo['buffer']);
			}
			unset($this->pipeline[$key]);
			unset($streams[$type][$idx]);
		}

		private function streamOpen($fd, &$streams, $map) {
	        $pipe = $this->getPipe($fd);
	        if (!$pipe)
	        	return false;
	        $key  = $this->resource2String($pipe);
	        if (!isset($map[$key])) {
	        	return false;
	        }

	        $streamInfo = $map[$key];
	        $streamType = $streamInfo['type'];
	        $idx  = array_search($pipe, $streams[$streamType]);
	        if ($idx === false)
				return false;

			return is_resource($streams[$streamType][$idx]);
		}

		private function addOutput($pipe, $data) {
			if (is_resource($pipe))
				$pipe = $this->resource2Descriptor($pipe);
			$this->channelData[$pipe][] = $data;
		}

		private function flushOutput($pipe) {
			$pipe   = $this->resource2Descriptor($pipe);
			$output = $this->getOutput($pipe);
			$this->channelData[$pipe] = array();
			return $output;
		}

		private function setPipelineReady($id, $state) {
			$this->pipeline[$id]['ready'] = $state;
		}
		private function pipelineReady($id) {
			if (!isset($this->pipeline[$id]))
				return null;
			return $this->pipeline[$id]['ready'];
		}
		private function processPipeline(&$in, &$out) {

		}

		/**
		 * Break a full command down into its command + arguments
		 *
		 * @param $cmd
		 * @return array
		 */
		public static function decompose($cmd)
		{
			$parts = array('cmd' => null, 'args' => array());
			$cmd = trim($cmd);
			if (!$cmd) {
				return $parts;
			}
			$delim = '" \\\'';
			$offset = 0;
			$tmp = array();
			// parse out command
			$mycmd = $cmd;
			$tok = strtok($mycmd, $delim);
			do {
				if (false === $tok) { break; }
				$offset += strlen($tok);
				$tmp[] = $tok;

				if ($offset >= strlen($mycmd)) {
					break;
				}
				$chr = $mycmd[$offset];

				if ($chr == '\\') {
					// @TODO keep the backslash?
					$tmp[] = $mycmd[++$offset];
					$offset++;
					$mycmd = substr($mycmd, $offset);
					$offset = 0;
					$tok = strtok($mycmd, $delim);
					continue;
				} else if ($chr == '"') {
					$tok = strtok('"');
					continue;
				} else if ($chr == "'") {
					$tok = strtok("'");
					continue;
				} else {
					break;
				}

				$tok = strtok($delim);
			} while (true);
			$parts['cmd'] = join("", $tmp);
			$myargs = ltrim(substr($mycmd, $offset));
			if (!$myargs) {
				return $parts;
			}

			// second pass, do arguments, which are slightly varied

			while ($myargs) {
				$offset = 0;
				$tok = strtok($myargs, $delim);
				$tmp = array();
				do {
					if (false === $tok) { break; }
					$chr = $myargs[$offset];

					$tmp[] = $tok;
					$offset += strlen($tok);
					if ($offset >= strlen($myargs)) {
						break;
					}

					if ($chr == '\\') {
						// @TODO keep the backslash?
						$tmp = (array)array_pop($parts['args']);
						$tmp[] = $myargs[++$offset];
						$myargs = substr($myargs, $offset);
						$offset = 0;
						$tok = strtok($myargs, $delim);
						continue;
					} else if ($chr == '"' || $chr == "'") {
						$tmp[] = $myargs[++$offset];
						$myargs = substr($myargs, ++$offset);
						$offset = 0;
						$tok = strtok($myargs, ($chr == '"' ? '"' : "'"));
						$tmp[] = $tok;
						break;
					} else {
						break;
					}
					$tok = strtok($delim);
				} while (true);
				// no more tokens
				if (!$tmp) { break; }
				$newargs = join("", $tmp);
				$parts['args'][] = $newargs;
				$offset = strlen($newargs);
				$len = strlen($myargs);

				// extra chars, trim them out
				if ($offset < $len) {
					while ($myargs[$offset] == " ") {
						$offset++;
						if ($offset >= $len) break;
					}
				}
				$myargs = substr($myargs, $offset);
			}
			return $parts;
		}

		private function _debugRing($r, $w, $sz, $buffer) {
			$width = 80;
			$posr = floor($r['pos']/$sz*$width);
			$posw = floor($w['pos']/$sz*$width);
			$fmt = "|%%%ds%%%ds%%-%ds%%s\n";
			$fw = $width;
			if ($posr == $posw) {
				if ($r['pos'] == $w['pos'])
					$args = array('B','','','|');
				else
					$args = array('*','','','|');
				$fmtargs = array($posr,$fw-$posr,0);

			} else if ($posr > $posw) {
				$fmtargs = array($posw, $posr-$posw, $fw-$posr);
				$args = array('w','','r','|');
			} else {
				$fmtargs = array($posr, $posw-$posr-1, $fw-$posw);
				$args = array('r','','w','|');
			}

			if (max($posr,$posw) >= 80) {
				$max    = array_pop($args);
				$border = array_pop($args);
				$args[] = $max; $args[] = $border;
			}
			$fmt = vsprintf($fmt,$fmtargs);
			$fmt = vsprintf($fmt,$args);
			print '+'.str_repeat('-',$width).'+'."\n";
			print $fmt;
			print '+'.str_repeat('-',$width).'+'."\n";
			$size = $w['pos']- $r['pos'];
			if ($size < 0)
				$size += $sz;
			printf("read: %-10d write: %-10d pos: %-10d %12s(%5d/%5d bytes)\n\n",$r['pos'],$w['pos'],ftell($buffer),' ',$size,$sz);
		}
	}
?>
