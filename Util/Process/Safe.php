<?php

    /**
     * Util Process extension
     * escape untrusted arguments
     *
     * MIT License
     *
     * @author  Matt Saladna <matt@apisnetworks.com>
     * @license http://opensource.org/licenses/MIT
     * @version $Rev: 1786 $ $Date: 2015-05-28 00:15:38 -0400 (Thu, 28 May 2015) $
     */
    class Util_Process_Safe extends Util_Process
    {
        protected function _setArgs($args, $depth = 0)
        {
            foreach ($args as $key => $arg) {
                // only strings can carry nasty payloads
                // objects could with a __toString() method
                // if a module were stupid enough to implement such a calamity
                if (is_array($arg)) {
                    $args[$key] = $this->_setArgs($args[$key], $depth + 1);
                } else {
                    if (is_string($arg)) {
                        $arg = escapeshellarg($arg);
                        $args[$key] = $arg;
                    }
                }
            }
            if ($depth > 0) {
                return $args;
            }
            return parent::_setArgs($args);
        }
    }

?>