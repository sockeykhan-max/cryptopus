<?php
require_once dirname(__FILE__) . '/config.php';
require_once dirname(__FILE__) . '/common.php';
require_once dirname(__FILE__) . '/db_connect.php';

check_login();

header('Content-Type: application/json');

$response = ['success' => false, 'message' => '알 수 없는 오류가 발생했습니다.'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_SESSION['user_id'])) {
        $userId = $_SESSION['user_id'];
        error_log("SESSION user_id: " . print_r($_SESSION['user_id'], true));
        error_log("UserId for validation: " . $userId);

        // user_id 유효성 검증
        $userCheckStmt = $pdo->prepare("SELECT id FROM users WHERE id = :user_id");
        $userCheckStmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $userCheckStmt->execute();
        $existingUser = $userCheckStmt->fetch(PDO::FETCH_ASSOC);
        error_log("Existing user check result: " . print_r($existingUser, true));

        if (!$existingUser) {
            $response = ['success' => false, 'message' => '유효하지 않은 사용자 정보입니다. 다시 로그인 해주세요.'];
            echo json_encode($response);
            exit; // 더 이상 진행하지 않고 종료
        }
        $wikiId = $_POST['wiki_id'] ?? null; // wiki_id 추가
        $title = $_POST['title'] ?? '';
        $content = $_POST['content'] ?? '';
        $tvImageUrl = $_POST['tv_image_url'] ?? null;
        $youtubeUrl = $_POST['youtube_url'] ?? null;
        $tags = $_POST['tags'] ?? null; // 태그 필드는 현재 폼에 없지만, 테이블 스키마에 있으므로 추가

        if (empty($title) || empty($content)) {
            $response = ['success' => false, 'message' => '제목과 내용을 입력해주세요.'];
        } else {
            try {
                if ($wikiId) {
                    // 수정
                    $stmt = $pdo->prepare("UPDATE strategy_wiki SET title = :title, content = :content, tv_image_url = :tv_image_url, youtube_url = :youtube_url, tags = :tags WHERE id = :wiki_id AND user_id = :user_id");
                    $stmt->bindParam(':wiki_id', $wikiId, PDO::PARAM_INT);
                    $stmt->bindParam(':title', $title, PDO::PARAM_STR);
                    $stmt->bindParam(':content', $content, PDO::PARAM_STR);
                    $stmt->bindParam(':tv_image_url', $tvImageUrl, PDO::PARAM_STR);
                    $stmt->bindParam(':youtube_url', $youtubeUrl, PDO::PARAM_STR);
                    $stmt->bindParam(':tags', $tags, PDO::PARAM_STR);
                    $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);

                    if ($stmt->execute()) {
                        $response = ['success' => true, 'message' => '전략 위키가 성공적으로 수정되었습니다.'];
                    } else {
                        $response = ['success' => false, 'message' => '위키 수정에 실패했습니다.'];
                    }
                } else {
                    // 신규 작성
                    $stmt = $pdo->prepare("INSERT INTO strategy_wiki (user_id, title, content, tv_image_url, youtube_url, tags) VALUES (:user_id, :title, :content, :tv_image_url, :youtube_url, :tags)");
                    $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
                    $stmt->bindParam(':title', $title, PDO::PARAM_STR);
                    $stmt->bindParam(':content', $content, PDO::PARAM_STR);
                    $stmt->bindParam(':tv_image_url', $tvImageUrl, PDO::PARAM_STR);
                    $stmt->bindParam(':youtube_url', $youtubeUrl, PDO::PARAM_STR);
                    $stmt->bindParam(':tags', $tags, PDO::PARAM_STR);

                    if ($stmt->execute()) {
                        $response = ['success' => true, 'message' => '전략 위키가 성공적으로 저장되었습니다.'];
                    } else {
                        $response = ['success' => false, 'message' => '데이터베이스 저장에 실패했습니다.'];
                    }
                }
            } catch (PDOException $e) {
                error_log("Wiki save error: " . $e->getMessage());
                $response = ['success' => false, 'message' => '데이터베이스 오류: ' . $e->getMessage()];
            }
        }
    } else {
        $response = ['success' => false, 'message' => '로그인 정보가 없습니다.'];
    }
} else {
    $response = ['success' => false, 'message' => '잘못된 요청 방식입니다.'];
}

echo json_encode($response);
