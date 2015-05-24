<?php
	include("stub.php");

	include("Util/Process.php");
	include("Util/Process/Safe.php");
	include("Util/Process/Schedule.php");
	$proc = new Util_Process_Schedule("5 minutes");
	$ret = $proc->run(
		"/bin/touch %s",
		tempnam(sys_get_temp_dir(), 'util-process')
	);

	print $ret['stdout'];
?>
