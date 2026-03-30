<?php
require_once dirname(__FILE__) . '/common.php';
check_login();

// 매매 원칙 POST 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_principle_order'])) {
        $orderData = json_decode($_POST['order_data'], true);
        if (is_array($orderData)) {
            $updateStmt = $pdo->prepare("UPDATE trading_principles SET principle_turn = ? WHERE id = ? AND user_id = ?");
            foreach ($orderData as $turn => $id) {
                $updateStmt->execute([$turn + 1, $id, $_SESSION['user_id']]);
            }
        }
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    } elseif (isset($_POST['add_principle'])) {
        $tagText = $_POST['tag_text'] ?? '';
        $tagClass = $_POST['tag_class'] ?? '';
        $principleText = $_POST['principle_text'] ?? '';
        if (!empty($principleText)) {
            $stmtMax = $pdo->prepare("SELECT MAX(principle_turn) FROM trading_principles WHERE user_id = ?");
            $stmtMax->execute([$_SESSION['user_id']]);
            $maxTurn = (int)$stmtMax->fetchColumn();
            $newTurn = $maxTurn + 1;

            $stmt = $pdo->prepare("INSERT INTO trading_principles (user_id, tag_text, tag_class, principle_text, principle_turn) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$_SESSION['user_id'], $tagText, $tagClass, $principleText, $newTurn]);
        }
        header("Location: index.php?show_principles=1");
        exit;
    } elseif (isset($_POST['update_principle'])) {
        $id = $_POST['id'];
        $tagText = $_POST['tag_text'] ?? '';
        $tagClass = $_POST['tag_class'] ?? '';
        $principleText = $_POST['principle_text'] ?? '';
        if (!empty($principleText)) {
            $stmt = $pdo->prepare("UPDATE trading_principles SET tag_text = ?, tag_class = ?, principle_text = ? WHERE id = ? AND user_id = ?");
            $stmt->execute([$tagText, $tagClass, $principleText, $id, $_SESSION['user_id']]);
        }
        header("Location: index.php?show_principles=1");
        exit;
    } elseif (isset($_POST['delete_principle'])) {
        $id = $_POST['id'];
        $stmt = $pdo->prepare("DELETE FROM trading_principles WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $_SESSION['user_id']]);
        header("Location: index.php?show_principles=1");
        exit;
    } elseif (isset($_POST['toggle_wiki_core'])) {
        $wiki_id = (int)$_POST['wiki_id'];
        $core_value = (int)$_POST['core_value'];
        $stmt = $pdo->prepare("UPDATE strategy_wiki SET core = ? WHERE id = ?");
        $stmt->execute([$core_value, $wiki_id]);
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    } elseif (isset($_POST['save_target_profit'])) {
        $targetMonth = $_POST['target_month'];
        $targetProfit = (float)$_POST['target_profit'];

        try {
            // 테이블이 없을 경우를 대비해 생성문 추가
            $pdo->exec("CREATE TABLE IF NOT EXISTS month_target (
                target_month VARCHAR(7) PRIMARY KEY,
                target_profit DECIMAL(20,2) NOT NULL DEFAULT 0
            )");

            $stmt = $pdo->prepare("INSERT INTO month_target (target_month, target_profit) VALUES (?, ?) ON DUPLICATE KEY UPDATE target_profit = ?");
            $stmt->execute([$targetMonth, $targetProfit, $targetProfit]);

            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
            exit;
        } catch (PDOException $e) {
            error_log("Save target profit error: " . $e->getMessage());
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'DB 에러']);
            exit;
        }
    }
}

require_once dirname(__FILE__) . '/header.php';

// 태그 문자열에 따라 일관된 색상을 반환하는 함수
function getTagColor($tagStr)
{
    $strUpper = strtoupper(trim($tagStr));
    // 자주 쓰는 키워드에 대한 고정 색상 매핑 (직관적인 버튼식 색상 제공)
    if (strpos($strUpper, 'STOP') !== false || strpos($strUpper, 'LOSS') !== false || strpos($strUpper, '손절') !== false) {
        return ['bg' => '#ffebee', 'text' => '#c62828', 'border' => '#ffcdd2'];
    }
    if (strpos($strUpper, 'VOL') !== false || strpos($strUpper, '거래량') !== false) {
        return ['bg' => '#e3f2fd', 'text' => '#1565c0', 'border' => '#bbdefb'];
    }
    if (strpos($strUpper, 'MIND') !== false || strpos($strUpper, '심리') !== false || strpos($strUpper, '멘탈') !== false) {
        return ['bg' => '#e8f5e9', 'text' => '#2e7d32', 'border' => '#c8e6c9'];
    }

    $colors = [
        ['bg' => '#eef2ff', 'text' => '#4f46e5', 'border' => '#c7d2fe'], // Indigo
        ['bg' => '#ecfdf5', 'text' => '#059669', 'border' => '#a7f3d0'], // Emerald
        ['bg' => '#fffbeb', 'text' => '#d97706', 'border' => '#fde68a'], // Amber
        ['bg' => '#fff1f2', 'text' => '#e11d48', 'border' => '#fecdd3'], // Rose
        ['bg' => '#f0fdfa', 'text' => '#0d9488', 'border' => '#ccfbf1'], // Teal
        ['bg' => '#fdf2f8', 'text' => '#db2777', 'border' => '#fbcfe8'], // Pink
        ['bg' => '#f5f3ff', 'text' => '#7c3aed', 'border' => '#ede9fe'], // Violet
        ['bg' => '#fff7ed', 'text' => '#ea580c', 'border' => '#ffedd5'], // Orange
    ];
    $hash = hexdec(substr(md5($tagStr), 0, 6));
    $index = $hash % count($colors);
    return $colors[$index];
}

// Upbit 매매 일지 FIFO 처리 로직 (거래 사이클 묶음)
$buy_queues = [];
$current_trade_group = [];
$all_upbit_trades = [];
$upbit_trade_results = [];
$list_total_profit = 0;
$list_total_buy_settle = 0;
$all_strategies_set = []; // 모든 전략명 수집
$all_entry_reasons_set = []; // 진입근거 수집
$all_psychology_states_set = []; // 심리상태 수집
$all_failure_factors_set = []; // 패착요인 수집
$seven_days_ago = date('Y-m-d H:i:s', strtotime('-7 days'));
$total_seven_days_profit = 0;
$total_seven_days_buy_settle = 0;
$one_month_ago = date('Y-m-d H:i:s', strtotime('-1 month'));
$total_one_month_profit = 0;
$total_one_month_buy_settle = 0;

$monthly_stats = []; // ['YYYY-MM' => ['profit' => 0, 'buy_settle' => 0]]
$total_profit = 0;
$total_buy_settle = 0;
$daily_profit_data = []; // 달력용 데이터 추가

try {
    $stmt = $pdo->prepare("SELECT * FROM upbit_orders ORDER BY created_at ASC");
    $stmt->execute();
    $all_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($all_orders as $order) {
        $market = $order['market'];
        $side = $order['side'];
        $volume = (float)$order['executed_volume'];
        $price = (float)$order['avg_price'];
        $settle_amount = (float)$order['settle_amount'];

        if (!empty($order['strategy_name'])) {
            $strats = explode(',', $order['strategy_name']);
            foreach ($strats as $s) {
                $s = trim($s);
                if ($s !== '') {
                    $all_strategies_set[$s] = true;
                }
            }
        }
        if (!empty($order['entry_reason'])) {
            $items = explode(',', $order['entry_reason']);
            foreach ($items as $item) {
                $item = trim($item);
                if ($item !== '') {
                    $all_entry_reasons_set[$item] = true;
                }
            }
        }
        if (!empty($order['psychology_state'])) {
            $items = explode(',', $order['psychology_state']);
            foreach ($items as $item) {
                $item = trim($item);
                if ($item !== '') {
                    $all_psychology_states_set[$item] = true;
                }
            }
        }
        if (!empty($order['failure_factor'])) {
            $items = explode(',', $order['failure_factor']);
            foreach ($items as $item) {
                $item = trim($item);
                if ($item !== '') {
                    $all_failure_factors_set[$item] = true;
                }
            }
        }

        if ($side === 'bid') {
            if (empty($buy_queues[$market])) {
                $buy_queues[$market] = [];
                // 큐가 비어있을 때 새로운 매수(bid)가 들어오면 새로운 거래 사이클 시작
                $current_trade_group[$market] = [
                    'uuid' => $order['uuid'],
                    'memo' => $order['memo'] ?? '',
                    'tradingview_url' => $order['tradingview_url'] ?? '',
                    'strategy_name' => $order['strategy_name'] ?? '',
                    'entry_reason' => $order['entry_reason'] ?? '',
                    'psychology_state' => $order['psychology_state'] ?? '',
                    'failure_factor' => $order['failure_factor'] ?? '',
                    'created_at' => $order['created_at'],
                    'market' => $market,
                    'profit' => 0,
                    'total_buy_settle' => 0,
                    'last_ask_time' => null,
                    'orders' => []
                ];
            }
            if ($volume > 0) {
                $buy_queues[$market][] = [
                    'volume' => $volume,
                    'original_volume' => $volume,
                    'settle_amount' => $settle_amount,
                    'price' => $price
                ];
                $current_trade_group[$market]['orders'][] = [
                    'uuid' => $order['uuid'],
                    'side' => 'bid',
                    'created_at' => $order['created_at'],
                    'avg_price' => $price,
                    'executed_volume' => $volume,
                    'paid_fee' => $order['paid_fee'],
                    'settle_amount' => $settle_amount
                ];
            }
        } elseif ($side === 'ask') {
            $sell_volume = $volume;
            $total_buy_settle_amount = 0;
            $total_buy_cost_by_price = 0;
            $total_matched_volume = 0;

            if (!empty($buy_queues[$market])) {
                while ($sell_volume > 0 && count($buy_queues[$market]) > 0) {
                    $buy_order = &$buy_queues[$market][0];
                    $matched_vol = min($sell_volume, $buy_order['volume']);

                    $total_buy_cost_by_price += $buy_order['price'] * $matched_vol;

                    if ($buy_order['original_volume'] > 0) {
                        $proportional_buy_settle = ($buy_order['settle_amount'] / $buy_order['original_volume']) * $matched_vol;
                        $total_buy_settle_amount += $proportional_buy_settle;
                    }

                    $buy_order['volume'] -= $matched_vol;
                    $sell_volume -= $matched_vol;
                    $total_matched_volume += $matched_vol;

                    if ($buy_order['volume'] <= 1e-8) {
                        array_shift($buy_queues[$market]);
                    }
                }
            }

            if ($total_matched_volume > 0) {
                $proportional_sell_settle = 0;
                $proportional_fee = 0;
                if ($volume > 0) {
                    $proportional_sell_settle = ($settle_amount / $volume) * $total_matched_volume;
                    $proportional_fee = ($order['paid_fee'] / $volume) * $total_matched_volume;
                }

                // 모달의 +/- 필드에 표시될 수익금 (체결 단가 기준, 수수료 미포함)
                $sell_amount_by_price = $price * $total_matched_volume;
                $profit_for_modal = $sell_amount_by_price - $total_buy_cost_by_price;
                $profit_rate_for_modal = ($total_buy_cost_by_price > 0) ? ($profit_for_modal / $total_buy_cost_by_price) * 100 : 0;

                // 전체 사이클의 실제 순수익 (정산 금액 기준, 수수료 포함)
                $profit = $proportional_sell_settle - $total_buy_settle_amount;

                if (!isset($current_trade_group[$market])) {
                    $current_trade_group[$market] = [
                        'uuid' => $order['uuid'],
                        'memo' => $order['memo'] ?? '',
                        'tradingview_url' => $order['tradingview_url'] ?? '',
                        'strategy_name' => $order['strategy_name'] ?? '',
                        'entry_reason' => $order['entry_reason'] ?? '',
                        'psychology_state' => $order['psychology_state'] ?? '',
                        'failure_factor' => $order['failure_factor'] ?? '',
                        'created_at' => $order['created_at'],
                        'market' => $market,
                        'profit' => 0,
                        'total_buy_settle' => 0,
                        'orders' => []
                    ];
                }

                $current_trade_group[$market]['orders'][] = [
                    'uuid' => $order['uuid'],
                    'side' => 'ask',
                    'created_at' => $order['created_at'],
                    'avg_price' => $price,
                    'executed_volume' => $total_matched_volume,
                    'paid_fee' => $proportional_fee,
                    'settle_amount' => $proportional_sell_settle,
                    'profit' => $profit_for_modal,
                    'profit_rate' => $profit_rate_for_modal
                ];

                // 사이클 전체 수익은 실제 정산금액 기준으로 합산
                $current_trade_group[$market]['profit'] += $profit;
                $current_trade_group[$market]['total_buy_settle'] += $total_buy_settle_amount;
                $current_trade_group[$market]['last_ask_time'] = $order['created_at'];

                // 사이클 종료 (buy_queue가 모두 소진됨)
                if (empty($buy_queues[$market])) {
                    $trade = $current_trade_group[$market];
                    $trade_close_time = $trade['last_ask_time'] ?? $order['created_at'];

                    if ($trade_close_time >= $one_month_ago) {
                        $total_one_month_profit += $trade['profit'];
                        $total_one_month_buy_settle += $trade['total_buy_settle'];
                    }

                    // Monthly stats and Total
                    $month_key = date('Y-m', strtotime($trade_close_time));
                    if (!isset($monthly_stats[$month_key])) {
                        $monthly_stats[$month_key] = ['profit' => 0, 'buy_settle' => 0];
                    }
                    $monthly_stats[$month_key]['profit'] += $trade['profit'];
                    $monthly_stats[$month_key]['buy_settle'] += $trade['total_buy_settle'];

                    $total_profit += $trade['profit'];
                    $total_buy_settle += $trade['total_buy_settle'];

                    if ($trade_close_time >= $seven_days_ago) {
                        $total_seven_days_profit += $trade['profit'];
                        $total_seven_days_buy_settle += $trade['total_buy_settle'];
                    }

                    // // 달력용 데이터 추가
                    // $date_key = date('Y-m-d', strtotime($trade_close_time));
                    // if (!isset($daily_profit_data[$date_key])) {
                    //     $daily_profit_data[$date_key] = ['KRW' => 0, 'USDT' => 0, 'trades' => 0, 'wins' => 0, 'losses' => 0];
                    // }
                    // $daily_profit_data[$date_key]['KRW'] += $trade['profit'];
                    // $daily_profit_data[$date_key]['trades'] += 1;
                    // if ($trade['profit'] > 0) {
                    //     $daily_profit_data[$date_key]['wins'] += 1;
                    // } elseif ($trade['profit'] < 0) {
                    //     $daily_profit_data[$date_key]['losses'] += 1;
                    // }

                    $profit_rate = ($trade['total_buy_settle'] > 0) ? ($trade['profit'] / $trade['total_buy_settle']) * 100 : 0;
                    $all_upbit_trades[] = [
                        'uuid' => $trade['uuid'],
                        'memo' => $trade['memo'],
                        'tradingview_url' => $trade['tradingview_url'] ?? '',
                        'strategy_name' => $trade['strategy_name'] ?? '',
                        'entry_reason' => $trade['entry_reason'] ?? '',
                        'psychology_state' => $trade['psychology_state'] ?? '',
                        'failure_factor' => $trade['failure_factor'] ?? '',
                        'created_at' => $trade['created_at'], // 매수 시작 시간 기준
                        'last_ask_time' => $trade_close_time,
                        'market' => $market,
                        'profit' => $trade['profit'],
                        'profit_rate' => $profit_rate,
                        'total_buy_settle' => $trade['total_buy_settle'],
                        'orders' => $trade['orders']
                    ];
                    unset($current_trade_group[$market]);
                }
            }
        }
    }

    // 반복문 종료 후: 부분 매도되어 아직 큐가 비워지지 않은 사이클도 화면에 표시
    foreach ($current_trade_group as $market => $trade) {
        if ($trade['total_buy_settle'] > 0) {
            $trade_close_time = $trade['last_ask_time'] ?? $trade['created_at']; // last_ask_time이 있어야 정상

            if ($trade_close_time >= $one_month_ago) {
                $total_one_month_profit += $trade['profit'];
                $total_one_month_buy_settle += $trade['total_buy_settle'];
            }

            // Monthly stats and Total
            $month_key = date('Y-m', strtotime($trade_close_time));
            if (!isset($monthly_stats[$month_key])) {
                $monthly_stats[$month_key] = ['profit' => 0, 'buy_settle' => 0];
            }
            $monthly_stats[$month_key]['profit'] += $trade['profit'];
            $monthly_stats[$month_key]['buy_settle'] += $trade['total_buy_settle'];

            $total_profit += $trade['profit'];
            $total_buy_settle += $trade['total_buy_settle'];

            if ($trade_close_time >= $seven_days_ago) {
                $total_seven_days_profit += $trade['profit'];
                $total_seven_days_buy_settle += $trade['total_buy_settle'];
            }

            // // 달력용 데이터 추가
            // $date_key = date('Y-m-d', strtotime($trade_close_time));
            // if (!isset($daily_profit_data[$date_key])) {
            //     $daily_profit_data[$date_key] = ['KRW' => 0, 'USDT' => 0, 'trades' => 0, 'wins' => 0, 'losses' => 0];
            // }
            // $daily_profit_data[$date_key]['KRW'] += $trade['profit'];
            // $daily_profit_data[$date_key]['trades'] += 1;
            // if ($trade['profit'] > 0) {
            //     $daily_profit_data[$date_key]['wins'] += 1;
            // } elseif ($trade['profit'] < 0) {
            //     $daily_profit_data[$date_key]['losses'] += 1;
            // }

            $profit_rate = ($trade['total_buy_settle'] > 0) ? ($trade['profit'] / $trade['total_buy_settle']) * 100 : 0;
            $all_upbit_trades[] = [
                'uuid' => $trade['uuid'],
                'memo' => $trade['memo'],
                'tradingview_url' => $trade['tradingview_url'] ?? '',
                'strategy_name' => $trade['strategy_name'] ?? '',
                'entry_reason' => $trade['entry_reason'] ?? '',
                'psychology_state' => $trade['psychology_state'] ?? '',
                'failure_factor' => $trade['failure_factor'] ?? '',
                'created_at' => $trade['created_at'], // 매수 시작 시간 기준
                'last_ask_time' => $trade_close_time,
                'market' => $market,
                'profit' => $trade['profit'],
                'profit_rate' => $profit_rate,
                'total_buy_settle' => $trade['total_buy_settle'],
                'orders' => $trade['orders']
            ];
        }
    }

    usort($all_upbit_trades, function ($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });

    $upbit_trade_results = array_slice($all_upbit_trades, 0, 30);

    foreach ($upbit_trade_results as $t) {
        $list_total_profit += $t['profit'];
        $list_total_buy_settle += $t['total_buy_settle'];
    }
} catch (PDOException $e) {
    error_log("Upbit orders fetch error: " . $e->getMessage());
}

