<?php

	//开始分割文档循环
	$source = file_get_contents('https://raw.githubusercontent.com/typecho-fans/plugins/master/README.md');
	$lines = explode(PHP_EOL,$source);
	$count = count($lines);
	foreach ($lines as $line=>$column) {
		if ($line<40) {
			$desciptions[] = $column;
		} elseif ($column) {
			preg_match_all('/(?<=)[^\|]+/',$column,$metas);
			preg_match('/(?<=\[)[^\]]+/',$metas['0']['0'],$name);
			$names[] = array($name['0'],'');
		}
	}
	//按插件名排序
	sort($names);

//	shell_exec('git clone '.$argv['1'].' /home/runner/work/API_Mirror/GITEE');
//	$output = shell_exec('ls /home/runner/work/API_Mirror/GITEE');

	$api = @file_get_contents('https://gitee.com/api/v5/repos/hoexhe/ActivatePowerMode/git/trees/master');
	file_put_contents('log.txt',$api);
