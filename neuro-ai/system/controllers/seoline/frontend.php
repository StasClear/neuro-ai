<?php

class seoline extends cmsFrontend {
	
	protected $useOptions = true;

    private $accessToken = null;
    private $tokenExpire = 0;
	
	public function initNeuron(array $content = [], string $prompt = '', bool $skip = false): array {

		$apiKey = !empty($this->options['token']) ? $this->options['token'] : '';
		$neuronModel = !empty($this->options['model']) ? $this->options['model'] : '';
		$provider = !empty($this->options['provider']) ? $this->options['provider'] : 'openrouter';
		
		if ($provider == 'openrouter'){
			$url = "https://openrouter.ai/api/v1/chat/completions";
		} else if ($provider == 'together'){
			$url = "https://api.together.xyz/v1/chat/completions";
		} else if ($provider == 'deepinfra'){
			$url = "https://api.deepinfra.com/v1/openai/chat/completions";
		}

		if (empty($url)) {
			return ['error' => true, 'message' => 'Неверный провайдер AI'];
		}
		
		$main_promt = "Ты опытный SEO-специалист и копирайтер.\n";
		if ($skip){
			$main_promt .= "Твоя задача — оптимизировать заголовок, SEO-заголовок, SEO-описание и ключевые слова.\n";
		} else {
			$main_promt .= "Твоя задача — оптимизировать предоставленный текст для поисковых систем, сохранив его смысл и улучшив читаемость.\n";
		}
		$main_promt .= "При оптимизации выполни следующее:\n";
		$main_promt .= "1. Улучши заголовок (title), чтобы он был цепляющим, релевантным и включал главные ключевые слова.\n";
		$main_promt .= "2. Сформируй SEO-заголовок (seo_title) длиной 55 символов.\n";
		$main_promt .= "3. Подбери 6-7 ключевых слов (seo_keys), включая заданные и LSI-слова, исключи дубликаты, раздели запятыми. **Ключи должны встречаться в тексте минимум 1–2 раза.**\n";
		$main_promt .= "4. Создай SEO-описание (seo_desc) длиной от 140 до 160 символов, чтобы оно привлекало внимание в поисковой выдаче.\n";
		if (!$skip){
			$main_promt .= "5. Оптимизируй сам текст:\n- Для каждого ключевого слова из списка `seo_keys` рассчитай целевое количество вхождений:\n Минимум = округлить(0.01 * общее количество слов в тексте),\n Максимум = округлить(0.03 * общее количество слов в тексте).\n- Вставь ключевые слова так, чтобы каждое встречалось в тексте не меньше Минимума и не больше Максимума раз.\n- Ключи должны быть распределены равномерно: хотя бы одно вхождение в начале, середине и конце текста.\n- Если ключей нет — подбери релевантные с учётом темы текста.\n- Добавь LSI-слова для повышения релевантности.\n- Исправь грамматические ошибки.\n- Сохрани исходный смысл.\n- Верни текст в формате HTML.\n\n";
		}
		$main_promt .= "Верни результат строго в формате JSON с такими ключами:\n";
		$main_promt .= "{\n";
			$main_promt .= "\"title\": \"улучшенный заголовок\",\n";
			$main_promt .= "\"seo_title\": \"SEO-заголовок\",\n";
			$main_promt .= "\"seo_keys\": \"ключ1, ключ2, ключ3\",\n";
			$main_promt .= "\"seo_desc\": \"SEO-описание\",\n";
			if (!$skip){ $main_promt .= "\"text\": \"улучшенный HTML-текст\"\n"; }
		$main_promt .= "}\n";
		$main_promt .= "В следующем сообщения будет данные для оптимизации. Отвечай только чистым JSON-объектом без ```json. Верните ответ на русском языке, используя только русские слова и кириллицу. Избегайте англицизмов и иностранных вставок. Никаких комментариев, никаких объяснений, никаких мыслей";

		$request = [
			"model" => $neuronModel,
			"messages" => [
				[
					"role" => "user",
					"content" => $main_promt
				],
				[
					"role" => "user",
					"content" => $prompt
				],
			]
		];

		// Устанавливаем таймаут (5 минут)
		set_time_limit(300);
		$ch = curl_init($url);

		if ($ch === false) {
			return ['error' => true, 'message' => 'Ошибка инициализации cURL'];
		}

		$headers = [
			"Authorization: Bearer " . $apiKey,
			"Content-Type: application/json",
			"HTTP-Referer: " . ($this->cms_config->host ?? ''),
			"X-Title: " . ($this->cms_config->sitename ?? ''),
		];

		$curlOptions = [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => json_encode($request, JSON_THROW_ON_ERROR),
			CURLOPT_TIMEOUT => 300,
		];

		curl_setopt_array($ch, $curlOptions);
		$response = curl_exec($ch);

		if ($response === false) {
			$error = curl_error($ch);
			$this->saveLog([['type' => 'error', 'text' => $error, 'content' => $content['title'] ?? '', 'file' => 'frontend.php']]);
			curl_close($ch);
			return ['error' => true, 'message' => $error];
		} else {
			$this->saveLog([['type' => 'response', 'text' => $response, 'content' => $content['title'] ?? '', 'file' => 'frontend.php']]);
		}

		curl_close($ch);
		set_time_limit(ini_get('max_execution_time')); // Возвращаем стандартный таймаут

		// Очистка ответа
		$response = $this->cleanResponse($response);
		$responseData = json_decode($response, true);

		if (empty($responseData['choices'])) {
			$this->saveLog([['type' => 'error', 'text' => 'Нейросеть не вернула результат', 'content' => $content['title'] ?? '', 'file' => 'frontend.php']]);
			return ['error' => true, 'message' => 'Нейросеть не вернула результат'];
		}

		$json = $responseData['choices'][0]['message']['content'] ?? '';
		$json = $this->extractAndFixJson($json);

		$this->saveLog([['type' => 'choices', 'text' => $json, 'content' => $content['title'] ?? '', 'file' => 'frontend.php']]);

		try {
			$phpArray = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			$this->saveLog([['type' => 'error', 'text' => 'Ошибка при обработке JSON', 'content' => $content['title'] ?? '', 'file' => 'frontend.php']]);
			return ['error' => true, 'message' => 'Ошибка при обработке JSON'];
		}
		
		if (!empty($phpArray['error'])){
			return ['error' => true, 'message' => $phpArray['error']['message']];
		}

		return $phpArray;
	}
	
