<?php
session_start();

// 세션에 user_id가 없으면 로그인하지 않은 상태로 간주
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
