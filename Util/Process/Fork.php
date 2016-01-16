<?php
	/**
	 * A forking implementation for Util_Process
	 *
	 * MIT License
	 *
	 * @author  Matt Saladna <matt@apisnetworks.com>
	 * @license http://opensource.org/licenses/MIT
	 * @version $Rev: 1786 $ $Date: 2015-05-28 00:15:38 -0400 (Thu, 28 May 2015) $
	 */
	class Util_Process_Fork extends Util_Process
	{
		public function run($cmd, $args = null)
		{
            if (!function_exists('pcntl_fork')) {
	            fatal("can't fork! posix functions missing");
            }

			$this->setOption('run', false);
			$args = func_get_args();
			call_user_func_array('parent::run', $args);
			$cmd = $this->getCommand();
			$parts = parent::decompose($cmd);
			if ($parts['cmd'][0] !== "/") {
				// pcntl_fork barfs if an absolute path isn't given
				// path discovery is part of the shell
				return error("command path must be absolute path");
			}

			$pid = pcntl_fork();

			if ($pid == -1) {
				fatal("fork failed!");
			}
			if ($pid) {
				pcntl_signal(SIGCHLD, SIG_IGN);
				$ret = parent::format();
				$ret['success'] = ($pid > 0);
				$ret['return'] = $pid;
				return $ret;
			} else {
				posix_setsid();
				pcntl_exec($parts['cmd'], $parts['args'], $this->getEnvironment());
				exit();
			}
		}
	}
?>