// OKX 전체 태그 수집 (날짜 검색과 무관하게 모든 태그 노출)
try {
    $stmt_okx_tags = $pdo->query("SELECT strategy_name, entry_reason, psychology_state, failure_factor FROM okx_trade_fills WHERE strategy_name != '' OR entry_reason != '' OR psychology_state != '' OR failure_factor != ''");
    if ($stmt_okx_tags) {
        while ($row = $stmt_okx_tags->fetch(PDO::FETCH_ASSOC)) {
            if (!empty($row['strategy_name'])) {
                foreach (explode(',', $row['strategy_name']) as $s) {
                    $s = trim($s);
                    if ($s !== '') $all_strategies_set[$s] = true;
                }
            }
            if (!empty($row['entry_reason'])) {
                foreach (explode(',', $row['entry_reason']) as $s) {
                    $s = trim($s);
                    if ($s !== '') $all_entry_reasons_set[$s] = true;
                }
            }
            if (!empty($row['psychology_state'])) {
                foreach (explode(',', $row['psychology_state']) as $s) {
                    $s = trim($s);
                    if ($s !== '') $all_psychology_states_set[$s] = true;
                }
            }
            if (!empty($row['failure_factor'])) {
                foreach (explode(',', $row['failure_factor']) as $s) {
                    $s = trim($s);
                    if ($s !== '') $all_failure_factors_set[$s] = true;
                }
            }
        }
    }
} catch (PDOException $e) {
    error_log("OKX tags fetch error: " . $e->getMessage());
}

// OKX 계정 잔고 (Estimated total value) 조회
$okx_total_eq = 0;
try {
    $apiKey = '242f8685-deb4-448d-940c-d5e0d32be456';
    $secretKey = '83684101C963C8FC0AAEB5EDCC8A4D9A';
    $passphrase = 'Khan160406@';

    $okx = new OkxApi($apiKey, $secretKey, $passphrase);
    // Trading 계정뿐만 아니라 모든 계정의 총 자산 평가액을 USDT 기준으로 가져옵니다.
    $balanceRes = $okx->request('GET', '/asset/asset-valuation', ['ccy' => 'USDT']);
    if (isset($balanceRes['code']) && $balanceRes['code'] === '0' && !empty($balanceRes['data'])) {
        $okx_total_eq = (float)$balanceRes['data'][0]['totalBal'];
    }
} catch (Exception $e) {
    error_log("OKX balance fetch error: " . $e->getMessage());
}

// OKX 날짜 필터 설정 (기본값: 최근 한달)
$okx_start_date = $_GET['okx_start_date'] ?? date('Y-m-d', strtotime('-1 month'));
$okx_end_date = $_GET['okx_end_date'] ?? date('Y-m-d');
$start_ms = (string)(strtotime($okx_start_date . ' 00:00:00') * 1000);
$end_ms = (string)(strtotime($okx_end_date . ' 23:59:59') * 1000);

