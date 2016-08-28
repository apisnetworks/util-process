<?php
	/**
	 * Add sudo support to Util_Process
	 *
	 * MIT License
	 *
	 * @author  Matt Saladna <matt@apisnetworks.com>
	 * @license http://opensource.org/licenses/MIT
	 * @version $Rev: 2450 $ $Date: 2016-08-17 15:15:41 -0400 (Wed, 17 Aug 2016) $
	 */
	class Util_Process_Sudo extends Util_Process_Safe
	{
		public function setUser($user) {
			$domain = Auth::profile()->domain;

			if (false !== ($pos = strpos($user, '@'))) {
				$domain = substr($user, $pos+1);
				$user = substr($user, 0, $pos);
			}
			if (!is_array($user)) {
				if (false !== ($pos = strpos($user, '@'))) {
					$domain = substr($user, $pos);
					$user = substr($user, $pos);
				}
			} else {
				if (isset($user['domain'])) {
					$domain = $user['domain'];
				}
				if (isset($user['user'])) {
					$user = $user['user'];
				} else {
					$user = Auth::profile()->username;
				}
			}

			// for security reasons, reset the domain to the account
			if (Auth::profile()->level & (PRIVILEGE_USER|PRIVILEGE_SITE)) {
				$domain = Auth::profile()->domain;

			} else if ($domain && !Auth::domain_exists($domain)) {
				return error("domain `%s' does not exist", $domain);
			}
			if ($domain && !apnscpFunctionInterceptor::init()->user_exists($user)) {
				return error("user `%s' does not exist", $user);
			}
			$this->opts['user'] = $user . ($domain ? '@' . $domain : '');
		}
		
		/**
		 * Additional options:
		 * 	user:    target username
		 *  domain:  target domain (default to current domain)
		 * 
		 * @param string $cmd
		 * @param mixed  $args
		 * @param mixed  $exits
		 * @param mixed  $options
		 */
		public function run($cmd, $args = null)
		{
			if (!IS_CLI) {
				return error(
					"%s: cannot sudo without CLI",
					Error_Reporter::get_caller(1,'/^Util_/')
				);
			}

			$umask = '';
			if (null !== ($umask = $this->getOption('umask'))) {
				$umask = 'umask ' . decoct($umask) . ' &&';
			}
			$user = $this->getOption('user');
			if (!$user) {
				$cred = array(
					'domain' => Auth::profile()->domain,
					'user' => Auth::profile()->username
				);
				$user = $cred['user'];
				if ($cred['domain']) {
					$user .= '@' . $cred['domain'];
				}
			}
			$cmd = sprintf('su %s -c "%s"',
				$user,
				$umask . "cd ~ && " . $cmd

			);
			$args = func_get_args();
			//array_shift($args);

			$args[0] = $cmd;

			return call_user_func_array('parent::run',$args);
		}
	}
?>