	public function initDzenCron(){
		
		$dzens = $this->model->filterEqual('i.is_pub', 1)->getData('seo_dzens', false, false, function($item, $model){
			$item['options'] = cmsModel::yamlToArray($item['options']);
			return $item;
		});
        if (!$dzens) { return false; }

        if (!is_writable($this->cms_config->upload_path . 'seoline')){
            return false;
        }
		
		$data = [];

        foreach ($dzens as $dzen){
			
			$ctype = $this->model_content->selectOnly('i.id, i.name')->getItemById('content_types', $dzen['ctype_id']);
			if (!$ctype){ continue; }

            $items = $this->model->
				filterEqual('i.is_pub', 1)->
				filterNotEqual('i.is_private', 1)->
				filterNotEqual('i.is_approved', 0)->
				//filterDateYounger('i.date_pub', 7)->
				orderBy('i.date_pub', 'DESC')->
				select('c.title', 'category_title')->
				joinLeft('con_' . $ctype['name'] . '_cats', 'c', 'c.id = i.category_id')->
				useCache('content.list.' . $ctype['name'])->
				get('con_' . $ctype['name']);

			if (!$items){ continue; }
			
			$is_amp = $this->model->
				selectOnly('i.id')->
				filterEqual('i.ctype_id', $ctype['id'])->
				filterEqual('i.is_pub', 1)->
				getData('seo_amps', false, true);

			foreach ($items as $item){

				$item['content'] = strip_tags($item['content'], '<p><a><b><i><u><s><h1><h2><h3><h4><blockquote><ul><li><ol>');
				$item['content'] = preg_replace('#<(\w+)[^>]*>\s*</\1>#u', '', $item['content']);
				$item['content'] = preg_replace_callback(
					'/<a\s+[^>]*href=("|\')(.*?)\1[^>]*>(.*?)<\/a>/iu',
					function ($matches) {
						$href = htmlspecialchars($matches[2], ENT_QUOTES);
						return '<a href="'.$href.'">'.$matches[3].'</a>';
					},
					$item['content']
				);
				$item['content'] = preg_replace('/<(?!a)(\w+)[^>]*>/', '<$1>', $item['content']);
				$item['content'] = trim(preg_replace('/\s+/', ' ', $item['content']));
				$item['content'] = str_replace('&nbsp;', ' ', $item['content']);

				$plain_text = trim(strip_tags($item['content']));
				if (mb_strlen($plain_text, 'UTF-8') < 300) {
					continue;
				}
				
				$date = new DateTime($item['date_pub']);
				$teaser = (!empty($dzen['field_teaser']) && !empty($item[$dzen['field_teaser']])) ? string_short(strip_tags($item[$dzen['field_teaser']]), 160, '...') : string_short(strip_tags($item['content']), 160, '...');
				$teaser = str_replace('&nbsp;', ' ', $teaser);
				$preset_photo = !empty($dzen['preset_photo']) ? $dzen['preset_photo'] : 'big';
				$field_photo = '';
				if (!empty($dzen['field_photo']) && !empty($item[$dzen['field_photo']])){
					$field_photo = $this->cms_config->host . html_image_src($item[$dzen['field_photo']], $preset_photo, true);
				}
				if (!empty($dzen['options']['generate']) && !$field_photo){
					
					if (file_exists($this->cms_config->upload_path . join(DIRECTORY_SEPARATOR, ['seoline', 'photos', $ctype['name'] . '_' . $item['id'] . '.png']))){
						$field_photo = $this->cms_config->upload_host_abs . '/seoline/photos/' . $ctype['name'] . '_' . $item['id'] . '.png';
					} else {
						if (!file_exists($this->cms_config->upload_path . join(DIRECTORY_SEPARATOR, ['seoline', 'photos']))) {
							if (mkdir($this->cms_config->upload_path . join(DIRECTORY_SEPARATOR, ['seoline', 'photos']), 0755, true)) {
								chmod($this->cms_config->upload_path . join(DIRECTORY_SEPARATOR, ['seoline', 'photos']), 0755);
							}
						}
						$field_photo = $this->generateImage(
							(!empty($this->options['cover']) ? $this->cms_config->host . html_image_src($this->options['cover'], 'original', true) : false), 
							(!empty($this->options['cover_color']) ? $this->options['cover_color'] : '#000'), 
							htmlspecialchars($item['title']), 
							(!empty($item['category_title']) ? $item['category_title'] : lang_date(date('j F Y', strtotime($item['date_pub'])))), 
							$ctype['name'] . '_' . $item['id'] . '.png'
						);
					}
					
				}
				
				$category = !empty($item['category_title']) ? $item['category_title'] : 'Корневая категория';
				if (!empty($dzen['options']['native-draft'])){
					$category = 'native-draft';
				}

				$data[] = [
					'title' => htmlspecialchars($item['title']),
					'link' => href_to_abs($ctype['name'], $item['slug'] . '.html'),
					'pdalink' => $is_amp ? href_to_abs($ctype['name'], $item['slug'] . '.html?amp=1') : '',
					'guid' => $ctype['name'] . '_' . $item['id'],
					'pubDate' => $date->format(DateTime::RFC822),
					'category' => $category,
					'adult' => !empty($dzen['options']['adult']) ? true : false,
					'description' => $teaser,
					'content' => $item['content'],
					'image_url' => $field_photo
				];

			}

        }
		
		if ($data){
			
			usort($data, function ($a, $b) {
				$timeA = strtotime($a['pubDate']);
				$timeB = strtotime($b['pubDate']);
				return $timeB <=> $timeA;
			});
				
			$path = $this->cms_config->upload_path . join(DIRECTORY_SEPARATOR, ['seoline', 'rss']);
			
			if (!file_exists($path)) {
				if (mkdir($path, 0755, true)) {
					chmod($path, 0755);
				}
			}
			
			$lastBuildDate = new DateTime();
			file_put_contents(
				$path . DIRECTORY_SEPARATOR . "dzen.xml",
				'<?xml version="1.0" encoding="UTF-8"?>' . html_minify($this->cms_template->renderInternal($this, 'dzen', [
					'data' => $data,
					'options' => [
						'title' => $this->options['sitename'],
						'link' => $this->cms_config->host,
						'description' => $this->options['description'],
						'language' => $this->options['lang'],
						'lastBuildDate' => $lastBuildDate->format(DateTime::RFC822)
					]
				]))
			);
			file_put_contents(
				$path . DIRECTORY_SEPARATOR . "dzen_test.xml",
				'<?xml version="1.0" encoding="UTF-8"?>' . html_minify($this->cms_template->renderInternal($this, 'dzen', [
					'data' => $data,
					'options' => [
						'title' => $this->options['sitename'],
						'link' => $this->cms_config->host,
						'description' => $this->options['description'],
						'language' => $this->options['lang'],
						'lastBuildDate' => $lastBuildDate->format(DateTime::RFC822)
					]
				]))
			);
			
		}
		
		return true;
		
	}