// OKX 매매 일지
$okx_trade_results = [];
$list_total_okx_profit = 0;
$overall_okx_profit = 0;
$overall_okx_trades = 0;
$overall_okx_wins = 0;
$overall_okx_losses = 0;
$overall_okx_win_rate = 0;
$okx_orders_by_id = [];
try {
    // OKX 전체 통계 구하기
    $stmt_okx_total_stats = $pdo->prepare("
        SELECT 
            SUM(realized_pnl) as total_pnl,
            COUNT(DISTINCT ord_id) as total_trades,
            SUM(CASE WHEN realized_pnl > 0 THEN 1 ELSE 0 END) as total_wins,
            SUM(CASE WHEN realized_pnl < 0 THEN 1 ELSE 0 END) as total_losses
        FROM (
            SELECT ord_id, SUM(realized_pnl - (fee * 2)) as realized_pnl
            FROM okx_trade_fills 
            WHERE realized_pnl != 0 
            GROUP BY ord_id
        ) t
    ");
    $stmt_okx_total_stats->execute();
    $okx_total_stats = $stmt_okx_total_stats->fetch(PDO::FETCH_ASSOC);

    $stmt_okx_total_funding = $pdo->prepare("
        SELECT SUM(amount) as total_funding
        FROM okx_account_bills
        WHERE type_id = 8
    ");
    $stmt_okx_total_funding->execute();
    $okx_total_funding = (float)$stmt_okx_total_funding->fetchColumn();

    $overall_okx_profit = (float)($okx_total_stats['total_pnl'] ?? 0) + $okx_total_funding;
    $overall_okx_trades = (int)($okx_total_stats['total_trades'] ?? 0);
    $overall_okx_wins = (int)($okx_total_stats['total_wins'] ?? 0);
    $overall_okx_losses = (int)($okx_total_stats['total_losses'] ?? 0);
    $overall_okx_win_rate = $overall_okx_trades > 0 ? ($overall_okx_wins / $overall_okx_trades) * 100 : 0;

    $stmt_okx = $pdo->prepare("
        SELECT 
            ord_id, 
            MAX(ts) as ts, 
            MIN(ts) as first_ts,
            MAX(inst_id) as inst_id, 
            SUM(realized_pnl - (fee * 2)) as realized_pnl,
            MAX(strategy_name) as strategy_name,
            MAX(entry_reason) as entry_reason,
            MAX(psychology_state) as psychology_state,
            MAX(failure_factor) as failure_factor,
            MAX(tradingview_url) as tradingview_url,
            MAX(memo) as memo,
            MAX(pos_side) as pos_side,
            MAX(lever) as lever
        FROM okx_trade_fills 
        WHERE realized_pnl != 0 
        GROUP BY ord_id 
        HAVING MAX(ts) >= ? AND MAX(ts) <= ?
        ORDER BY MAX(ts) DESC
    ");
    $stmt_okx->execute([$start_ms, $end_ms]);
    $okx_trade_results = $stmt_okx->fetchAll(PDO::FETCH_ASSOC);

    $okx_ord_ids = array_column($okx_trade_results, 'ord_id');
    if (!empty($okx_ord_ids)) {
        $inst_ids = array_values(array_unique(array_column($okx_trade_results, 'inst_id')));
        $inst_placeholders = implode(',', array_fill(0, count($inst_ids), '?'));

        $stmt_all_fills = $pdo->prepare("SELECT * FROM okx_trade_fills WHERE inst_id IN ($inst_placeholders) ORDER BY ts ASC");
        $stmt_all_fills->execute($inst_ids);
        $all_inst_fills = $stmt_all_fills->fetchAll(PDO::FETCH_ASSOC);

        $stmt_all_bills = $pdo->prepare("SELECT * FROM okx_account_bills WHERE inst_id IN ($inst_placeholders) AND type_id = 8 ORDER BY ts ASC");
        $stmt_all_bills->execute($inst_ids);
        $all_funding_bills = $stmt_all_bills->fetchAll(PDO::FETCH_ASSOC);

        $events = [];
        foreach ($all_inst_fills as $fill) {
            $events[] = ['type' => 'fill', 'ts' => (int)$fill['ts'], 'data' => $fill];
        }
        foreach ($all_funding_bills as $bill) {
            $events[] = ['type' => 'funding', 'ts' => (int)$bill['ts'], 'data' => $bill];
        }
        usort($events, function ($a, $b) {
            return $a['ts'] <=> $b['ts'];
        });

        $okx_fifo_queues = [];
        $matched_orders_for_close = []; // 종결 주문의 ord_id별 매칭된 진입(오픈) 주문들 저장
        $close_funding_total = [];
        $okx_trade_costs = [];

        foreach ($events as $event) {
            if ($event['type'] === 'fill') {
                $fill = $event['data'];
                $inst_id = $fill['inst_id'];
                $pos_side = strtolower($fill['pos_side'] ?? 'net');
                $side = strtolower($fill['side']);
                $sz = (float)$fill['fill_sz'];
                $realized_pnl = (float)$fill['realized_pnl'];
                $ord_id = $fill['ord_id'];

                $pos_key = $inst_id . '_' . $pos_side;
                if (!isset($okx_fifo_queues[$pos_key])) {
                    $okx_fifo_queues[$pos_key] = [];
                }
                $queue = &$okx_fifo_queues[$pos_key];

                $is_open = false;
                $is_close = false;

                if ($pos_side === 'long') {
                    if ($side === 'buy') $is_open = true;
                    if ($side === 'sell') $is_close = true;
                } elseif ($pos_side === 'short') {
                    if ($side === 'sell') $is_open = true;
                    if ($side === 'buy') $is_close = true;
                } else {
                    if ($realized_pnl != 0) $is_close = true;
                    else $is_open = true;
                }

                if ($is_open) {
                    $fill['accumulated_funding'] = 0;
                    $fill['funding_bills'] = [];
                    $queue[] = $fill;
                } elseif ($is_close) {
                    if (!isset($matched_orders_for_close[$ord_id])) {
                        $matched_orders_for_close[$ord_id] = [];
                        $close_funding_total[$ord_id] = 0;
                        $okx_trade_costs[$ord_id] = 0;
                    }

                    $close_sz = $sz;
                    while ($close_sz > 0 && count($queue) > 0) {
                        $open_fill = &$queue[0];
                        $match_sz = min($close_sz, (float)$open_fill['fill_sz']);
                        $ratio = $match_sz / (float)$open_fill['fill_sz'];

                        $ctVal = (float)($open_fill['ct_val'] ?? 1);
                        $okx_trade_costs[$ord_id] += (float)$open_fill['fill_px'] * $match_sz * $ctVal;

                        $matched_open = $open_fill;
                        $matched_open['fill_sz'] = $match_sz; // 체결량 비율 조정
                        $matched_open['fee'] = ((float)$open_fill['fee'] * $ratio); // 수수료 비율 조정

                        $matched_funding = (float)$open_fill['accumulated_funding'] * $ratio;
                        $matched_open['accumulated_funding'] = $matched_funding;

                        $matched_bills = [];
                        foreach ($open_fill['funding_bills'] as $fb) {
                            $matched_bills[] = [
                                'ts' => $fb['ts'],
                                'amount' => $fb['amount'] * $ratio
                            ];
                        }
                        $matched_open['funding_bills'] = $matched_bills;

                        $matched_orders_for_close[$ord_id][] = $matched_open;
                        $close_funding_total[$ord_id] += $matched_funding;

                        $open_fill['fill_sz'] = (float)$open_fill['fill_sz'] - $match_sz;
                        $open_fill['fee'] = (float)$open_fill['fee'] - $matched_open['fee'];
                        $open_fill['accumulated_funding'] = (float)$open_fill['accumulated_funding'] - $matched_funding;
                        foreach ($open_fill['funding_bills'] as &$fb) {
                            foreach ($matched_bills as $mb) {
                                if ($fb['ts'] === $mb['ts']) {
                                    $fb['amount'] -= $mb['amount'];
                                    break;
                                }
                            }
                        }
                        unset($fb);

                        $close_sz -= $match_sz;

                        if ($open_fill['fill_sz'] <= 1e-8) {
                            array_shift($queue);
                        }
                    }
                }
            } elseif ($event['type'] === 'funding') {
                $bill = $event['data'];
                $inst_id = $bill['inst_id'];

                $total_sz = 0;
                $active_queues = [];
                foreach ($okx_fifo_queues as $pos_key => &$queue) {
                    if (strpos($pos_key, $inst_id . '_') === 0) {
                        foreach ($queue as &$q_item) {
                            $total_sz += (float)$q_item['fill_sz'];
                        }
                        $active_queues[] = &$queue;
                    }
                }
                unset($queue);

                if ($total_sz > 0) {
                    foreach ($active_queues as &$queue) {
                        foreach ($queue as &$q_item) {
                            $share = ((float)$q_item['fill_sz'] / $total_sz) * (float)$bill['amount'];
                            $q_item['accumulated_funding'] += $share;

                            $q_item['funding_bills'][] = [
                                'ts' => $bill['ts'],
                                'amount' => $share
                            ];
                        }
                    }
                    unset($queue);
                }
            }
        }

        // 팝업 뷰에 전달할 매수(오픈)/매도(클로즈) 데이터 종합
        foreach ($all_inst_fills as $fill) {
            if (in_array($fill['ord_id'], $okx_ord_ids)) {
                $ord_id = $fill['ord_id'];

                if (isset($matched_orders_for_close[$ord_id])) {
                    foreach ($matched_orders_for_close[$ord_id] as $open_fill) {
                        $ctVal = (float)($open_fill['ct_val'] ?? 1);
                        $settle_amount = ((float)$open_fill['fill_px'] * (float)$open_fill['fill_sz'] * $ctVal) + (float)$open_fill['fee'];

                        $okx_orders_by_id[$ord_id][] = [
                            'ord_id' => $open_fill['ord_id'],
                            'side' => $open_fill['side'],
                            'created_at' => date('Y-m-d H:i:s', $open_fill['ts'] / 1000),
                            'avg_price' => (float)$open_fill['fill_px'],
                            'executed_volume' => (float)$open_fill['fill_sz'],
                            'paid_fee' => (float)$open_fill['fee'],
                            'settle_amount' => $settle_amount,
                            'profit' => 0,
                            'profit_rate' => 0
                        ];

                        foreach ($open_fill['funding_bills'] as $fb) {
                            $okx_orders_by_id[$ord_id][] = [
                                'ord_id' => $ord_id,
                                'side' => 'funding',
                                'created_at' => date('Y-m-d H:i:s', $fb['ts'] / 1000),
                                'avg_price' => 0,
                                'executed_volume' => 0,
                                'paid_fee' => 0,
                                'settle_amount' => 0,
                                'profit' => (float)$fb['amount'],
                                'profit_rate' => 0
                            ];
                        }
                    }
                    unset($matched_orders_for_close[$ord_id]);
                }

                $is_close_fill = (float)($fill['realized_pnl'] ?? 0) != 0;
                $ctVal = (float)($fill['ct_val'] ?? 1);
                $total_val = (float)$fill['fill_px'] * (float)$fill['fill_sz'] * $ctVal;
                $fee = (float)$fill['fee'];
                $settle_amount = $is_close_fill ? ($total_val - $fee) : ($total_val + $fee);

                $okx_orders_by_id[$ord_id][] = [
                    'ord_id' => $fill['ord_id'],
                    'side' => $fill['side'],
                    'created_at' => date('Y-m-d H:i:s', $fill['ts'] / 1000),
                    'avg_price' => (float)$fill['fill_px'],
                    'executed_volume' => (float)$fill['fill_sz'],
                    'paid_fee' => $fee,
                    'settle_amount' => $settle_amount,
                    'profit' => (float)$fill['realized_pnl'],
                    'profit_rate' => 0
                ];
            }
        }

        foreach ($okx_trade_results as &$t) {
            $ord_id = $t['ord_id'];
            if (isset($close_funding_total[$ord_id])) {
                $t['realized_pnl'] += $close_funding_total[$ord_id];
            }
            if (isset($okx_trade_costs[$ord_id])) {
                $t['cost'] = $okx_trade_costs[$ord_id];
            } else {
                $t['cost'] = 0;
            }
        }
        unset($t);

        foreach ($okx_orders_by_id as $ord_id => &$orders) {
            $funding_by_ts = [];
            $cleaned_orders = [];
            foreach ($orders as $o) {
                if ($o['side'] === 'funding') {
                    if (!isset($funding_by_ts[$o['created_at']])) {
                        $funding_by_ts[$o['created_at']] = $o;
                    } else {
                        $funding_by_ts[$o['created_at']]['profit'] += $o['profit'];
                    }
                } else {
                    $cleaned_orders[] = $o;
                }
            }
            foreach ($funding_by_ts as $f) {
                if (abs($f['profit']) > 1e-8) {
                    $cleaned_orders[] = $f;
                }
            }
            usort($cleaned_orders, function ($a, $b) {
                return strtotime($a['created_at']) - strtotime($b['created_at']);
            });
            $orders = $cleaned_orders;
        }
        unset($orders);
    }

    foreach ($okx_trade_results as $t) {
        $list_total_okx_profit += (float)$t['realized_pnl'];
    }

    // 일별 수익 합산 (OKX 달력용)
    $stmt_okx_daily_trades = $pdo->prepare("
        SELECT 
            DATE(FROM_UNIXTIME(ts/1000)) as trade_date,
            SUM(realized_pnl) as daily_pnl,
            COUNT(DISTINCT ord_id) as daily_trades,
            SUM(CASE WHEN realized_pnl > 0 THEN 1 ELSE 0 END) as daily_wins,
            SUM(CASE WHEN realized_pnl < 0 THEN 1 ELSE 0 END) as daily_losses
        FROM (
            SELECT ord_id, MAX(ts) as ts, SUM(realized_pnl - (fee * 2)) as realized_pnl
            FROM okx_trade_fills 
            WHERE realized_pnl != 0 
            GROUP BY ord_id
        ) t
        GROUP BY trade_date
    ");
    $stmt_okx_daily_trades->execute();
    $okx_daily_trades = $stmt_okx_daily_trades->fetchAll(PDO::FETCH_ASSOC);

    $stmt_okx_daily_funding = $pdo->prepare("
        SELECT 
            DATE(FROM_UNIXTIME(ts/1000)) as trade_date,
            SUM(amount) as daily_funding
        FROM okx_account_bills
        WHERE type_id = 8
        GROUP BY trade_date
    ");
    $stmt_okx_daily_funding->execute();
    $okx_daily_funding = $stmt_okx_daily_funding->fetchAll(PDO::FETCH_ASSOC);

    foreach ($okx_daily_trades as $row) {
        $date_key = $row['trade_date'];
        if (!isset($daily_profit_data[$date_key])) {
            $daily_profit_data[$date_key] = ['KRW' => 0, 'USDT' => 0, 'trades' => 0, 'wins' => 0, 'losses' => 0];
        }
        $daily_profit_data[$date_key]['USDT'] += (float)$row['daily_pnl'];
        $daily_profit_data[$date_key]['trades'] += (int)$row['daily_trades'];
        $daily_profit_data[$date_key]['wins'] += (int)$row['daily_wins'];
        $daily_profit_data[$date_key]['losses'] += (int)$row['daily_losses'];
    }

    foreach ($okx_daily_funding as $row) {
        $date_key = $row['trade_date'];
        if (!isset($daily_profit_data[$date_key])) {
            $daily_profit_data[$date_key] = ['KRW' => 0, 'USDT' => 0, 'trades' => 0, 'wins' => 0, 'losses' => 0];
        }
        $daily_profit_data[$date_key]['USDT'] += (float)$row['daily_funding'];
    }
} catch (PDOException $e) {
    error_log("OKX trades fetch error: " . $e->getMessage());
}

// 월별 목표 수익 가져오기
$month_targets = [];
try {
    $stmt = $pdo->prepare("SELECT target_month, target_profit FROM month_target");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $month_targets[$row['target_month']] = (float)$row['target_profit'];
    }
} catch (PDOException $e) {
    // 테이블이 없을 수도 있으므로 오류 무시
}

$wiki_search = $_GET['wiki_search'] ?? '';
$recent_wikis = [];
try {
    $wiki_query = "SELECT id, title, content, tv_image_url, youtube_url, image, created_at, core FROM strategy_wiki";
    $wiki_params = [];
    if (!empty($wiki_search)) {
        $wiki_query .= " WHERE title LIKE :search OR content LIKE :search";
        $wiki_params[':search'] = '%' . $wiki_search . '%';
    }
    $wiki_query .= " ORDER BY core DESC, updated_at DESC LIMIT 20";

    $stmt_wiki = $pdo->prepare($wiki_query);
    $stmt_wiki->execute($wiki_params);
    $recent_wikis = $stmt_wiki->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Wiki fetch error: " . $e->getMessage());
}
?>
<?php
// 나의 매매 원칙 데이터 가져오기
$trading_principles = [];
try {
    $stmt_principles = $pdo->prepare("SELECT * FROM trading_principles WHERE user_id = ? ORDER BY principle_turn ASC, id ASC");
    $stmt_principles->execute([$_SESSION['user_id']]);
    $trading_principles = $stmt_principles->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Trading principles fetch error: " . $e->getMessage());
    // 오류 발생 시 빈 배열로 처리
}
?>
<div class="main-content-wrapper">
    <div class="dashboard-header">
        <div class="dashboard-header-inner" style="position: relative;">
            <h2 class="dashboard-title">
                <i class="fa-solid fa-gauge-high"></i> Trading Overview
            </h2>
            <p class="hide-on-mobile dashboard-welcome">
                반갑습니다, <?php echo h($_SESSION['nickname']); ?> 님. 원칙 매매를 응원합니다!
            </p>
            <div class="dashboard-buttons">
                <div class="sync-container" style="display: none;">
                    <a href="#" class="btn-upbit" id="btnSync">Upbit Orders</a>
                    <div id="syncMsg" class="sync-popup"></div>
                </div>
                <div class="sync-container">
                    <a href="#" class="btn-okx" id="btnSyncOkx">OKX Orders</a>
                    <div id="syncMsgOkx" class="sync-popup"></div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const monthlyStats = <?php echo json_encode($monthly_stats); ?>;
        let currentDisplayMonth = new Date(); // Current date

        function formatCurrencyStr(num) {
            return Math.round(num).toLocaleString('ko-KR');
        }

        function updateMonthDisplay() {
            const year = currentDisplayMonth.getFullYear();
            const month = String(currentDisplayMonth.getMonth() + 1).padStart(2, '0');
            const monthKey = `${year}-${month}`;

            document.getElementById('currentMonthLabel').innerText = `${year}.${month}`;

            const stats = monthlyStats[monthKey] || {
                profit: 0,
                buy_settle: 0
            };
            const profit = stats.profit;
            const rate = stats.buy_settle > 0 ? (profit / stats.buy_settle) * 100 : 0;
            const truncatedRate = Math.trunc(rate * 100) / 100;

            const profitEl = document.getElementById('monthlyProfitValue');
            const color = profit > 0 ? '#1261c4' : (profit < 0 ? '#c84a31' : 'inherit');
            const sign = profit > 0 ? '+' : '';

            profitEl.style.color = color;
            profitEl.innerHTML = `${formatCurrencyStr(profit)}\n<div style="font-size: 0.85rem; font-weight: 600; margin-top: 2px;" id="monthlyProfitRate">(${sign}${truncatedRate.toFixed(2)}%)</div>`;
        }

        document.addEventListener('DOMContentLoaded', function() {
            const prevBtn = document.getElementById('prevMonthBtn');
            const nextBtn = document.getElementById('nextMonthBtn');
            if (prevBtn && nextBtn) {
                prevBtn.addEventListener('click', () => {
                    currentDisplayMonth.setMonth(currentDisplayMonth.getMonth() - 1);
                    updateMonthDisplay();
                });
                nextBtn.addEventListener('click', () => {
                    currentDisplayMonth.setMonth(currentDisplayMonth.getMonth() + 1);
                    updateMonthDisplay();
                });
                updateMonthDisplay();
            }

            const btn = document.getElementById('btnSync');
            const msgPopup = document.getElementById('syncMsg');
            let isSyncing = false;

            btn.addEventListener('click', function(e) {
                e.preventDefault();
                if (isSyncing) return;

                isSyncing = true;
                btn.disabled = true;
                msgPopup.innerText = "데이터 동기화 중...";
                msgPopup.classList.add('show');

                fetch('upbit_orders_sync.php')
                    .then(async res => {
                        const contentType = res.headers.get("content-type");
                        if (!res.ok) {
                            throw new Error(`HTTP 에러 (${res.status}) - 파일을 찾을 수 없거나 서버 오류입니다.`);
                        }
                        if (contentType && contentType.includes("application/json")) {
                            return res.json();
                        } else {
                            const errText = await res.text();
                            throw new Error(`JSON 응답이 아님: ${errText.substring(0, 50)}...`);
                        }
                    })
                    .then(data => {
                        if (data.success) {
                            msgPopup.innerText = data.count + "건의 새로운 거래가 저장되었습니다.";
                            setTimeout(() => location.reload(), 1500);
                        } else {
                            msgPopup.innerText = "오류 발생: " + (data.message || "알 수 없는 오류");
                            setTimeout(() => {
                                msgPopup.classList.remove('show');
                                isSyncing = false;
                                btn.disabled = false;
                            }, 3000);
                        }
                    })
                    .catch(err => {
                        console.error("Sync error:", err);
                        msgPopup.innerText = "동기화 실패: " + err.message;
                        setTimeout(() => {
                            msgPopup.classList.remove('show');
                            isSyncing = false;
                            btn.disabled = false;
                        }, 3000);
                    });
            });

            const btnOkx = document.getElementById('btnSyncOkx');
            const msgPopupOkx = document.getElementById('syncMsgOkx');
            let isSyncingOkx = false;

            btnOkx.addEventListener('click', function(e) {
                e.preventDefault();
                if (isSyncingOkx) return;

                isSyncingOkx = true;
                btnOkx.disabled = true;
                msgPopupOkx.innerText = "데이터 동기화 중...";
                msgPopupOkx.classList.add('show');

                fetch('okx_orders_sync.php')
                    .then(async res => {
                        const contentType = res.headers.get("content-type");
                        if (!res.ok) {
                            throw new Error(`HTTP 에러 (${res.status}) - 파일을 찾을 수 없거나 서버 오류입니다.`);
                        }
                        if (contentType && contentType.includes("application/json")) {
                            return res.json();
                        } else {
                            const errText = await res.text();
                            throw new Error(`JSON 응답이 아님: ${errText.substring(0, 50)}...`);
                        }
                    })
                    .then(data => {
                        if (data.success) {
                            msgPopupOkx.innerText = data.count + "건의 새로운 거래가 저장되었습니다.";
                            setTimeout(() => location.reload(), 1500);
                        } else {
                            msgPopupOkx.innerText = "오류 발생: " + (data.message || "알 수 없는 오류");
                            setTimeout(() => {
                                msgPopupOkx.classList.remove('show');
                                isSyncingOkx = false;
                                btnOkx.disabled = false;
                            }, 3000);
                        }
                    })
                    .catch(err => {
                        console.error("Sync error:", err);
                        msgPopupOkx.innerText = "동기화 실패: " + err.message;
                        setTimeout(() => {
                            msgPopupOkx.classList.remove('show');
                            isSyncingOkx = false;
                            btnOkx.disabled = false;
                        }, 3000);
                    });
            });
        });
    </script>

    <div class="row">
        <div class="col-2">
            <div class="card card-top-warning" style="background-color: lightyellow;">
                <div class="card-stats-item" style="align-items: flex-start;">
                    <div style="width: 100%;">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <span>매매원칙 (Trading Principles)</span>
                            <a href="#" onclick="openPrinciplesModal(); return false;" title="원칙 편집" style="text-decoration: none; color: #555;"><i class="fa-solid fa-pen"></i></a>
                        </div>
                        <div class="principle-list" style="margin-top: 15px;">
                            <?php if (empty($trading_principles)): ?>
                                <p class="text-gray-500 text-sm" style="line-height: 1.5;">아직 설정된 원칙이 없습니다.<br><a href="#" onclick="openPrinciplesModal(); return false;" class="font-bold" style="text-decoration: underline;">여기</a>를 눌러 추가해주세요.</p>
                            <?php else: ?>
                                <?php foreach ($trading_principles as $principle): ?>
                                    <div class="principle-item" style="display: flex; flex-direction: column; gap: 6px; margin-bottom: 16px;">
                                        <?php if (!empty($principle['tag_text'])): ?>
                                            <?php $tagColor = getTagColor($principle['tag_text']); ?>
                                            <div class="principle-tag" style="padding: 4px 10px 3px; font-size: 13px; border-radius: 6px; background-color: <?php echo $tagColor['bg']; ?>; color: <?php echo $tagColor['text']; ?>; border: 1px solid <?php echo $tagColor['border']; ?>; white-space: nowrap; line-height: 1; box-shadow: 0 1px 2px rgba(0,0,0,0.05); font-weight: 600; display: inline-block; flex-shrink: 0; cursor: default; align-self: flex-start;"><?php echo h($principle['tag_text']); ?></div>
                                        <?php endif; ?>
                                        <p class="principle-text" style="margin: 0; font-size: 0.85rem; line-height: 100%; color: #333; flex-grow: 1;padding: 0 3px;"><?php echo h($principle['principle_text']); ?></p>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="stat-icon"><i class="fa-solid fa-shield-halved"></i></div>
                </div>
            </div>

            <!-- 전략위키 최근 목록 카드 -->
            <div class="card section-margin-top">
                <div class="card-stats-item" style="align-items: flex-start;">
                    <div style="width: 100%;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <span style="margin-bottom: 0;"><a href="study_wiki.php">전략위키</a></span>
                                <a href="#" class="button-new" id="indexNewWikiButton" style="margin: 0; transform: none; padding: 1px 6px; font-size: 0.65rem; background-color: orange;">N</a>
                            </div>
                            <form action="index.php" method="GET" style="display: flex; align-items: center; margin: 0; position: relative;">
                                <input type="text" name="wiki_search" value="<?php echo h($wiki_search); ?>" placeholder="검색" class="search-input-modern" style="width: 130px; padding: 4px 25px 4px 30px; font-size: 0.8rem; border-radius: 15px; background-position: 10px center; background-size: 12px;">
                                <?php if (!empty($wiki_search)): ?>
                                    <a href="index.php" style="position: absolute; right: 10px; color: var(--text-muted); text-decoration: none; font-size: 0.8rem;"><i class="fa-solid fa-circle-xmark"></i></a>
                                <?php endif; ?>
                            </form>
                        </div>
                        <ul style="list-style: none; padding: 0; margin: 0; font-size: 0.85rem; line-height: 1.6;">
                            <?php if (empty($recent_wikis)): ?>
                                <li style="color: var(--text-muted);"><?php echo empty($wiki_search) ? '작성된 위키가 없습니다.' : '검색된 위키가 없습니다.'; ?></li>
                            <?php else: ?>
                                <?php foreach ($recent_wikis as $index => $wiki): ?>
                                    <li class="wiki-list-item" data-core="<?php echo isset($wiki['core']) ? $wiki['core'] : 0; ?>" data-index="<?php echo $index; ?>" style="margin-bottom: 8px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: flex; align-items: center;">
                                        <?php
                                        $is_core = isset($wiki['core']) && $wiki['core'] == 1;
                                        $star_color = $is_core ? 'orange' : 'var(--text-muted)';
                                        ?>
                                        <i class="fa-solid fa-star wiki-core-toggle"
                                            data-wiki-id="<?php echo h($wiki['id']); ?>"
                                            data-core="<?php echo $is_core ? 1 : 0; ?>"
                                            style="font-size: 0.8rem; color: <?php echo $star_color; ?>; margin-right: 8px; cursor: pointer;"
                                            onclick="toggleWikiCore(event, this);"></i>
                                        <a href="#"
                                            data-title="<?php echo h($wiki['title']); ?>"
                                            data-content="<?php echo h($wiki['content']); ?>"
                                            data-tv-url="<?php echo h($wiki['tv_image_url']); ?>"
                                            data-youtube-url="<?php echo h($wiki['youtube_url']); ?>"
                                            data-image="<?php echo h($wiki['image']); ?>"
                                            onclick="openIndexWikiModal(this); return false;"
                                            style="color: var(--text-main); text-decoration: none; transition: color 0.2s; overflow: hidden; text-overflow: ellipsis;" onmouseover="this.style.color='var(--accent-color)'" onmouseout="this.style.color='var(--text-main)'">
                                            <?php echo h($wiki['title']); ?>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-10">
            <div style="display: flex; gap: 20px; align-items: stretch; margin-bottom: 20px;">
                <!-- 일별 수익 달력 시작 -->
                <div class="card card-top-black" style="flex: 8; padding: 24px; box-sizing: border-box;">
                    <div class="section-header" style="display: flex; align-items: center; justify-content: space-between; border-bottom: none; margin-bottom: 0; padding-bottom: 0;">
                        <div style="flex: 1;">
                            <h3 style="margin: 0; color: #333;"><i class="fa-regular fa-calendar-days"></i> 수익 달력</h3>
                        </div>
                        <div style="flex: 4; display: flex; justify-content: center; align-items: center;">
                            <div onclick="window.openTargetProfitModal()" style="cursor: pointer; display: flex; align-items: center; gap: 20px; padding: 5px 10px; border-radius: 6px; transition: background-color 0.2s;" onmouseover="this.style.backgroundColor='#f1f3f5'" onmouseout="this.style.backgroundColor='transparent'">
                                <span style="font-size: 0.9rem; font-weight: bold; color: var(--text-muted);">Target Profit</span>
                                <span id="targetProfitDisplay" style="font-size: 1.1rem; font-weight: 800; color: var(--accent-color);">-</span>
                                <span id="dailyStatsDisplay" style="font-size: 0.9rem; font-weight: bold; color: var(--text-muted);"></span>
                            </div>
                        </div>
                        <div style="flex: 1; display: flex; justify-content: flex-end; align-items: center; gap: 15px;">
                            <button type="button" id="calPrevMonth" style="border: none; background: none; cursor: pointer; color: var(--text-muted); font-size: 1.2rem;"><i class="fa-solid fa-chevron-left"></i></button>
                            <span id="calMonthLabel" style="font-size: 1.1rem; font-weight: bold; color: var(--text-main);"></span>
                            <button type="button" id="calNextMonth" style="border: none; background: none; cursor: pointer; color: var(--text-muted); font-size: 1.2rem;"><i class="fa-solid fa-chevron-right"></i></button>
                        </div>
                    </div>

                    <div class="calendar-container" style="margin-top: 20px;">
                        <div class="calendar-header" style="display: grid; grid-template-columns: repeat(9, 1fr); gap: 8px; text-align: center; font-weight: bold; color: var(--text-muted); margin-bottom: 10px; font-size: 0.9rem;">
                            <div style="color: #e11d48;">일</div>
                            <div>월</div>
                            <div>화</div>
                            <div>수</div>
                            <div>목</div>
                            <div>금</div>
                            <div style="color: #2962ff;">토</div>
                            <div>WEEK</div>
                            <div>MONTH</div>
                        </div>
                        <div style="display: grid; grid-template-columns: repeat(9, 1fr); gap: 8px;">
                            <div style="grid-column: 1 / 9;">
                                <div id="calendarBody" style="display: grid; grid-template-columns: repeat(8, 1fr); gap: 8px;">
                                    <!-- JS로 렌더링 -->
                                </div>
                            </div>
                            <div id="monthlySummary" class="calendar-day" style="grid-column: 9; padding: 15px 10px; display: flex; flex-direction: column; justify-content: center; gap: 8px; background-color: var(--table-bg-dark, #f8f9fa); border-color: var(--border-color, #e0e3eb); text-align: center;">
                                <div style="font-weight: bold; font-size: 0.9rem; color: var(--text-main);">월간 요약</div>
                                <div style="font-size: 0.85rem; color: var(--text-muted);"><span id="summaryTotalProfit"></span></div>
                                <div style="font-weight: bold;font-size: 0.85rem; color: var(--text-muted);"><span id="summaryTotalTrades"></span></div>
                                <div style="font-weight: bold;font-size: 0.85rem; color: var(--text-muted);"><span id="summaryWinLoss"></span></div>
                                <div style="font-weight: bold;font-size: 0.85rem; color: var(--text-muted);"><span id="summaryWinRate"></span></div>
                                <!-- JS로 렌더링 -->
                            </div>
                        </div>
                    </div>

                    <style>
                        .calendar-day {
                            background-color: var(--table-bg-light, #ffffff);
                            border: 1px solid var(--table-border-light, #e0e3eb);
                            border-radius: 8px;
                            min-height: 50px;
                            padding: 2px 8px;
                            display: flex;
                            flex-direction: column;
                            transition: all 0.2s ease;
                            cursor: pointer;
                        }

                        .calendar-day:hover {
                            background-color: var(--table-row-hover, #f1f3f5);
                        }

                        .calendar-day.empty {
                            background-color: transparent;
                            border: none;
                        }

                        .calendar-day.empty:hover {
                            background-color: transparent;
                        }

                        .calendar-day .date-num {
                            font-size: 0.85rem;
                            font-weight: bold;
                            color: var(--text-main);
                            margin-bottom: auto;
                        }

                        .calendar-day.sun .date-num {
                            color: #e11d48;
                        }

                        .calendar-day.sat .date-num {
                            color: #2962ff;
                        }

                        .calendar-day.today {
                            border-color: var(--accent-color, #2962ff);
                            box-shadow: 0 0 0 1px var(--accent-color, #2962ff);
                        }

                        .calendar-day.today .date-num {
                            color: var(--accent-color, #2962ff);
                        }

                        .calendar-day.selected {
                            background-color: rgba(41, 98, 255, 0.1) !important;
                            border-color: var(--accent-color, #2962ff);
                        }

                        .calendar-day .profit-usdt {
                            font-size: 0.85rem;
                            font-weight: 700;
                            text-align: right;
                            margin-top: auto;
                            margin-bottom: 4px;
                            letter-spacing: -0.5px;
                        }

                        .calendar-day .profit-usdt.plus {
                            color: #1261c4;
                        }

                        .calendar-day .profit-usdt.minus {
                            color: #c84a31;
                        }
                    </style>

                    <script>
                        const monthTargets = <?php echo json_encode($month_targets); ?>;
                        document.addEventListener('DOMContentLoaded', function() {
                            const dailyProfitData = <?php echo json_encode($daily_profit_data); ?>;
                            let currentCalDate = new Date();
                            <?php if (isset($_GET['cal_month']) && preg_match('/^\d{4}-\d{2}$/', $_GET['cal_month'])): ?>
                                const calParts = '<?php echo $_GET['cal_month']; ?>'.split('-');
                                currentCalDate = new Date(calParts[0], parseInt(calParts[1]) - 1, 1);
                            <?php endif; ?>

                            const okxStartDate = '<?php echo h($okx_start_date); ?>';
                            const okxEndDate = '<?php echo h($okx_end_date); ?>';
                            const isSingleDateSelected = (okxStartDate === okxEndDate) ? okxStartDate : null;
                            let originalOkxProfitHTML = '';

                            const okxProfitDisplayEl = document.getElementById('okxTotalProfitDisplay');
                            if (okxProfitDisplayEl) {
                                originalOkxProfitHTML = okxProfitDisplayEl.innerHTML;
                            }

                            window.openTargetProfitModal = function() {
                                const year = currentCalDate.getFullYear();
                                const month = currentCalDate.getMonth();
                                const targetMonthStr = `${year}-${String(month + 1).padStart(2, '0')}`;

                                document.getElementById('targetMonthLabel').innerText = `${year}년 ${month + 1}월 목표 수익`;
                                document.getElementById('targetMonthInput').value = targetMonthStr;

                                if (monthTargets[targetMonthStr] !== undefined) {
                                    document.getElementById('targetProfitInput').value = monthTargets[targetMonthStr];
                                } else {
                                    document.getElementById('targetProfitInput').value = '';
                                }

                                document.getElementById('targetProfitModal').style.display = 'flex';
                                setTimeout(() => document.getElementById('targetProfitInput').focus(), 100);
                            };

                            window.closeTargetProfitModal = function() {
                                document.getElementById('targetProfitModal').style.display = 'none';
                            };

                            window.saveTargetProfit = function(e) {
                                e.preventDefault();
                                const form = e.target;
                                const formData = new FormData(form);
                                formData.append('save_target_profit', '1');

                                fetch('index.php', {
                                        method: 'POST',
                                        body: formData
                                    })
                                    .then(response => response.json())
                                    .then(data => {
                                        if (data.success) {
                                            const targetMonthStr = document.getElementById('targetMonthInput').value;
                                            const targetProfitVal = document.getElementById('targetProfitInput').value;
                                            monthTargets[targetMonthStr] = parseFloat(targetProfitVal);
                                            renderCalendar();
                                            window.closeTargetProfitModal();
                                        } else {
                                            alert('저장 실패: ' + (data.message || '알 수 없는 오류'));
                                        }
                                    })
                                    .catch(error => {
                                        console.error('Error:', error);
                                        alert('서버 통신 오류가 발생했습니다.');
                                    });
                            };

                            function renderCalendar() {
                                const year = currentCalDate.getFullYear();
                                const month = currentCalDate.getMonth();

                                const monthLabel = document.getElementById('calMonthLabel');
                                const targetMonthStr = `${year}-${String(month + 1).padStart(2, '0')}`;
                                if (monthLabel) monthLabel.innerText = `${year}. ${String(month + 1).padStart(2, '0')}`;

                                const targetProfitDisplay = document.getElementById('targetProfitDisplay');
                                if (targetProfitDisplay) {
                                    if (monthTargets[targetMonthStr] !== undefined) {
                                        targetProfitDisplay.innerText = Number(monthTargets[targetMonthStr]).toLocaleString('ko-KR');
                                    } else {
                                        targetProfitDisplay.innerText = '-';
                                    }
                                }

                                const firstDay = new Date(year, month, 1).getDay();
                                const daysInMonth = new Date(year, month + 1, 0).getDate();

                                const calBody = document.getElementById('calendarBody');
                                if (!calBody) return;
                                calBody.innerHTML = '';

                                let totalMonthlyProfitUSDT = 0;
                                let totalMonthlyProfitKRW = 0;
                                let totalMonthlyTrades = 0;
                                let monthlyWins = 0;
                                let monthlyLosses = 0;

                                let weeklyProfitUSDT = 0;
                                let weeklyProfitKRW = 0;
                                let weeklyTrades = 0;
                                let weeklyWins = 0;
                                let weeklyLosses = 0;

                                const today = new Date();
                                const todayStr = `${today.getFullYear()}-${String(today.getMonth()+1).padStart(2,'0')}-${String(today.getDate()).padStart(2,'0')}`;

                                for (let i = 0; i < firstDay; i++) {
                                    const emptyDiv = document.createElement('div');
                                    emptyDiv.className = 'calendar-day empty';
                                    calBody.appendChild(emptyDiv);
                                }

                                for (let d = 1; d <= daysInMonth; d++) {
                                    const dateStr = `${year}-${String(month+1).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
                                    const dayOfWeek = new Date(year, month, d).getDay();

                                    const dayDiv = document.createElement('div');
                                    dayDiv.className = 'calendar-day';
                                    if (dayOfWeek === 0) dayDiv.classList.add('sun');
                                    if (dayOfWeek === 6) dayDiv.classList.add('sat');
                                    if (dateStr === todayStr) dayDiv.classList.add('today');
                                    if (dateStr === isSingleDateSelected) dayDiv.classList.add('selected');
                                    dayDiv.dataset.date = dateStr;
                                    dayDiv.onclick = handleDateClick;

                                    let html = `<div class="date-num">${d}</div>`;
                                    let profitHtml = `<div style="margin-top: auto; display: flex; flex-direction: column; gap: 2px; margin-bottom: 4px;">`;

                                    if (dailyProfitData[dateStr]) {
                                        const usdt = dailyProfitData[dateStr].USDT || 0;
                                        const krw = dailyProfitData[dateStr].KRW || 0;
                                        const trades = dailyProfitData[dateStr].trades || 0;
                                        const wins = dailyProfitData[dateStr].wins || 0;
                                        const losses = dailyProfitData[dateStr].losses || 0;

                                        totalMonthlyProfitUSDT += usdt;
                                        totalMonthlyProfitKRW += krw;
                                        totalMonthlyTrades += trades;
                                        monthlyWins += wins;
                                        monthlyLosses += losses;

                                        weeklyProfitUSDT += usdt;
                                        weeklyProfitKRW += krw;
                                        weeklyTrades += trades;
                                        weeklyWins += wins;
                                        weeklyLosses += losses;

                                        let hasProfit = false;

                                        if (krw !== 0) {
                                            hasProfit = true;
                                            const krwClass = krw > 0 ? 'plus' : 'minus';
                                            const sign = krw > 0 ? '+' : '';
                                            profitHtml += `<div class="profit-usdt ${krwClass}">${sign}${Math.round(krw).toLocaleString('ko-KR')}</div>`;
                                        }

                                        if (usdt !== 0) {
                                            hasProfit = true;
                                            const usdtClass = usdt > 0 ? 'plus' : 'minus';
                                            const sign = usdt > 0 ? '+' : '';
                                            profitHtml += `<div class="profit-usdt ${usdtClass}" style="font-size: 0.75rem;">${sign}${usdt.toFixed(2)}</div>`;
                                        }

                                        if (hasProfit) {
                                            if ((krw + (usdt * 1400)) > 0) {
                                                dayDiv.style.backgroundColor = 'rgba(18, 97, 196, 0.07)';
                                            } else {
                                                dayDiv.style.backgroundColor = 'rgba(200, 74, 49, 0.07)';
                                            }
                                        }
                                    }

                                    profitHtml += `</div>`;
                                    html += profitHtml;
                                    dayDiv.innerHTML = html;
                                    calBody.appendChild(dayDiv);

                                    // 주간 요약 추가
                                    if (dayOfWeek === 6 || d === daysInMonth) {
                                        if (d === daysInMonth && dayOfWeek !== 6) {
                                            for (let i = dayOfWeek + 1; i <= 6; i++) {
                                                const emptyDiv = document.createElement('div');
                                                emptyDiv.className = 'calendar-day empty';
                                                calBody.appendChild(emptyDiv);
                                            }
                                        }

                                        const weekSummaryDiv = document.createElement('div');
                                        weekSummaryDiv.className = 'calendar-day week-summary';
                                        weekSummaryDiv.style.backgroundColor = 'var(--table-bg-dark, #f8f9fa)';
                                        weekSummaryDiv.style.justifyContent = 'center';
                                        weekSummaryDiv.style.alignItems = 'center';
                                        weekSummaryDiv.style.borderStyle = 'dashed';
                                        weekSummaryDiv.style.cursor = 'pointer';

                                        let weekStartD = d - dayOfWeek;
                                        if (weekStartD < 1) weekStartD = 1;
                                        const wStartDate = `${year}-${String(month+1).padStart(2,'0')}-${String(weekStartD).padStart(2,'0')}`;
                                        const wEndDate = `${year}-${String(month+1).padStart(2,'0')}-${String(d).padStart(2,'0')}`;

                                        weekSummaryDiv.dataset.startDate = wStartDate;
                                        weekSummaryDiv.dataset.endDate = wEndDate;
                                        weekSummaryDiv.onclick = handleWeekClick;

                                        if (okxStartDate === wStartDate && okxEndDate === wEndDate) {
                                            weekSummaryDiv.classList.add('selected');
                                            weekSummaryDiv.style.backgroundColor = 'rgba(41, 98, 255, 0.1)';
                                            weekSummaryDiv.style.borderColor = 'var(--accent-color, #2962ff)';
                                        }

                                        let weekHtml = ``;
                                        let weekProfitHtml = `<div style="display: flex; flex-direction: column; gap: 2px; align-items: center; width: 100%;">`;
                                        let hasWeekProfit = false;

                                        if (weeklyProfitKRW !== 0) {
                                            hasWeekProfit = true;
                                            const krwClass = weeklyProfitKRW > 0 ? 'plus' : 'minus';
                                            const sign = weeklyProfitKRW > 0 ? '+' : '';
                                            weekProfitHtml += `<div class="profit-usdt ${krwClass}" style="text-align: center;">${sign}${Math.round(weeklyProfitKRW).toLocaleString('ko-KR')}</div>`;
                                        }
                                        if (weeklyProfitUSDT !== 0) {
                                            hasWeekProfit = true;
                                            const usdtClass = weeklyProfitUSDT > 0 ? 'plus' : 'minus';
                                            const sign = weeklyProfitUSDT > 0 ? '+' : '';
                                            weekProfitHtml += `<div class="profit-usdt ${usdtClass}" style="font-size: 0.75rem; text-align: center;">${sign}${weeklyProfitUSDT.toFixed(2)}</div>`;
                                        }
                                        if (!hasWeekProfit) {
                                            weekProfitHtml += `<div class="profit-usdt" style="color:var(--text-muted); text-align: center;"></div>`;
                                        }
                                        weekProfitHtml += `</div>`;

                                        weekSummaryDiv.innerHTML = weekHtml + weekProfitHtml;
                                        calBody.appendChild(weekSummaryDiv);

                                        weeklyProfitUSDT = 0;
                                        weeklyProfitKRW = 0;
                                        weeklyTrades = 0;
                                        weeklyWins = 0;
                                        weeklyLosses = 0;
                                    }
                                }

                                // Update monthly summary
                                const monthlySummaryEl = document.getElementById('monthlySummary');
                                if (monthlySummaryEl) {
                                    let summaryProfitHtml = '';
                                    if (totalMonthlyProfitKRW !== 0) {
                                        const profitColorKRW = totalMonthlyProfitKRW > 0 ? '#1261c4' : (totalMonthlyProfitKRW < 0 ? '#c84a31' : 'inherit');
                                        const profitSignKRW = totalMonthlyProfitKRW > 0 ? '+' : '';
                                        summaryProfitHtml += `<div style="color: ${profitColorKRW}; font-weight: 700;">${profitSignKRW}${Math.round(totalMonthlyProfitKRW).toLocaleString('ko-KR')}</div>`;
                                    }
                                    if (totalMonthlyProfitUSDT !== 0) {
                                        const profitColorUSDT = totalMonthlyProfitUSDT > 0 ? '#1261c4' : (totalMonthlyProfitUSDT < 0 ? '#c84a31' : 'inherit');
                                        const profitSignUSDT = totalMonthlyProfitUSDT > 0 ? '+' : '';
                                        summaryProfitHtml += `<div style="color: ${profitColorUSDT}; font-weight: 700; font-size: 0.8rem;">${profitSignUSDT}${totalMonthlyProfitUSDT.toFixed(2)}</div>`;
                                    }
                                    if (summaryProfitHtml === '') {
                                        summaryProfitHtml = `<div style="color: inherit; font-weight: 700;">0</div>`;
                                    }
                                    document.getElementById('summaryTotalProfit').innerHTML = summaryProfitHtml;
                                    document.getElementById('summaryTotalTrades').innerText = `${totalMonthlyTrades} 건`;

                                    const winLossColor = monthlyWins > monthlyLosses ? '#1261c4' : (monthlyLosses > monthlyWins ? '#c84a31' : 'inherit');
                                    document.getElementById('summaryWinLoss').innerHTML = `<span style="color: ${winLossColor};">${monthlyWins}승 ${monthlyLosses}패</span>`;

                                    let monthlyWinRate = 0;
                                    if (totalMonthlyTrades > 0) {
                                        monthlyWinRate = (monthlyWins / totalMonthlyTrades) * 100;
                                    }
                                    const winRateColor = monthlyWinRate >= 50 ? '#1261c4' : (monthlyWinRate < 50 ? '#c84a31' : 'inherit');
                                    document.getElementById('summaryWinRate').innerHTML = `<span style="color: ${winRateColor};">${monthlyWinRate.toFixed(2)}%</span>`;
                                }

                                const dailyStatsDisplay = document.getElementById('dailyStatsDisplay');
                                if (dailyStatsDisplay) {
                                    const today = new Date();
                                    const isCurrentMonth = year === today.getFullYear() && month === today.getMonth();
                                    const isPastMonth = currentCalDate < new Date(today.getFullYear(), today.getMonth(), 1);

                                    let elapsedDays = 0;
                                    if (isCurrentMonth) {
                                        elapsedDays = today.getDate();
                                    } else if (isPastMonth) {
                                        elapsedDays = daysInMonth;
                                    }

                                    let dailyAvgHtml = 'Daily Avg: -';
                                    let runRateHtml = 'Run Rate: -';
                                    let pacingHtml = 'Pacing: -';

                                    if (elapsedDays > 0) {
                                        const dailyAvg = totalMonthlyProfitUSDT / elapsedDays;
                                        const avgColor = dailyAvg > 0 ? '#1261c4' : (dailyAvg < 0 ? '#c84a31' : 'inherit');
                                        const avgSign = dailyAvg > 0 ? '+' : '';
                                        dailyAvgHtml = `Daily Avg: <span style="color: ${avgColor};">${avgSign}${dailyAvg.toFixed(2)}</span>`;

                                        const projectedProfit = dailyAvg * daysInMonth;
                                        const projectedColor = projectedProfit > 0 ? '#1261c4' : (projectedProfit < 0 ? '#c84a31' : 'inherit');
                                        const projectedSign = projectedProfit > 0 ? '+' : '';
                                        runRateHtml = `Run Rate: <span style="color: ${projectedColor};">${projectedSign}${projectedProfit.toFixed(2)}</span>`;

                                        const targetProfit = monthTargets[targetMonthStr];
                                        if (targetProfit !== undefined && targetProfit > 0) {
                                            const timeElapsedRate = elapsedDays / daysInMonth;
                                            const profitAchievementRate = totalMonthlyProfitUSDT / targetProfit;
                                            let pacing = 0;
                                            if (timeElapsedRate > 0) {
                                                pacing = (profitAchievementRate / timeElapsedRate) * 100;
                                            }
                                            const pacingColor = pacing >= 100 ? '#1261c4' : (pacing > 0 ? '#c84a31' : 'inherit');
                                            pacingHtml = `Pacing: <span style="color: ${pacingColor};">${pacing.toFixed(2)}%</span>`;
                                        }
                                    }

                                    // Combine all into one span with separators
                                    dailyStatsDisplay.innerHTML = `${dailyAvgHtml} &nbsp; ${runRateHtml} &nbsp; ${pacingHtml}`;
                                }
                            }

                            const prevBtn = document.getElementById('calPrevMonth');
                            const nextBtn = document.getElementById('calNextMonth');
                            if (prevBtn && nextBtn) {
                                prevBtn.addEventListener('click', () => {
                                    currentCalDate.setMonth(currentCalDate.getMonth() - 1);
                                    renderCalendar();
                                });
                                nextBtn.addEventListener('click', () => {
                                    currentCalDate.setMonth(currentCalDate.getMonth() + 1);
                                    renderCalendar();
                                });
                            }

                            renderCalendar();
                        });

                        function handleDateClick(event) {
                            const clickedDayDiv = event.currentTarget;
                            const date = clickedDayDiv.dataset.date;

                            const form = document.getElementById('okxDateFilterForm');
                            if (form) {
                                const startDateInput = form.querySelector('input[name="okx_start_date"]');
                                const endDateInput = form.querySelector('input[name="okx_end_date"]');

                                if (startDateInput && endDateInput) {
                                    startDateInput.value = date;
                                    endDateInput.value = date;

                                    let calMonthInput = form.querySelector('input[name="cal_month"]');
                                    if (!calMonthInput) {
                                        calMonthInput = document.createElement('input');
                                        calMonthInput.type = 'hidden';
                                        calMonthInput.name = 'cal_month';
                                        form.appendChild(calMonthInput);
                                    }
                                    calMonthInput.value = date.substring(0, 7);

                                    form.submit();
                                }
                            }
                        }

                        function handleWeekClick(event) {
                            const clickedDiv = event.currentTarget;
                            const startDate = clickedDiv.dataset.startDate;
                            const endDate = clickedDiv.dataset.endDate;

                            const form = document.getElementById('okxDateFilterForm');
                            if (form) {
                                const startDateInput = form.querySelector('input[name="okx_start_date"]');
                                const endDateInput = form.querySelector('input[name="okx_end_date"]');

                                if (startDateInput && endDateInput) {
                                    startDateInput.value = startDate;
                                    endDateInput.value = endDate;

                                    let calMonthInput = form.querySelector('input[name="cal_month"]');
                                    if (!calMonthInput) {
                                        calMonthInput = document.createElement('input');
                                        calMonthInput.type = 'hidden';
                                        calMonthInput.name = 'cal_month';
                                        form.appendChild(calMonthInput);
                                    }
                                    calMonthInput.value = startDate.substring(0, 7);

                                    form.submit();
                                }
                            }
                        }
                    </script>
                </div>
                <!-- 일별 수익 달력 끝 -->

                <div class="card card-top-black" style="flex: 2; box-sizing: border-box; display: flex; flex-direction: column; justify-content: flex-start; padding: 24px;">
                    <div class="card-stats-item" style="padding: 0; margin-bottom: 25px; align-items: flex-start; width: 100%;">
                        <div>
                            <span style="color: var(--text-muted); font-size: 0.75rem; font-weight: bold; display: block; margin-bottom: 10px;">ESTIMATED TOTAL VALUE</span>
                            <div class="stat-value" style="font-size: 1.8rem; font-weight: 800; line-height: 1;"><?php echo number_format($okx_total_eq, 2); ?> <span style="font-size: 1rem; color: var(--text-muted); font-weight: bold; display: inline-block; margin-bottom: 0;">USDT</span></div>
                        </div>
                        <div class="stat-icon" style="color: var(--border-color); font-size: 2em; flex-shrink: 0;"><i class="fa-solid fa-wallet"></i></div>
                    </div>

                    <div style="display: flex; justify-content: space-between; align-items: center; font-size: 1rem; font-weight: bold; color: var(--text-main); padding-bottom: 10px;">
                        <span>Overview</span>
                        <span style="text-align: right;">TOTAL</span>
                    </div>

                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                        <div style="font-size: 0.9rem; color: var(--text-muted); font-weight: bold;">전체 수익</div>
                        <div style="font-size: 1.1rem; font-weight: bold; color: <?php echo $overall_okx_profit > 0 ? '#1261c4' : ($overall_okx_profit < 0 ? '#c84a31' : 'inherit'); ?>;">
                            <?php echo $overall_okx_profit > 0 ? '+' : ''; ?><?php echo number_format($overall_okx_profit, 2); ?> USDT
                        </div>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                        <div style="font-size: 0.9rem; color: var(--text-muted); font-weight: bold;">총 건수</div>
                        <div style="font-size: 1rem; font-weight: bold; color: var(--text-main);"><?php echo number_format($overall_okx_trades); ?> 건</div>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                        <div style="font-size: 0.9rem; color: var(--text-muted); font-weight: bold;">승패</div>
                        <div style="font-size: 1rem; font-weight: bold; color: <?php echo $overall_okx_wins > $overall_okx_losses ? '#1261c4' : ($overall_okx_losses > $overall_okx_wins ? '#c84a31' : 'inherit'); ?>;">
                            <?php echo $overall_okx_wins; ?>승 <?php echo $overall_okx_losses; ?>패
                        </div>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <div style="font-size: 0.9rem; color: var(--text-muted); font-weight: bold;">승률</div>
                        <div style="font-size: 1rem; font-weight: bold; color: <?php echo $overall_okx_win_rate >= 50 ? '#1261c4' : ($overall_okx_win_rate > 0 ? '#c84a31' : 'inherit'); ?>;">
                            <?php echo number_format($overall_okx_win_rate, 2); ?>%
                        </div>
                    </div>
                </div>
            </div>

            <div class="card" style="padding: 24px; box-sizing: border-box;">
                <div class="section-header" style="display: flex; align-items: center;">
                    <h3 style="margin: 0;"><i class="fa-solid fa-clipboard-list"></i> OKX 매매 일지</h3>
                    <form id="okxDateFilterForm" action="index.php" method="GET" style="margin-left: 20px; display: flex; align-items: center; gap: 5px; margin-bottom: 0;">
                        <?php if (!empty($wiki_search)): ?>
                            <input type="hidden" name="wiki_search" value="<?php echo h($wiki_search); ?>">
                        <?php endif; ?>
                        <input type="date" name="okx_start_date" value="<?php echo h($okx_start_date); ?>" class="input-field" style="padding: 4px 8px; font-size: 0.85rem; width: auto;" onchange="this.form.submit()">
                        <span>~</span>
                        <input type="date" name="okx_end_date" value="<?php echo h($okx_end_date); ?>" class="input-field" style="padding: 4px 8px; font-size: 0.85rem; width: auto;" onchange="this.form.submit()">
                    </form>
                    <div id="okxTotalProfitDisplay" style="margin-left: auto; margin-right: 15px; font-size: 0.95rem;">
                        <?php
                        if (!empty($okx_trade_results)) {
                            $okx_profit_color = $list_total_okx_profit > 0 ? '#1261c4' : ($list_total_okx_profit < 0 ? '#c84a31' : 'inherit');
                            echo '<span style="font-weight: bold; color: ' . $okx_profit_color . ';">';
                            $total_str = sprintf('%.6F', $list_total_okx_profit);
                            $truncated_total = substr($total_str, 0, strpos($total_str, '.') + 3);
                            echo '총수익: ' . number_format((float)$truncated_total, 2) . ' USDT';
                            echo '</span>';
                        }
                        ?>
                    </div>
                </div>
                <?php if (empty($okx_trade_results)): ?>
                    <div class="empty-state-card-content" style="padding: 40px 20px; text-align: center;">
                        <p style="margin-bottom: 20px; color: #666;">기록된 매매 데이터가 없습니다.</p>
                    </div>
                <?php else: ?>
                    <div id="okxTableContainer" style="overflow-x: auto;">
                        <table class="data-table" style="width: 100%; font-size: 0.9rem; table-layout: fixed; min-width: 1260px;">
                            <colgroup>
                                <col style="width: 120px;"> <!-- 매매일 -->
                                <col style="width: 100px;"> <!-- 코인 -->
                                <col style="width: 80px;"> <!-- 롱/숏 -->
                                <col style="width: 80px;"> <!-- 레버리지 -->
                                <col style="width: 100px;"> <!-- 진입금액 -->
                                <col style="width: 100px;"> <!-- 수익 (USDT) -->
                                <col style="width: 100px;"> <!-- 전략 -->
                                <col style="width: 100px;"> <!-- 근거 -->
                                <col style="width: 100px;"> <!-- 심리 -->
                                <col style="width: 100px;"> <!-- 패착 -->
                                <col style="width: 80px;"> <!-- 트레이딩뷰 -->
                                <col> <!-- 분석 -->
                            </colgroup>
                            <thead>
                                <tr>
                                    <th class="text-center" style="white-space: nowrap;">매매일</th>
                                    <th class="text-center" style="white-space: nowrap;">코인</th>
                                    <th class="text-center" style="white-space: nowrap;">롱/숏</th>
                                    <th class="text-center" style="white-space: nowrap;">Lever</th>
                                    <th class="text-center" style="white-space: nowrap;">진입금액</th>
                                    <th style="text-align: right; white-space: nowrap;">수익 (수익율)</th>
                                    <th class="text-center" style="white-space: nowrap;">전략</th>
                                    <th class="text-center" style="white-space: nowrap;">근거</th>
                                    <th class="text-center" style="white-space: nowrap;">심리</th>
                                    <th class="text-center" style="white-space: nowrap;">패착</th>
                                    <th class="text-center" style="white-space: nowrap;">차트</th>
                                    <th class="text-center" style="white-space: nowrap;">복기</th>
                                </tr>
                            </thead>
                            <tbody id="okxTradeLogBody">
                                <?php foreach ($okx_trade_results as $trade):
                                    $orders_json = json_encode($okx_orders_by_id[$trade['ord_id']] ?? []);

                                    $pnl_for_rate = (float)$trade['realized_pnl'];
                                    $cost_for_rate = $trade['cost'] ?? 0;
                                    $lever_for_rate = (float)($trade['lever'] ?? 1);
                                    $lever_for_rate = $lever_for_rate > 0 ? $lever_for_rate : 1;
                                    $margin_for_rate = $cost_for_rate / $lever_for_rate;
                                    $trade_profit_rate = ($margin_for_rate > 0) ? ($pnl_for_rate / abs($margin_for_rate)) * 100 : 0;
                                ?>
                                    <tr class="trade-row"
                                        data-type="okx"
                                        data-uuid="<?php echo h($trade['ord_id']); ?>"
                                        data-memo="<?php echo h($trade['memo'] ?? ''); ?>"
                                        data-tradingview-url="<?php echo h($trade['tradingview_url'] ?? ''); ?>"
                                        data-strategy-name="<?php echo h($trade['strategy_name'] ?? ''); ?>"
                                        data-entry-reason="<?php echo h($trade['entry_reason'] ?? ''); ?>"
                                        data-psychology-state="<?php echo h($trade['psychology_state'] ?? ''); ?>"
                                        data-failure-factor="<?php echo h($trade['failure_factor'] ?? ''); ?>"
                                        data-market="<?php echo h(str_replace(['-SWAP', '-USDT'], '', $trade['inst_id'])); ?>"
                                        data-buy-time="<?php echo h(date('Y-m-d H:i:s', $trade['first_ts'] / 1000)); ?>"
                                        data-sell-time="<?php echo h(date('Y-m-d H:i:s', $trade['ts'] / 1000)); ?>"
                                        data-profit="<?php echo h($trade['realized_pnl']); ?>"
                                        data-profit-rate="<?php echo h($trade_profit_rate); ?>"
                                        data-orders="<?php echo htmlspecialchars($orders_json, ENT_QUOTES, 'UTF-8'); ?>"
                                        onclick="openTradeModal(this)"
                                        style="cursor: pointer; transition: background-color 0.2s;"
                                        onmouseover="this.style.backgroundColor='#f5f5f5'"
                                        onmouseout="this.style.backgroundColor='transparent'">
                                        <td class="text-center" style="white-space: nowrap; font-size: 14px;"><?php echo date('m/d H:i', $trade['ts'] / 1000); ?></td>
                                        <td class="text-center" style="font-size: 14px; font-weight: bold;"><?php echo h(str_replace(['-SWAP', '-USDT'], '', $trade['inst_id'])); ?></td>
                                        <?php
                                        $pos_side = h($trade['pos_side'] ?? 'net');
                                        $pos_side_display = ucfirst($pos_side);
                                        $pos_side_color = 'inherit';
                                        if ($pos_side === 'long') {
                                            $pos_side_color = '#1261c4'; // 파란색
                                        } elseif ($pos_side === 'short') {
                                            $pos_side_color = '#c84a31'; // 빨간색
                                        }
                                        ?>
                                        <td class="text-center" style="font-size: 14px; font-weight: bold; color: <?php echo $pos_side_color; ?>;"><?php echo $pos_side_display; ?></td>
                                        <td class="text-center" style="font-size: 14px; color: #555; font-weight: bold;">x<?php echo h($trade['lever'] ?? '1'); ?></td>
                                        <td class="text-center" style="font-size: 14px; color: #555; font-weight: bold;">
                                            <?php
                                            $cost = $trade['cost'] ?? 0;
                                            $lever = (float)($trade['lever'] ?? 1);
                                            $lever = $lever > 0 ? $lever : 1;
                                            $margin = $cost / $lever; // 레버리지가 배제된 실제 진입 증거금
                                            echo number_format($margin, 2);
                                            ?>
                                        </td>
                                        <td style="text-align: right; font-size: 14px; font-weight: bold; white-space: nowrap; color: <?php echo $trade['realized_pnl'] > 0 ? '#1261c4' : ($trade['realized_pnl'] < 0 ? '#c84a31' : 'inherit'); ?>;">
                                            <?php
                                            $pnl_str = sprintf('%.6F', (float)$trade['realized_pnl']);
                                            $truncated_pnl = substr($pnl_str, 0, strpos($pnl_str, '.') + 3);
                                            echo number_format((float)$truncated_pnl, 2);

                                            $pnl = (float)$trade['realized_pnl'];
                                            // 수익율 = 실현손익 / 실제 증거금 * 100
                                            $profit_rate = ($margin > 0) ? ($pnl / abs($margin)) * 100 : 0;
                                            echo '<br><span style="font-size: 12px;">(' . sprintf('%.2f', $profit_rate) . '%)</span>';
                                            ?>
                                        </td>
                                        <td class="text-center" style="vertical-align: middle; padding-left: 2px; padding-right: 2px;">
                                            <?php if (!empty(trim($trade['strategy_name'] ?? ''))): ?>
                                                <div style="display: flex; flex-direction: column; gap: 2px; align-items: center; justify-content: center; height: 100%;">
                                                    <?php foreach (explode(',', $trade['strategy_name']) as $tag): $tag = trim($tag);
                                                        if ($tag !== ''): ?>
                                                            <span style="padding: 2px 4px; font-size: 13px; border-radius: 4px; background-color: #eef2ff; color: #4f46e5; border: 1px solid #c7d2fe; white-space: nowrap; line-height: 1.2; box-shadow: 0 1px 2px rgba(0,0,0,0.05); font-weight: 600; display: inline-block; cursor: default;"><?php echo h($tag); ?></span>
                                                    <?php endif;
                                                    endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center" style="vertical-align: middle; padding-left: 2px; padding-right: 2px;">
                                            <?php if (!empty(trim($trade['entry_reason'] ?? ''))): ?>
                                                <div style="display: flex; flex-direction: column; gap: 2px; align-items: center; justify-content: center; height: 100%;">
                                                    <?php foreach (explode(',', $trade['entry_reason']) as $tag): $tag = trim($tag);
                                                        if ($tag !== ''): ?>
                                                            <span style="padding: 2px 4px; font-size: 13px; border-radius: 4px; background-color: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; white-space: nowrap; line-height: 1.2; box-shadow: 0 1px 2px rgba(0,0,0,0.05); font-weight: 600; display: inline-block; cursor: default;"><?php echo h($tag); ?></span>
                                                    <?php endif;
                                                    endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center" style="vertical-align: middle; padding-left: 2px; padding-right: 2px;">
                                            <?php if (!empty(trim($trade['psychology_state'] ?? ''))): ?>
                                                <div style="display: flex; flex-direction: column; gap: 2px; align-items: center; justify-content: center; height: 100%;">
                                                    <?php foreach (explode(',', $trade['psychology_state']) as $tag): $tag = trim($tag);
                                                        if ($tag !== ''): ?>
                                                            <span style="padding: 2px 4px; font-size: 13px; border-radius: 4px; background-color: #fffbeb; color: #d97706; border: 1px solid #fde68a; white-space: nowrap; line-height: 1.2; box-shadow: 0 1px 2px rgba(0,0,0,0.05); font-weight: 600; display: inline-block; cursor: default;"><?php echo h($tag); ?></span>
                                                    <?php endif;
                                                    endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center" style="vertical-align: middle; padding-left: 2px; padding-right: 2px;">
                                            <?php if (!empty(trim($trade['failure_factor'] ?? ''))): ?>
                                                <div style="display: flex; flex-direction: column; gap: 2px; align-items: center; justify-content: center; height: 100%;">
                                                    <?php foreach (explode(',', $trade['failure_factor']) as $tag): $tag = trim($tag);
                                                        if ($tag !== ''): ?>
                                                            <span style="padding: 2px 4px; font-size: 13px; border-radius: 4px; background-color: #fff1f2; color: #e11d48; border: 1px solid #fecdd3; white-space: nowrap; line-height: 1.2; box-shadow: 0 1px 2px rgba(0,0,0,0.05); font-weight: 600; display: inline-block; cursor: default;"><?php echo h($tag); ?></span>
                                                    <?php endif;
                                                    endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center" style="font-size: 14px;">
                                            <?php if (!empty($trade['tradingview_url'])): ?>
                                                <a href="<?php echo h($trade['tradingview_url']); ?>" target="_blank" onclick="event.stopPropagation();" title="트레이딩뷰 차트 보기">
                                                    <i class="fa-solid fa-chart-line" style="color: #555;"></i>
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-left" style="font-size: 13px; word-break: break-word; padding: 10px; line-height: 1.4;">
                                            <?php echo nl2br(h($trade['memo'] ?? '')); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div class="card section-margin-top card-top-accent" style="display: none;">
                <div style="text-align: center; padding-top: 15px; font-weight: 900; font-size: 1.1rem; color: #093687; letter-spacing: 1px;">
                    UPBIT
                </div>
                <div style="display: flex; justify-content: space-between; align-items: stretch; text-align: center; padding: 1.2rem 0.5rem;">
                    <!-- 최근 7일 -->
                    <div style="flex: 1; border-right: 1px solid var(--border-color); padding: 0 5px; display: flex; flex-direction: column; justify-content: center;">
                        <span style="color: var(--text-muted); font-size: 0.8rem; font-weight: bold; display: block; margin-bottom: 5px;">최근 7일</span>
                        <?php
                        $total_seven_days_profit_rate = ($total_seven_days_buy_settle > 0) ? ($total_seven_days_profit / $total_seven_days_buy_settle) * 100 : 0;
                        $profit_color_7d = $total_seven_days_profit > 0 ? '#1261c4' : ($total_seven_days_profit < 0 ? '#c84a31' : 'inherit');
                        $sign_7d = $total_seven_days_profit > 0 ? '+' : '';
                        ?>
                        <div class="stat-value" style="font-size: 1.35rem; line-height: 1.2; color: <?php echo $profit_color_7d; ?>;">
                            <?php echo format_num($total_seven_days_profit); ?>
                            <div style="font-size: 0.85rem; font-weight: 600; margin-top: 2px;">(<?php
                                                                                                    $rate_str = sprintf('%.8F', $total_seven_days_profit_rate);
                                                                                                    $truncated_rate = substr($rate_str, 0, strpos($rate_str, '.') + 3);
                                                                                                    echo $sign_7d . $truncated_rate;
                                                                                                    ?>%)</div>
                        </div>
                    </div>

                    <!-- 최근 한달 -->
                    <div style="flex: 1; border-right: 1px solid var(--border-color); padding: 0 5px; display: flex; flex-direction: column; justify-content: center;">
                        <span style="color: var(--text-muted); font-size: 0.8rem; font-weight: bold; display: block; margin-bottom: 5px;">최근 한달</span>
                        <?php
                        $total_one_month_profit_rate = ($total_one_month_buy_settle > 0) ? ($total_one_month_profit / $total_one_month_buy_settle) * 100 : 0;
                        $profit_color_1m = $total_one_month_profit > 0 ? '#1261c4' : ($total_one_month_profit < 0 ? '#c84a31' : 'inherit');
                        $sign_1m = $total_one_month_profit > 0 ? '+' : '';
                        ?>
                        <div class="stat-value" style="font-size: 1.35rem; line-height: 1.2; color: <?php echo $profit_color_1m; ?>;">
                            <?php echo format_num($total_one_month_profit); ?>
                            <div style="font-size: 0.85rem; font-weight: 600; margin-top: 2px;">(<?php
                                                                                                    $rate_str = sprintf('%.8F', $total_one_month_profit_rate);
                                                                                                    $truncated_rate = substr($rate_str, 0, strpos($rate_str, '.') + 3);
                                                                                                    echo $sign_1m . $truncated_rate;
                                                                                                    ?>%)</div>
                        </div>
                    </div>

                    <!-- 이번달 -->
                    <div style="flex: 1; border-right: 1px solid var(--border-color); padding: 0 5px; display: flex; flex-direction: column; align-items: center; justify-content: center;">
                        <div style="display: flex; align-items: center; justify-content: center; gap: 8px; margin-bottom: 5px;">
                            <button type="button" id="prevMonthBtn" style="border: none; background: none; cursor: pointer; color: var(--text-muted); padding: 0; font-size: 0.8rem;"><i class="fa-solid fa-chevron-left"></i></button>
                            <span id="currentMonthLabel" style="color: var(--text-muted); font-size: 0.8rem; font-weight: bold; display: block; white-space: nowrap;">2026.03</span>
                            <button type="button" id="nextMonthBtn" style="border: none; background: none; cursor: pointer; color: var(--text-muted); padding: 0; font-size: 0.8rem;"><i class="fa-solid fa-chevron-right"></i></button>
                        </div>
                        <div class="stat-value" id="monthlyProfitValue" style="font-size: 1.35rem; line-height: 1.2;">
                            0
                            <div style="font-size: 0.85rem; font-weight: 600; margin-top: 2px;" id="monthlyProfitRate">(+0.00%)</div>
                        </div>
                    </div>

                    <!-- TOTAL -->
                    <div style="flex: 1; padding: 0 5px; display: flex; flex-direction: column; justify-content: center;">
                        <span style="color: var(--text-muted); font-size: 0.8rem; font-weight: bold; display: block; margin-bottom: 5px;">TOTAL</span>
                        <?php
                        $total_profit_rate_overall = ($total_buy_settle > 0) ? ($total_profit / $total_buy_settle) * 100 : 0;
                        $total_color = $total_profit > 0 ? '#1261c4' : ($total_profit < 0 ? '#c84a31' : 'inherit');
                        $total_sign = $total_profit > 0 ? '+' : '';
                        ?>
                        <div class="stat-value" style="font-size: 1.35rem; line-height: 1.2; color: <?php echo $total_color; ?>;">
                            <?php echo format_num($total_profit); ?>
                            <div style="font-size: 0.85rem; font-weight: 600; margin-top: 2px;">(<?php
                                                                                                    $rate_str = sprintf('%.8F', $total_profit_rate_overall);
                                                                                                    $truncated_rate = substr($rate_str, 0, strpos($rate_str, '.') + 3);
                                                                                                    echo $total_sign . $truncated_rate;
                                                                                                    ?>%)</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card section-margin-top" style="padding: 24px; display: none;">
                <div class="section-header" style="display: flex; align-items: center;">
                    <h3 style="color: royalblue; margin: 0;"><i class="fa-solid fa-clipboard-list"></i> Upbit 매매 일지 (최근 30건)</h3>
                    <div style="margin-left: auto; margin-right: 15px; font-size: 0.95rem;">
                        <?php
                        if (!empty($upbit_trade_results)) {
                            $total_profit_rate = ($list_total_buy_settle > 0) ? ($list_total_profit / $list_total_buy_settle) * 100 : 0;
                            $profit_color = $list_total_profit > 0 ? '#1261c4' : ($list_total_profit < 0 ? '#c84a31' : 'inherit');
                            $rate_str = sprintf('%.8F', $total_profit_rate);
                            $truncated_rate = substr($rate_str, 0, strpos($rate_str, '.') + 3);
                            echo '<span style="font-weight: bold; color: ' . $profit_color . ';">';
                            echo '총수익: ' . format_num($list_total_profit) . ' (' . $truncated_rate . '%)';
                            echo '</span>';
                        }
                        ?>
                    </div>
                </div>
                <?php if (empty($upbit_trade_results)): ?>
                    <div class="empty-state-card-content" style="padding: 40px 20px; text-align: center;">
                        <p style="margin-bottom: 20px; color: #666;">기록된 매매 데이터가 없습니다.</p>
                    </div>
                <?php else: ?>
                    <div style="overflow-x: auto;">
                        <table class="data-table" style="width: 100%; font-size: 0.9rem;">
                            <thead>
                                <tr>
                                    <th class="text-center" style="white-space: nowrap;">매매일</th>
                                    <th class="text-center" style="white-space: nowrap;">코인</th>
                                    <th class="text-center" style="white-space: nowrap;">전략</th>
                                    <th class="text-center" style="white-space: nowrap;">근거</th>
                                    <th class="text-center" style="white-space: nowrap;">심리</th>
                                    <th class="text-center" style="white-space: nowrap;">패착</th>
                                    <th style="text-align: right; white-space: nowrap;">수익 (수익율)</th>
                                    <th class="text-center" style="white-space: nowrap;">분석</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($upbit_trade_results as $trade): ?>
                                    <tr class="trade-row"
                                        data-uuid="<?php echo h($trade['uuid']); ?>"
                                        data-memo="<?php echo h($trade['memo']); ?>"
                                        data-tradingview-url="<?php echo h($trade['tradingview_url']); ?>"
                                        data-strategy-name="<?php echo h($trade['strategy_name']); ?>"
                                        data-entry-reason="<?php echo h($trade['entry_reason']); ?>"
                                        data-psychology-state="<?php echo h($trade['psychology_state']); ?>"
                                        data-failure-factor="<?php echo h($trade['failure_factor']); ?>"
                                        data-market="<?php echo h($trade['market']); ?>"
                                        data-buy-time="<?php echo h($trade['created_at']); ?>"
                                        data-sell-time="<?php echo h($trade['last_ask_time']); ?>"
                                        data-buy-settle="<?php echo h($trade['total_buy_settle']); ?>"
                                        data-profit="<?php echo h($trade['profit']); ?>"
                                        data-profit-rate="<?php echo h($trade['profit_rate']); ?>"
                                        data-orders="<?php echo htmlspecialchars(json_encode($trade['orders']), ENT_QUOTES, 'UTF-8'); ?>"
                                        onclick="openTradeModal(this)"
                                        style="cursor: pointer; transition: background-color 0.2s;"
                                        onmouseover="this.style.backgroundColor='#f5f5f5'"
                                        onmouseout="this.style.backgroundColor='transparent'">
                                        <td class="text-center" style="white-space: nowrap; font-size: 14px;"><?php echo date('d일 H시', strtotime($trade['created_at'])); ?></td>
                                        <td class="text-center" style="font-size: 14px;"><?php echo h(str_replace('KRW-', '', $trade['market'])); ?></td>
                                        <td class="text-center" style="vertical-align: middle; padding-left: 2px; padding-right: 2px;">
                                            <?php if (!empty(trim($trade['strategy_name']))): ?>
                                                <div style="display: flex; flex-direction: column; gap: 2px; align-items: center; justify-content: center; height: 100%;">
                                                    <?php foreach (explode(',', $trade['strategy_name']) as $tag): $tag = trim($tag);
                                                        if ($tag !== ''): ?>
                                                            <span style="padding: 2px 4px; font-size: 13px; border-radius: 4px; background-color: #eef2ff; color: #4f46e5; border: 1px solid #c7d2fe; white-space: nowrap; line-height: 1.2; box-shadow: 0 1px 2px rgba(0,0,0,0.05); font-weight: 600; display: inline-block; cursor: default;"><?php echo h($tag); ?></span>
                                                    <?php endif;
                                                    endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center" style="vertical-align: middle; padding-left: 2px; padding-right: 2px;">
                                            <?php if (!empty(trim($trade['entry_reason']))): ?>
                                                <div style="display: flex; flex-direction: column; gap: 2px; align-items: center; justify-content: center; height: 100%;">
                                                    <?php foreach (explode(',', $trade['entry_reason']) as $tag): $tag = trim($tag);
                                                        if ($tag !== ''): ?>
                                                            <span style="padding: 2px 4px; font-size: 13px; border-radius: 4px; background-color: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; white-space: nowrap; line-height: 1.2; box-shadow: 0 1px 2px rgba(0,0,0,0.05); font-weight: 600; display: inline-block; cursor: default;"><?php echo h($tag); ?></span>
                                                    <?php endif;
                                                    endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center" style="vertical-align: middle; padding-left: 2px; padding-right: 2px;">
                                            <?php if (!empty(trim($trade['psychology_state']))): ?>
                                                <div style="display: flex; flex-direction: column; gap: 2px; align-items: center; justify-content: center; height: 100%;">
                                                    <?php foreach (explode(',', $trade['psychology_state']) as $tag): $tag = trim($tag);
                                                        if ($tag !== ''): ?>
                                                            <span style="padding: 2px 4px; font-size: 13px; border-radius: 4px; background-color: #fffbeb; color: #d97706; border: 1px solid #fde68a; white-space: nowrap; line-height: 1.2; box-shadow: 0 1px 2px rgba(0,0,0,0.05); font-weight: 600; display: inline-block; cursor: default;"><?php echo h($tag); ?></span>
                                                    <?php endif;
                                                    endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center" style="vertical-align: middle; padding-left: 2px; padding-right: 2px;">
                                            <?php if (!empty(trim($trade['failure_factor']))): ?>
                                                <div style="display: flex; flex-direction: column; gap: 2px; align-items: center; justify-content: center; height: 100%;">
                                                    <?php foreach (explode(',', $trade['failure_factor']) as $tag): $tag = trim($tag);
                                                        if ($tag !== ''): ?>
                                                            <span style="padding: 2px 4px; font-size: 13px; border-radius: 4px; background-color: #fff1f2; color: #e11d48; border: 1px solid #fecdd3; white-space: nowrap; line-height: 1.2; box-shadow: 0 1px 2px rgba(0,0,0,0.05); font-weight: 600; display: inline-block; cursor: default;"><?php echo h($tag); ?></span>
                                                    <?php endif;
                                                    endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: right; font-size: 14px; white-space: nowrap; color: <?php echo $trade['profit'] > 0 ? '#1261c4' : ($trade['profit'] < 0 ? '#c84a31' : 'inherit'); ?>;">
                                            <?php echo format_num($trade['profit']); ?>
                                            <span style="font-size: 12px; margin-left: 2px;">(<?php
                                                                                                $rate_str = sprintf('%.8F', $trade['profit_rate']);
                                                                                                $truncated_rate = substr($rate_str, 0, strpos($rate_str, '.') + 3);
                                                                                                echo $truncated_rate; ?>%)</span>
                                        </td>
                                        <td class="text-left" style="font-size: 13px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 200px; padding: 0 10px;" title="<?php echo h($trade['memo'] ?? ''); ?>">
                                            <?php echo h($trade['memo'] ?? ''); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div><!-- .row -->
</div><!-- .main-content-wrapper -->

<!-- 목표 수익 설정 모달 -->
<div id="targetProfitModal" style="display: none; position: fixed; z-index: 10000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); align-items: center; justify-content: center;">
    <div style="background-color: #fff; padding: 25px; border-radius: 12px; width: 90%; max-width: 400px; box-shadow: 0 10px 25px rgba(0,0,0,0.2);">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #f0f0f0; padding-bottom: 15px; margin-bottom: 15px;">
            <h3 style="margin: 0; color: #333; font-size: 1.2rem;"><i class="fa-solid fa-bullseye" style="color: #555; margin-right: 8px;"></i> Target Profit 설정</h3>
            <button type="button" onclick="closeTargetProfitModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #666;">&times;</button>
        </div>
        <form id="targetProfitForm" onsubmit="window.saveTargetProfit(event)">
            <input type="hidden" id="targetMonthInput" name="target_month">
            <div style="margin-bottom: 20px;">
                <label id="targetMonthLabel" style="display: block; font-weight: bold; margin-bottom: 10px; color: #333; text-align: center; font-size: 1.1rem;"></label>
                <input type="number" step="any" id="targetProfitInput" name="target_profit" class="input-field" placeholder="목표 수익 금액 입력" required style="text-align: right; font-size: 1.1rem;">
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="submit" class="button-primary" style="padding: 10px 20px; width: 100%;">저장</button>
            </div>
        </form>
    </div>
</div>

<!-- 매매 상세 정보 모달 -->
<div id="tradeModal" style="display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); align-items: center; justify-content: center;">
    <div style="background-color: #fff; padding: 25px; border-radius: 12px; width: 90%; max-width: 1200px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); position: relative; max-height: 90vh; overflow-y: auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #f0f0f0; padding-bottom: 15px; margin-bottom: 15px;">
            <h3 id="modalMarketName" style="margin-top: 0; color: #333; font-size: 1.4rem; margin-bottom: 0px;"><i class="fa-solid fa-receipt" style="color: #555; margin-right: 8px;"></i> 매매 상세</h3>
            <div style="text-align: right; display: flex; align-items: center;">
                <strong id="modalProfit" style="font-size: 1.1rem;"></strong>
                <strong id="modalProfitRate" style="font-size: 1.1rem; margin-left: 15px;"></strong>
                <a href="#" id="viewChartBtn" target="_blank" class="button-secondary" style="margin-left: 15px; padding: 5px 10px; font-size: 0.9rem; text-decoration: none;">차트 보기</a>
            </div>
        </div>

        <div style="display: flex; flex-wrap: wrap; gap: 20px;">
            <!-- 좌측 영역 (비율 2) -->
            <div style="flex: 2; min-width: 600px;">
                <div style="overflow-x: auto;">
                    <table class="data-table" style="width: 100%; min-width: 600px; font-size: 0.9rem; table-layout: fixed; border-bottom: 1px solid #e0e3eb;">
                        <colgroup>
                            <col style="width: 150px;"> <!-- 일시 -->
                            <col style="width: 60px;"> <!-- 구분 -->
                            <col style="width: 100px;"> <!-- 체결 단가 -->
                            <col> <!-- +/- -->
                            <col style="width: 110px;"> <!-- 체결 총액 -->
                            <col style="width: 70px;"> <!-- 수수료 -->
                            <col style="width: 120px;"> <!-- 정산 금액 -->
                        </colgroup>
                        <thead>
                            <tr>
                                <th class="text-center" style="white-space: nowrap; padding: 8px 10px;">일시</th>
                                <th class="text-center" style="white-space: nowrap; padding: 8px 10px;">구분</th>
                                <th class="text-center" style="white-space: nowrap; padding: 8px 10px;">체결 단가</th>
                                <th class="text-center" style="white-space: nowrap; padding: 8px 10px;">+/-</th>
                                <th class="text-center" style="white-space: nowrap; padding: 8px 10px;">체결 총액</th>
                                <th class="text-center" style="white-space: nowrap; padding: 8px 10px;">수수료</th>
                                <th class="text-center" style="white-space: nowrap; padding: 8px 10px;">정산 금액</th>
                            </tr>
                        </thead>
                        <tbody id="modalOrdersTbody">
                        </tbody>
                    </table>
                </div>

                <!-- 전략명 영역 추가 -->
                <div style="margin-top: 20px;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <label for="tradeStrategyInput" style="font-weight: bold; margin-bottom: 0px; color: #333; white-space: nowrap; font-size: 14px;">전략명 :</label>
                        <input type="text" id="tradeStrategyInput" class="input-field" style="flex: 1; max-width: 250px; padding: 8px 10px; font-size: 14px;" placeholder="새 전략명 (쉼표로 복수 입력) 후 엔터">
                    </div>
                    <div id="strategyTagsContainer" style="display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px;">
                        <!-- 전략 태그 렌더링 영역 -->
                    </div>
                </div>

                <!-- 진입근거 영역 추가 -->
                <div style="margin-top: 20px;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <label for="tradeEntryReasonInput" style="font-weight: bold; margin-bottom: 0px; color: #333; white-space: nowrap; font-size: 14px;">진입근거 :</label>
                        <input type="text" id="tradeEntryReasonInput" class="input-field" style="flex: 1; max-width: 250px; padding: 8px 10px; font-size: 14px;" placeholder="새 진입근거 (쉼표로 복수 입력) 후 엔터">
                    </div>
                    <div id="entryReasonTagsContainer" style="display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px;"></div>
                </div>

                <!-- 심리상태 영역 추가 -->
                <div style="margin-top: 20px;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <label for="tradePsychologyStateInput" style="font-weight: bold; margin-bottom: 0px; color: #333; white-space: nowrap; font-size: 14px;">심리상태 :</label>
                        <input type="text" id="tradePsychologyStateInput" class="input-field" style="flex: 1; max-width: 250px; padding: 8px 10px; font-size: 14px;" placeholder="새 심리상태 (쉼표로 복수 입력) 후 엔터">
                    </div>
                    <div id="psychologyStateTagsContainer" style="display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px;"></div>
                </div>

                <!-- 패착요인 영역 추가 -->
                <div style="margin-top: 20px;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <label for="tradeFailureFactorInput" style="font-weight: bold; margin-bottom: 0px; color: #333; white-space: nowrap; font-size: 14px;">패착요인 :</label>
                        <input type="text" id="tradeFailureFactorInput" class="input-field" style="flex: 1; max-width: 250px; padding: 8px 10px; font-size: 14px;" placeholder="새 패착요인 (쉼표로 복수 입력) 후 엔터">
                    </div>
                    <div id="failureFactorTagsContainer" style="display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px;"></div>
                </div>
            </div>

            <!-- 우측 영역 (비율 1) -->
            <div style="flex: 1; min-width: 300px; display: flex; flex-direction: column;">
                <!-- 트레이딩뷰 링크 영역 추가 -->
                <div style="margin-top: 0px;">
                    <label for="tradeTvUrl" style="display: block; font-weight: bold; margin-bottom: 8px; color: #333;">트레이딩뷰 링크</label>
                    <div style="display: flex; gap: 10px;">
                        <input type="text" id="tradeTvUrl" class="input-field" style="flex: 1;" placeholder="트레이딩뷰 링크를 입력하고 엔터키를 누르세요.">
                        <button type="button" id="btnViewTvLink" class="button-secondary" style="display: none; padding: 10px 15px; white-space: nowrap;" onclick="openTvLinkPopup()">링크보기</button>
                    </div>
                </div>

                <!-- 메모 영역 추가 -->
                <div style="margin-top: 20px; flex-grow: 1; display: flex; flex-direction: column;">
                    <label for="tradeMemo" style="display: block; font-weight: bold; margin-bottom: 8px; color: #333;">메모</label>
                    <textarea id="tradeMemo" class="textarea-field" style="width: 100%; flex-grow: 1; min-height: 300px; padding: 10px; border: 1px solid #ddd; border-radius: 6px; box-sizing: border-box; font-size: 0.9rem;" placeholder="메모를 입력하고 엔터키를 누르면 저장됩니다. (Shift + Enter로 줄바꿈)"></textarea>
                    <input type="hidden" id="tradeMemoUuid" value="">
                </div>
            </div>
        </div>

        <!-- 메모 저장 성공 팝업 -->
        <div id="memoToast" style="display: none; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: rgba(0, 0, 0, 0.8); color: white; padding: 15px 30px; border-radius: 8px; font-weight: bold; z-index: 10001; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.3);">
            <i class="fa-solid fa-check-circle" style="margin-right: 5px; color: #4ade80;"></i> 메모가 저장되었습니다.
        </div>

        <div style="margin-top: 25px; text-align: center;">
            <button type="button" onclick="closeTradeModal()" style="width: 100%; padding: 12px; border: none; background: #333; color: #fff; font-weight: bold; border-radius: 6px; cursor: pointer; transition: background 0.2s;" onmouseover="this.style.background='#555'" onmouseout="this.style.background='#333'">닫기</button>
        </div>
    </div>
</div>

<!-- 전략위키 상세 정보 모달 -->
<div id="indexWikiModal" style="display: none; position: fixed; z-index: 10000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); align-items: center; justify-content: center;">
    <div style="background-color: #fff; padding: 25px; border-radius: 12px; width: 90%; max-width: 800px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); position: relative; max-height: 90vh; overflow-y: auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #f0f0f0; padding-bottom: 15px; margin-bottom: 15px;">
            <h3 id="indexWikiModalTitle" style="margin-top: 0; color: #333; font-size: 1.4rem; margin-bottom: 0px;"><i class="fa-solid fa-book" style="color: #555; margin-right: 8px;"></i> <span></span></h3>
            <button type="button" onclick="closeIndexWikiModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #666;">&times;</button>
        </div>

        <div id="indexWikiModalContent" style="font-size: 1rem; line-height: 1.6; color: #444; margin-bottom: 20px; white-space: pre-wrap; word-break: break-word;"></div>

        <div id="indexWikiModalYoutube" style="margin-bottom: 20px; text-align: center; display: none;">
            <a href="#" target="_blank" rel="noopener noreferrer" class="youtube-thumbnail-link" style="text-decoration: none;">
                <i class="fa-brands fa-youtube text-red-600" style="font-size: 3rem; color: #dc2626;"></i><br>
                <span style="font-size: 0.9rem; color: #666; display: inline-block; margin-top: 5px;">유튜브 영상 보기</span>
            </a>
        </div>

        <div id="indexWikiModalImages" style="text-align: center;"></div>

        <div style="margin-top: 25px; text-align: center;">
            <button type="button" onclick="closeIndexWikiModal()" style="width: 100%; padding: 12px; border: none; background: #333; color: #fff; font-weight: bold; border-radius: 6px; cursor: pointer; transition: background 0.2s;" onmouseover="this.style.background='#555'" onmouseout="this.style.background='#333'">닫기</button>
        </div>
    </div>
</div>

<!-- 전략위키 작성 모달 (index.php) -->
<div id="wikiModal" style="display: none; position: fixed; z-index: 10000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); align-items: center; justify-content: center;">
    <div style="background-color: #fff; padding: 25px; border-radius: 12px; width: 90%; max-width: 800px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); position: relative; max-height: 90vh; overflow-y: auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #f0f0f0; padding-bottom: 15px; margin-bottom: 15px;">
            <h3 id="wikiModalTitle" style="margin: 0; color: #333; font-size: 1.4rem;"><i class="fa-solid fa-pen-to-square"></i> 전략위키 작성</h3>
            <button type="button" class="wiki-cancel-button" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #666;">&times;</button>
        </div>
        <form id="wikiForm">
            <input type="hidden" id="wiki_id" name="wiki_id">

            <div style="margin-bottom: 15px;">
                <label for="title" style="display: block; font-weight: bold; margin-bottom: 5px; color: #333;">제목:</label>
                <input type="text" id="title" name="title" class="input-field" required>
            </div>
            <div style="margin-bottom: 15px;">
                <label for="content" style="display: block; font-weight: bold; margin-bottom: 5px; color: #333;">내용:</label>
                <textarea id="content" name="content" class="textarea-field" rows="15" required></textarea>
            </div>
            <div style="margin-bottom: 15px;">
                <label for="tv_image_url" style="display: block; font-weight: bold; margin-bottom: 5px; color: #333;">트레이딩뷰 이미지 주소 (URL):</label>
                <input type="url" id="tv_image_url" name="tv_image_url" class="input-field">
            </div>
            <div style="margin-bottom: 15px;">
                <label for="images" style="display: block; font-weight: bold; margin-bottom: 5px; color: #333;">이미지 파일 (다중 선택 가능):</label>
                <input type="file" id="images" name="images[]" class="input-field" multiple accept="image/*">
                <div id="imagePreviewContainer" style="margin-top: 10px;"></div>
            </div>
            <div style="margin-bottom: 15px;">
                <label for="youtube_url" style="display: block; font-weight: bold; margin-bottom: 5px; color: #333;">유튜브 영상 주소:</label>
                <input type="url" id="youtube_url" name="youtube_url" class="input-field">
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 25px;">
                <button type="submit" class="button-primary" style="padding: 10px 20px;">저장</button>
                <button type="button" class="button-secondary wiki-cancel-button" style="padding: 10px 20px;">취소</button>
            </div>
        </form>
    </div>
</div>

<!-- 매매 원칙 편집 모달 -->
<div id="principlesModal" style="display: none; position: fixed; z-index: 10000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); align-items: center; justify-content: center;">
    <div style="background-color: #fff; padding: 25px; border-radius: 12px; width: 90%; max-width: 800px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); position: relative; max-height: 90vh; overflow-y: auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #f0f0f0; padding-bottom: 15px; margin-bottom: 15px;">
            <h3 style="margin-top: 0; color: #333; font-size: 1.4rem; margin-bottom: 0px;"><i class="fa-solid fa-shield-halved" style="color: #555; margin-right: 8px;"></i> 나의 매매 원칙 편집</h3>
            <button type="button" onclick="closePrinciplesModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #666;">&times;</button>
        </div>

        <div style="margin-bottom: 20px;">
            <h4 style="font-size: 1.1rem; margin-bottom: 10px;">원칙 목록 <span style="font-size: 0.8rem; color: #888; font-weight: normal;">(드래그하여 순서를 변경할 수 있습니다)</span></h4>
            <?php if (empty($trading_principles)): ?>
                <p class="text-gray-500 text-sm">아직 설정된 원칙이 없습니다. 아래에서 새 원칙을 추가해주세요.</p>
            <?php else: ?>
                <div id="principleSortableList">
                    <?php foreach ($trading_principles as $p): ?>
                        <div class="principle-sortable-item" draggable="true" data-id="<?php echo h($p['id']); ?>" style="margin-bottom: 10px; padding: 10px; border: 1px solid #ddd; border-radius: 8px; display: flex; flex-wrap: wrap; align-items: center; gap: 10px; background-color: #fff; cursor: grab;">
                            <div style="color: #aaa; padding: 0 5px; cursor: grab;"><i class="fa-solid fa-grip-vertical"></i></div>
                            <form action="index.php" method="POST" style="display: flex; flex: 1; flex-wrap: wrap; align-items: center; gap: 10px; margin: 0;">
                                <input type="hidden" name="id" value="<?php echo h($p['id']); ?>">
                                <div style="flex: 1; min-width: 120px; max-width: 180px;"><label style="display: block; font-size: 0.8rem; color: #666;">태그명</label><input type="text" name="tag_text" value="<?php echo h($p['tag_text']); ?>" class="input-field" style="padding: 8px; font-size: 0.9rem;" placeholder="예: STOP-LOSS"></div>
                                <div style="flex: 3; min-width: 200px;"><label style="display: block; font-size: 0.8rem; color: #666;">원칙 내용</label><input type="text" name="principle_text" value="<?php echo h($p['principle_text']); ?>" class="input-field" style="padding: 8px; font-size: 0.9rem;" required></div>
                                <div style="display: flex; gap: 5px; align-items: flex-end; margin-top: 18px;">
                                    <button type="submit" name="update_principle" class="button-primary" style="padding: 8px 12px; font-size: 0.85rem;">수정</button>
                                    <button type="submit" name="delete_principle" class="button-danger" style="padding: 8px 12px; font-size: 0.85rem;" onclick="return confirm('정말 삭제하시겠습니까?');">삭제</button>
                                </div>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div style="background-color: #f9f9f9; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
            <h4 style="font-size: 1.1rem; margin-top: 0; margin-bottom: 10px;">새 원칙 추가</h4>
            <form action="index.php" method="POST" style="display: flex; flex-wrap: wrap; align-items: center; gap: 10px;">
                <div style="flex: 1; min-width: 120px; max-width: 180px;"><label style="display: block; font-size: 0.8rem; color: #666;">태그명</label><input type="text" name="tag_text" class="input-field" style="padding: 8px; font-size: 0.9rem;" placeholder="예: STOP-LOSS"></div>
                <div style="flex: 3; min-width: 200px;"><label style="display: block; font-size: 0.8rem; color: #666;">원칙 내용</label><input type="text" name="principle_text" class="input-field" style="padding: 8px; font-size: 0.9rem;" required></div>
                <div style="display: flex; align-items: flex-end; margin-top: 18px;">
                    <button type="submit" name="add_principle" class="button-new" style="margin-right: 0; transform: none; padding: 8px 15px; font-size: 0.85rem;">추가</button>
                </div>
            </form>
        </div>

        <div style="text-align: center;">
            <button type="button" onclick="closePrinciplesModal()" style="width: 100%; padding: 12px; border: none; background: #333; color: #fff; font-weight: bold; border-radius: 6px; cursor: pointer; transition: background 0.2s;" onmouseover="this.style.background='#555'" onmouseout="this.style.background='#333'">닫기</button>
        </div>
    </div>
</div>

<script>
    let globalStrategies = <?php echo json_encode(array_keys($all_strategies_set)); ?>;
    let globalEntryReasons = <?php echo json_encode(array_keys($all_entry_reasons_set)); ?>;
    let globalPsychologyStates = <?php echo json_encode(array_keys($all_psychology_states_set)); ?>;
    let globalFailureFactors = <?php echo json_encode(array_keys($all_failure_factors_set)); ?>;
    let currentTradeUuid = '';
    let currentTradeTags = {};
    let currentTradeType = 'upbit';

    document.addEventListener('DOMContentLoaded', function() {
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('show_principles')) {
            openPrinciplesModal();
            window.history.replaceState({}, document.title, window.location.pathname);
        }
    });

    function createTagEditor(config) {
        let {
            fieldName,
            globalTags,
            inputEl,
            containerEl,
            getTags,
            setTags
        } = config;

        function render() {
            containerEl.innerHTML = '';
            const currentTags = getTags();

            globalTags.forEach(tag => {
                const isActive = currentTags.includes(tag);
                const tagEl = document.createElement('span');
                tagEl.innerText = tag;
                tagEl.style.padding = '4px 12px';
                tagEl.style.borderRadius = '9999px';
                tagEl.style.fontSize = '0.85rem';
                tagEl.style.fontWeight = 'bold';
                tagEl.style.cursor = 'pointer';
                tagEl.style.userSelect = 'none';
                tagEl.style.transition = 'all 0.2s ease';

                if (isActive) {
                    tagEl.style.backgroundColor = 'var(--accent-color, #2962ff)';
                    tagEl.style.color = '#fff';
                    tagEl.style.border = '1px solid var(--accent-color, #2962ff)';
                } else {
                    tagEl.style.backgroundColor = 'var(--bg-color, #f0f3fa)';
                    tagEl.style.color = 'var(--text-muted, #707584)';
                    tagEl.style.border = '1px solid var(--border-color, #e0e3eb)';
                }

                tagEl.onclick = () => toggleTag(tag);
                containerEl.appendChild(tagEl);
            });
        }

        function toggleTag(tag) {
            if (!currentTradeUuid) return;
            let currentTags = getTags();
            if (currentTags.includes(tag)) {
                setTags(currentTags.filter(t => t !== tag));
            } else {
                setTags([...currentTags, tag]);
            }
            save();
        }

        function save() {
            const uuid = currentTradeUuid;
            const tagString = getTags().join(', ');

            const formData = new FormData();
            formData.append('uuid', uuid);
            formData.append('type', currentTradeType);
            formData.append(fieldName, tagString);

            fetch('save_trade_memo.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        render();
                        const row = document.querySelector(`.trade-row[data-uuid="${uuid}"]`);
                        if (row) {
                            const dataAttributeName = `data-${fieldName.replace(/_/g, '-')}`;
                            row.setAttribute(dataAttributeName, tagString);

                            let columnMap;
                            if (currentTradeType === 'okx') {
                                columnMap = {
                                    'strategy_name': 6,
                                    'entry_reason': 7,
                                    'psychology_state': 8,
                                    'failure_factor': 9
                                };
                            } else {
                                columnMap = {
                                    'strategy_name': 2,
                                    'entry_reason': 3,
                                    'psychology_state': 4,
                                    'failure_factor': 5
                                };
                            }
                            const cellIndex = columnMap[fieldName];
                            if (cellIndex !== undefined) {
                                const cells = row.querySelectorAll('td');
                                if (cells.length > cellIndex) {
                                    const tags = tagString.split(',').map(t => t.trim()).filter(t => t !== '');
                                    let bg = '#f1f3f5',
                                        color = '#495057',
                                        border = '#dee2e6';
                                    if (fieldName === 'strategy_name') {
                                        bg = '#eef2ff';
                                        color = '#4f46e5';
                                        border = '#c7d2fe';
                                    } else if (fieldName === 'entry_reason') {
                                        bg = '#ecfdf5';
                                        color = '#059669';
                                        border = '#a7f3d0';
                                    } else if (fieldName === 'psychology_state') {
                                        bg = '#fffbeb';
                                        color = '#d97706';
                                        border = '#fde68a';
                                    } else if (fieldName === 'failure_factor') {
                                        bg = '#fff1f2';
                                        color = '#e11d48';
                                        border = '#fecdd3';
                                    }

                                    if (tags.length > 0) {
                                        let html = '<div style="display: flex; flex-direction: column; gap: 3px; align-items: center; justify-content: center; height: 100%;">';
                                        tags.forEach(tag => {
                                            html += `<span style="padding: 2px 5px; font-size: 13px; border-radius: 4px; background-color: ${bg}; color: ${color}; border: 1px solid ${border}; white-space: nowrap; line-height: 1.2; box-shadow: 0 1px 2px rgba(0,0,0,0.05); font-weight: 600; display: inline-block; cursor: default;">${tag}</span>`;
                                        });
                                        html += '</div>';
                                        cells[cellIndex].innerHTML = html;
                                    } else {
                                        cells[cellIndex].innerHTML = '';
                                    }
                                }
                            }
                        }
                    } else {
                        alert('저장 실패: ' + (data.message || '알 수 없는 오류'));
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('서버 통신 오류가 발생했습니다.');
                });
        }

        inputEl.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                const newTagsRaw = this.value.trim();
                if (newTagsRaw) {
                    const newTags = newTagsRaw.split(',').map(t => t.trim()).filter(t => t);
                    let changed = false;
                    let currentTags = getTags();

                    newTags.forEach(tag => {
                        if (!globalTags.includes(tag)) {
                            globalTags.push(tag);
                        }
                        if (!currentTags.includes(tag)) {
                            currentTags.push(tag);
                            changed = true;
                        }
                    });
                    setTags(currentTags);

                    if (changed) {
                        save();
                    } else {
                        render();
                    }
                    this.value = '';
                }
            }
        });

        return {
            render
        };
    }

    const tagEditors = {
        strategy_name: createTagEditor({
            fieldName: 'strategy_name',
            globalTags: globalStrategies,
            inputEl: document.getElementById('tradeStrategyInput'),
            containerEl: document.getElementById('strategyTagsContainer'),
            getTags: () => currentTradeTags.strategy_name || [],
            setTags: (tags) => {
                currentTradeTags.strategy_name = tags;
            }
        }),
        entry_reason: createTagEditor({
            fieldName: 'entry_reason',
            globalTags: globalEntryReasons,
            inputEl: document.getElementById('tradeEntryReasonInput'),
            containerEl: document.getElementById('entryReasonTagsContainer'),
            getTags: () => currentTradeTags.entry_reason || [],
            setTags: (tags) => {
                currentTradeTags.entry_reason = tags;
            }
        }),
        psychology_state: createTagEditor({
            fieldName: 'psychology_state',
            globalTags: globalPsychologyStates,
            inputEl: document.getElementById('tradePsychologyStateInput'),
            containerEl: document.getElementById('psychologyStateTagsContainer'),
            getTags: () => currentTradeTags.psychology_state || [],
            setTags: (tags) => {
                currentTradeTags.psychology_state = tags;
            }
        }),
        failure_factor: createTagEditor({
            fieldName: 'failure_factor',
            globalTags: globalFailureFactors,
            inputEl: document.getElementById('tradeFailureFactorInput'),
            containerEl: document.getElementById('failureFactorTagsContainer'),
            getTags: () => currentTradeTags.failure_factor || [],
            setTags: (tags) => {
                currentTradeTags.failure_factor = tags;
            }
        })
    };

    function openTradeModal(row) {
        currentTradeType = row.getAttribute('data-type') || 'upbit';
        const uuid = row.getAttribute('data-uuid');
        const memo = row.getAttribute('data-memo');
        const tvUrl = row.getAttribute('data-tradingview-url');
        const strategyNameStr = row.getAttribute('data-strategy-name');
        const entryReasonStr = row.getAttribute('data-entry-reason');
        const psychologyStateStr = row.getAttribute('data-psychology-state');
        const failureFactorStr = row.getAttribute('data-failure-factor');
        const market = row.getAttribute('data-market');
        const profit = parseFloat(row.getAttribute('data-profit'));
        const profitRate = parseFloat(row.getAttribute('data-profit-rate'));
        const buyTime = row.getAttribute('data-buy-time');

        let orders = [];
        try {
            orders = JSON.parse(row.getAttribute('data-orders'));
        } catch (e) {
            console.error(e);
        }

        // 주문 데이터들을 ord_id (또는 uuid) 기준으로 그룹화
        let groupedOrders = {};
        orders.forEach(order => {
            let id = order.uuid || order.ord_id;
            if (order.side === 'funding') {
                id = 'funding_' + order.created_at + '_' + Math.random();
            } else if (!id) {
                id = order.created_at + '_' + order.side;
            }
            if (!groupedOrders[id]) {
                groupedOrders[id] = {
                    ...order
                };
                groupedOrders[id].executed_volume = parseFloat(order.executed_volume);
                groupedOrders[id].paid_fee = parseFloat(order.paid_fee);
                groupedOrders[id].settle_amount = parseFloat(order.settle_amount);
                groupedOrders[id].totalAmount = parseFloat(order.avg_price) * parseFloat(order.executed_volume);
                groupedOrders[id].profit = parseFloat(order.profit || 0);
            } else {
                groupedOrders[id].executed_volume += parseFloat(order.executed_volume);
                groupedOrders[id].paid_fee += parseFloat(order.paid_fee);
                groupedOrders[id].settle_amount += parseFloat(order.settle_amount);
                groupedOrders[id].totalAmount += parseFloat(order.avg_price) * parseFloat(order.executed_volume);
                groupedOrders[id].profit += parseFloat(order.profit || 0);
                if (groupedOrders[id].executed_volume > 0) {
                    groupedOrders[id].avg_price = groupedOrders[id].totalAmount / groupedOrders[id].executed_volume;
                }
            }
        });
        orders = Object.values(groupedOrders);

        currentTradeUuid = uuid;
        currentTradeTags = {
            strategy_name: strategyNameStr ? strategyNameStr.split(',').map(s => s.trim()).filter(s => s) : [],
            entry_reason: entryReasonStr ? entryReasonStr.split(',').map(s => s.trim()).filter(s => s) : [],
            psychology_state: psychologyStateStr ? psychologyStateStr.split(',').map(s => s.trim()).filter(s => s) : [],
            failure_factor: failureFactorStr ? failureFactorStr.split(',').map(s => s.trim()).filter(s => s) : []
        };
        Object.values(tagEditors).forEach(editor => editor.render());

        const displayMarket = currentTradeType === 'okx' ? market : market.replace('KRW-', '');
        document.getElementById('modalMarketName').innerHTML = '<i class="fa-solid fa-receipt" style="color: #555; margin-right: 8px;"></i>' + displayMarket + ' 매매 상세';

        document.getElementById('tradeMemo').value = memo || '';
        document.getElementById('tradeMemoUuid').value = uuid || '';

        document.getElementById('tradeTvUrl').value = tvUrl || '';
        if (tvUrl) {
            document.getElementById('btnViewTvLink').style.display = 'inline-block';
        } else {
            document.getElementById('btnViewTvLink').style.display = 'none';
        }

        const formatCurrency = (num) => {
            if (currentTradeType === 'okx') return Number(num).toFixed(2);
            return Math.round(num).toLocaleString('ko-KR');
        };
        const formatPrice = (num) => {
            if (currentTradeType === 'okx') return Number(num).toString();
            return Math.round(num).toLocaleString('ko-KR');
        };

        const tbody = document.getElementById('modalOrdersTbody');
        tbody.innerHTML = '';

        orders.forEach(order => {
            const tr = document.createElement('tr');

            if (order.side === 'funding') {
                const profit = parseFloat(order.profit || 0);
                const color = profit > 0 ? '#1261c4' : (profit < 0 ? '#c84a31' : 'inherit');
                const sign = profit > 0 ? '+' : '';
                const profitHtml = `<span style="color: ${color}; font-weight: bold;">${sign}${formatCurrency(profit)}</span>`;
                tr.innerHTML = `
                    <td class="text-center" style="white-space: nowrap; font-size: 13px; padding: 8px 10px;">${order.created_at}</td>
                    <td class="text-center" style="font-size: 13px; padding: 8px 10px; font-weight: bold; color: #888;">펀딩비</td>
                    <td class="text-center" style="font-size: 13px; padding: 8px 10px; color: #888;">-</td>
                    <td class="text-center" style="font-size: 13px; padding: 8px 10px;">${profitHtml}</td>
                    <td class="text-center" style="font-size: 13px; padding: 8px 10px; color: #888;">-</td>
                    <td class="text-center" style="font-size: 13px; padding: 8px 10px; color: #888;">-</td>
                    <td class="text-center" style="font-size: 13px; padding: 8px 10px; color: #888;">-</td>
                `;
            } else {
                const sideTextRaw = (order.side === 'bid' || order.side === 'buy') ? '매수' : '매도';
                const sideColor = sideTextRaw === '매수' ? '#1261c4' : '#c84a31';
                const sideText = `<span style="color: ${sideColor}; font-weight: bold;">${sideTextRaw}</span>`;
                const fee = parseFloat(order.paid_fee);
                const settleAmount = parseFloat(order.settle_amount);
                let totalAmount = 0;

                // 체결 총액 = 정산금액 - 수수료 (매수) 또는 정산금액 + 수수료 (매도)
                if (sideTextRaw === '매수') {
                    totalAmount = settleAmount - fee;
                } else { // 매도
                    totalAmount = settleAmount + fee;
                }

                const price = parseFloat(order.avg_price);
                const volume = parseFloat(order.executed_volume);
                let profitHtml = '';
                if (order.side === 'ask' || order.side === 'sell') {
                    const orderProfit = parseFloat(order.profit || 0);
                    const orderProfitRate = parseFloat(order.profit_rate || 0);
                    const color = orderProfit > 0 ? '#1261c4' : (orderProfit < 0 ? '#c84a31' : 'inherit');
                    const sign = orderProfit > 0 ? '+' : '';
                    if (currentTradeType === 'okx') {
                        profitHtml = `<span style="color: ${color};">${formatCurrency(orderProfit)}</span>`;
                    } else {
                        profitHtml = `<span style="color: ${color};">${formatCurrency(orderProfit)} (${sign}${orderProfitRate.toFixed(2)}%)</span>`;
                    }
                }

                tr.innerHTML = `
                    <td class="text-center" style="white-space: nowrap; font-size: 13px; padding: 8px 10px;">${order.created_at}</td>
                    <td class="text-center" style="font-size: 13px; padding: 8px 10px;">${sideText}</td>
                    <td class="text-center" style="font-size: 13px; padding: 8px 10px;">${formatPrice(price)}</td>
                    <td class="text-center" style="font-size: 13px; padding: 8px 10px;">${profitHtml}</td>
                    <td class="text-center" style="font-size: 13px; padding: 8px 10px;">${formatCurrency(totalAmount)}</td>
                    <td class="text-center" style="font-size: 13px; padding: 8px 10px;">${formatPrice(fee)}</td>
                    <td class="text-center" style="font-size: 13px; padding: 8px 10px;">${formatCurrency(settleAmount)}</td>
                `;
            }
            tbody.appendChild(tr);
        });

        const profitEl = document.getElementById('modalProfit');
        profitEl.innerText = formatCurrency(profit);
        profitEl.style.color = profit > 0 ? '#1261c4' : (profit < 0 ? '#c84a31' : 'inherit');

        const profitRateEl = document.getElementById('modalProfitRate');
        profitRateEl.style.display = 'inline';
        const rateSign = profitRate > 0 ? '+' : '';
        profitRateEl.innerText = rateSign + profitRate.toFixed(2) + ' %';
        profitRateEl.style.color = profit > 0 ? '#1261c4' : (profit < 0 ? '#c84a31' : 'inherit');

        const viewChartBtn = document.getElementById('viewChartBtn');
        let tradingViewSymbol = '';
        if (currentTradeType === 'okx') {
            tradingViewSymbol = 'OKX:' + market + 'USDT.P';
        } else {
            tradingViewSymbol = 'UPBIT:' + market.replace('KRW-', '') + 'KRW';
        }
        const tradingViewUrl = `https://www.tradingview.com/chart/?symbol=${tradingViewSymbol}&interval=15`;

        viewChartBtn.onclick = function(e) {
            e.preventDefault();
            if (buyTime) {
                // 트레이딩뷰 '이동(Go to)' 입력 형식에 맞게 날짜(YYYY-MM-DD)만 복사 (예: 2026-03-15)
                const formattedTime = buyTime.substring(0, 10);

                // HTTP 환경에서도 안정적으로 작동하는 동기식 클립보드 복사 로직
                let copied = false;
                const textArea = document.createElement("textarea");
                textArea.value = formattedTime;
                textArea.style.position = "fixed";
                document.body.appendChild(textArea);
                textArea.focus();
                textArea.select();
                try {
                    copied = document.execCommand('copy');
                } catch (err) {}
                document.body.removeChild(textArea);

                if (copied) {
                    alert(`💡 트레이딩뷰 웹은 URL을 통한 과거 시간 이동을 지원하지 않습니다.\n\n대신 매수 일시 [ ${formattedTime} ] 가 클립보드에 복사되었습니다.\n\n새 창에서 차트가 열리면 단축키 Alt + G (Mac은 ⌥ + G)를 누르고 붙여넣기(Ctrl+V) 하시면 해당 시점으로 바로 이동합니다.`);
                } else {
                    alert(`💡 트레이딩뷰 웹은 URL을 통한 과거 시간 이동을 지원하지 않습니다.\n\n새 창에서 차트가 열리면 단축키 Alt + G (Mac은 ⌥ + G)를 누르고\n매수 일시 [ ${formattedTime} ] 를 직접 입력해 주세요.`);
                }
                window.open(tradingViewUrl, '_blank');
            } else {
                window.open(tradingViewUrl, '_blank');
            }
        };

        document.getElementById('tradeModal').style.display = 'flex';
    }

    function closeTradeModal() {
        document.getElementById('tradeModal').style.display = 'none';
    }

    // 엔터키 입력 시 메모 저장
    document.getElementById('tradeMemo').addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            saveMemo();
        }
    });

    function saveMemo() {
        const uuid = document.getElementById('tradeMemoUuid').value;
        const memo = document.getElementById('tradeMemo').value;

        if (!uuid) return;

        const formData = new FormData();
        formData.append('uuid', uuid);
        formData.append('type', currentTradeType);
        formData.append('memo', memo);

        fetch('save_trade_memo.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const toast = document.getElementById('memoToast');
                    toast.innerHTML = '<i class="fa-solid fa-check-circle" style="margin-right: 5px; color: #4ade80;"></i> 메모가 저장되었습니다.';
                    toast.style.display = 'block';

                    const row = document.querySelector(`.trade-row[data-uuid="${uuid}"]`);
                    if (row) {
                        row.setAttribute('data-memo', memo);
                        const cells = row.querySelectorAll('td');
                        if (cells.length > 0) {
                            const lastCell = cells[cells.length - 1];
                            lastCell.textContent = memo;
                            lastCell.title = memo;
                        }
                    }

                    setTimeout(() => {
                        toast.style.display = 'none';
                    }, 1000);
                } else {
                    alert('저장 실패: ' + (data.message || '알 수 없는 오류'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('서버 통신 오류가 발생했습니다.');
            });
    }

    // 트레이딩뷰 링크 엔터키 입력 시 저장
    document.getElementById('tradeTvUrl').addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            saveTvUrl();
        }
    });

    function saveTvUrl() {
        const uuid = document.getElementById('tradeMemoUuid').value;
        const tvUrl = document.getElementById('tradeTvUrl').value;

        if (!uuid) return;

        const formData = new FormData();
        formData.append('uuid', uuid);
        formData.append('type', currentTradeType);
        formData.append('tradingview_url', tvUrl);

        fetch('save_trade_memo.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const toast = document.getElementById('memoToast');
                    toast.innerHTML = '<i class="fa-solid fa-check-circle" style="margin-right: 5px; color: #4ade80;"></i> 링크가 저장되었습니다.';
                    toast.style.display = 'block';

                    const row = document.querySelector(`.trade-row[data-uuid="${uuid}"]`);
                    if (row) {
                        row.setAttribute('data-tradingview-url', tvUrl);
                    }

                    if (tvUrl) {
                        document.getElementById('btnViewTvLink').style.display = 'inline-block';
                    } else {
                        document.getElementById('btnViewTvLink').style.display = 'none';
                    }

                    setTimeout(() => {
                        toast.style.display = 'none';
                        // 원래 메시지로 복구
                        setTimeout(() => {
                            toast.innerHTML = '<i class="fa-solid fa-check-circle" style="margin-right: 5px; color: #4ade80;"></i> 메모가 저장되었습니다.';
                        }, 300);
                    }, 1000);
                } else {
                    alert('저장 실패: ' + (data.message || '알 수 없는 오류'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('서버 통신 오류가 발생했습니다.');
            });
    }

    function openTvLinkPopup() {
        const url = document.getElementById('tradeTvUrl').value;
        if (url) {
            window.open(url, 'tvPopup', 'width=1200,height=800,scrollbars=yes,resizable=yes');
        }
    }

    // 모달 영역 외 클릭 시 닫기
    window.addEventListener('click', function(event) {
        const modal = document.getElementById('tradeModal');
        if (event.target === modal) {
            closeTradeModal();
        }

        const wikiModal = document.getElementById('indexWikiModal');
        if (event.target === wikiModal) {
            closeIndexWikiModal();
        }

        const principlesModal = document.getElementById('principlesModal');
        if (event.target === principlesModal) {
            closePrinciplesModal();
        }

        const wikiModalForm = document.getElementById('wikiModal');
        if (event.target === wikiModalForm) {
            wikiModalForm.style.display = 'none';
        }

        const targetProfitModal = document.getElementById('targetProfitModal');
        if (event.target === targetProfitModal) {
            window.closeTargetProfitModal();
        }
    });

    // 위키 작성 모달
    const wikiModalForm = document.getElementById('wikiModal');
    const indexNewWikiButton = document.getElementById('indexNewWikiButton');
    const wikiCancelButtons = document.querySelectorAll('.wiki-cancel-button');
    const wikiForm = document.getElementById('wikiForm');

    if (indexNewWikiButton) {
        indexNewWikiButton.addEventListener('click', function(e) {
            e.preventDefault();
            wikiForm.reset();
            document.getElementById('imagePreviewContainer').innerHTML = '';
            document.getElementById('wiki_id').value = '';
            document.getElementById('wikiModalTitle').innerHTML = '<i class="fa-solid fa-pen-to-square"></i> 전략위키 작성';
            wikiModalForm.style.display = 'flex';
        });
    }

    wikiCancelButtons.forEach(button => {
        button.addEventListener('click', function() {
            wikiModalForm.style.display = 'none';
        });
    });

    function openIndexWikiModal(element) {
        const title = element.getAttribute('data-title');
        const content = element.getAttribute('data-content');
        const tvUrl = element.getAttribute('data-tv-url');
        const youtubeUrl = element.getAttribute('data-youtube-url');
        const imageStr = element.getAttribute('data-image');

        document.querySelector('#indexWikiModalTitle span').innerText = title;
        document.getElementById('indexWikiModalContent').innerHTML = content;

        const ytContainer = document.getElementById('indexWikiModalYoutube');
        if (youtubeUrl) {
            ytContainer.style.display = 'block';
            ytContainer.querySelector('a').href = youtubeUrl;
        } else {
            ytContainer.style.display = 'none';
        }

        const imgContainer = document.getElementById('indexWikiModalImages');
        imgContainer.innerHTML = '';
        let images = [];
        if (tvUrl) images.push(tvUrl);
        if (imageStr) {
            images = images.concat(imageStr.split('|').filter(img => img.trim() !== ''));
        }

        images.forEach(imgUrl => {
            const img = document.createElement('img');
            img.src = imgUrl.trim();
            img.style.maxWidth = '100%';
            img.style.height = 'auto';
            img.style.marginBottom = '10px';
            img.style.borderRadius = '8px';
            img.style.border = '1px solid #ddd';
            imgContainer.appendChild(img);
        });

        document.getElementById('indexWikiModal').style.display = 'flex';
    }

    function closeIndexWikiModal() {
        document.getElementById('indexWikiModal').style.display = 'none';
    }

    wikiForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const form = e.target;
        const formData = new FormData(form);

        fetch('save_wiki.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    form.reset();
                    wikiModalForm.style.display = 'none';
                    location.reload();
                } else {
                    alert('저장 실패: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('오류 발생: 서버와 통신할 수 없습니다.');
            });
    });

    function openPrinciplesModal() {
        document.getElementById('principlesModal').style.display = 'flex';
    }

    function closePrinciplesModal() {
        document.getElementById('principlesModal').style.display = 'none';
        location.reload(); // 닫을 때 변경된 순서 반영을 위해 새로고침
    }

    // 드래그 앤 드롭 정렬 스크립트
    const sortableList = document.getElementById('principleSortableList');
    if (sortableList) {
        let draggedItem = null;

        sortableList.addEventListener('dragstart', function(e) {
            draggedItem = e.target.closest('.principle-sortable-item');
            if (draggedItem) {
                setTimeout(() => draggedItem.style.opacity = '0.5', 0);
            }
        });

        sortableList.addEventListener('dragend', function(e) {
            if (draggedItem) {
                draggedItem.style.opacity = '1';
                draggedItem = null;
                savePrincipleOrder();
            }
        });

        sortableList.addEventListener('dragover', function(e) {
            e.preventDefault();
            const targetItem = e.target.closest('.principle-sortable-item');
            if (targetItem && targetItem !== draggedItem) {
                const rect = targetItem.getBoundingClientRect();
                const next = (e.clientY - rect.top) / (rect.bottom - rect.top) > 0.5;
                sortableList.insertBefore(draggedItem, next ? targetItem.nextSibling : targetItem);
            }
        });
    }

    function savePrincipleOrder() {
        if (!sortableList) return;
        const items = sortableList.querySelectorAll('.principle-sortable-item');
        const orderData = [];
        items.forEach((item) => {
            orderData.push(item.getAttribute('data-id'));
        });

        const formData = new FormData();
        formData.append('update_principle_order', '1');
        formData.append('order_data', JSON.stringify(orderData));

        fetch('index.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (!data.success) {
                    console.error('순서 저장 실패');
                }
            })
            .catch(error => console.error('Error:', error));
    }

    function toggleWikiCore(event, icon) {
        event.preventDefault();
        event.stopPropagation();
        const wikiId = icon.getAttribute('data-wiki-id');
        const currentCore = parseInt(icon.getAttribute('data-core'));
        const newCore = currentCore === 1 ? 0 : 1;

        if (newCore === 1) {
            icon.style.color = 'orange';
            icon.setAttribute('data-core', 1);
        } else {
            icon.style.color = 'var(--text-muted)';
            icon.setAttribute('data-core', 0);
        }

        const formData = new FormData();
        formData.append('toggle_wiki_core', '1');
        formData.append('wiki_id', wikiId);
        formData.append('core_value', newCore);

        // 즉시 UI 반영 및 재정렬
        const liItem = icon.closest('li');
        if (liItem) {
            liItem.setAttribute('data-core', newCore);
            const listContainer = liItem.closest('ul');
            if (listContainer) {
                const items = Array.from(listContainer.querySelectorAll('li.wiki-list-item'));
                items.sort((a, b) => {
                    const coreA = parseInt(a.getAttribute('data-core') || 0);
                    const coreB = parseInt(b.getAttribute('data-core') || 0);
                    if (coreA !== coreB) {
                        return coreB - coreA;
                    }
                    const indexA = parseInt(a.getAttribute('data-index') || 0);
                    const indexB = parseInt(b.getAttribute('data-index') || 0);
                    return indexA - indexB;
                });
                items.forEach(item => listContainer.appendChild(item));
            }
        }

        fetch('index.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (!data.success) {
                    alert('저장 실패');
                }
            })
            .catch(error => console.error('Error:', error));
    }
</script>

<?php
require_once dirname(__FILE__) . '/footer.php';
