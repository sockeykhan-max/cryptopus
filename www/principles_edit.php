<?php
$pageTitle = '나의 매매 원칙 편집';
require_once dirname(__FILE__) . '/header.php';
check_login();

$userId = $_SESSION['user_id'];

// POST 요청 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_principle_order'])) {
        $orderData = json_decode($_POST['order_data'], true);
        if (is_array($orderData)) {
            $updateStmt = $pdo->prepare("UPDATE trading_principles SET principle_turn = ? WHERE id = ? AND user_id = ?");
            foreach ($orderData as $turn => $id) {
                $updateStmt->execute([$turn + 1, $id, $userId]);
            }
        }
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    }
    // 추가
    elseif (isset($_POST['add_principle'])) {
        $tagText = $_POST['tag_text'] ?? '';
        $tagClass = $_POST['tag_class'] ?? '';
        $principleText = $_POST['principle_text'] ?? '';
        if (!empty($principleText)) {
            $stmtMax = $pdo->prepare("SELECT MAX(principle_turn) FROM trading_principles WHERE user_id = ?");
            $stmtMax->execute([$userId]);
            $maxTurn = (int)$stmtMax->fetchColumn();
            $newTurn = $maxTurn + 1;

            $stmt = $pdo->prepare("INSERT INTO trading_principles (user_id, tag_text, tag_class, principle_text, principle_turn) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$userId, $tagText, $tagClass, $principleText, $newTurn]);
        }
    }
    // 수정
    elseif (isset($_POST['update_principle'])) {
        $id = $_POST['id'];
        $tagText = $_POST['tag_text'] ?? '';
        $tagClass = $_POST['tag_class'] ?? '';
        $principleText = $_POST['principle_text'] ?? '';
        if (!empty($principleText)) {
            $stmt = $pdo->prepare("UPDATE trading_principles SET tag_text = ?, tag_class = ?, principle_text = ? WHERE id = ? AND user_id = ?");
            $stmt->execute([$tagText, $tagClass, $principleText, $id, $userId]);
        }
    }
    // 삭제
    elseif (isset($_POST['delete_principle'])) {
        $id = $_POST['id'];
        $stmt = $pdo->prepare("DELETE FROM trading_principles WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $userId]);
    }
    header("Location: principles_edit.php");
    exit;
}

// 원칙 목록 가져오기
$stmt = $pdo->prepare("SELECT * FROM trading_principles WHERE user_id = ? ORDER BY principle_turn ASC, id ASC");
$stmt->execute([$userId]);
$principles = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<div class="max-w-1800 mx-auto p-4">
    <div class="flex justify-between items-center mb-4">
        <h1 class="text-2xl font-bold"><i class="fa-solid fa-shield-halved"></i> 나의 매매 원칙 편집</h1>
        <a href="index.php" class="button-secondary">돌아가기</a>
    </div>

    <div class="card p-6">
        <h2 class="text-xl font-bold mb-4">원칙 목록 <span style="font-size: 0.8rem; color: #888; font-weight: normal;">(드래그하여 순서를 변경할 수 있습니다)</span></h2>
        <?php if (empty($principles)): ?>
            <p class="text-gray-500">아직 설정된 원칙이 없습니다. 아래에서 새 원칙을 추가해주세요.</p>
        <?php else: ?>
            <div id="principleSortableList">
                <?php foreach ($principles as $p): ?>
                    <div class="principle-sortable-item" draggable="true" data-id="<?php echo h($p['id']); ?>" class="mb-4 p-4 border rounded-lg flex items-center gap-4 bg-white" style="cursor: grab; margin-bottom: 1rem; padding: 1rem; border: 1px solid #e2e8f0; border-radius: 0.5rem; display: flex; align-items: center; gap: 1rem;">
                        <div style="color: #aaa; padding: 0 5px; cursor: grab;"><i class="fa-solid fa-grip-vertical"></i></div>
                        <form action="principles_edit.php" method="POST" style="display: flex; flex: 1; align-items: center; gap: 1rem; margin: 0;">
                            <input type="hidden" name="id" value="<?php echo h($p['id']); ?>">
                            <div class="w-48 flex-none"><label class="block text-sm font-medium text-gray-700">태그명</label><input type="text" name="tag_text" value="<?php echo h($p['tag_text']); ?>" class="input-field mt-1" placeholder="예: STOP-LOSS"></div>
                            <div class="flex-1"><label class="block text-sm font-medium text-gray-700">원칙 내용</label><input type="text" name="principle_text" value="<?php echo h($p['principle_text']); ?>" class="input-field mt-1" required></div>
                            <div class="flex items-end gap-2" style="margin-top: 24px;">
                                <button type="submit" name="update_principle" class="button-primary">수정</button>
                                <button type="submit" name="delete_principle" class="button-danger" onclick="return confirm('정말 삭제하시겠습니까?');">삭제</button>
                            </div>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="card p-6 mt-6">
        <h2 class="text-xl font-bold mb-4">새 원칙 추가</h2>
        <form action="principles_edit.php" method="POST" class="flex items-center gap-4">
            <div class="w-48 flex-none"><label class="block text-sm font-medium text-gray-700">태그명</label><input type="text" name="tag_text" class="input-field mt-1" placeholder="예: STOP-LOSS"></div>
            <div class="flex-1"><label class="block text-sm font-medium text-gray-700">원칙 내용</label><input type="text" name="principle_text" class="input-field mt-1" required></div>
            <div class="flex items-end"><button type="submit" name="add_principle" class="button-new">추가</button></div>
        </form>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
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

            fetch('principles_edit.php', {
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
    });
</script>

<?php
require_once dirname(__FILE__) . '/footer.php';
?>