<?php
//https://raw.githubusercontent.com/typecho-fans/plugins/master/TESTORE.md
	$source = file_get_contents('TESTORE.md');
	$lines = preg_split('/(\r|\n|\r\n)/',$source);
//https://api.github.com/repos/typecho-fans/plugins/contents/ZIP_CDN
	$api = file_get_contents('test_zc.json');
	$datas = json_decode($api,true);

	$desciptions = array();
	$links = array();
	$metas = array();
	$infos = array();
	$download = '';
	$name = '';
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
				$infos = call_user_func('parseInfo',$links['0']['0'].'/raw/master/Plugin.php');
				if ($infos && $infos['version']>$metas['0']['2']) {
					$column = str_replace($metas['0']['2'],$infos['version'],$column);
					$download = file_get_contents(end($links));
					preg_match('/(?<=\[)[^\]]+/',$metas['0']['0'],$name);
					foreach ($datas as $data) {
						if ($data['name']==$name.'_'.$infos['author'].'.zip') { //带作者名优先
							$file = 'ZIP_CDN/'.$name.'_'.$infos['author'].'.zip';
						} elseif ($data['name']==$name.'.zip') {
							$file = 'ZIP_CDN/'.$name.'.zip';
						}
					}
					if ($download) {
						file_put_contents($file,$download);
						$status = 'successful';
					}
					$logs .= $name.' '.date('Y-m-d H:i',time()).' '.$status.PHP_EOL;
				}
			}
			$tables[] = $column;
		}
	}

	$content1 = '';
	$content2 = '';
	foreach ($desciptions as $desciption) {
		$content1 .= $desciption.PHP_EOL;
	}
	foreach ($tables as $table) {
		$content2 .= $table.PHP_EOL;
	}
	file_put_contents('TESTORE.md',$content1.$content2);
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