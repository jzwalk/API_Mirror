<?php
//https://raw.githubusercontent.com/typecho-fans/plugins/master/TESTORE.md
	$source = file_get_contents('TESTORE.md');
	$lines = preg_split('/(\r|\n|\r\n)/',$source);

	$desciptions = array();
	$links = array();
	$metas = array();
	$infos = array();
	$version = '';
	$download = '';
	$api = '';
	$name = array();
	$datas = array();
	$path = '';
	$status = 'failed';
	$logs = '';
	$tables = array();
	foreach ($lines as $line=>$column) {
		if ($line<38) {
			$desciptions[] = $column;
		} else {
			preg_match_all('/(?<=\()[^\)]+/',$column,$links);
			preg_match_all('/(?<=)[^\|]+/',$column,$metas);
			if ($column && strpos($links['0']['0'],'github.com')) {
				$api = file_get_contents(str_replace('github.com','api.github.com/repos',$links['0']['0']).'/git/trees/master?recursive=1',0,
					stream_context_create(array('http'=>array('header'=>array('User-Agent: PHP')))));
				$datas = json_decode($api ,true);
				preg_match('/(?<=\[)[^\]]+/',$metas['0']['0'],$name);
				foreach ($datas['tree'] as $tree) {
					if (false!==stripos($tree['path'],'Plugin.php')) {
						$path = $tree['path'];
					}
				}
				$path = $path ? $links['0']['0'].'/raw/master/'.$path : $links['0']['0'].'/raw/master/'.$name['0'].'.php';
				$infos = call_user_func('parseInfo',$path);
				$version = stripos($metas['0']['2'],'v')===0 ? trim(substr($metas['0']['2'],1)) : trim($metas['0']['2']);
				if ($infos && $infos['version']>$version) {
					$column = str_replace($version,$infos['version'],$column);
					$download = file_get_contents(end($links['0']));
//https://api.github.com/repos/typecho-fans/plugins/contents/ZIP_CDN
					$datas = json_decode(file_get_contents('test_zc.json'),true);
					foreach ($datas as $data) {
						if ($data['name']==$name['0'].'_'.$infos['author'].'.zip') { //带作者名优先
							$file = 'ZIP_CDN/'.$name['0'].'_'.$infos['author'].'.zip';
						} elseif ($data['name']==$name['0'].'.zip') {
							$file = 'ZIP_CDN/'.$name['0'].'.zip';
						}
					}
					if ($download) {
						file_put_contents($file,$download);
						$status = 'successful';
					}
					$logs .= $name['0'].' '.date('Y-m-d H:i',time()).' '.$status.PHP_EOL;
				}
			}
			$tables[] = $column;
		}
	}

	file_put_contents('TESTORE.md',implode(PHP_EOL,$desciptions).PHP_EOL.implode(PHP_EOL,$tables));
	echo $logs;

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