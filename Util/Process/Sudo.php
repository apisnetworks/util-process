<?php
	/**
	 * Add sudo support to Util_Process
	 *
	 * MIT License
	 *
	 * @author  Matt Saladna <matt@apisnetworks.com>
	 * @license http://opensource.org/licenses/MIT
	 * @version $Rev: 2194 $ $Date: 2016-05-05 13:06:57 -0400 (Thu, 05 May 2016) $
	 */
	class Util_Process_Sudo extends Util_Process
	{
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
			$args   = func_get_args();
			$cmd    = $args[0];
			$len = count($args)-1;
			$opts = array('domain' => Auth::profile()->domain, 'user' => Auth::profile()->username);
			if ($len > 0) {
				// @FIXME run() sets arguments before exec, manually parse out
				// domain + user arguments
				$tmp = $args[$len];
				if (isset($tmp['domain'])) {
					$domain = $tmp['domain'];
					if (!Auth::domain_exists($domain)) {
						return error("domain `%s' does not exist", $domain);
					}
					$opts['domain'] = $domain;
				}
				if (isset($tmp['user'])) {
					$user = $tmp['user'];
					// for security reasons, reset the domain to the account
					if (Auth::profile()->level & (PRIVILEGE_USER|PRIVILEGE_SITE)) {
						$opts['domain'] = Auth::profile()->domain;
					}
					if (!isset($opts['domain'])) {
						$exists = apnscpFunctionInterceptor::init()->user_exists($user);
						if (!$exists) return error("user `%s' does not exist", $user);
					}

					if (false !== ($pos = strpos($user, '@'))) {
						deprecated_func("user@domain notation is deprecated");
						$user = substr($user, 0, $pos);
					}

					$opts['user'] = $user;
				}

			}

			$cmd = sprintf('su -l -c %s %s@%s',
				escapeshellarg("cd ~ && ".$cmd),
				$opts['user'],
				$opts['domain']);
			$args[0] = $cmd;
			return call_user_func_array('parent::run',$args);
		}
	}
?>