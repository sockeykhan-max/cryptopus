<?php
$pageTitle = '전략위키';
require_once dirname(__FILE__) . '/header.php';
check_login();
?>

<div class="flex justify-between items-center mb-4 max-w-1800 mx-auto p-4">
    <h1 class="text-2xl font-bold"><i class="fa-solid fa-book"></i> 전략위키</h1>
    <a href="#" class="button-new" id="newWikiButton">NEW</a>
</div>
<?php

// 페이징 설정
$itemsPerPage = 15;
$currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($currentPage - 1) * $itemsPerPage;

// 총 위키 개수 가져오기
try {
    $searchKeyword = isset($_GET['search']) ? trim($_GET['search']) : '';

    $whereClause = '';
    if (!empty($searchKeyword)) {
        $whereClause = " WHERE title LIKE :searchKeyword OR content LIKE :searchKeyword";
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM strategy_wiki" . $whereClause);
    if (!empty($searchKeyword)) {
        $stmt->bindValue(':searchKeyword', '%' . $searchKeyword . '%', PDO::PARAM_STR);
    }
    $stmt->execute();
    $totalItems = $stmt->fetchColumn();
} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    $totalItems = 0;
}

$totalPages = ceil($totalItems / $itemsPerPage);

// 위키 목록 가져오기
$wikis = [];
if ($totalItems > 0) {
    try {
        $stmt = $pdo->prepare("SELECT sw.id, sw.title, sw.content, sw.created_at, sw.tv_image_url, sw.youtube_url, sw.image FROM strategy_wiki sw JOIN users u ON sw.user_id = u.id" . $whereClause . " ORDER BY sw.created_at DESC LIMIT :limit OFFSET :offset");
        if (!empty($searchKeyword)) {
            $stmt->bindValue(":searchKeyword", "%" . $searchKeyword . "%", PDO::PARAM_STR);
        }
        $stmt->bindParam(':limit', $itemsPerPage, PDO::PARAM_INT);
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $wikis = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Database error: " . $e->getMessage());
        $wikis = [];
    }
}

// XSS 방지 함수 (common.php 에 정의되어야 함)
// function h($string) { return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8'); }
require_once dirname(__FILE__) . '/common.php'; // h() 함수가 common.php에 있다고 가정

?>

