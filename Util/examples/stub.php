<?php
	$includes = ini_get('include_path');
	ini_set('include_path', dirname(__FILE__) . '/../../' . PATH_SEPARATOR . $includes);
	include("error-reporter/Error_Reporter.php");
	include("error-reporter/Error_Reporter/wrapper.php");
	include("error-reporter/Error_Reporter/stub.php");
?>
