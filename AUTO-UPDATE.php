<?php

	/**
	 * Typecho-Fans/Plugins专用脚本：自动化更新插件信息文档及zip包
	 * (GitHub Actions工作流调用，每次提交自动运行，修改务必谨慎！)
	 * 反馈：https://github.com/typecho-fans/plugins/issues
	 */

	date_default_timezone_set('Asia/Shanghai');
	$authKey = $argv[1];
	$requestUrl = $argv[2] ?? '';

	$urls = explode(',',$requestUrl);

	//提取最近变更信息
	if (strpos($requestUrl,'.diff')) {
		$record = @file_get_contents($requestUrl,0,
			stream_context_create(array('http'=>array('header'=>array('Accept: application/vnd.github.v3.diff')))));
		$diffs = explode(PHP_EOL,$record);

		$urls = [];
		$begin = 0;
		foreach ($diffs as $line=>$diff) {
			//查找有关文档变化
			if ($diff=='+++ b/TESTORE.md') {
				$begin = $line;
			}
			if ($begin && $line>$begin) {
				//匹配变更repo信息
				if (strpos($diff,'+[')===0) {
					preg_match_all('/(?<=\()[^\)]+/',$diff,$links);
					if ($links && strpos($diff,'](')) {
						$urls[] = trim($links[0][0]); //取第一个链接内容
					}
				}
				if (strpos($diff,'diff --git')===0) {
					break;
				}
			}
		}
	}

	//检测文档执行更新
	$movable = [];
	if (file_exists('README_test.md')) {
		$movable = updatePlugins('README_test.md',$urls,$authKey);
	} else {
		throw new RuntimeException('README.md is missing!');
	}
	if (file_exists('TESTORE.md')) {
		updatePlugins('TESTORE.md',$urls,$authKey,$movable);
	} else {
		throw new RuntimeException('TESTORE.md is missing!');
	}

	/**
	 * 循环每行检测更新并重组文档
	 *
	 * @param string $tableFile MD文档路径
	 * @param array $requested 需求更新repo信息
	 * @param string $token GitHub Token
	 * @param array $added 转移表格条目
	 * @return array
	 */
	function updatePlugins(string $tableFile,array $requested,string $token='',array $added=[]): array
	{
		//预设出循环变量
		$logs = '-------'.$tableFile.'-------'.PHP_EOL.date('Y-m-d',time()).PHP_EOL;
		$descriptions = [];
		$all = 0;
		$revise = 0;
		$creat = 0;
		$update = 0;
		$renew = 0;
		$release = 0;
		$done = 0;
		$nameList = 'ZIP_CDN/NAME_LIST.log';
		$listNames = file_exists($nameList) ? explode(PHP_EOL,file_get_contents($nameList)) : [];
		$movable = [];
		$tables = [];

		//创建临时文件夹
		$tmpDir = realpath('../').'/TMP';
		if (!is_dir($tmpDir)) {
			mkdir($tmpDir,0777,true);
		}
		$tmpNew = realpath('../').'/NEW';
		if (!is_dir($tmpNew)) {
			mkdir($tmpNew,0777,true);
		}

		//分割文档准备开始循环
		$source = file_get_contents($tableFile);
		$lines = explode(PHP_EOL,trim($source));
		$tableLine = 0;
		foreach ($lines as $line=>$column) {
			if (strpos($column,'| :----:')) {
				$tableLine = $line; //定位表格行
			}
		}
		if ($tableLine) {
			$counts = count($lines);
			foreach ($lines as $line=>$column) {
				//说明部分更新收录总数
				if ($line<$tableLine+1) {
					if ($line==$tableLine-8) {
						preg_match('/(?<=\()[^\)]*/',$column,$total);
						if ($total) {
							$column = str_replace($total[0],$counts-($tableLine+1),$column);
						} else {
							$logs .= 'Error: Cannot match the total number in "'.$tableFile.'"!'.PHP_EOL;
						}
					}
					$descriptions[] = $column;
				//表格部分匹配repo信息
				} elseif ($column) {
					$metas = explode(' | ',$column);
					if (count($metas)==5) {

						$nameMeta = $metas[0];
						preg_match('/(?<=\[)[^\]]*/',$nameMeta,$names);
						$name = trim($names ? $names[0] : $nameMeta); //取第一个栏位(链接)文本
						if ($name) {

							preg_match_all('/(?<=\()[^)]*/',$column,$links);
							$url = $links && strpos($nameMeta,'](') ? trim($links[0][0]) : ''; //取第一个栏位链接内容
							$github = parse_url($url,PHP_URL_HOST)=='github.com';
							//处理文档变更或指定插件
							if ($requested = array_filter($requested)) {
								$condition = in_array($url,$requested) || in_array($name,$requested);
							//定期处理全GitHub源插件
							} else {
								$condition = $github;
							}

							//处理表格作者名
							$authorMeta = $metas[3];
							$authorCode = html_entity_decode(trim($authorMeta));
							preg_match('/[\t ]*(,|&|，)[ \t]*/',$authorCode,$separators); //匹配分隔符
							//多作者情况
							$separator = '';
							if ($separators) {
								$separator = $separators[0];
								$authors = explode($separator,$authorCode);
								$authorNames = [];
								$authorMDfix = [];
								foreach ($authors as $author) {
									preg_match('/(?<=\[)[^\]]*/',$author,$authorName); //匹配链接文字
									$authorText = trim($authorName ? $authorName[0] : $author);
									$authorNames[] = $authorText;
									$authorMDfix[] = str_replace(['_','*'],['&#95;','&#42;'],$authorText); //Markdown转义
								}
							//单作者情况
							} else {
								preg_match('/(?<=\[)[^\]]*/',$authorCode,$authorName);
								$author = trim($authorName ? $authorName[0] : $authorCode);
								$authorMD = str_replace(['_','*'],['&#95;','&#42;'],$author);
							}
							$authorTable = $separator ? implode($separator,$authorNames) : $author;
							//使*和_正常显示
							$column = str_replace($authorMeta,($separator ? str_replace($authorNames,$authorMDfix,$authorMeta) : str_replace($author,$authorMD,$authorMeta)),$column);
							//作者名转文件名
							$zipName = $name.'_'.str_replace([':','"','/','\\','|','?','*'],'',preg_replace('/[\t ]*(,|&|，)[ \t]*/','_',$authorTable)).'.zip';

							$tf = $tableFile=='README_test.md';
							$isUrl = str_starts_with($url,'http://') || str_starts_with($url,'https://');
							$zipMeta = end($metas);
							$latest = array_slice($listNames,0,20); //分割前20
							preg_match('/(?<=\[)[^\]]*/',$zipMeta,$zipText);
							$mark = $zipText ? trim($zipText[0]) : ($tf ? 'Download' : '下载'); //取最后一个栏位链接文本
							if ($condition) {
								++$all; //记录检测次数

								//提取子目录(分支名)
								$paths = preg_split('#/tree/([^/]+)/#',$url,2,PREG_SPLIT_DELIM_CAPTURE);
								$url = rtrim($paths[0],'/');
								$branch = $paths[1] ?? '';
								$folder = !empty($paths[2]) ? rtrim($paths[2],'/').'/' : '';

								$gitee = parse_url($url,PHP_URL_HOST)=='gitee.com';
								$apiUrl = str_replace(['/github.com/','/gitee.com/'],['/api.github.com/repos/','/api.gitee.com/api/v5/repos/'],$url);
								$api = '';
								if (!$branch) {
									$branch = 'master';
									//API查询分支名
									if ($github || $gitee) {
										$api = @file_get_contents($apiUrl,0,
											stream_context_create(array('http'=>array('header'=>array('User-Agent: PHP')))));
										if ($api) {
											$branch = json_decode($api,true)['default_branch'];
										}
									}
								}

								$tfLocal = $tf && is_dir($url); //本地社区维护版
								$datas = [];
								$plugin = '';
								if (!$tfLocal) {
									//API查询repo文件树
									if ($github || $gitee) {
										$api = @file_get_contents($apiUrl.'/git/trees/'.$branch.'?recursive=1',0,
											stream_context_create(array('http'=>array('header'=>array('User-Agent: PHP')))));
									}
									$path = '';
									if ($api) {
										$datas = array_column(array_filter(
											json_decode($api,true)['tree'],
											fn($item)=>$item['type']==='blob' //排除目录
										),'path');
										//定位主文件路径
										$path = pluginRoute($datas,$name);
									}

									//下载主文件获取信息
									if ($isUrl) {
										$pluginUri = $url.'/raw/'.$branch.'/'.$folder;
										$plugin = $path ? $url.'/raw/'.$branch.'/'.$path : $pluginUri.'Plugin.php';
										$infos = parseInfo($plugin);
										//无API重试单文件
										if (!$infos['version'] && !$path) {
											$plugin = $pluginUri.$name.'.php';
											$infos = parseInfo($plugin);
										}
									}
								} else {
									//本地读取主文件信息
									$plugin = pluginRoute($url,$name);
									$infos = parseInfo($plugin);
								}

								$noPlugin = empty($infos['version']); //表格repo信息无效
								$gitIsh = !$noPlugin && !$api && !$tfLocal; //有效但无API
								$zip = strpos($zipMeta,'](') ? trim(end($links[0])) : ''; //取最后一个栏位链接地址
								$tmpSub = $tmpDir.'/'.$all.'_'.$name;
								$pluginZip = '';
file_put_contents('ZIP_CDN/api_through_bug', 'apiUrl: '.$apiUrl.'/git/trees/'.$branch.'?recursive=1'.($github ? '&access_token='.$token : '').', datas: '.print_r($datas,true).', plugin: '.$plugin.', noPlugin: '.print_r($noPlugin,true).', gitIsh: '.print_r($gitIsh,true),FILE_APPEND|LOCK_EX);
								//解压zip包获取信息
								if ($noPlugin || $gitIsh) {
									$download = @file_get_contents($zip);
									if ($download) {
										$tmpZip = $tmpSub.'_origin.zip';
										file_put_contents($tmpZip,$download);
										$phpZip = new ZipArchive();
										if ($phpZip->open($tmpZip)!==true) {
											$logs .= 'Error: Table zip - "'.$zip.'" is not valid!'.PHP_EOL;
										} else {
											mkdir($tmpSub,0777,true);
											$phpZip->extractTo($tmpSub);
											$pluginZip = pluginRoute($tmpSub,$name);
											if ($pluginZip && !$gitIsh) {
												$infos = parseInfo($pluginZip);
											}
										}
									} else {
										$logs .= 'Error: Table zip - "'.$zip.'" cannot be downloaded!'.PHP_EOL;
									}
								}

								//有主文件信息即修正
								if (!empty($infos['version'])) {
									++$revise; //记录修正次数
									$fixed = '';
									$updated = '';

									//修正表格插件名与链接
									if ($pluginFile = $pluginZip ?: $plugin) {
										$nameData = workingName($pluginFile);
									}
									$nameFile = $nameData[0] ?? '';
									if ($nameFile) {
										if ($noPlugin) {
											$logs .= 'Warning: "'.($plugin ?: $url).'" is not valid, using "'.$zip.'" to read info.'.PHP_EOL;
											if (!$isUrl) { //TeStore不显示无文档链接插件
												$column = str_replace($nameMeta,'['.$nameFile.']('.($tf ? $url : $infos['homepage']).')',$column);
												$fixed .= ' / Table Repo Masked';
											}
										} elseif ($name!==$nameFile) {
											$logs .= 'Warning: "'.$name.'" in table does not match "'.$nameFile.'" in file.'.PHP_EOL;
											$column = str_replace($nameMeta,str_replace($name.'](',$nameFile.'](',$nameMeta),$column);
											$fixed .= ' / Table Name Fixed';
										}
										$name = $nameFile;
									}

									//处理repo作者名
									$authorInfo = strip_tags(trim($infos['author']));
									preg_match('/[\t ]*(,|&|，)[ \t]*/',$authorInfo,$seps);
									$sep = '';
									if ($seps) {
										$sep = $seps[0];
										$authors = array_map(fn($id)=>'['.trim($id).']('.$infos['homepage'].')',explode($sep,$authorInfo));
									}

									//修正表格作者名与链接
									if ($authorTable!==$authorInfo) {
										$logs .= 'Warning: "'.$authorTable.'" in table does not match "'.$authorInfo.'" in file.'.PHP_EOL;
										$column = str_replace($authorMeta,($sep ? implode($sep,$authors) : '['.$authorInfo.']('.$infos['homepage'].')'),$column);
										$fixed .= ' / Table Author Fixed';
									}

									//创建加速文件夹用zip
									$zipName = $name.'_'.str_replace([':','"','/','\\','|','?','*'],'',preg_replace('/[\t ]*(,|&|，)[ \t]*/','_',$authorInfo)).'.zip';
									$cdn = 'ZIP_CDN/'.$zipName;
									$params = [$tableFile,!$noPlugin,$url,$name,$datas,$branch,$plugin,$pluginZip,$cdn,$zip,$all,$logs];
									$newCdn = false;
									if (!file_exists($cdn)) {
										$newCdn = true;
										$logs = dispatchZips(...$params);
										++$creat;
										$fixed .= ' / CDN Zip Created';
									}

									//表格版本落后则更新(或强制更新)
									$version = trim($metas[2]); //取第三个栏位文本
									if (version_compare(trim($infos['version']),$version,'>') || !empty($requested)) {
										++$update; //记录更新次数

										//更新加速文件夹用zip
										if (!$newCdn) {
											dispatchZips(...$params);
											++$renew;
											$fixed .= ' / CDN Zip Renewed';
										}

										//复制到release发布用
										$isRelease = strpos($zip,'typecho-fans/plugins/releases/download');
										if ($isRelease && file_exists($cdn)) {
											copy($cdn,$tmpNew.'/'.basename($zip));
											++$release;
										}

										//更新表格版本号
										$column = str_replace($version,trim($infos['version']),$column);

										//更新表格下载标记(用于TeStore筛选)
										if ($mark=='Download' || $mark=='下载') {
											$newOr = 'Lat';
											$orNew = '近';
											//标记新版写法
											if (!empty($nameData[1])) {
												$newOr = 'New';
												$orNew = '新';
												$fixed .= ' / Marked as 1.2.1+';
											}
											//标记最近更新
											$column = str_replace($zipMeta,str_replace($mark,($tf ? $newOr.'est' : '最'.$orNew),$zipMeta),$column);
										}

										$updated = '& Updated';
										++$done; //记录完成次数
									}

									if ($fixed || $updated) {
										//置顶排序zip名表
										if (in_array($zipName,$listNames)) {
											array_splice($listNames,array_search($zipName,$listNames),1);
										}
										array_unshift($listNames,$zipName);
										$latest = array_slice($listNames,0,20);
										//记录插件改动明细
										$logs .= $name.' By '.$authorInfo.' - '.date('Y-m-d H:i',time()).' - Revised '.$updated.$fixed.PHP_EOL;
									}
								} else {
									$logs .= 'Error: Table info - "'.$url.'" & "'.$zip.'" both invalid, nothing is revised!'.PHP_EOL;
								}
							}

							//非前20移除标记
							$latestMark = ['Latest','Newest','最近','最新'];
							if (in_array($mark,$latestMark) && $latest && !in_array($zipName,$latest)) {
								$column = str_replace($zipMeta,str_replace($latestMark,['Download','NewVer','下载','新版'],$zipMeta),$column);
							}

							//筛出README.md外部信息
							if ($tf && $isUrl) {
								$movable[] = str_replace($zipMeta,str_replace(['Download','N/A','Special','NewVer','Latest','Newest'],['下载','不可用','特殊','新版','最近','最新'],$zipMeta),$column);
								if (is_dir($name)) {
									$logs .= 'Warning: "'.$name.'" is local but table info "'.$url.'" is external.'.PHP_EOL;
								}
							}
						} else {
							$logs .= 'Error: Line '.$line.' matches no plugin name!'.PHP_EOL;
						}
					} else {
						$logs .= 'Error: Line '.($line+1).' matches wrong columns!'.PHP_EOL;
					}

					$tables[] = $column;
				}
			}

			//合并所有行排序后重建文档
			$tables = array_unique(array_merge(array_diff($tables,$movable),$added));
			sort($tables);
			file_put_contents($tableFile,implode(PHP_EOL,$descriptions).PHP_EOL.implode(PHP_EOL,$tables).PHP_EOL);
		} else {
			$logs .= 'Error: "'.$tableFile.'" has no table in it!'.PHP_EOL;
		}

		//清空临时目录(保留updates.log)
		exec('find "'.$tmpDir.'" -mindepth 1 ! -name "updates.log" -exec rm -rf {} +');

		if ($listNames) {
			//检查重复项
			$duplicates = array_keys(
				array_filter(
					array_count_values($listNames),
					fn($count)=>$count>1
				)
			);
			if ($duplicates) {
				$logs .= 'Warning: Table info about "'.implode(' / ',$duplicates).'" may be added repeatedly.'.PHP_EOL;
			}
			//清除冗余zip
			$listNames = array_unique($listNames);
			if (count($listNames)>600) { //有足量记录后
				$api = @file_get_contents('https://api.github.com/repositories/14101953/contents/ZIP_CDN',0,
					stream_context_create(array('http'=>array('header'=>array('User-Agent: PHP')))));
				if ($api) {
					$datas = json_decode($api,true);
					$extras = array_diff(array_column($datas,'name'),$listNames);
					if ($extras) {
						$logs .= 'Warning: These zip files do not match the names in NAME_LIST.log and will be deleted: "'.implode(' / ',$extras).'"'.PHP_EOL;
						foreach ($extras as $extra) {
							if (file_exists('ZIP_CDN/'.$extra)) {
								unlink('ZIP_CDN/'.$extra);
							}
						}
					}
				}
			}
		}
		//保存zip名表记录
		file_put_contents($nameList,implode(PHP_EOL,$listNames));

		//生成完整的操作日志
		$logFile = $tmpDir.'/updates.log';
		$logs .= 'SCANED: '.$all.PHP_EOL.
			'REVISED: '.$revise.PHP_EOL.
			'NEED UPDATE: '.$update.PHP_EOL.
			'DONE: '.$done.PHP_EOL.
			'ZIPS: Created-'.$creat.', Renewed-'.$renew.', Released-'.$release.PHP_EOL;
		file_put_contents($logFile,$logs,FILE_APPEND|LOCK_EX);

		return $movable;
	}

	/**
	 * 获取插件主文件路径或文件树数据
	 *
	 * @param string|array $pluginData 文件夹路径或文件树数据
	 * @param string $name 表格插件名
	 * @param boolean $needTree 是否返回文件树数据
	 * @return string|array
	 */
	function pluginRoute(string|array $pluginData,string $name,bool $needTree=false): string|array
	{
		$plugin = '';
		$routes = is_array($pluginData) ? $pluginData : array();

		//遍历获取文件树
		if (!$routes) {
			foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pluginData)) as $files) {
				if (!$files->isDir() && !strpos($files,'/.git/') && !strpos($files,'/.github/')) {
					$routes[] = $files->getRealPath();
				}
			}
		}
		//定位主文件路径
		foreach ($routes as $route) {
			if (strpos($route,'Plugin.php')!==false) { //目录型优先
				$plugin = $route;
				break;
			} elseif (strpos($route,$name.'.php')!==false) {
				$plugin = $route;
				break;
			}
		}

		return $needTree ? $routes : $plugin;
	}

	/**
	 * 获取插件文件的头信息 (Typecho)
	 *
	 * @param string $pluginFile 主文件路径或地址
	 * @return array
	 */
	function parseInfo(string $pluginFile): array
	{
		$codes = @file_get_contents($pluginFile);
		$tokens = $codes ? token_get_all($codes) : [];

		/** 初始信息 */
		$info = [
			'title'=>'',
			'author'=>'',
			'homepage'=>'',
			'version'=>'',
			'since'=>''
		];

		$map = [
			'package'=>'title',
			'author'=>'author',
			'link'=>'homepage',
			'since'=>'since',
			'version'=>'version'
		];

		foreach ($tokens as $token) {
			/** 获取doc comment */
			if (is_array($token) && T_DOC_COMMENT == $token[0]) {

				/** 分行读取 */
				$lines = preg_split("(\r|\n)", $token[1]);
				foreach ($lines as $line) {
					$line = trim($line);
					if (!empty($line) && '*' == $line[0]) {
						$line = trim(substr($line, 1));

						if (!empty($line) && '@' == $line[0]) {
							$line = trim(substr($line, 1));
							$args = explode(' ', $line);
							$key = array_shift($args);

							if (isset($map[$key])) {
								$info[$map[$key]] = trim(implode(' ', $args));
							}
						}
					}
				}
			}
		}

		return $info;
	}

	/**
	 * 获取插件文件的有效命名
	 *
	 * @param string $pluginFile 主文件路径或地址
	 * @return array
	 */
	function workingName(string $pluginFile): array
	{
		$codes = @file_get_contents($pluginFile);
		$tokens = $codes ? token_get_all($codes) : [];
		$count = count($tokens);

		$namespace = '';
		$classes = '';
		if ($tokens) {
			for ($i=0;$i<$count;$i++) {
				if ($tokens[$i][0]===T_NAMESPACE) {
					for ($j=$i+1;$j<$count;++$j) {
						if ($tokens[$j][0]===T_NAME_QUALIFIED) {
							$namespace = substr($tokens[$j][1],14);
						} elseif ($tokens[$j]==='{' || $tokens[$j]===';') {
							break;
						}
					}
				}
				if ($tokens[$i][0]===T_CLASS) {
					for ($j=$i+1;$j<$count;++$j) {
						if ($tokens[$j]==='{') {
							$classes=$namespace.(!$namespace ? $tokens[$i+2][1] : '');
 						}
 					}
				}
			}
		}

		return [str_replace('_Plugin','',$classes),!empty($namespace)];
	}

	/**
	 * 下载或重新打包加速用zip
	 *
	 * @param string $md Markdown文档路径
	 * @param boolean $bingo 表格repo信息有效
	 * @param string $url 表格repo链接
	 * @param string $name 插件有效命名
	 * @param array $datas API文件树数据(Gitee)
	 * @param string $branch repo有效分支名
	 * @param string $plugin repo有效主文件地址
	 * @param string $pluginZip 解包有效主文件路径
	 * @param string $cdn 加速用zip文件路径
	 * @param string $zip 表格zip地址
	 * @param integer $index 循环次数序号
	 * @param string $logs 已记录日志
	 * @return string
	 */
	function dispatchZips(string $md,bool $bingo,string $url,string $name,array $datas,string $branch,string $plugin,string $pluginZip,string $cdn,string $zip,int $index,string $logs): string
	{
		$host = parse_url($url,PHP_URL_HOST);
		$github  = $host=='github.com';
		$folder = realpath('../').'/TMP/'.$index.'_'.$name;
		$tfLocal = $md=='README_test.md' && is_dir($url);
		if (!is_dir($folder) && !$tfLocal) {
			mkdir($folder,0777,true);
		}

		//重新打包到加速文件夹
		if ($bingo && !$github) {
			if ($host=='gitee.com') {
				if (count($datas)<=30) {
					foreach ($datas as $data) {
						if (!strpos($data,'.gitignore') && !strpos($data,'/.github/')) {
							$plugin = $url.'/raw/'.$branch.'/'.$data;
							$download = @file_get_contents($plugin);
							if ($download) {
								$path = $folder.'/'.$data;
								if (!is_dir(dirname($path))) {
									mkdir(dirname($path),0777,true);
								}
								file_put_contents($path,$download); //逐一抓取文件
							} else {
								$logs .= 'Error: Plugin file - "'.$plugin.'" cannot be downloaded!'.PHP_EOL;
							}
						}
					}
				} else {
					$logs .= 'Error: Gitee API - Too many files, please upload the zip manually!'.PHP_EOL;
				}
			//即$gitIsh已解包
			} elseif (!$datas && !$tfLocal) {
				$download = @file_get_contents($plugin); //只能取主文件
				$path = $pluginZip ?: $folder.'/'.basename($plugin);
				if (!is_dir(dirname($path))) {
					mkdir(dirname($path),0777,true);
				}
				if ($download) {
					file_put_contents($path,$download); //覆盖解包位置
				}
			//社区版就地打包
			} elseif ($tfLocal) {
				$folder = realpath($url);
			}
			$phpZip = new ZipArchive();
			if ($phpZip->open($cdn,ZipArchive::CREATE | ZipArchive::OVERWRITE)!==true) {
				$logs .= 'Error: Packing zip - "'.$cdn.'" failed to create files!'.PHP_EOL;
			} else {
				$filePaths = pluginRoute($folder,$name,true);
				foreach ($filePaths as $filePath) {
					$phpZip->addFile($filePath,$name.substr($filePath,strlen($folder)));
				}
				$phpZip->close();
			}
		//直接下载到加速文件夹
		} else {
			$zip = $bingo && $github ? $url.'/archive/'.$branch.'.zip' : $zip;
			$download = @file_get_contents($zip);
			if ($download) {
				file_put_contents($cdn,$download);
			} else {
				$logs .= 'Error: Source zip - "'.$zip.'" cannot be downloaded!'.PHP_EOL;
			}
			if ($tfLocal) {
				$logs .= 'Warning: Local "'.$url.'" is not valid, using "'.$zip.'" for download.'.PHP_EOL;
			}
		}

		return $logs;
	}
