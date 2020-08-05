<?php
	date_default_timezone_set('Asia/Shanghai');
//https://raw.githubusercontent.com/typecho-fans/plugins/master/TESTORE.md
	$source = file_get_contents('TESTORE.md');
	$lines = explode(PHP_EOL,$source);

	$desciptions = array();
	$links = array();
	$metas = array();
	$url = '';
	$name = array();
	$doc = false;
	$sub = false;
	$paths = array();
	$api = '';
	$datas = array();
	$path = '';
	$logs = '--------------'.PHP_EOL.date('Y-m-d',time()).PHP_EOL;
	$infos = array();
	$version = '';
	$all = 0;
	$zip = '';
	$download = '';
	$tmpDir = '';
	$tmpZip = '';
	$tmpSub = '';
	$phpZip = (object)array();
	$authors = array();
	$separator = '';
	$authorNames = '';
	$cdn = '';
	$rootPath = '';
	$files = (object)array();
	$filePath = (object)array();
	$status = 'failed';
	$done = 0;
	$tables = array();
	foreach ($lines as $line=>$column) {
		if ($line<38) {
			$desciptions[] = $column;
		} else {
			preg_match_all('/(?<=\()[^\)]+/',$column,$links);
			preg_match_all('/(?<=)[^\|]+/',$column,$metas);
			if ($column) {
				$url = $links['0']['0'];
				if (strpos($url,'github.com')) {
					preg_match('/(?<=\[)[^\]]+/',$metas['0']['0'],$name);
					$doc = strpos($url,'/blob/master/') && strpos($url,'.php');
					if (!$doc) {
						$sub = strpos($url,'/tree/master/');
						if ($sub) {
							$paths = explode('/tree/master/',$url);
							$url = $paths['0'];
						}
						$api = @file_get_contents(str_replace('github.com','api.github.com/repos',$url).'/git/trees/master?recursive=1',0,
							stream_context_create(array('http'=>array('header'=>array('User-Agent: PHP')))));
						if ($api) {
							$datas = json_decode($api,true);
							foreach ($datas['tree'] as $tree) {
								if (false!==stripos($tree['path'],($sub ? $name['0'].'/Plugin.php' : 'Plugin.php'))) {
									$path = $tree['path'];
								}
							}
							$path = $path ? $url.'/raw/master/'.$path : $url.'/raw/master/'.($sub ? $paths['1'].'/' : '').$name['0'].'.php';
						} else {
							$logs .= 'Error: "'.$url.'" not found!'.PHP_EOL;
						}
					} else {
						$path = str_replace('blob','raw',$url);
						$paths = explode('/raw/master/',$path);
						$url = $paths['0'];
					}
					$infos = call_user_func('parseInfo',$path);
					$version = stripos($metas['0']['2'],'v')===0 ? trim(substr($metas['0']['2'],1)) : trim($metas['0']['2']);
					if ($infos && $infos['version']>$version) {
						++$all;
						$zip = end($links['0']);
						if (strpos($zip,'typecho-fans/plugins/releases/download')) {
							$download = @file_get_contents($url.'/archive/master.zip');
							if ($download) {
								$tmpDir = realpath('../').'/TMP';
								if (!is_dir($tmpDir)) {
									mkdir($tmpDir);
								}
								$tmpZip = $tmpDir.'/'.$all.'_'.$name['0'].'_master.zip';
								file_put_contents($tmpZip,$download);
								$phpZip = new ZipArchive();
								$phpZip->open($tmpZip);
								$tmpSub = $tmpDir.'/'.$all.'_'.$name['0'];
								mkdir($tmpSub);
								$phpZip->extractTo($tmpSub);
								preg_match('/(?<=\[)[^\]]+/',$metas['0']['3'],$authors);
								switch (true) {
									case strpos($metas['0']['3'],',') :
									$separator = ',';
									break;
									case strpos($metas['0']['3'],', ') :
									$separator = ', ';
									break;
									case strpos($metas['0']['3'],'&') :
									$separator = '&';
									break;
									case strpos($metas['0']['3'],' & ') :
									$separator = ' & ';
									break;
								}
								$authorNames = html_entity_decode(implode($separator,$authors));
								if ($authorNames!==trim(strip_tags($infos['author']))) {
									$logs .= $authorNames.' not equal to '.trim(strip_tags($infos['author'])).PHP_EOL;
								}
								$cdn = call_user_func('cdnZip',$name['0'],$infos['author']);
								$phpZip->open($cdn,ZipArchive::CREATE | ZipArchive::OVERWRITE);
								if (!$doc) {
									$rootPath = $tmpSub.'/'.basename($url).'-master'.($sub ? '/'.$paths['1'] : '');
									$files = new RecursiveIteratorIterator(
										new RecursiveDirectoryIterator($rootPath),
										RecursiveIteratorIterator::LEAVES_ONLY
									);
									foreach ($files as $file) {
										if (!$file->isDir()) {
											$filePath = $file->getRealPath();
											$phpZip->addFile($filePath,($doc ? '' : $name['0'].'/').substr($filePath,strlen($rootPath)+1));
										}
									}
								} else {
									$phpZip->addFile($tmpSub.'/'.basename($url).'-master/'.$paths['1'],$paths['1']);
								}
								if ($phpZip->close()) {
									$status = 'succeeded';
									++$done;
								}
								$logs .= $name['0'].' - '.date('Y-m-d H:i',time()).' - RE-ZIP '.$status.PHP_EOL;
							} else {
								$logs .= 'Error: "'.$url.'" not found!'.PHP_EOL;
							}
						} else {
							$download = @file_get_contents($zip);
							if ($download) {
								$cdn = call_user_func('cdnZip',$name['0'],$infos['author']);
								if ($cdn) {
									file_put_contents($cdn,$download);
									$status = 'succeeded';
									++$done;
								}
								$logs .= $name['0'].' - '.date('Y-m-d H:i',time()).' - '.$status.PHP_EOL;
							} else {
								$logs .= 'Error: "'.$zip.'" not found!'.PHP_EOL;
							}
						}
						$column = str_replace($version,$infos['version'],$column);
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
	 * @param string $pluginAuthor 作者名
	 * @return string
	 */
	function cdnZip($pluginName,$pluginAuthor)
	{
//https://api.github.com/repos/typecho-fans/plugins/contents/ZIP_CDN
		$datas = json_decode(file_get_contents('test_zc.json'),true);
		$cdn = '';
		foreach ($datas as $data) {
			if ($data['name']==$pluginName.'_'.$pluginAuthor.'.zip') { //带作者名优先
				$cdn = 'ZIP_CDN/'.$pluginName.'_'.$pluginAuthor.'.zip';
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