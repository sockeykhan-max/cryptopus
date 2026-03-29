<?php require_once __DIR__ . '/common.php'; ?>
<?php
// [설정] PHP 7.4 환경 및 한국 시간 기준
date_default_timezone_set('Asia/Seoul');
header('Content-Type: application/json; charset=utf-8');

// API 정보 (본인의 키로 교체)
$apiKey = '242f8685-deb4-448d-940c-d5e0d32be456';
$secretKey = '83684101C963C8FC0AAEB5EDCC8A4D9A';
$passphrase = 'Khan160406@';

function getOKXOrders($key, $secret, $pwd)
{
    $method = "GET";
    $path = "/api/v5/trade/orders-history?instType=SWAP"; // 최근 7일 선물(SWAP) 내역
    $timestamp = gmdate('Y-m-d\TH:i:s.v\Z'); // UTC 기준 ISO 8601

    // Signature 생성 (Timestamp + Method + Path)
    $signStr = $timestamp . $method . $path;
    $signature = base64_encode(hash_hmac('sha256', $signStr, $secret, true));

    $headers = [
        "OK-ACCESS-KEY: " . $key,
        "OK-ACCESS-SIGN: " . $signature,
        "OK-ACCESS-TIMESTAMP: " . $timestamp,
        "OK-ACCESS-PASSPHRASE: " . $pwd,
        "Content-Type: application/json"
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://www.okx.com" . $path);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true);
}

$result = getOKXOrders($apiKey, $secretKey, $passphrase);

if ($result && $result['code'] === '0') {
    $newCount = 0;
    foreach ($result['data'] as $row) {
        // 데이터 정제
        $ordId = $row['ordId'];
        $instId = $row['instId'];
        $side = $row['side'];
        $fillPx = (float)$row['avgPx'];
        $sz = (float)$row['accFillSz'];
        $fee = abs((float)$row['fee']); // 수수료 음수 -> 양수 변환
        $feeCcy = $row['feeCcy'];
        $state = $row['state'];
        $uTime = $row['uTime'];

        // USDT 기준 총 거래액 계산 (수익이 아님)
        $amt = $fillPx * $sz;

        // KST 시간 변환 (ms / 1000)
        $tradeDate = date("Y-m-d H:i:s", ($uTime / 1000));

        // INSERT IGNORE로 중복 데이터 방지
        $sql = "INSERT IGNORE INTO okx_trades 
                (ordId, instId, side, fillPx, sz, fee, feeCcy, trade_date, uTime, state, amt) 
                VALUES 
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $pdo->prepare($sql);
        if ($stmt->execute([
            $ordId,
            $instId,
            $side,
            $fillPx,
            $sz,
            $fee,
            $feeCcy,
            $tradeDate,
            $uTime,
            $state,
            $amt
        ])) {
            if ($stmt->rowCount() > 0) $newCount++;
        }
    }
    echo json_encode(['success' => true, 'count' => $newCount]);
} else {
    echo json_encode(['success' => false, 'message' => $result['msg'] ?? '연결 실패']);
}
?>