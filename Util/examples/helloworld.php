<?php  
	include('stub.php');

	include("Util/Process.php");  	
	$proc = Util_Process::exec("echo %s", "Hello World!");
	print $proc['stdout'];
?>
