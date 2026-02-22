<?php
require_once 'config.php';

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
