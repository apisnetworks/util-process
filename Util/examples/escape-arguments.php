<?php
	include("stub.php");

	include("Util/Process.php");
	include("Util/Process/Safe.php");
	$proc = Util_Process_Safe::exec("echo %s %s", '"Hello World\\!(;', ':(){ :|: & };:');
	print $proc['stdout'];