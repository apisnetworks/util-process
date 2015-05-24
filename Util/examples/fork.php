<?php
	include("stub.php");

	include("Util/Process.php");
	include("Util/Process/Fork.php");
	$proc = Util_Process_Fork::exec(
		"/bin/touch %s",
		tempnam(sys_get_temp_dir(), 'util-process')
	);

	print $proc['stdout'];
	print $proc['success'] === true;
?>