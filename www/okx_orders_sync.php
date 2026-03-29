<?php
require_once __DIR__ . '/common.php';
header('Content-Type: application/json; charset=utf-8');

ini_set('display_errors', 0); // Notice 등의 에러 메시지가 JSON 응답을 깨뜨리지 않도록 방지

// API 정보
$apiKey = '242f8685-deb4-448d-940c-d5e0d32be456';
$secretKey = '83684101C963C8FC0AAEB5EDCC8A4D9A';
$passphrase = 'Khan160406@';

$okx = new OkxApi($apiKey, $secretKey, $passphrase);

$fillCount = 0;
$billCount = 0;
$errors = [];

try {
    // 선물(SWAP) 및 현물(SPOT) 거래 모두 순회
    $instTypes = ['SWAP', 'SPOT'];

    // [추가] 레버리지 매핑을 위한 주문 내역(Orders) 수집
    $leverageMap = [];
    $orderEndpoints = ['/trade/orders-history', '/trade/orders-history-archive'];

    foreach ($instTypes as $instType) {
        foreach ($orderEndpoints as $endpoint) {
            $afterOrd = '';
            $pageCount = 0;

            while ($pageCount < 20) {
                $pageCount++;
                $params = ['instType' => $instType, 'limit' => '100'];
                if ($afterOrd !== '') $params['after'] = $afterOrd;

                $res = $okx->request('GET', $endpoint, $params);
                if (isset($res['code']) && $res['code'] === '0' && !empty($res['data'])) {
                    foreach ($res['data'] as $ord) {
                        if (isset($ord['ordId']) && isset($ord['lever']) && $ord['lever'] !== '') {
                            $leverageMap[$ord['ordId']] = $ord['lever'];
                        }
                    }
                    $lastOrd = end($res['data']);
                    $afterOrd = $lastOrd['ordId'] ?? '';
                    if (count($res['data']) < 100) break;
                } else {
                    break;
                }
                usleep(100000); // Rate limit 방지
            }
        }
    }

    // 1. 체결 내역 (Fills) 수집
    $stmtFill = $pdo->prepare("
        INSERT IGNORE INTO okx_trade_fills
        (fill_id, ord_id, inst_id, side, pos_side, fill_px, fill_sz, fee, fee_ccy, realized_pnl, ts, lever, ct_val)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    // 최근 7일(fills-history) 및 3개월(fills-history-archive) 내역을 모두 수집
    $fillEndpoints = ['/trade/fills-history', '/trade/fills-history-archive'];
    $allFills = [];

    foreach ($instTypes as $instType) {
        foreach ($fillEndpoints as $endpoint) {
            $afterFill = '';
            $pageCount = 0;

            while ($pageCount < 20) { // 최대 20페이지(2000건) 제한으로 무한루프 방지
                $pageCount++;
                $fillsParams = ['instType' => $instType, 'limit' => '100'];
                if ($afterFill !== '') {
                    $fillsParams['after'] = $afterFill;
                }

                $fillsResult = $okx->request('GET', $endpoint, $fillsParams);

                if (isset($fillsResult['code']) && $fillsResult['code'] === '0' && !empty($fillsResult['data'])) {
                    $allFills = array_merge($allFills, $fillsResult['data']);
                    $lastFill = end($fillsResult['data']);
                    $afterFill = $lastFill['billId'] ?? ($lastFill['tradeId'] ?? '');

                    if (count($fillsResult['data']) < 100) {
                        break; // 더 이상 가져올 과거 데이터 없음
                    }
                } else {
                    if (isset($fillsResult['msg']) && !empty($fillsResult['msg'])) {
                        $errors[] = "Fills API Error ($instType, $endpoint): " . $fillsResult['msg'];
                    }
                    break; // 오류 발생 시 종료
                }
                usleep(100000); // API Rate limit 방지 (0.1초 대기)
            }
        }
    }

    // [추가] SWAP 상품들의 ctVal(계약 가치) 정보 수집
    $ctValMap = [];
    $instsResult = $okx->request('GET', '/public/instruments', ['instType' => 'SWAP']);
    if (isset($instsResult['code']) && $instsResult['code'] === '0' && !empty($instsResult['data'])) {
        foreach ($instsResult['data'] as $inst) {
            $ctValMap[$inst['instId']] = $inst['ctVal'];
        }
    }

    // 오래된 순으로 저장하기 위해 배열을 뒤집음
    $allFills = array_reverse($allFills);

    foreach ($allFills as $fill) {
        try {
            // OKX Fills API 응답에는 fillId 필드가 없으며 billId 또는 tradeId가 식별자 역할을 함
            $billId = $fill['billId'] ?? ($fill['tradeId'] ?? '');
            $ordId = $fill['ordId'] ?? '';
            $instId = $fill['instId'] ?? '';
            $side = $fill['side'] ?? '';
            $posSide = $fill['posSide'] ?? '';
            $fillPx = (float)($fill['fillPx'] ?? 0);
            $fillSz = (float)($fill['fillSz'] ?? 0);
            $fee = abs((float)($fill['fee'] ?? 0)); // 수수료는 항상 양수로 저장
            $feeCcy = $fill['feeCcy'] ?? '';
            // realized_pnl은 응답에 fillPnl 또는 pnl 필드가 있으면 사용, 없으면 0 처리
            $realizedPnl = isset($fill['fillPnl']) && $fill['fillPnl'] !== '' ? (float)$fill['fillPnl'] : (isset($fill['pnl']) && $fill['pnl'] !== '' ? (float)$fill['pnl'] : 0);
            $ts = (string)($fill['ts'] ?? '0');

            // [추가] 매핑해둔 레버리지 값 가져오기 (매핑되지 않았다면 기본값 '1')
            $lever = $leverageMap[$ordId] ?? '1';

            // [추가] 상품의 ctVal 가져오기 (없으면 기본값 1, SPOT 등)
            $ctVal = (float)($ctValMap[$instId] ?? 1);

            $stmtFill->execute([$billId, $ordId, $instId, $side, $posSide, $fillPx, $fillSz, $fee, $feeCcy, $realizedPnl, $ts, $lever, $ctVal]);

            if ($stmtFill->rowCount() > 0) {
                $fillCount++;
            }
        } catch (PDOException $e) {
            // 한 건에서 에러가 발생해도 중단되지 않도록 무시하고 계속 진행
            $errors[] = "Fills DB Error: " . $e->getMessage();
        }
    }

    // 2. 계정 자금 흐름 및 펀딩비 내역 (Bills) 수집
    $stmtBill = $pdo->prepare("
        INSERT INTO okx_account_bills 
        (bill_id, inst_id, type_id, amount, bal_ccy, ts) 
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE amount = VALUES(amount)
    ");

    // 최근 거래 내역은 bills(7일 이내), 과거 내역은 bills-archive(최대 3개월)에 존재하므로 모두 호출
    $billEndpoints = ['/account/bills', '/account/bills-archive'];
    $allBills = [];

    foreach ($billEndpoints as $endpoint) {
        $afterBill = '';
        $pageCount = 0;

        while ($pageCount < 20) {
            $pageCount++;
            $billsParams = ['limit' => '50'];
            if ($afterBill !== '') {
                $billsParams['after'] = $afterBill;
            }

            $billsResult = $okx->request('GET', $endpoint, $billsParams);

            if (isset($billsResult['code']) && $billsResult['code'] === '0' && !empty($billsResult['data'])) {
                $allBills = array_merge($allBills, $billsResult['data']);
                $lastBill = end($billsResult['data']);
                $afterBill = $lastBill['billId'];

                if (count($billsResult['data']) < 50) {
                    break;
                }
            } else {
                break;
            }
            usleep(100000);
        }
    }

    // 오래된 순으로 저장하기 위해 배열을 뒤집음
    $allBills = array_reverse($allBills);

    foreach ($allBills as $bill) {
        try {
            // OKX API의 잔고 증감은 balChg, 또는 pnl로 제공됨
            // balChg가 '0'으로 내려오는 경우 pnl 필드의 값을 사용하도록 수정
            $balChg = isset($bill['balChg']) && $bill['balChg'] !== '' ? (float)$bill['balChg'] : 0;
            $pnl = isset($bill['pnl']) && $bill['pnl'] !== '' ? (float)$bill['pnl'] : 0;
            $amount = ($balChg != 0) ? $balChg : $pnl;

            $stmtBill->execute([$bill['billId'] ?? '', $bill['instId'] ?? '', (int)($bill['type'] ?? 0), $amount, $bill['ccy'] ?? '', (string)($bill['ts'] ?? '0')]);

            if ($stmtBill->rowCount() > 0) {
                $billCount++;
            }
        } catch (PDOException $e) {
            $errors[] = "Bills DB Error: " . $e->getMessage();
        }
    }

    echo json_encode([
        'success' => true,
        'count'   => $fillCount + $billCount,
        'message' => "동기화 완료: 체결 내역 {$fillCount}건, 자금 내역 {$billCount}건" . (!empty($errors) ? " (경고: " . count($errors) . "건의 오류 발생)" : ""),
        'errors'  => $errors
    ]);
} catch (Exception $e) {
    error_log("OKX sync error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => '데이터 동기화 중 오류가 발생했습니다. (' . $e->getMessage() . ')']);
}
