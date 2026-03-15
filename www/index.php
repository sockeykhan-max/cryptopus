<?php
require_once dirname(__FILE__) . '/header.php';
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
    }
}

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
$upbit_trade_results = [];
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
                        $profit_rate = ($trade['total_buy_settle'] > 0) ? ($trade['profit'] / $trade['total_buy_settle']) * 100 : 0;
                        $upbit_trade_results[] = [
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
                        $total_seven_days_profit += $trade['profit'];
                        $total_seven_days_buy_settle += $trade['total_buy_settle'];
                    }
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
                $profit_rate = ($trade['total_buy_settle'] > 0) ? ($trade['profit'] / $trade['total_buy_settle']) * 100 : 0;
                $upbit_trade_results[] = [
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
                $total_seven_days_profit += $trade['profit'];
                $total_seven_days_buy_settle += $trade['total_buy_settle'];
            }
        }
    }

    usort($upbit_trade_results, function ($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });
} catch (PDOException $e) {
    error_log("Upbit orders fetch error: " . $e->getMessage());
}

$wiki_search = $_GET['wiki_search'] ?? '';
$recent_wikis = [];
try {
    $wiki_query = "SELECT id, title, content, tv_image_url, youtube_url, image, created_at FROM strategy_wiki";
    $wiki_params = [];
    if (!empty($wiki_search)) {
        $wiki_query .= " WHERE title LIKE :search OR content LIKE :search";
        $wiki_params[':search'] = '%' . $wiki_search . '%';
    }
    $wiki_query .= " ORDER BY created_at DESC LIMIT 20";

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
        <div class="flex justify-between items-center">
            <h2>
                <i class="fa-solid fa-gauge-high"></i> Trading Overview
            </h2>
            <div class="sync-container">
                <a href="#" class="btn-upbit" id="btnSync">Upbit Orders</a>
                <div id="syncMsg" class="sync-popup"></div>
            </div>
        </div>
        <p style='padding-left: 35px;'>
            반갑습니다, <?php echo h($_SESSION['nickname']); ?> 님. 원칙 매매를 응원합니다!
        </p>
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

            const profitEl = document.getElementById('monthlyProfitValue');
            const color = profit > 0 ? '#c84a31' : (profit < 0 ? '#1261c4' : 'inherit');
            const sign = profit > 0 ? '+' : '';

            profitEl.style.color = color;
            profitEl.innerHTML = `${formatCurrencyStr(profit)}\n<div style="font-size: 0.85rem; font-weight: 600; margin-top: 2px;" id="monthlyProfitRate">(${sign}${rate.toFixed(2)}%)</div>`;
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
                                            <div class="principle-tag" style="padding: 4px 10px 3px; font-size: 11px; border-radius: 6px; background-color: <?php echo $tagColor['bg']; ?>; color: <?php echo $tagColor['text']; ?>; border: 1px solid <?php echo $tagColor['border']; ?>; white-space: nowrap; line-height: 1; box-shadow: 0 1px 2px rgba(0,0,0,0.05); font-weight: 600; display: inline-block; flex-shrink: 0; cursor: default; align-self: flex-start;"><?php echo h($principle['tag_text']); ?></div>
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
                                <span style="margin-bottom: 0;">전략위키</span>
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
                                <?php foreach ($recent_wikis as $wiki): ?>
                                    <li style="margin-bottom: 8px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                        <a href="#"
                                            data-title="<?php echo h($wiki['title']); ?>"
                                            data-content="<?php echo h($wiki['content']); ?>"
                                            data-tv-url="<?php echo h($wiki['tv_image_url']); ?>"
                                            data-youtube-url="<?php echo h($wiki['youtube_url']); ?>"
                                            data-image="<?php echo h($wiki['image']); ?>"
                                            onclick="openIndexWikiModal(this); return false;"
                                            style="color: var(--text-main); text-decoration: none; transition: color 0.2s;" onmouseover="this.style.color='var(--accent-color)'" onmouseout="this.style.color='var(--text-main)'">
                                            <i class="fa-solid fa-book-open" style="font-size: 0.75rem; color: var(--text-muted); margin-right: 5px;"></i> <?php echo h($wiki['title']); ?>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-5">
            <div class="card card-top-accent">
                <div style="text-align: center; padding-top: 15px; font-weight: 900; font-size: 1.1rem; color: #093687; letter-spacing: 1px;">
                    UPBIT
                </div>
                <div style="display: flex; justify-content: space-between; align-items: stretch; text-align: center; padding: 1.2rem 0.5rem;">
                    <!-- 최근 한달 -->
                    <div style="flex: 1; border-right: 1px solid var(--border-color); padding: 0 5px; display: flex; flex-direction: column; justify-content: center;">
                        <span style="color: var(--text-muted); font-size: 0.8rem; font-weight: bold; display: block; margin-bottom: 5px;">최근 한달</span>
                        <?php
                        $total_one_month_profit_rate = ($total_one_month_buy_settle > 0) ? ($total_one_month_profit / $total_one_month_buy_settle) * 100 : 0;
                        $profit_color = $total_one_month_profit > 0 ? '#c84a31' : ($total_one_month_profit < 0 ? '#1261c4' : 'inherit');
                        $sign = $total_one_month_profit > 0 ? '+' : '';
                        ?>
                        <div class="stat-value" style="font-size: 1.35rem; line-height: 1.2; color: <?php echo $profit_color; ?>;">
                            <?php echo format_num($total_one_month_profit); ?>
                            <div style="font-size: 0.85rem; font-weight: 600; margin-top: 2px;">(<?php echo $sign . number_format($total_one_month_profit_rate, 2); ?>%)</div>
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
                        $total_color = $total_profit > 0 ? '#c84a31' : ($total_profit < 0 ? '#1261c4' : 'inherit');
                        $total_sign = $total_profit > 0 ? '+' : '';
                        ?>
                        <div class="stat-value" style="font-size: 1.35rem; line-height: 1.2; color: <?php echo $total_color; ?>;">
                            <?php echo format_num($total_profit); ?>
                            <div style="font-size: 0.85rem; font-weight: 600; margin-top: 2px;">(<?php echo $total_sign . number_format($total_profit_rate_overall, 2); ?>%)</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card section-margin-top" style="padding: 24px;">
                <div class="section-header" style="display: flex; align-items: center;">
                    <h3 style="color: royalblue; margin: 0;"><i class="fa-solid fa-clipboard-list"></i> Upbit 매매 일지 (최근 7일)</h3>
                    <div style="margin-left: auto; margin-right: 15px; font-size: 0.95rem;">
                        <?php
                        if (!empty($upbit_trade_results)) {
                            $total_profit_rate = ($total_seven_days_buy_settle > 0) ? ($total_seven_days_profit / $total_seven_days_buy_settle) * 100 : 0;
                            $profit_color = $total_seven_days_profit > 0 ? '#c84a31' : ($total_seven_days_profit < 0 ? '#1261c4' : 'inherit');
                            echo '<span style="font-weight: bold; color: ' . $profit_color . ';">';
                            echo '총수익: ' . format_num($total_seven_days_profit) . ' (' . number_format($total_profit_rate, 2) . '%)';
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
                                                            <span style="padding: 2px 4px; font-size: 11px; border-radius: 4px; background-color: #eef2ff; color: #4f46e5; border: 1px solid #c7d2fe; white-space: nowrap; line-height: 1.2; box-shadow: 0 1px 2px rgba(0,0,0,0.05); font-weight: 600; display: inline-block; cursor: default;"><?php echo h($tag); ?></span>
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
                                                            <span style="padding: 2px 4px; font-size: 11px; border-radius: 4px; background-color: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; white-space: nowrap; line-height: 1.2; box-shadow: 0 1px 2px rgba(0,0,0,0.05); font-weight: 600; display: inline-block; cursor: default;"><?php echo h($tag); ?></span>
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
                                                            <span style="padding: 2px 4px; font-size: 11px; border-radius: 4px; background-color: #fffbeb; color: #d97706; border: 1px solid #fde68a; white-space: nowrap; line-height: 1.2; box-shadow: 0 1px 2px rgba(0,0,0,0.05); font-weight: 600; display: inline-block; cursor: default;"><?php echo h($tag); ?></span>
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
                                                            <span style="padding: 2px 4px; font-size: 11px; border-radius: 4px; background-color: #fff1f2; color: #e11d48; border: 1px solid #fecdd3; white-space: nowrap; line-height: 1.2; box-shadow: 0 1px 2px rgba(0,0,0,0.05); font-weight: 600; display: inline-block; cursor: default;"><?php echo h($tag); ?></span>
                                                    <?php endif;
                                                    endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: right; font-size: 14px; white-space: nowrap; color: <?php echo $trade['profit'] > 0 ? '#c84a31' : ($trade['profit'] < 0 ? '#1261c4' : 'inherit'); ?>;">
                                            <?php echo format_num($trade['profit']); ?>
                                            <span style="font-size: 12px; margin-left: 2px;">(<?php echo number_format($trade['profit_rate'], 2); ?>%)</span>
                                        </td>
                                        <td class="text-center" style="font-size: 14px;">
                                            <?php if (!empty(trim($trade['memo']))): ?>
                                                <i class="fa-solid fa-magnifying-glass" style="color: #999;"></i>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-5">
            <div class="card card-top-black">
                <div class="card-stats-item">
                    <div>
                        <span>TOTAL TRADES</span>
                        <div class="stat-value">0건</div>
                    </div>
                    <div class="stat-icon"><i class="fa-solid fa-layer-group"></i></div>
                </div>
            </div>
            <div class="card section-margin-top" style="padding: 24px;">
                <div class="section-header">
                    <h3><i class="fa-solid fa-clipboard-list"></i> OKX 매매 일지</h3>
                    <a href="trade_list.php">더보기</a>
                </div>
                <div class="empty-state-card-content" style="padding: 40px 20px; text-align: center;">
                    <p style="margin-bottom: 20px; color: #666;">기록된 매매 데이터가 없습니다.</p>
                </div>
            </div>
        </div>
    </div><!-- .row -->
</div><!-- .main-content-wrapper -->

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

                            const columnMap = {
                                'strategy_name': 2,
                                'entry_reason': 3,
                                'psychology_state': 4,
                                'failure_factor': 5
                            };
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
                                            html += `<span style="padding: 2px 5px; font-size: 11px; border-radius: 4px; background-color: ${bg}; color: ${color}; border: 1px solid ${border}; white-space: nowrap; line-height: 1.2; box-shadow: 0 1px 2px rgba(0,0,0,0.05); font-weight: 600; display: inline-block; cursor: default;">${tag}</span>`;
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

        currentTradeUuid = uuid;
        currentTradeTags = {
            strategy_name: strategyNameStr ? strategyNameStr.split(',').map(s => s.trim()).filter(s => s) : [],
            entry_reason: entryReasonStr ? entryReasonStr.split(',').map(s => s.trim()).filter(s => s) : [],
            psychology_state: psychologyStateStr ? psychologyStateStr.split(',').map(s => s.trim()).filter(s => s) : [],
            failure_factor: failureFactorStr ? failureFactorStr.split(',').map(s => s.trim()).filter(s => s) : []
        };
        Object.values(tagEditors).forEach(editor => editor.render());

        document.getElementById('modalMarketName').innerHTML = '<i class="fa-solid fa-receipt" style="color: #555; margin-right: 8px;"></i>' + market.replace('KRW-', '') + ' 매매 상세';

        document.getElementById('tradeMemo').value = memo || '';
        document.getElementById('tradeMemoUuid').value = uuid || '';

        document.getElementById('tradeTvUrl').value = tvUrl || '';
        if (tvUrl) {
            document.getElementById('btnViewTvLink').style.display = 'inline-block';
        } else {
            document.getElementById('btnViewTvLink').style.display = 'none';
        }

        const formatCurrency = (num) => Math.round(num).toLocaleString('ko-KR');
        const formatPrice = (num) => Math.round(num).toLocaleString('ko-KR');

        const tbody = document.getElementById('modalOrdersTbody');
        tbody.innerHTML = '';

        orders.forEach(order => {
            const tr = document.createElement('tr');

            const sideText = order.side === 'bid' ? '<span style="color: #c84a31; font-weight: bold;">매수</span>' : '<span style="color: #1261c4; font-weight: bold;">매도</span>';
            const price = parseFloat(order.avg_price);
            const volume = parseFloat(order.executed_volume);
            const totalAmount = price * volume;
            const fee = parseFloat(order.paid_fee);
            const settleAmount = parseFloat(order.settle_amount);

            let profitHtml = '';
            if (order.side === 'ask') {
                const orderProfit = parseFloat(order.profit || 0);
                const orderProfitRate = parseFloat(order.profit_rate || 0);
                const color = orderProfit > 0 ? '#c84a31' : (orderProfit < 0 ? '#1261c4' : 'inherit');
                const sign = orderProfit > 0 ? '+' : '';
                profitHtml = `<span style="color: ${color};">${formatCurrency(orderProfit)} (${sign}${orderProfitRate.toFixed(2)}%)</span>`;
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
            tbody.appendChild(tr);
        });

        const profitEl = document.getElementById('modalProfit');
        profitEl.innerText = formatCurrency(profit);
        profitEl.style.color = profit > 0 ? '#c84a31' : (profit < 0 ? '#1261c4' : 'inherit');

        const profitRateEl = document.getElementById('modalProfitRate');
        profitRateEl.innerText = profitRate.toFixed(2) + ' %';
        profitRateEl.style.color = profit > 0 ? '#c84a31' : (profit < 0 ? '#1261c4' : 'inherit');

        const viewChartBtn = document.getElementById('viewChartBtn');
        const tradingViewSymbol = 'UPBIT:' + market.replace('KRW-', '') + 'KRW';
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
                            lastCell.innerHTML = memo.trim() !== '' ? '<i class="fa-solid fa-magnifying-glass" style="color: #999;"></i>' : '';
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
</script>

<?php
require_once dirname(__FILE__) . '/footer.php';
