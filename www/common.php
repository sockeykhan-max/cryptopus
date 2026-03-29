<?php
require_once __DIR__ . '/config.php';

// 세션 시작 (이미 시작되어 있는지 체크)
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// 로그인 체크 함수
function check_login($redirect = true)
{
    if (!isset($_SESSION['user_id'])) {
        if ($redirect) {
            header("Location: login.php");
            exit();
        } else {
            return false;
        }
    }
    return true;
}

// 수익률 계산 함수
function get_yield($entry, $exit, $qty)
{
    if ($entry <= 0) return 0;
    $buy_total = ($entry * $qty) * (1 + UPBIT_FEE);
    $sell_total = ($exit * $qty) * (1 - UPBIT_FEE);
    return (($sell_total - $buy_total) / $buy_total) * 100;
}

// 숫자 포맷 (코인 가격 등)
function format_num($num, $decimal = 0)
{
    return number_format($num, $decimal);
}

// XSS 방지 출력 함수
function h($str)
{
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

// 유튜브 영상 ID 추출 함수
function get_youtube_id($url)
{
    $parts = parse_url($url);
    if (isset($parts['query'])) {
        parse_str($parts['query'], $query);
        if (isset($query['v'])) {
            return $query['v'];
        }
    }
    if (isset($parts['path'])) {
        $path_parts = explode('/', rtrim($parts['path'], '/'));
        if (in_array('embed', $path_parts) || in_array('v', $path_parts)) {
            return end($path_parts);
        }
    } else if (isset($parts['host']) && ($parts['host'] == 'youtu.be' || $parts['host'] == 'www.youtu.be')) {
        return ltrim($parts['path'], '/');
    }
    return false;
}
// 업비트 API 거래 내역 조회 함수
function call_upbit_api($url, $params = [], $access_key, $secret_key)
{
    $query_string = http_build_query($params);

    // JWT 토큰 생성
    $payload = [
        'access_key' => $access_key,
        'nonce' => bin2hex(random_bytes(16)),
    ];

    if ($query_string) {
        $payload['query_hash'] = hash('sha512', $query_string);
        $payload['query_hash_alg'] = 'SHA512';
    }

    $header = json_encode(['alg' => 'HS256', 'typ' => 'JWT']);
    $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
    $base64UrlPayload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode(json_encode($payload)));
    $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $secret_key, true);
    $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
    $token = $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;

    $full_url = $query_string ? $url . "?" . $query_string : $url;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $full_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $token"]);
    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true);
}

class OkxApi
{
    private $apiKey;
    private $secretKey;
    private $passphrase;
    private $baseUrl = "https://www.okx.com";

    public function __construct($key, $secret, $pass)
    {
        $this->apiKey = $key;
        $this->secretKey = $secret;
        $this->passphrase = $pass;
    }

    private function getSignature($timestamp, $method, $requestPath, $body = "")
    {
        $message = $timestamp . $method . $requestPath . $body;
        return base64_encode(hash_hmac('sha256', $message, $this->secretKey, true));
    }

    public function request($method, $path, $params = [])
    {
        $timestamp = gmdate('Y-m-d\TH:i:s.v\Z');
        $queryString = ($method === 'GET' && !empty($params)) ? '?' . http_build_query($params) : '';
        $fullPath = "/api/v5" . $path . $queryString;

        $ch = curl_init($this->baseUrl . $fullPath);
        $headers = [
            "OK-ACCESS-KEY: " . $this->apiKey,
            "OK-ACCESS-SIGN: " . $this->getSignature($timestamp, $method, $fullPath),
            "OK-ACCESS-TIMESTAMP: " . $timestamp,
            "OK-ACCESS-PASSPHRASE: " . $this->passphrase,
            "Content-Type: application/json"
        ];

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response, true);
    }
}
