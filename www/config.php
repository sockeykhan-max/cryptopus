<?php
// DB 접속 정보 (db_connect.php에서 사용하므로 여기서는 변수만 정의하거나 로드)
require_once 'db_connect.php';

// 사이트 정보
define('SITE_NAME', 'Cryptopus');
define('SITE_URL', 'http://cryptopus.mycafe24.com');

// 매매 관련 설정
define('UPBIT_FEE', 0.0005); // 수수료 0.05%

// 시간대 설정
date_default_timezone_set('Asia/Seoul');