<div class="max-w-1800 mx-auto p-4 table-container">

    <div class="card p-4 mb-4 flex justify-center">
        <form action="" method="GET" class="flex items-center gap-4 mx-auto study-wiki-search-form">
            <input type="text" name="search" placeholder="제목 또는 내용 검색" class="input-field" style="width: 200px;" value="<?php echo h($searchKeyword); ?>">
            <button type="submit" class="button-primary flex-shrink-0">검색</button>
            <?php if (!empty($searchKeyword)): ?>
                <button type="button" onclick="location.href='study_wiki.php'" class="button-secondary flex-shrink-0">초기화</button>
            <?php endif; ?>
            <span class="text-gray-600 ml-2">총 <?php echo h($totalItems); ?>개</span>

        </form>
    </div>
    <!-- 상단 페이징 -->
    <?php if ($totalPages > 1): ?>
        <div class="pagination flex justify-center items-center gap-2 mb-8">
            <?php if ($currentPage > 1): ?>
                <a href="?page=<?php echo $currentPage - 1; ?><?php echo (!empty($searchKeyword) ? '&search=' . h($searchKeyword) : ''); ?>" class="pagination-button">&laquo; 이전</a>
            <?php endif; ?>

            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <a href="?page=<?php echo $i; ?><?php echo (!empty($searchKeyword) ? '&search=' . h($searchKeyword) : ''); ?>" class="pagination-button <?php echo ($i == $currentPage) ? 'active' : ''; ?>"><?php echo $i; ?></a>
            <?php endfor; ?>

            <?php if ($currentPage < $totalPages): ?>
                <a href="?page=<?php echo $currentPage + 1; ?><?php echo (!empty($searchKeyword) ? '&search=' . h($searchKeyword) : ''); ?>" class="pagination-button">다음 &raquo;</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <div class="wiki-list mt-15px">
        <?php if (!empty($wikis)): ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 5%;">작성일</th>
                        <th style="width: 55%;">제목&내용</th>
                        <th style="width: 5%;">유튜브</th>
                        <th style="width: 35%;">차트이미지</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($wikis as $wiki): ?>
                        <tr>
                            <td data-label="작성일" class="text-center" style="width: 5%;">
                                <?php echo h(date('y.m.d', strtotime($wiki['created_at']))); ?>
                                <br>
                                <button type="button" class="button-danger delete-wiki-button" data-wiki-id="<?php echo h($wiki["id"]); ?>" style="margin-top: 5px;">삭제</button>
                            </td>
                            <td data-label="제목&내용" style="width: 55%;">
                                <a href="#" class="wiki-edit-link"
                                    data-wiki-id="<?php echo h($wiki['id']); ?>"
                                    data-wiki-title="<?php echo h($wiki['title']); ?>"
                                    data-wiki-content="<?php echo h($wiki['content']); ?>"
                                    data-tv-image-url="<?php echo h($wiki['tv_image_url']); ?>"
                                    data-youtube-url="<?php echo h($wiki['youtube_url']); ?>"
                                    data-wiki-image="<?php echo h($wiki['image']); ?>">
                                    <div class="wiki-title" style="margin-bottom: 10px;"><?php echo h($wiki['title']); ?></div>
                                </a>
                                <?php echo nl2br($wiki['content']); ?>
                            </td>
                            <td data-label="유튜브" class="text-center" style="width: 5%;">
                                <?php if (!empty($wiki['youtube_url'])): ?>
                                    <a href="<?php echo h($wiki['youtube_url']); ?>" target="_blank" rel="noopener noreferrer" class="youtube-thumbnail-link">
                                        <i class="fa-brands fa-youtube text-red-600 youtube-icon-large"></i>
                                    </a>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td data-label="차트이미지" class="text-center" style="width: 35%;">
                                <div>
                                    <?php
                                    $images = [];
                                    if (!empty($wiki['tv_image_url'])) {
                                        $images[] = $wiki['tv_image_url'];
                                    }
                                    if (!empty($wiki['image'])) {
                                        $wikiImages = explode('|', $wiki['image']);
                                        $images = array_merge($images, $wikiImages);
                                    }
                                    $images = array_filter($images); // 빈 값 제거

                                    if (!empty($images)):
                                        foreach ($images as $imgUrl):
                                    ?>
                                            <img src="<?php echo h(trim($imgUrl)); ?>" alt="Chart Image" class="wiki-chart-image cursor-pointer w-16 h-16 object-cover block mx-auto mb-1" loading="lazy" data-fullsize-url="<?php echo h(trim($imgUrl)); ?>">
                                        <?php
                                        endforeach;
                                    else:
                                        ?>
                                        -
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="text-center text-gray-500">아직 작성된 전략 위키가 없습니다.</p>
        <?php endif; ?>
    </div>

    <!-- 페이징 -->
    <?php if ($totalPages > 1): ?>
        <div class="pagination flex justify-center items-center gap-2 mt-8">
            <?php if ($currentPage > 1): ?>
                <a href="?page=<?php echo $currentPage - 1; ?><?php echo (!empty($searchKeyword) ? '&search=' . h($searchKeyword) : ''); ?>" class="pagination-button">&laquo; 이전</a>
            <?php endif; ?>

            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <a href="?page=<?php echo $i; ?><?php echo (!empty($searchKeyword) ? '&search=' . h($searchKeyword) : ''); ?>" class="pagination-button <?php echo ($i == $currentPage) ? 'active' : ''; ?>"><?php echo $i; ?></a>
            <?php endfor; ?>

            <?php if ($currentPage < $totalPages): ?>
                <a href="?page=<?php echo $currentPage + 1; ?><?php echo (!empty($searchKeyword) ? '&search=' . h($searchKeyword) : ''); ?>" class="pagination-button">다음 &raquo;</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<div id="wikiModal" class="modal">
    <div class="modal-content">

        <h2 class="text-xl font-bold mb-4" id="wikiModalTitle">전략위키 작성</h2>
        <form id="wikiForm">
            <input type="hidden" id="wiki_id" name="wiki_id">

            <div class="mb-4">
                <label for="title" class="block text-gray-700 font-bold mb-2">제목:</label>
                <input type="text" id="title" name="title" class="input-field" required>
            </div>
            <div class="mb-4">
                <label for="content" class="block text-gray-700 font-bold mb-2">내용:</label>
                <textarea id="content" name="content" class="textarea-field" rows="20" required></textarea>
            </div>
            <div class="mb-4">
                <label for="tv_image_url" class="block text-gray-700 font-bold mb-2">트레이딩뷰 이미지 주소 (URL):</label>
                <input type="url" id="tv_image_url" name="tv_image_url" class="input-field">
            </div>
            <div class="mb-4">
                <label for="images" class="block text-gray-700 font-bold mb-2">이미지 파일 (다중 선택 가능):</label>
                <input type="file" id="images" name="images[]" class="input-field" multiple accept="image/*">
                <div id="imagePreviewContainer" class="mt-2"></div>
            </div>
            <div class="mb-4">
                <label for="youtube_url" class="block text-gray-700 font-bold mb-2">유튜브 영상 주소:</label>
                <input type="url" id="youtube_url" name="youtube_url" class="input-field">
            </div>
            <div class="flex justify-end gap-4">
                <button type="submit" class="button-primary">저장</button>
                <button type="button" class="button-secondary wiki-cancel-button">취소</button>
            </div>
        </form>
    </div>
</div>

