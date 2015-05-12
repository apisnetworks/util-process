# Util_Process
General PHP process management utility

## What it does
Util_Process ("UP") provides a flexible process wrapper around command execution. It's used extensively in our control panel, apnscp, to provide a coherent process execution interface. UP includes support for variadic arguments, named arguments (%(name)s), and back references (%1$s).

## Components
UP consists of:
* a basic process driver (`Util_Process`)
* chroot driver (`Util_Process_Chroot`)
* forking processes (`Util_Process_Fork`)
* untrusted argument input (`Util_Process_Safe`)
* scheduled background tasks via at (`Util_Process_Schedule`)
* sudo execution (`Util_Process_Sudo`)
* teed output + persistence - will need some hacking to adapt, part of apnscp - (`Util_Process_Tee`)

## Examples
### Standard program
```php
<?php
  include("Util/Process.php");
  $proc = Util_Process::exec("echo %s", "Hello World!");
?>
```

### Escaping unsafe arguments
```php
<?php  
	include("Util/Process.php");
	include("Util/Process/Safe.php");
	$proc = Util_Process_Safe::exec("echo %s %s", '"Hello World\\!(;', ':(){ :|: & };:');
	print $proc['stdout'];
?>
```

### Using named arguments
```php
<?php
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
```

### Sending a program to background
```php
<?php
	include("Util/Process.php");
	include("Util/Process/Fork.php");
	$proc = Util_Process_Fork::exec(
		"/bin/touch %s",
		tempnam(sys_get_temp_dir(), 'util-process')
	);
	
	print $proc['stdout'];
	print $proc['success'] === true; 
?>
```
### Scheduling a task
In this example, at is used to handle program execution. 
```php
<?php
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
```

## Todo
- [ ] Fully implement pipe support (not working yet)
- [ ] Refactor stream implementation, ~25%-40% slower than shell execution
