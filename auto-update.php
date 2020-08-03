<?php
	date_default_timezone_set('Asia/Shanghai');
//https://raw.githubusercontent.com/typecho-fans/plugins/master/TESTORE.md
	$source = file_get_contents('TESTORE.md');
	$lines = preg_split('/(\r|\n|\r\n)/',$source);

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
	$download = '';
	$done = 0;
	$cdn = '';
	$status = 'failed';
	$tables = array();
	foreach ($lines as $line=>$column) {
		if ($line<38) {
			$desciptions[] = $column;
		} else {
			preg_match_all('/(?<=\()[^\)]+/',$column,$links);
			preg_match_all('/(?<=)[^\|]+/',$column,$metas);
			$url = $links['0']['0'];
			if ($column && strpos($url,'github.com')) {
				$sub = strpos($url,'/tree/master/');
				if ($sub) {
					$paths = explode('/tree/master/',$url);
					$url = $paths['0'];
				}
				try {
					$api = file_get_contents(str_replace('github.com','api.github.com/repos',$url).'/git/trees/master?recursive=1',0,
						stream_context_create(array('http'=>array('header'=>array('User-Agent: PHP')))));
				} catch (Exception $e) {
					$logs = 'Error: '.$e->getMessage().PHP_EOL;
				}
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
					if (strpos(end($links['0']),'typecho-fans/plugins/releases/download')) {
						$logs = $name['0'].' need manul update!'.PHP_EOL;
						return;
					}
					try {
						$download = file_get_contents(end($links['0']));
					} catch (Exception $e) {
						$logs = 'Error: '.$e->getMessage().PHP_EOL;
					}
//https://api.github.com/repos/typecho-fans/plugins/contents/ZIP_CDN
					$datas = json_decode(file_get_contents('test_zc.json'),true);
					foreach ($datas as $data) {
						if ($data['name']==$name['0'].'_'.$infos['author'].'.zip') { //带作者名优先
							$cdn = 'ZIP_CDN/'.$name['0'].'_'.$infos['author'].'.zip';
						} elseif ($data['name']==$name['0'].'.zip') {
							$cdn = 'ZIP_CDN/'.$name['0'].'.zip';
						}
					}
					if ($cdn && $download) {
						file_put_contents($cdn,$download);
						$status = 'succeeded';
						++$done;
					}
					$logs .= $name['0'].' - '.date('Y-m-d H:i',time()).' - '.$status.PHP_EOL;
				}
			}
			$tables[] = $column;
		}
	}

	file_put_contents('TESTORE.md',implode(PHP_EOL,$desciptions).PHP_EOL.implode(PHP_EOL,$tables));
	file_put_contents('updates.txt',$logs.'ALL: '.$all.PHP_EOL.
		'DONE: '.$done.PHP_EOL,FILE_APPEND);

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