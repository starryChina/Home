<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');

// 获取请求参数，确定返回阶段
$stage = $_GET['stage'] ?? 'ip'; // ip, ip9, weather

// 获取用户真实IP地址
function getRealIP() {
    $ip = '';
    
    // 优先获取HTTP_X_FORWARDED_FOR（代理服务器转发）
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        // 处理多个IP的情况（如：client, proxy1, proxy2）
        $ipList = explode(',', $ip);
        $ip = trim($ipList[0]);
    } 
    // 其次获取HTTP_CLIENT_IP
    elseif (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    }
    // 最后获取REMOTE_ADDR
    elseif (!empty($_SERVER['REMOTE_ADDR'])) {
        $ip = $_SERVER['REMOTE_ADDR'];
    }
    
    // 验证IP地址格式
    if (filter_var($ip, FILTER_VALIDATE_IP)) {
        return $ip;
    } else {
        return '';
    }
}

// 使用IP9 API获取IP详细信息
function getIPInfoFromIP9($ip) {
    if (empty($ip)) {
        return null;
    }
    
    $url = "https://ip9.com.cn/get?ip=" . $ip;
    
    // 使用cURL获取数据
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200 && !empty($response)) {
        $data = json_decode($response, true);
        if ($data && isset($data['ret']) && $data['ret'] === 200 && isset($data['data'])) {
            return $data['data'];
        }
    }
    
    return null;
}

// 获取经纬度信息（通过IP9 API）
function getCoordinatesFromIP9($ipInfo) {
    if (!$ipInfo) return null;
    
    // IP9 API返回的经纬度信息（注意：IP9使用'lng'而不是'lon'）
    $lat = $ipInfo['lat'] ?? null;
    $lon = $ipInfo['lng'] ?? null; // 修正：使用'lng'而不是'lon'
    
    // 如果IP9没有返回经纬度，使用默认值（北京）
    if (!$lat || !$lon) {
        return ['lat' => 39.9042, 'lon' => 116.4074]; // 北京
    }
    
    return ['lat' => $lat, 'lon' => $lon];
}

// 使用open-meteo API获取天气信息
function getWeatherFromOpenMeteo($lat, $lon) {
    $url = "https://api.open-meteo.com/v1/forecast?latitude={$lat}&longitude={$lon}&current_weather=true&timezone=Asia%2FShanghai";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200 && !empty($response)) {
        $data = json_decode($response, true);
        if ($data && isset($data['current_weather'])) {
            return $data['current_weather'];
        }
    }
    
    return null;
}

// 天气代码映射
function getWeatherDescription($weatherCode) {
    $weatherMap = [
        0 => "晴朗", 1 => "多云", 2 => "阴天", 3 => "多云",
        45 => "有雾", 48 => "有雾",
        51 => "小雨", 53 => "中雨", 55 => "大雨",
        61 => "小雨", 63 => "中雨", 65 => "大雨",
        71 => "小雪", 73 => "中雪", 75 => "大雪",
        95 => "雷雨", 99 => "强雷雨"
    ];
    
    return $weatherMap[$weatherCode] ?? '未知';
}

// 根据阶段返回不同的数据
switch ($stage) {
    case 'ip':
        // 第一阶段：只返回IP地址
        $userIP = getRealIP();
        $response = array(
            'ip' => $userIP,
            'timestamp' => date('Y-m-d H:i:s'),
            'success' => !empty($userIP),
            'stage' => 'ip'
        );
        break;
        
    case 'ip9':
        // 第二阶段：返回IP9 API信息
        $userIP = getRealIP();
        $ipInfo = null;
        if (!empty($userIP)) {
            $ipInfo = getIPInfoFromIP9($userIP);
        }
        
        $response = array(
            'ip' => $userIP,
            'timestamp' => date('Y-m-d H:i:s'),
            'success' => !empty($userIP),
            'stage' => 'ip9'
        );
        
        if ($ipInfo) {
            // 运营商信息
            $response['isp'] = $ipInfo['isp'] ?? '';
            
            // 拼装地区信息：使用省份、城市、区域
            $locationParts = [];
            if (!empty($ipInfo['prov'])) {
                $locationParts[] = $ipInfo['prov'];
            }
            if (!empty($ipInfo['city'])) {
                $locationParts[] = $ipInfo['city'];
            }
            if (!empty($ipInfo['area'])) {
                $locationParts[] = $ipInfo['area'];
            }
            
            if (!empty($locationParts)) {
                $response['location'] = implode(' · ', $locationParts);
            } else {
                $response['location'] = $ipInfo['country'] ?? '';
            }
            
            // 保存经纬度信息用于下一阶段
            $response['coordinates'] = getCoordinatesFromIP9($ipInfo);
        }
        break;
        
    case 'weather':
        // 第三阶段：返回天气信息
        $userIP = getRealIP();
        $ipInfo = null;
        if (!empty($userIP)) {
            $ipInfo = getIPInfoFromIP9($userIP);
        }
        
        $coordinates = null;
        if ($ipInfo) {
            $coordinates = getCoordinatesFromIP9($ipInfo);
        }
        
        $weatherInfo = null;
        if ($coordinates) {
            $weatherInfo = getWeatherFromOpenMeteo($coordinates['lat'], $coordinates['lon']);
        }
        
        $response = array(
            'ip' => $userIP,
            'timestamp' => date('Y-m-d H:i:s'),
            'success' => !empty($userIP),
            'stage' => 'weather'
        );
        
        if ($weatherInfo) {
            $response['weather'] = array(
                'temperature' => round($weatherInfo['temperature'] ?? 0) . '°C',
                'windspeed' => round($weatherInfo['windspeed'] ?? 0) . 'm/s',
                'description' => getWeatherDescription($weatherInfo['weathercode'] ?? 0)
            );
        }
        break;
        
    default:
        // 默认返回IP信息
        $userIP = getRealIP();
        $response = array(
            'ip' => $userIP,
            'timestamp' => date('Y-m-d H:i:s'),
            'success' => !empty($userIP),
            'stage' => 'ip'
        );
        break;
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
?>