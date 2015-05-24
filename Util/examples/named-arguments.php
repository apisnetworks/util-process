<?php
	include('stub.php');

	include("Util/Process.php");
	$proc = Util_Process::exec(
		"ls %(flags)s %(path)s",
		array(
			'flags' => '-latr',
		    'path' => '/'
		)
	);
	print $proc['stdout'];
?>
