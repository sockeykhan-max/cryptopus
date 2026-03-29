<?php require_once __DIR__ . '/common.php'; ?>
<!DOCTYPE html>
<html lang="ko">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? h($pageTitle) : SITE_NAME; ?></title>
    <link rel="preconnect" href="https://cdn.jsdelivr.net">
    <link rel="stylesheet" as="style" crossorigin href="https://cdn.jsdelivr.net/npm/pretendard@1.3.9/dist/web/static/pretendard.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
</head>

<body>
    <nav class="top-nav">
        <div class="nav-container">
            <div class="logo-section mobile-top">
                <a href="index.php" style="letter-spacing: 1.5px;">CRYP<span style="color: #D29595;">TO</span><span style="color: #A52A2A;">PUS</span></a>
                <div class="mobile-logout-button">
                    <a href="logout.php" class="logout-btn">로그아웃</a>
                </div>

            </div>

            <div class="user-section">
                <!--<span class="user-nickname"><?php echo h($_SESSION['nickname'] ?? ''); ?></span>-->
                <a href="logout.php" class="logout-btn pc-logout-btn">로그아웃</a>
            </div>
        </div>
    </nav>
    <div class="main-wrap">