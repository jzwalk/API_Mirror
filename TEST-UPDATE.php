<?php
	/**
	 * Typecho-Fans/Plugins专用自动化更新插件信息与zip包脚本
	 * (非Typecho插件，仅供GitHub Actions功能调用，勿删改！)
	 * 作者：羽中
	 * 反馈：https://github.com/typecho-fans/plugins/issues
	 */
	date_default_timezone_set('Asia/Shanghai');

	//创建临时文件夹
	$tmpDir = realpath('../').'/TMP';
	$tmpNew = $tmpDir.'/NEW';
	if (!is_dir($tmpDir)) {
		mkdir($tmpDir);
	}
	if (!is_dir($tmpNew)) {
		mkdir($tmpNew);
	}

	//分析最新文档变化
	if (!empty($argv['2']) && strpos($argv['2'],'.diff')) {
		$record = @file_get_contents($argv['2'],0,
			stream_context_create(array('http'=>array('header'=>array('Accept: application/vnd.github.v3.diff')))));
		$diffs = explode(PHP_EOL,$record);

		//确定行范围
		$begin = 0;
		$end = count($diffs)-1;
		foreach ($diffs as $line=>$diff) {
			if ($diff=='+++ b/TESTORE.md') {
				$begin = $line;
			}
			if ($begin && $line>$begin && strpos($diff,'diff --git')===0) {
				$end = $line;
				break;
			}
		}
		//提取变化行
		$links = array();
		$urls = array();
		foreach ($diffs as $line=>$diff) {
			if ($begin && $line>$begin && $line<$end && strpos($diff,'+[')===0) {
				preg_match_all('/(?<=\()[^\)]+/',$diff,$links);
				$urls[] = $links['0']['0'];
			}
		}
	}

	//预设循环内变量
	$desciptions = array();
	$links = array();
	$metas = array();
	$url = '';
	$github = false;
	$condition = false;
	$all = 0;
	$authorCode = '';
	$separator = '';
	$authors = array();
	$authorName = array();
	$authorNames = array();
	$author = '';
	$name = array();
	$doc = false;
	$main = false;
	$sub = false;
	$paths = array();
	$api = '';
	$detect = true;
	$datas = array();
	$path = '';
	$pluginFile = '';
	$logs = '--------------'.PHP_EOL.date('Y-m-d',time()).PHP_EOL;
	$infos = array();
	$match = array();
	$version = '';
	$update = 0;
	$zip = '';
	$repoZip = '';
	$download = '';
	$tmpName = '';
	$tmpZip = '';
	$tmpSub = '';
	$phpZip = (object)array();
	$pluginFolder = '';
	$plugin = '';
	$renamed = '';
	$cdn = '';
	$rootPath = '';
	$filePath = '';
	$status = 'failed';
	$done = 0;
	$tables = array();

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
			$names[] = $name['0'];
		}
	}
	//按插件名排序
	sort($names);
	file_put_contents('readme.json',json_encode($names));
