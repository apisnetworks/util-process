<?php
	/**
	 * Scheduled process when system load permits via at
	 *
	 * MIT License
	 *
	 * @author  Matt Saladna <matt@apisnetworks.com>
	 * @license http://opensource.org/licenses/MIT
	 * @version $Rev: 1786 $ $Date: 2015-05-28 00:15:38 -0400 (Thu, 28 May 2015) $
	 */
	class Util_Process_Batch extends Util_Process
	{
		public function run($cmd, $args = null)
		{
			$safecmd = sprintf("echo %s | batch 2> /dev/null",
				escapeshellarg($cmd)
			);
			$args = func_get_args();
			$args[0] = $safecmd;
			return call_user_func_array(array('Util_Process_Safe', 'exec'), $args);

		}
	}
?>