	public function generateImage($cover, $bg_color, $title, $category, $outputFile = 'result.png') {

		$width = 600;
		$height = 400;

		// Путь к твоему шрифту
		$font = $this->cms_config->system_path . join(DIRECTORY_SEPARATOR, ['controllers', 'seoline', 'libs', 'font.ttf']);
		if (!file_exists($font)) {
			die("Шрифт font.ttf не найден по пути: $font");
		}

		// Создаём холст
		$image = imagecreatetruecolor($width, $height);

		// Цвет фона
		list($r, $g, $b) = sscanf($bg_color, "#%02x%02x%02x");
		$bg = imagecolorallocate($image, $r, $g, $b);
		imagefilledrectangle($image, 0, 0, $width, $height, $bg);

		// Загружаем фон, если есть
		if ($cover && @getimagesize($cover)) {
			$coverInfo = getimagesize($cover);
			switch ($coverInfo['mime']) {
				case 'image/jpeg': $coverImg = @imagecreatefromjpeg($cover); break;
				case 'image/png':  $coverImg = @imagecreatefrompng($cover); break;
				case 'image/gif':  $coverImg = @imagecreatefromgif($cover); break;
				default: $coverImg = null;
			}
			if ($coverImg) {
				$coverResized = imagescale($coverImg, $width, $height);
				imagecopy($image, $coverResized, 0, 0, 0, 0, $width, $height);
				imagedestroy($coverImg);
			}
		}

		// Цвета
		$white = imagecolorallocate($image, 255, 255, 255);
		$blackTransparent = imagecolorallocatealpha($image, 0, 0, 0, 80); // полупрозрачный чёрный

		// === Заголовок ===
		$fontSize = 20;
		$maxTextWidth = $width - 40;
		$lines = [];

		// Перенос текста
		$words = explode(' ', $title);
		$currentLine = '';
		foreach ($words as $word) {
			$testLine = $currentLine ? $currentLine . ' ' . $word : $word;
			$bbox = imagettfbbox($fontSize, 0, $font, $testLine);
			$lineWidth = abs($bbox[2] - $bbox[0]);
			if ($lineWidth > $maxTextWidth && $currentLine !== '') {
				$lines[] = $currentLine;
				$currentLine = $word;
			} else {
				$currentLine = $testLine;
			}
		}
		if ($currentLine) {
			$lines[] = $currentLine;
		}

		// Центрируем
		$lineHeight = abs($bbox[1] - $bbox[7]) + 6;
		$totalTextHeight = count($lines) * $lineHeight;
		$y = (int)(($height - $totalTextHeight) / 2 + $lineHeight);

		// Фон под заголовком
		$rectY1 = $y - $lineHeight;
		$rectY2 = $y - $lineHeight + $totalTextHeight + 10;
		imagefilledrectangle($image, 20, $rectY1 - 5, $width - 20, $rectY2, $blackTransparent);

		// Текст
		foreach ($lines as $line) {
			$bbox = imagettfbbox($fontSize, 0, $font, $line);
			$textWidth = abs($bbox[2] - $bbox[0]);
			$x = (int)(($width - $textWidth) / 2);
			imagettftext($image, $fontSize, 0, $x, $y, $white, $font, $line);
			$y += $lineHeight;
		}

		// === Категория ===
		$catFontSize = 12;
		$bboxCat = imagettfbbox($catFontSize, 0, $font, $category);
		$catWidth = abs($bboxCat[2] - $bboxCat[0]);
		$catHeight = abs($bboxCat[1] - $bboxCat[7]);
		$padding = 5;
		$margin = 20;
		$radius = 2;

		$rectX1 = (int)($width - $catWidth - $padding*2 - $margin);
		$rectY1 = (int)$margin;
		$rectX2 = (int)($width - $margin);
		$rectY2 = (int)($margin + $catHeight + $padding*2);

		// Полупрозрачный фон для категории
		imagefilledrectangle($image, $rectX1, $rectY1, $rectX2, $rectY2, $blackTransparent);

		// Закруглённая рамка
		imagearc($image, $rectX1 + $radius, $rectY1 + $radius, $radius*2, $radius*2, 180, 270, $white);
		imagearc($image, $rectX2 - $radius, $rectY1 + $radius, $radius*2, $radius*2, 270, 360, $white);
		imagearc($image, $rectX1 + $radius, $rectY2 - $radius, $radius*2, $radius*2, 90, 180, $white);
		imagearc($image, $rectX2 - $radius, $rectY2 - $radius, $radius*2, $radius*2, 0, 90, $white);

		imageline($image, $rectX1 + $radius, $rectY1, $rectX2 - $radius, $rectY1, $white);
		imageline($image, $rectX1 + $radius, $rectY2, $rectX2 - $radius, $rectY2, $white);
		imageline($image, $rectX1, $rectY1 + $radius, $rectX1, $rectY2 - $radius, $white);
		imageline($image, $rectX2, $rectY1 + $radius, $rectX2, $rectY2 - $radius, $white);

		// Текст категории
		imagettftext($image, $catFontSize, 0, $rectX1 + $padding, $rectY1 + $catHeight + $padding - 1, $white, $font, $category);

		// Сохраняем
		imagepng($image, $this->cms_config->upload_path . join(DIRECTORY_SEPARATOR, ['seoline', 'photos', $outputFile]));
		imagedestroy($image);
		
		return $this->cms_config->upload_host_abs . '/seoline/photos/' . $outputFile;
		
	}
	