<!-- 이미지 팝업 모달 -->
<div id="imageModal" class="modal">
    <div class="modal-content-image">
        <span class="close-button-image">&times;</span>
        <img id="fullSizeImage" src="" alt="Full Size Image" class="max-w-full h-auto">
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const modal = document.getElementById('wikiModal');
        const wikiModalTitle = document.getElementById('wikiModalTitle');
        const newWikiButton = document.getElementById('newWikiButton');
        const closeButtons = document.querySelectorAll('.close-button, .wiki-cancel-button');
        const wikiForm = document.getElementById('wikiForm');
        const wikiIdInput = document.getElementById('wiki_id');
        const titleInput = document.getElementById('title');
        const contentInput = document.getElementById('content');
        const tvImageUrlInput = document.getElementById('tv_image_url');
        const youtubeUrlInput = document.getElementById('youtube_url');

        function openWikiModal(id = '', title = '', content = '', tvImageUrl = '', youtubeUrl = '', wikiImages = '') {
            wikiIdInput.value = id;
            titleInput.value = title;
            contentInput.value = content;
            tvImageUrlInput.value = tvImageUrl;
            youtubeUrlInput.value = youtubeUrl;

            const imagePreviewContainer = document.getElementById('imagePreviewContainer');
            imagePreviewContainer.innerHTML = ''; // 기존 미리보기 초기화

            if (wikiImages) {
                const imagesArray = wikiImages.split('|');
                imagesArray.forEach(imageUrl => {
                    const img = document.createElement('img');
                    img.src = imageUrl.trim();
                    img.classList.add('block', 'w-full', 'h-auto', 'object-cover', 'rounded-md', 'border', 'border-gray-300', 'mb-2', 'wiki-preview-image-max-width');
                    imagePreviewContainer.appendChild(img);
                });
            }

            if (id) {
                wikiModalTitle.textContent = '전략위키 수정';
            } else {
                wikiModalTitle.textContent = '전략위키 작성';
            }
            modal.style.display = 'flex';
        }

        newWikiButton.addEventListener('click', function(e) {
            e.preventDefault();
            wikiForm.reset(); // 폼 초기화
            document.getElementById('imagePreviewContainer').innerHTML = ''; // 이미지 미리보기 초기화
            openWikiModal(); // 새 위키 작성 모드로 열기
        });

        document.querySelectorAll('.wiki-edit-link').forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                const wikiId = this.dataset.wikiId;
                const wikiTitle = this.dataset.wikiTitle;
                const wikiContent = this.dataset.wikiContent;
                const tvImageUrl = this.dataset.tvImageUrl;
                const youtubeUrl = this.dataset.youtubeUrl;
                const wikiImages = this.dataset.wikiImage; // 추가
                openWikiModal(wikiId, wikiTitle, wikiContent, tvImageUrl, youtubeUrl, wikiImages); // 위키 수정 모드로 열기
            });
        });

        closeButtons.forEach(button => {
            button.addEventListener('click', function() {
                modal.style.display = 'none';
            });
        });

        window.addEventListener('click', function(event) {
            if (event.target == modal) {
                modal.style.display = 'none';
            }
        });

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
                        form.reset(); // 폼 초기화
                        modal.style.display = 'none';
                        location.reload(); // 페이지 새로고침
                    } else {
                        alert('저장 실패: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('오류 발생: 서버와 통신할 수 없습니다.');
                });
        });

        // 이미지 팝업 로직
        const imageModal = document.getElementById('imageModal');
        const fullSizeImage = document.getElementById('fullSizeImage');
        const closeButtonImage = document.querySelector('.close-button-image');

        document.querySelectorAll('.wiki-chart-image').forEach(image => {
            image.addEventListener('click', function() {
                const fullSizeUrl = this.getAttribute('data-fullsize-url');
                if (fullSizeUrl) {
                    fullSizeImage.src = fullSizeUrl;
                    imageModal.style.display = 'flex';
                }
            });
        });

        closeButtonImage.addEventListener('click', function() {
            imageModal.style.display = 'none';
        });

        window.addEventListener('click', function(event) {
            if (event.target == imageModal) {
                imageModal.style.display = 'none';
            }
        });

        // 위키 삭제 로직
        document.querySelectorAll('.delete-wiki-button').forEach(button => {
            button.addEventListener('click', function() {
                const wikiId = this.dataset.wikiId;
                if (confirm('정말로 이 위키를 삭제하시겠습니까?')) {
                    fetch('delete_wiki.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                            },
                            body: `wiki_id=${wikiId}`
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                alert(data.message);
                                location.reload(); // 페이지 새로고침
                            } else {
                                alert('삭제 실패: ' + data.message);
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            alert('오류 발생: 서버와 통신할 수 없습니다.');
                        });
                }
            });
        });

    });
</script>
<?php
require_once dirname(__FILE__) . '/footer.php';
?>