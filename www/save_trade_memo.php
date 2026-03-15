<?php
require_once dirname(__FILE__) . '/common.php';

header('Content-Type: application/json');

if (!check_login(false)) {
    echo json_encode(['success' => false, 'message' => '로그인이 필요합니다.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => '잘못된 요청입니다.']);
    exit;
}

$uuid = $_POST['uuid'] ?? '';
$memo = $_POST['memo'] ?? null;
$tradingview_url = $_POST['tradingview_url'] ?? null;
$strategy_name = $_POST['strategy_name'] ?? null;
$entry_reason = $_POST['entry_reason'] ?? null;
$psychology_state = $_POST['psychology_state'] ?? null;
$failure_factor = $_POST['failure_factor'] ?? null;

if (empty($uuid)) {
    echo json_encode(['success' => false, 'message' => '유효하지 않은 주문 ID입니다.']);
    exit;
}

try {
    $update_fields = [];
    $params = [':uuid' => $uuid];

    if ($memo !== null) {
        $update_fields[] = "memo = :memo";
        $params[':memo'] = $memo;
    }
    if ($tradingview_url !== null) {
        $update_fields[] = "tradingview_url = :tradingview_url";
        $params[':tradingview_url'] = $tradingview_url;
    }
    if ($strategy_name !== null) {
        $update_fields[] = "strategy_name = :strategy_name";
        $params[':strategy_name'] = $strategy_name;
    }
    if ($entry_reason !== null) {
        $update_fields[] = "entry_reason = :entry_reason";
        $params[':entry_reason'] = $entry_reason;
    }
    if ($psychology_state !== null) {
        $update_fields[] = "psychology_state = :psychology_state";
        $params[':psychology_state'] = $psychology_state;
    }
    if ($failure_factor !== null) {
        $update_fields[] = "failure_factor = :failure_factor";
        $params[':failure_factor'] = $failure_factor;
    }

    if (!empty($update_fields)) {
        $sql = "UPDATE upbit_orders SET " . implode(', ', $update_fields) . " WHERE uuid = :uuid";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }

    echo json_encode(['success' => true, 'message' => '저장되었습니다.']);
} catch (PDOException $e) {
    error_log("Memo save error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => '데이터베이스 저장 중 오류가 발생했습니다.']);
}