	public function addIndexingQueue($url, $search, $type = 'URL_UPDATED'){
		
		cmsQueue::pushOn('indexing', [
			'controller' => $this->name,
			'hook'       => 'queue_indexing',
			'params'     => [$url, $search, $type]
		]);
		
	}

	public function saveSearchLog($query, $count, $source = 'search'){
		
		cmsCore::includeFile('system/controllers/seoline/libs/device_info.php');
		$deviceInfo = new DeviceInfo();
		$device = $deviceInfo->getInfo();
		
		$data = [
			'user_id' => $this->cms_user->id ? $this->cms_user->id : null,
			'ip' => $this->cms_user->ip ? $this->cms_user->ip : $device['ip'],
			'query' => $query,
			'count' => $count,
			'device' => $device,
			'source' => $source,
			'page' => !empty($_SERVER['HTTP_REFERER']) ? strtok($this->sanitizeSearchQuery($_SERVER['HTTP_REFERER']), '?') : null
		];
		
		$this->model->saveData('seo_searchs', $data);
		
	}
	
	function sanitizeSearchQuery($input) {
		// убираем управляющие символы
		$input = preg_replace('/[\x00-\x1F\x7F]/u', '', $input);

		// убираем теги, чтобы не остался <script>
		$input = strip_tags($input);

		// обрезаем слишком длинные строки (например, 160 символов)
		$input = mb_substr($input, 0, 160);

		// убираем лишние пробелы
		$input = trim($input);

		return $input;
	}

	/**
     * Получение access_token с кэшированием
     */
    private function getAccessToken() {
		
		if (empty($this->options['google_json']['path'])){
			$this->saveLog([['type' => 'error', 'text' => 'JSON файл от Google не загружен', 'content' => $url ?? '', 'file' => 'frontend.php']]);
			return false;
		}
		
        // если токен ещё живой → возвращаем его
        if ($this->accessToken && $this->tokenExpire > time() + 50) {
            return $this->accessToken;
        }

        $data = json_decode(file_get_contents($this->cms_config->upload_path . $this->options['google_json']['path']), true);

        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $now = time();
        $payload = [
            'iss'   => $data['client_email'],
            'scope' => 'https://www.googleapis.com/auth/indexing',
            'aud'   => $data['token_uri'],
            'iat'   => $now,
            'exp'   => $now + 3600,
        ];

        $base64UrlHeader  = rtrim(strtr(base64_encode(json_encode($header)), '+/', '-_'), '=');
        $base64UrlPayload = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');

        $signatureInput = $base64UrlHeader . "." . $base64UrlPayload;
        openssl_sign($signatureInput, $signature, $data['private_key'], 'sha256WithRSAEncryption');
        $base64UrlSignature = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

        $jwt = $signatureInput . "." . $base64UrlSignature;

        // запрос токена
        $postFields = http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $data['token_uri']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        $response = curl_exec($ch);
        curl_close($ch);

        $result = json_decode($response, true);
        if (!empty($result['access_token'])) {
            $this->accessToken = $result['access_token'];
            $this->tokenExpire = time() + $result['expires_in'];
            return $this->accessToken;
        }

        return null;
    }
	
