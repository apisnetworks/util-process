<?php

    /**
     * Chroot process module
     *
     * MIT License
     *
     * @author  Matt Saladna <matt@apisnetworks.com>
     * @license http://opensource.org/licenses/MIT
     * @version $Rev: 2687 $ $Date: 2017-01-14 13:58:49 -0500 (Sat, 14 Jan 2017) $
     */
    class Util_Process_Chroot extends Util_Process
    {
        private $_root;
        private $_user;
        private $_groups;

        /**
         * Constructor
         *
         * @param string $root   new root directory
         * @param null   $user   user or user:group to set upon chroot
         * @param null   $sgroup supplementary groups in the form g1,g2,.. gN
         */
        public function __construct($root = null, $user = null, $sgroup = null)
        {
            parent::__construct();
            if (posix_geteuid()) {
                fatal("chroot processes must run as root");
            }
            $this->_root = $root;
            if ($user) {
                $this->setUser($user);
            }
            if ($sgroup) {
                $this->setGroups($sgroup);
            }
        }

        final public static function exec($cmd, $args = null, $exits = array(0), $opts = array())
        {
            return error("cannot call exec() directly, must set root first");
        }

        public function setUser($user)
        {
            // leave verification to chroot
            $this->_user = $user;
        }

        public function setGroups($group)
        {
            // leave verification to chroot
            $this->_groups = $group;
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
         * @return bool|\Util_Process
         */

        public function run($cmd, $args = null)
        {
            if (posix_geteuid()) {
                return error(
                    "%s: cannot chroot without CLI",
                    Error_Reporter::get_caller(1, '/^Util_/')
                );
            }

            if (!file_exists($this->_root)) {
                return error("path `%s' does not exist", $this->_root);
            }
            $xtraflags = '';
            if (isset($this->_user)) {
                $xtraflags .= '--userspec=' . escapeshellarg($this->_user) . ' ';

            }

            if (isset($this->_groups)) {
                $xtraflags .= '--groups=' . escapeshellarg($this->_groups) . ' ';
            }
            if (function_exists('chroot')) {
                $dir = opendir('.');
                chroot($this->_root);
                $args = func_get_args();
                call_user_func_array('parent::run', $args);

            }

            $args = func_get_args();
            parent::setOption('run', false);
            if (isset($args[0]) && false !== strpos($args[0], '%(')) {
	            /**
	             * a little screwy, but early init, parse args,
	             * remove args, add chroot args, resubmit..
	             *
	             * if not, then escapeshellcmd escapes named format
	             * specifiers, e.g. %(foo)s becomes %\(foo\)s
	             */
            	parent::_init($args[0], [$args[1]]);
				$cmd = $this->getCommand();
				unset($args[1]);
            }

            $cmd = sprintf('chroot %s %s %s',
                escapeshellarg($this->_root),
                $xtraflags,
                escapeshellcmd($cmd)
            );
            $args[0] = $cmd;
            return call_user_func_array('parent::run', $args)->forceRun();
        }
    }

?>