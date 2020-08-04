<?php
	date_default_timezone_set('Asia/Shanghai');
//https://raw.githubusercontent.com/typecho-fans/plugins/master/TESTORE.md
	$source = file_get_contents('TESTORE.md');
	$lines = explode(PHP_EOL,$source);

	$desciptions = array();
	$links = array();
	$metas = array();
	$url = '';
	$sub = false;
	$paths = array();
	$api = '';
	$logs = '--------------'.PHP_EOL.date('Y-m-d',time()).PHP_EOL;
	$datas = array();
	$name = array();
	$path = '';
	$infos = array();
	$version = '';
	$all = 0;
	$zip = '';
	$download = '';
	$done = 0;
	$cdn = '';
	$status = 'failed';
	$tables = array();
	foreach ($lines as $line=>$column) {
		if ($line<39) {
			$desciptions[] = $column;
		} else {
			preg_match_all('/(?<=\()[^\)]+/',$column,$links);
			preg_match_all('/(?<=)[^\|]+/',$column,$metas);
			if ($column) {
				$url = $links['0']['0'];
				if (strpos($url,'github.com')) {
					$sub = strpos($url,'/tree/master/');
					if ($sub) {
						$paths = explode('/tree/master/',$url);
						$url = $paths['0'];
					}
					$api = @file_get_contents(str_replace('github.com','api.github.com/repos',$url).'/git/trees/master?recursive=1',0,
						stream_context_create(array('http'=>array('header'=>array('User-Agent: PHP')))));
					if ($api) {
						$datas = json_decode($api,true);
						preg_match('/(?<=\[)[^\]]+/',$metas['0']['0'],$name);
						foreach ($datas['tree'] as $tree) {
							if (false!==stripos($tree['path'],($sub ? $name['0'].'/Plugin.php' : 'Plugin.php'))) {
								$path = $tree['path'];
							}
						}
						$path = $path ? $url.'/raw/master/'.$path : $url.'/raw/master/'.($sub ? $paths['1'] : '').$name['0'].'.php';
						$infos = call_user_func('parseInfo',$path);
						$version = stripos($metas['0']['2'],'v')===0 ? trim(substr($metas['0']['2'],1)) : trim($metas['0']['2']);
						if ($infos && $infos['version']>$version) {
							++$all;
							$column = str_replace($version,$infos['version'],$column);
							$zip = end($links['0']);
							if (strpos($zip,'typecho-fans/plugins/releases/download')) {
								$download = @file_get_contents($url.'/archive/master.zip');
								if ($download) {
									$tmpDir = '../TMP/';
									$tmpZip = $tmpDir.$name['0'].'_master.zip';
									file_put_contents($tmpZip,$download);
									$phpZip = new ZipArchive();
									$phpZip->open($tmpZip);
									$phpZip->extractTo($tmpDir);
									preg_match('/(?<=\[)[^\]]+/',$metas['0']['3'],$author);
									if ($author!==trim(strip_tags($infos['author']))) {
										$logs .= $name['0'].' needs manual update!'.PHP_EOL;
									}
									$rootPath = realpath($tmpDir.basename($url).'-master'.($sub ? '/'.$paths['1'] : ''));
									$cdn = call_user_func('cdnZip',$name['0']);
									$phpZip->open($cdn, ZipArchive::CREATE | ZipArchive::OVERWRITE);
									$files = new RecursiveIteratorIterator(
										new RecursiveDirectoryIterator($rootPath),
										RecursiveIteratorIterator::LEAVES_ONLY
									);
									foreach ($files as $file) {
										if (!$file->isDir()) {
											$filePath = $file->getRealPath();
											$relativePath = substr($filePath,strlen($rootPath)+1);
											$phpZip->addFile($filePath,$relativePath);
										}
									}
									$phpZip->close();
									$logs .= $name['0'].' - '.date('Y-m-d H:i',time()).' - RE-ZIP '.$status.PHP_EOL;
								} else {
									$logs .= 'Error: "'.$url.'" not found!'.PHP_EOL;
								}
							} else {
								$download = @file_get_contents($zip);
								if ($download) {
									$cdn = call_user_func('cdnZip',$name['0']);
									if ($cdn && $download) {
										file_put_contents($cdn,$download);
										$status = 'succeeded';
										++$done;
									}
									$logs .= $name['0'].' - '.date('Y-m-d H:i',time()).' - '.$status.PHP_EOL;
								} else {
									$logs .= 'Error: "'.$zip.'" not found!'.PHP_EOL;
								}
							}
						}
					} else {
						$logs .= 'Error: "'.$url.'" not found!'.PHP_EOL;
					}
				}
			}
			$tables[] = $column;
		}
	}

	file_put_contents('TESTORE.md',implode(PHP_EOL,$desciptions).PHP_EOL.implode(PHP_EOL,$tables));
	file_put_contents('updates.log',$logs.'ALL: '.$all.PHP_EOL.
		'DONE: '.$done.PHP_EOL,FILE_APPEND);

	/**
	 * 获取ZIP_CDN文件名称
	 *
	 * @param string $pluginName 插件名
	 * @return string
	 */
	function cdnZip($pluginName)
	{
//https://api.github.com/repos/typecho-fans/plugins/contents/ZIP_CDN
		$datas = json_decode(file_get_contents('test_zc.json'),true);
		foreach ($datas as $data) {
			if ($data['name']==$pluginName.'_'.$infos['author'].'.zip') { //带作者名优先
				$cdn = 'ZIP_CDN/'.$pluginName.'_'.$infos['author'].'.zip';
			} elseif ($data['name']==$pluginName.'.zip') {
				$cdn = 'ZIP_CDN/'.$pluginName.'.zip';
			}
		}
		return $cdn;
	}

	/**
	 * 获取插件文件的头信息
	 *
	 * @param string $pluginFile 插件文件路径
	 * @return array
	 */
	function parseInfo($pluginFile)
	{
		$tokens = token_get_all(file_get_contents($pluginFile));
		$isDoc = false;
		$isFunction = false;
		$isClass = false;
		$isInClass = false;
		$isInFunction = false;
		$isDefined = false;
		$current = NULL;

		/** 初始信息 */
		$info = array(
			'description'	   => '',
			'title'			 => '',
			'author'			=> '',
			'homepage'		  => '',
			'version'		   => '',
			'dependence'		=> '',
			'activate'		  => false,
			'deactivate'		=> false,
			'config'			=> false,
			'personalConfig'	=> false
		);

		$map = array(
			'package'   =>  'title',
			'author'	=>  'author',
			'link'	  =>  'homepage',
			'dependence'=>  'dependence',
			'version'   =>  'version'
		);

		foreach ($tokens as $token) {
			/** 获取doc comment */
			if (!$isDoc && is_array($token) && T_DOC_COMMENT == $token[0]) {

				/** 分行读取 */
				$described = false;
				$lines = preg_split("(\r|\n)", $token[1]);
				foreach ($lines as $line) {
					$line = trim($line);
					if (!empty($line) && '*' == $line[0]) {
						$line = trim(substr($line, 1));
						if (!$described && !empty($line) && '@' == $line[0]) {
							$described = true;
						}

						if (!$described && !empty($line)) {
							$info['description'] .= $line . "\n";
						} else if ($described && !empty($line) && '@' == $line[0]) {
							$info['description'] = trim($info['description']);
							$line = trim(substr($line, 1));
							$args = explode(' ', $line);
							$key = array_shift($args);

							if (isset($map[$key])) {
								$info[$map[$key]] = trim(implode(' ', $args));
							}
						}
					}
				}

				$isDoc = true;
			}

			if (is_array($token)) {
				switch ($token[0]) {
					case T_FUNCTION:
						$isFunction = true;
						break;
					case T_IMPLEMENTS:
						$isClass = true;
						break;
					case T_WHITESPACE:
					case T_COMMENT:
					case T_DOC_COMMENT:
						break;
					case T_STRING:
						$string = strtolower($token[1]);
						switch ($string) {
							case 'typecho_plugin_interface':
								$isInClass = $isClass;
								break;
							case 'activate':
							case 'deactivate':
							case 'config':
							case 'personalconfig':
								if ($isFunction) {
									$current = ('personalconfig' == $string ? 'personalConfig' : $string);
								}
								break;
							default:
								if (!empty($current) && $isInFunction && $isInClass) {
									$info[$current] = true;
								}
								break;
						}
						break;
					default:
						if (!empty($current) && $isInFunction && $isInClass) {
							$info[$current] = true;
						}
						break;
				}
			} else {
				$token = strtolower($token);
				switch ($token) {
					case '{':
						if ($isDefined) {
							$isInFunction = true;
						}
						break;
					case '(':
						if ($isFunction && !$isDefined) {
							$isDefined = true;
						}
						break;
					case '}':
					case ';':
						$isDefined = false;
						$isFunction = false;
						$isInFunction = false;
						$current = NULL;
						break;
					default:
						if (!empty($current) && $isInFunction && $isInClass) {
							$info[$current] = true;
						}
						break;
				}
			}
		}

		return $info;
	}