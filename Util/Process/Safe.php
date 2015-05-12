<?php
    /**
     * Util Process extension
     * escape untrusted arguments
     *
     * MIT License
     *
     * @author  Matt Saladna <matt@apisnetworks.com>
     * @license http://opensource.org/licenses/MIT
     * @version $Rev: 1760 $ $Date: 2015-05-12 13:28:47 -0400 (Tue, 12 May 2015) $
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
                    $args[$key] = $this->_setArgs($args[$key], $depth+1);
                } else if (is_string($arg)) {
                    $arg = escapeshellarg($arg);
                    $args[$key] = $arg;
                }
            }
            if ($depth > 0) {
                return $args;
            }    
			return parent::_setArgs($args);
		}
	}
?>