	/**
     * Отправка одного URL
     */
    public function sendUrlToGoogle($url, $type = 'URL_UPDATED') {

        $accessToken = $this->getAccessToken();
        if (!$accessToken) {
            $this->saveLog([['type' => 'error', 'text' => 'Ошибка получение AccessToken', 'content' => $url ?? '', 'file' => 'frontend.php']]);
			return false;
        }

        $data = json_encode([
            'url'  => $url,
            'type' => $type
        ]);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://indexing.googleapis.com/v3/urlNotifications:publish');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $accessToken
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        $response = curl_exec($ch);
        curl_close($ch);

        return $this->handleGoogleResponse($response, $url);
		
    }
	
	/**
     * Отправка пачки URL (перебором)
     */
    public function sendUrlsToGoogle($urls, $type = 'URL_UPDATED') {
        $results = [];
        foreach ($urls as $url) {
            $results[$url] = $this->sendUrlToGoogle($url, $type);
        }
        return $results;
    }

	function handleGoogleResponse($response, $url) {

		$data = json_decode($response, true);

		// Ошибка от API
		if (isset($data['error'])) {
			$message = $data['error']['message'] ?? 'Неизвестная ошибка';
			$this->saveLog([[
				'type'    => 'error',
				'text'    => 'Ошибка GoogleIndexing: ' . $message,
				'content' => $url ?? '',
				'file'    => 'frontend.php'
			]]);
			return false;
		}

		// Успешный ответ
		if (isset($data['urlNotificationMetadata'])) {
			$this->saveLog([[
				'type'    => 'URL_UPDATED',
				'text'    => 'Операция прошла успешно',
				'content' => $url ?? '',
				'file'    => 'frontend.php'
			]]);
			return true;
		}

		// Непредвиденный ответ
		$this->saveLog([[
			'type'    => 'error',
			'text'    => 'Неожиданный ответ API: ' . $response,
			'content' => $url ?? '',
			'file'    => 'frontend.php'
		]]);

		return false;

	}
	
	/**
     * Отправка запроса к Яндекс IndexNow
     */
    private function sendRequestToYandex($urls) {
		
		if (empty($this->options['yandex_key'])){
			return ['httpCode' => 404, 'response' => 'Поле ключ яндекса не заполнен'];
		}

        $data = [
            "host"        => preg_replace('#^https?://#', '', $this->cms_config->host),
            "key"         => $this->options['yandex_key'],
            "keyLocation" => $this->cms_config->host . '/' . $this->options['yandex_key'] . '.txt',
            "urlList"     => array_values($urls)
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://yandex.com/indexnow");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'httpCode' => $httpCode,
            'response' => $response
        ];
    }
	
	/**
     * Отправить один URL
     */
    public function sendUrlsToYandex($urls) {
		
		if (!is_array($urls)) {
            $urls = [$urls];
        }

        $result = $this->sendRequestToYandex($urls);
		
		$errors = [
			202 => 'Новый ключ ожидает проверки. Если вы уверены, что он корректный, подождите некоторое время и отправьте несколько других адресов. Если код изменился на 200 OK, значит, ключ проверен и адреса переданы. Если код остался 202, то ключ еще не был добавлен в базу после проверки и необходимо еще подождать.',
			403 => 'Ключ не удалось загрузить или он не подходит к указанным в запросе адресам.',
			405 => 'Поддерживаются методы GET и POST.',
			422 => [
				'Invalid key location' => 'Параметр keyLocation указан неверно.',
				'Invalid url' => 'В запросе указан неверный URL-адрес или переданный ключ не подходит для его обработки.',
				'Key must be at least 8 characters' => 'Ключ включает в себя меньше 8 символов.',
				'Key must be no longer than 128 characters' => 'Ключ включает в себя больше 128 символов.',
				'Key must consist of a-Z0-9 or \'-\'' => 'Ключ содержит неподходящие символы.',
				'No key provided' => 'Отсутствует параметр key в запросе.',
				'No url provided' => 'Отсутствует параметр url в запросе.'
			],
			429 => 'Превышено количество запросов для одного IP-адреса.'
		];

        if ($result['httpCode'] == 200) {
            foreach ($urls as $url) {
                $this->saveLog([[
                    'type'    => 'response',
                    'text'    => 'URL успешно отправлен',
                    'content' => $url,
                    'file'    => 'frontend.php'
                ]]);
            }
            return true;
        } else {
			$error = $result['httpCode'];
			if (!empty($errors[$result['httpCode']])){
				$error = $errors[$result['httpCode']];
				if (is_array($error) && !empty($result['response'])){
					$response = json_decode($result['response'], true);
					if (!empty($response['message'])){
						$error = !empty($error[$response['message']]) ? $error[$response['message']] : $response['message'];
					}
				}
			}
            foreach ($urls as $url) {
                $this->saveLog([[
                    'type'    => ($result['httpCode'] == 202) ? 'response' : 'error',
                    'text'    => 'Ответ IndexNow ' . $result['httpCode'] . ' : ' . $error,
                    'content' => $url,
                    'file'    => 'frontend.php'
                ]]);
            }
            return false;
        }

    }

