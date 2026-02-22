<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require 'db_connect.php';

$user_id = $_POST['username'] ?? '';
$user_pw = $_POST['password'] ?? '';

$stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if ($user && password_verify($user_pw, $user['password'])) {
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['nickname'] = $user['nickname'];

    // 마지막 로그인 시간 업데이트
    $upd = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
    $upd->execute([$user['id']]);

    header("Location: index.php");
    exit();
} else {
    echo "<script>alert('아이디 또는 비밀번호가 틀립니다.'); history.back();</script>";
}
