<?php

    /**
     * Add sudo support to Util_Process
     *
     * MIT License
     *
     * @author  Matt Saladna <matt@apisnetworks.com>
     * @license http://opensource.org/licenses/MIT
     * @version $Rev: 2732 $ $Date: 2017-03-25 14:54:59 -0400 (Sat, 25 Mar 2017) $
     */
    class Util_Process_Sudo extends Util_Process_Safe
    {
    	public function setUserRaw($user) {
    	    /**
	         * Set user without added lookups
	         */
            $this->opts['user'] = $user;
            return true;
	    }

        public function setUser($user)
        {
            $domain = Auth::profile()->domain;

            if (false !== ($pos = strpos($user, '@'))) {
                $domain = substr($user, $pos + 1);
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
            if (Auth::profile()->level & (PRIVILEGE_USER | PRIVILEGE_SITE)) {
                $domain = Auth::profile()->domain;
            } else if ($domain && !Auth::domain_exists($domain)) {
                return error("domain `%s' does not exist", $domain);
            }

            if ($domain && !apnscpFunctionInterceptor::init()->user_exists($user)) {
                return error("user `%s' does not exist", $user);
            }
            return $this->setUserRaw($user . ($domain ? '@' . $domain : ''));
        }

        /**
         * Additional options:
         *    user:    target username
         *  domain:  target domain (default to current domain)
         *
         * @param string $cmd
         * @param mixed  $args
         * @param mixed  $exits
         * @param mixed  $options
         */
        public function run($cmd, $args = null)
        {
            if (getmyuid() != 0) {
                return error(
                    "%s: cannot sudo without root",
                    Error_Reporter::get_caller(1, '/^Util_/')
                );
            }

            if (null !== ($umask = $this->getOption('umask'))) {
                $umask = 'umask ' . decoct($umask) . ' &&';
            }
            $user = $this->getOption('user');
            if (!$user) {
                $cred = array(
                    'domain' => Auth::profile()->domain,
                    'user'   => Auth::profile()->username
                );
                $user = $cred['user'];
                if ($cred['domain']) {
                    $user .= '@' . $cred['domain'];
                }
            }
            if (null === ($home = $this->getOption('home'))) {
                $home = '/tmp';
            } else {
                $home = '~';
            }

            $cmd = sprintf("su -s /bin/sh %s -%sc %s",
                $user,
                $this->getOption('login') ? 'i' : null,
                escapeshellarg($umask . 'cd ' . $home . ' && ' . $cmd)
            );
            /*
             * Won't work on sudo 1.8.6p4+
             * /etc/passwd is persisted after jail, need further investigation
            $cmd = sprintf("sudo %s -n%su %s -- /bin/sh -c %s",
                $this->getOption('home') ? '-H' : null,
                $this->getOption('login') ? 'i' : null,
                $user,
                escapeshellarg($umask . $cmd)
            );*/

            $args = func_get_args();
            $args[0] = $cmd;
            return call_user_func_array('parent::run', $args);
        }

        protected function _setArgs($args, $depth = 0)
        {
            $ret = parent::_setArgs($args, $depth);
            if (!$depth) {
                // actual args
                return $ret;
            }
            foreach ($ret as $k => $v) {
                // double up since su -c '<CMD>' adds first round of single quotes
                $ret[$k] = str_replace("'", "'\\''", $v);
            }
            return $ret;
        }
    }