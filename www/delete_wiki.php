<?php
error_reporting(E_ALL);
ini_set("display_errors", 1);

require_once dirname(__FILE__) . "/db_connect.php";
require_once dirname(__FILE__) . "/common.php";

header("Content-Type: application/json");

$response = ["success" => false, "message" => ""];

try {
    if (!check_login(false)) {
        $response["message"] = "로그인이 필요합니다.";
        // No need for echo json_encode($response); exit; here because finally block will handle it
    } else if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["wiki_id"])) {
        $wikiId = filter_input(INPUT_POST, "wiki_id", FILTER_VALIDATE_INT);

        if ($wikiId === false || $wikiId === null) {
            $response["message"] = "유효하지 않은 위키 ID입니다.";
        } else {
            // 위키 소유자 확인 (선택 사항, 필요시 구현)
            // $stmt = $pdo->prepare("SELECT user_id FROM strategy_wiki WHERE id = :id");
            // $stmt->bindParam(":id", $wikiId, PDO::PARAM_INT);
            // $stmt->execute();
            // $ownerId = $stmt->fetchColumn();

            // if ($ownerId !== $_SESSION["user_id"]) {
            //     $response["message"] = "삭제 권한이 없습니다.";
            // } else {
            $stmt = $pdo->prepare("DELETE FROM strategy_wiki WHERE id = :id");
            $stmt->bindParam(":id", $wikiId, PDO::PARAM_INT);

            if ($stmt->execute()) {
                if ($stmt->rowCount() > 0) {
                    $response["success"] = true;
                    $response["message"] = "위키가 성공적으로 삭제되었습니다.";
                } else {
                    $response["message"] = "해당 위키를 찾을 수 없거나 이미 삭제되었습니다.";
                }
            } else {
                $response["message"] = "위키 삭제 중 오류가 발생했습니다.";
            }
            // } // End of owner check
        }
    } else {
        $response["message"] = "잘못된 요청입니다.";
    }
} catch (PDOException $e) {
    error_log("Wiki deletion database error: " . $e->getMessage());
    $response["message"] = "데이터베이스 오류가 발생했습니다.";
} catch (Exception $e) {
    error_log("Wiki deletion general error: " . $e->getMessage());
    $response["message"] = "서버 내부 오류가 발생했습니다.";
} finally {
    echo json_encode($response);
}
