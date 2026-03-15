<?php require_once __DIR__ . '/common.php'; ?>
<?php
header('Content-Type: application/json');

$upbit_access_key = "zD8Esci5MP93QAL3O44EXoAQ4JcPgxTR08qHjxC9";
$upbit_secret_key = "S8XOMWkUXHn4MyfRhaohwHTgMZtHYaC92XIeK99z";

$orders = call_upbit_api('https://api.upbit.com/v1/orders', ['state' => 'done', 'limit' => 100], $upbit_access_key, $upbit_secret_key);

if (isset($orders['error'])) {
    echo json_encode(['success' => false, 'message' => $orders['error']['message']]);
    exit;
}

if (!is_array($orders)) {
    echo json_encode(['success' => false, 'message' => '업비트 API 호출에 실패했습니다.']);
    exit;
}

$count = 0;
foreach ($orders as $o) {
    if (!isset($o['uuid'])) continue;

    $d = call_upbit_api('https://api.upbit.com/v1/order', ['uuid' => $o['uuid']], $upbit_access_key, $upbit_secret_key);

    if (isset($d['error'])) continue;

    // 평균가 계산 로직 (trades 배열의 체결 내역 기반)
    $avg_price = 0;
    if (!empty($d['trades'])) {
        $total_funds = 0;
        $total_volume = 0;
        foreach ($d['trades'] as $trade) {
            $total_funds += $trade['funds'];
            $total_volume += $trade['volume'];
        }
        if ($total_volume > 0) {
            $avg_price = $total_funds / $total_volume;
        }
    } else {
        $avg_price = $d['price'] ?? 0;
    }

    $executed_volume = $d['executed_volume'] ?? 0;
    $paid_fee = $d['paid_fee'] ?? 0;
    $created_at = isset($d['created_at']) ? date('Y-m-d H:i:s', strtotime($d['created_at'])) : date('Y-m-d H:i:s');

    $settle_amount = 0;
    if ($d['side'] === 'bid') {
        $settle_amount = ($avg_price * $executed_volume) + $paid_fee;
    } elseif ($d['side'] === 'ask') {
        $settle_amount = ($avg_price * $executed_volume) - $paid_fee;
    }

    $sql = "INSERT INTO upbit_orders (uuid, market, side, ord_type, avg_price, executed_volume, paid_fee, created_at, settle_amount)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE avg_price=VALUES(avg_price), paid_fee=VALUES(paid_fee), settle_amount=VALUES(settle_amount)";

    $stmt = $pdo->prepare($sql);
    if ($stmt->execute([
        $d['uuid'],
        $d['market'],
        $d['side'],
        $d['ord_type'],
        $avg_price,
        $executed_volume,
        $paid_fee,
        $created_at,
        $settle_amount
    ])) {
        $count++;
    }
}
echo json_encode(['success' => true, 'count' => $count]);