	public function postToTelegram($item, $posting, $urlToPost = null) {

		if (empty($posting['options']['telegram_token'])) {
			$this->saveLog([['type' => 'error', 'text' => 'Поле токен Telegram-бота не заполнено', 'content' => $item['title'] ?? '', 'file' => 'frontend.php']]);
			return false;
		}

		if (empty($posting['options']['telegram_chat_id'])) {
			$this->saveLog([['type' => 'error', 'text' => 'Поле ID Telegram-канала не заполнено', 'content' => $item['title'] ?? '', 'file' => 'frontend.php']]);
			return false;
		}

		$botToken = $posting['options']['telegram_token'];
		$chatId   = $posting['options']['telegram_chat_id'];
		$url      = "https://api.telegram.org/bot{$botToken}/sendPhoto";
		
		$text_field = (!empty($posting['field_content']) && !empty($item[$posting['field_content']])) ? $posting['field_content'] : 'content';

		// Очистка и обрезка текста
		$text    = $this->cleanTelegramText($item[$text_field], 850); // запас на заголовок + кнопки
		$title   = $this->cleanTelegramText($item['title'], 120);
		$caption = "<b>{$title}</b>\n\n{$text}";

		$params = [
			'chat_id'    => $chatId,
			'photo'      => $posting['image'],
			'caption'    => $caption,
			'parse_mode' => 'HTML'
		];

		// Добавляем кнопку "Читать полностью", если есть ссылка
		if (!empty($urlToPost)) {
			$params['reply_markup'] = json_encode([
				'inline_keyboard' => [
					[
						['text' => '📖 Читать полностью', 'url' => $urlToPost],
						['text' => '🔗 Поделиться', 'url' => 'https://t.me/share/url?url=' . urlencode($urlToPost)]
					]
				]
			]);
		}

		// Запрос в Telegram
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4 );
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params, JSON_UNESCAPED_UNICODE));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15); // макс время соединения
		curl_setopt($ch, CURLOPT_TIMEOUT, 20);        // макс время выполнения
		$result   = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		// Проверяем ответ Telegram
		$response = json_decode($result, true);
		if ($httpCode !== 200 || empty($response['ok'])) {
			$this->saveLog([[
				'type'    => 'error',
				'text'    => 'Ошибка отправки в Telegram: ' . ($response['description'] ?? 'Неизвестная ошибка'),
				'content' => $caption,
				'file'    => 'frontend.php'
			]]);
			return false;
		}

		// Лог успешной отправки
		$this->saveLog([[
			'type'    => 'response',
			'text'    => 'Пост опубликован в Telegram',
			'content' => $title,
			'file'    => 'frontend.php'
		]]);

		return true;

	}

	/**
	 * Очистка и подготовка текста для Telegram
	 */
	private function cleanTelegramText($text, $maxLength = 1000) {
		
		$text = preg_replace(
			'/<a\b[^>]*class="[^"]*\bbtn\b[^"]*\bbtn-outline-info\b[^"]*\bmt-2\b[^"]*\bexternal_link\b[^"]*"[^>]*>.*?<\/a>/isu',
			'',
			$text
		);

		// Заменяем переносы строк
		$text = str_replace(["<br>", "<br/>", "<br />"], "\n", $text);

		// Убираем пробелы и невалидные переносы
		$text = trim(preg_replace('/\s+/', ' ', $text));

		// Декодируем HTML-сущности
		$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		
		// Исправляем двойные теги
		$text = str_replace(['<b><b>', '</b></b>', '<i><i>', '</i></i>'], ['<b>', '</b>', '<i>', '</i>'], $text);

		// Убираем лишние пробелы и переносы
		$text = preg_replace('/[ \t]+/', ' ', $text);
		$text = preg_replace("/\n{3,}/", "\n\n", $text);
		
		// Возвращаем разрешённые теги
		$allowed = ['b','i','u','s','a','code','pre','strong','em','tg-spoiler'];
		foreach ($allowed as $tag) {
			$text = preg_replace("#&lt;(/?$tag)(.*?)&gt;#i", "<$1$2>", $text);
		}
		
		// Декодируем HTML-сущности
		$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		
		// Экранируем спецсимволы
		$text = strip_tags($text, '<b><i><u><s><a><code><pre><strong><em><tg-spoiler>');
		
		// Проверяем <a href=""> чтобы не было незакрытых ссылок
		$text = $this->simpleFixUnclosedTags($text);

		// Обрезаем до лимита
		if (mb_strlen($text, 'UTF-8') > $maxLength) {
			$text = mb_substr($text, 0, $maxLength - 20, 'UTF-8') . "...";
		}

		return trim($text);
	}
	
	public function simpleFixUnclosedTags($text) {
		$allowedTags = ['b', 'i', 'u', 's', 'a', 'code', 'pre', 'strong', 'em', 'tg-spoiler'];
    
		foreach ($allowedTags as $tag) {
			// Удаляем тег, если есть дисбаланс
			$openPattern = '/<' . $tag . '(\s[^>]*)?>/i';
			$closePattern = '/<\/' . $tag . '>/i';
			
			$openCount = preg_match_all($openPattern, $text);
			$closeCount = preg_match_all($closePattern, $text);
			
			if ($openCount !== $closeCount) {
				$text = preg_replace($openPattern, '', $text);
				$text = preg_replace($closePattern, '', $text);
			}
		}
		
		return $text;
	}

	private function buildSeoKeyHashtags($seo_keys) {

		if (empty($seo_keys)) {
			return '';
		}

		$hashtags = [];
		$keywords = explode(',', html_entity_decode(strip_tags($seo_keys), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

		foreach ($keywords as $keyword) {

			$keyword = trim($keyword);
			if ($keyword === '') {
				continue;
			}

			$words = preg_split('/\s+/u', $keyword);
			$tag = '';

			foreach ($words as $word) {

				$word = preg_replace('/[^\p{L}\p{N}_-]+/u', '', $word);
				if ($word === '') {
					continue;
				}

				$tag .= mb_strtoupper(mb_substr($word, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($word, 1, null, 'UTF-8');
			}

			if ($tag !== '') {
				$hashtags[$tag] = '#' . $tag;
			}
		}

		return implode(' ', array_values($hashtags));
	}

	public function postToVK($item, $posting, $url){

		if (empty($posting['options']['vk_group_token'])) {
			$this->saveLog([[
				'type' => 'error',
				'text' => 'Поле токена сообщества VK не заполнено',
				'content' => $item['title'] ?? '',
				'file' => 'frontend.php'
			]]);
			return false;
		}

		if (empty($posting['options']['vk_group_id'])) {
			$this->saveLog([[
				'type' => 'error',
				'text' => 'Поле ID группы VK не заполнено',
				'content' => $item['title'] ?? '',
				'file' => 'frontend.php'
			]]);
			return false;
		}

		$groupToken = $posting['options']['vk_group_token'];
		$groupId    = abs($posting['options']['vk_group_id']);

		$text_field = (!empty($posting['field_content']) && !empty($item[$posting['field_content']]))
			? $posting['field_content']
			: 'content';

		$title = $this->cleanTelegramText($item['title'], 120);
		$text  = $this->cleanTelegramText($item[$text_field], 1000);
		$message = $title . "\n\n" . $text . "\n\n🔗 Читать полностью: " . $url;
		$hashtags = $this->buildSeoKeyHashtags($item['seo_keys'] ?? '');

		if ($hashtags) {
			$message .= "\n\n" . $hashtags;
		}

		cmsCore::includeFile('system/controllers/seoline/libs/vk/vendor/autoload.php');

		$vk = new \VK\Client\VKApiClient('5.199', \VK\Client\Enums\VKLanguage::RUSSIAN, 60); // 30 секунд таймаут
		
		try {
			$response = $vk->wall()->post($groupToken, [
				'owner_id' => -$groupId,
				'message' => $message
			]);
		} catch (VK\Exceptions\VKClientException $e) {
			if (strpos($e->getMessage(), 'cURL error 28') !== false || strpos($e->getMessage(), '504') !== false) {
				$this->saveLog([['type' => 'error', 'text' => 'Сервер VK вернул ошибку: ' . $e->getMessage(), 'content' => $title, 'file' => 'frontend.php']]);
				return false;
			} else {
				$this->saveLog([['type' => 'error', 'text' => 'VKClientException: ' . $e->getMessage(), 'content' => $title, 'file' => 'frontend.php']]);
				return false;
			}
		} catch (VK\Exceptions\Api\VKApiException $e) {
			$this->saveLog([['type' => 'error', 'text' => 'VKApiException: ' . $e->getMessage(), 'content' => $title, 'file' => 'frontend.php']]);
			return false;
		}

		$this->saveLog([[
			'type' => 'response',
			'text' => 'Пост опубликован в VK',
			'content' => $item['title'] ?? '',
			'file' => 'frontend.php'
		]]);

		return true;
	}

	
	/**
	 * Очищает ответ от лишних символов.
	 */
	private function cleanResponse(string $response): string {
		$response = preg_replace('/<think>.*?<\/think>/s', '', $response);
		return str_replace(["\n", "\r", "\t"], '', $response);
	}

	/**
	 * Извлекает JSON из ответа и исправляет его, если нужно.
	 */
	private function extractAndFixJson(string $json): string {
		if (preg_match('/```json(.*?)```/s', $json, $matches)) {
			$json = trim($matches[1]);
			$json = str_replace(["\n", "\r", "\t"], '', $json);
			$json = preg_replace('/[^\p{L}\p{N}\p{P}\p{Z}]+/u', '', $json);
		}

		if (substr($json, 0, 1) !== '{') {
			$start = strpos($json, '{');
			if ($start === false) {
				$json = '{' . $json . '}';
			} else {
				$end = strpos($json, '}', $start + 1);
				$json = ($end !== false) ? substr($json, $start, $end - $start + 1) : '{' . substr($json, $start + 1) . '}';
			}
		}
		
		$json = str_replace(["\n", "\r", "\t"], '', $json);
		$json = str_replace(["'"], ['"'], $json);
		$json = str_replace(["**Заголовок:**", "**Текст:**"], ['"title":', ',"text":'], $json);
		$json = str_replace(['", ,"'], ['","'], $json);

		return $json;
	}
	
	public function getModelLists($provider = false){
		
		$input = !empty($this->options['models']) ? $this->options['models'] : <<<TEXT
[openrouter]
deepseek/deepseek-r1-0528:free

[together]
deepseek-ai/DeepSeek-R1-Distill-Llama-70B-free

[deepinfra]
deepseek-ai/DeepSeek-R1-Turbo
TEXT;
		$lines = explode("\n", $input);
		$result = [];
		$currentParent = null;

		foreach ($lines as $line) {
			$line = trim($line);
			if ($line === '') continue;

			// Если строка — это родитель
			if (preg_match('/^\[(.+)\]$/', $line, $matches)) {
				$currentParent = $matches[1];
				$result[$currentParent] = [];
			} elseif ($currentParent !== null) {
				// Добавляем дочерний элемент
				$cleanedLine = str_replace([' ', ','], '', $line);
				$result[$currentParent][$cleanedLine] = $cleanedLine;
			}
		}
		
		if ($provider){
			return !empty($result[$provider]) ? $result[$provider] : [];
		}
		
		return $result;
		
	}
	
	public function getCachedWordForms($keyword) {

        $cacheDir = cmsConfig::get('system_path') . 'controllers/seoline/cache/';
        $cacheFile = $cacheDir . 'forms_cache.php';

        // создаём папку при необходимости
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        static $cache = null;

        // Загружаем кэш один раз
        if ($cache === null) {
            $cache = is_file($cacheFile) ? include $cacheFile : [];
        }

        $keyword = trim(mb_strtolower($keyword));
        if (isset($cache[$keyword])) {
            return $cache[$keyword];
        }

        // Генерация новых форм
        $forms = $this->getWordForms($keyword);
		if (!$forms){
			return [];
		}
        $cache[$keyword] = $forms;

        // сохраняем кэш в файл
        file_put_contents($cacheFile, '<?php return ' . var_export($cache, true) . ';');

        return $forms;
    }
	
	private function getWordForms($keyword) {
		
		cmsCore::includeFile('system/controllers/seoline/libs/phpmorphy/vendor/autoload.php');
        $dictPath = cmsConfig::get('root_path') . 'system/controllers/seoline/libs/phpmorphy/vendor/phpmorphy/dicts';
        $morphy = new \cijic\phpMorphy\Morphy('ru', ['storage' => PHPMORPHY_STORAGE_FILE], $dictPath);

        // Разбиваем фразу на слова
		$parts = preg_split('/\s+/u', trim($keyword));

		// Если фраза из одного слова — стандартный путь
		if (count($parts) === 1) {

			$word = $parts[0];
			if (!preg_match('/[А-Яа-яЁё]/u', $word)) {
				return [$word];
			}

			$forms = $morphy->getAllForms(mb_strtoupper($word, 'UTF-8'));
			foreach ($forms as $key => $word){
				$forms[$key] = mb_strtolower($word ?? $word, 'UTF-8');
			}
			return $forms && is_array($forms) ? $forms : [$word];

		}

		return [];
	
    }
	
	public function getTopWords($text, $limit = 5){
		// Загружаем список стоп-слов
		$stopwords = array_flip(string_get_stopwords(cmsCore::getLanguageName()));

		// Удаляем HTML и приводим текст к нижнему регистру
		$cleanText = mb_strtolower(strip_tags($text), 'UTF-8');

		// Удаляем пунктуацию и лишние символы
		$cleanText = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $cleanText);

		// Разбиваем текст на слова
		$words = preg_split('/\s+/u', $cleanText, -1, PREG_SPLIT_NO_EMPTY);

		// Фильтруем стоп-слова и короткие слова
		$filtered = array_filter($words, function($word) use ($stopwords) {
			return !isset($stopwords[$word]);
		});

		// Считаем частоту
		$frequency = array_count_values($filtered);

		// Сортируем по убыванию частоты
		arsort($frequency);

		// Возвращаем только нужное количество
		return array_slice(array_keys($frequency), 0, $limit);
	}

	
	public function initLog($clear = false){

		$cache = cmsConfig::get('root_path') . "/system/controllers/seoline/log.yml";
		if ($clear){
			@unlink($cache);
			return [$cache, []];
		}

		if(file_exists($cache)){
			$file_creation_date = filectime($cache);
			$date = date("ymd");
			if (date('ymd', $file_creation_date) == date("ymd")){
				$result = @file_get_contents($cache);
				$logs = cmsModel::yamlToArray($result);
				return [$cache, $logs];
			} else {
				@unlink($cache);
			}
		} else {
			@file_put_contents($cache, '');
		}
		
		return [$cache, []];
		
	}
	
	public function saveLog($log = []){
		
		if (!empty($log[0])){ $log[0]['time'] = date('H:i:s'); }
		
		list($cache, $logs) = $this->initLog();
		if ($logs){
			$log = array_merge($logs, $log);
		}
		
		if(is_writable(dirname($cache))){
			@file_put_contents($cache, cmsModel::arrayToYaml($log));
		}
		
		return true;
		
	}

}
