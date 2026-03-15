<?php
require_once dirname(__FILE__) . '/common.php';

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

        // 파일 업로드 처리
        $uploadedImageUrls = [];
        $uploadDir = 'image/'; // 이미지 저장 폴더 (루트 기준)

        // image 폴더가 없으면 생성
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        if (isset($_FILES['images']) && is_array($_FILES['images']['name'])) {
            foreach ($_FILES['images']['name'] as $key => $name) {
                if ($_FILES['images']['error'][$key] == UPLOAD_ERR_OK) {
                    $tmp_name = $_FILES['images']['tmp_name'][$key];
                    $fileExtension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                    $fileName = uniqid() . '.' . $fileExtension; // 고유한 파일 이름 생성
                    $targetFilePath = $uploadDir . $fileName;

                    $compressionQuality = 65; // JPEG 품질 (0-100, 높을수록 고품질)

                    $pngCompressionLevel = 6; // PNG 압축 레벨 (0-9, 높을수록 압축률 높음)

                    // 이미지 타입에 따라 압축 처리
                    $image = null;
                    // 파일이 실제로 업로드되었는지 확인 후 exif_imagetype 호출
                    if (file_exists($tmp_name) && is_uploaded_file($tmp_name)) {
                        $imageType = exif_imagetype($tmp_name);

                        if ($imageType == IMAGETYPE_JPEG) {
                            $image = imagecreatefromjpeg($tmp_name);
                        } elseif ($imageType == IMAGETYPE_PNG) {
                            $image = imagecreatefrompng($tmp_name);
                            // PNG 알파 채널 유지
                            imagealphablending($image, false);
                            imagesavealpha($image, true);
                        } elseif ($imageType == IMAGETYPE_GIF) {
                            $image = imagecreatefromgif($tmp_name);
                        }
                    }

                    if ($image) {
                        $width = imagesx($image);
                        $height = imagesy($image);

                        $maxWidth = 1000;

                        if ($width > $maxWidth) {
                            $ratio = $maxWidth / $width;
                            $newWidth = $maxWidth;
                            $newHeight = $height * $ratio;

                            $resizedImage = imagecreatetruecolor($newWidth, $newHeight);

                            if ($imageType == IMAGETYPE_PNG) {
                                imagealphablending($resizedImage, false);
                                imagesavealpha($resizedImage, true);
                                imagefill($resizedImage, 0, 0, imagecolorallocatealpha($resizedImage, 0, 0, 0, 127));
                            }

                            imagecopyresampled($resizedImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
                            imagedestroy($image); // 원본 이미지 파괴
                            $image = $resizedImage; // 리사이즈된 이미지로 교체
                        }

                        // 압축된 이미지를 새로운 파일명으로 저장
                        if ($imageType == IMAGETYPE_JPEG) {
                            imageinterlace($image, 1); // 점진적(Progressive) JPEG 설정
                            imagejpeg($image, $targetFilePath, $compressionQuality);
                        } elseif ($imageType == IMAGETYPE_PNG) {
                            imagepng($image, $targetFilePath, $pngCompressionLevel);
                        } elseif ($imageType == IMAGETYPE_GIF) {
                            imagegif($image, $targetFilePath);
                        }
                        imagedestroy($image); // 처리된 이미지 파괴
                        $uploadedImageUrls[] = $targetFilePath; // 압축된 이미지 경로 추가
                    } else {
                        // 이미지 파일이 아니거나 처리할 수 없는 형식일 경우 원본 파일을 이동
                        if (move_uploaded_file($tmp_name, $targetFilePath)) {
                            $uploadedImageUrls[] = $targetFilePath;
                        } else {
                            error_log("Failed to move uploaded file: " . $tmp_name . " to " . $targetFilePath);
                        }
                    }
                } else {
                    error_log("File upload error for " . $name . ": " . $_FILES['images']['error'][$key]);
                }
            }
        }

        $imageColumnValue = !empty($uploadedImageUrls) ? implode('|', $uploadedImageUrls) : null;
        // 기존 이미지 URL이 있다면 가져와서 병합
        if ($wikiId) {
            $existingImageStmt = $pdo->prepare("SELECT image FROM strategy_wiki WHERE id = :wiki_id");
            $existingImageStmt->bindParam(':wiki_id', $wikiId, PDO::PARAM_INT);
            $existingImageStmt->execute();
            $existingImageRow = $existingImageStmt->fetch(PDO::FETCH_ASSOC);

            if ($existingImageRow && !empty($existingImageRow['image'])) {
                $existingImages = explode('|', $existingImageRow['image']);
                // 새로 업로드된 이미지와 기존 이미지를 병합 (중복 방지는 필요에 따라 추가)
                $mergedImages = array_merge($existingImages, $uploadedImageUrls);
                $imageColumnValue = implode('|', array_filter(array_map('trim', array_unique($mergedImages))));
            } else if (empty($uploadedImageUrls)) {
                // 수정 모드이고, 새로 업로드된 이미지가 없으며, 기존 이미지도 없으면 null
                $imageColumnValue = null;
            }
        }
        if (empty($title) || empty($content)) {
            $response = ['success' => false, 'message' => '제목과 내용을 입력해주세요.'];
        } else {
            try {
                if ($wikiId) {
                    // 수정
                    $stmt = $pdo->prepare("UPDATE strategy_wiki SET title = :title, content = :content, tv_image_url = :tv_image_url, youtube_url = :youtube_url, tags = :tags, image = :image WHERE id = :wiki_id AND user_id = :user_id");
                    $stmt->bindParam(':wiki_id', $wikiId, PDO::PARAM_INT);
                    $stmt->bindParam(':title', $title, PDO::PARAM_STR);
                    $stmt->bindParam(':content', $content, PDO::PARAM_STR);
                    $stmt->bindParam(':tv_image_url', $tvImageUrl, PDO::PARAM_STR);
                    $stmt->bindParam(':youtube_url', $youtubeUrl, PDO::PARAM_STR);
                    $stmt->bindParam(':tags', $tags, PDO::PARAM_STR);
                    $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
                    $stmt->bindParam(':image', $imageColumnValue, PDO::PARAM_STR);

                    if ($stmt->execute()) {
                        $response = ['success' => true, 'message' => '전략 위키가 성공적으로 수정되었습니다.'];
                    } else {
                        $response = ['success' => false, 'message' => '위키 수정에 실패했습니다.'];
                    }
                } else {
                    // 신규 작성
                    $stmt = $pdo->prepare("INSERT INTO strategy_wiki (user_id, title, content, tv_image_url, youtube_url, tags, image) VALUES (:user_id, :title, :content, :tv_image_url, :youtube_url, :tags, :image)");
                    $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
                    $stmt->bindParam(':title', $title, PDO::PARAM_STR);
                    $stmt->bindParam(':content', $content, PDO::PARAM_STR);
                    $stmt->bindParam(':tv_image_url', $tvImageUrl, PDO::PARAM_STR);
                    $stmt->bindParam(':youtube_url', $youtubeUrl, PDO::PARAM_STR);
                    $stmt->bindParam(':tags', $tags, PDO::PARAM_STR);
                    $stmt->bindParam(':image', $imageColumnValue, PDO::PARAM_STR);

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
