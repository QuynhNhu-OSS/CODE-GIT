<?php
// CẤU HÌNH HỆ THỐNG
$csvFile = 'tracks_with_clusters.csv'; // 

// Các đặc trưng âm thanh dùng để tính Cosine (nếu chạy mô hình Cosine)
$features = ["danceability", "energy", "loudness", "speechiness", "acousticness", "instrumentalness", "liveness", "valence", "tempo"];

// 1. HÀM ĐỌC DỮ LIỆU CSV
function loadData($filename) {
    $data = [];
    if (!file_exists($filename)) {
        return null;
    }
    if (($handle = fopen($filename, "r")) !== false) {
        $header = fgetcsv($handle);
        // Xử lý lỗi ký tự lạ đầu file (BOM) nếu có
        $header[0] = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $header[0]);
        $header = array_map('trim', $header); // Xóa khoảng trắng thừa
        
        while (($row = fgetcsv($handle)) !== false) {
            // Chỉ lấy dòng nào đủ dữ liệu
            if (count($header) == count($row)) {
                $data[] = array_combine($header, $row);
            }
        }
        fclose($handle);
    }
    return $data;
}

// 2. MÔ HÌNH 1: COSINE SIMILARITY (TÍNH TOÁN KHOẢNG CÁCH VECTOR)
function cosineSimilarity($vecA, $vecB) {
    $dot = 0; $normA = 0; $normB = 0;
    foreach ($vecA as $key => $val) {
        $vA = floatval($val);
        $vB = floatval($vecB[$key] ?? 0);
        $dot += $vA * $vB;
        $normA += $vA * $vA;
        $normB += $vB * $vB;
    }
    if ($normA == 0 || $normB == 0) return 0;
    return $dot / (sqrt($normA) * sqrt($normB));
}

function runCosineModel($allTracks, $targetTrack, $featuresList) {
    // Tạo vector cho bài hát mục tiêu
    $targetVec = [];
    foreach ($featuresList as $f) $targetVec[$f] = $targetTrack[$f];

    $candidates = [];
    foreach ($allTracks as $track) {
        // Bỏ qua chính bài hát đang tìm
        if ($track['track_name'] === $targetTrack['track_name']) continue;

        // Tạo vector cho bài hát gợi ý
        $candidateVec = [];
        foreach ($featuresList as $f) $candidateVec[$f] = $track[$f];

        // Tính độ giống nhau
        $score = cosineSimilarity($targetVec, $candidateVec);

        // Chỉ lấy những bài có độ giống > 0.8 để đảm bảo chất lượng
        if ($score > 0.8) {
            $candidates[] = [
                'track_name' => $track['track_name'],
                'artists'    => $track['artists'],
                'score'      => round($score * 100, 2) . '%', // Chuyển sang %
                'type'       => 'Cosine Score'
            ];
        }
    }

    // Sắp xếp điểm từ cao xuống thấp
    usort($candidates, function($a, $b) {
        return $b['score'] <=> $a['score'];
    });

    // Lấy 15 bài đầu tiên
    return array_slice($candidates, 0, 15);
}

// 3. MÔ HÌNH 2: K-MEANS CLUSTERING (LẤY THEO NHÓM)
function runKMeansModel($allTracks, $targetTrack) {
    // Kiểm tra xem file CSV có cột cluster_label không
    if (!isset($targetTrack['cluster_label'])) {
        return ['error' => 'File CSV thiếu cột "cluster_label". Hãy chạy Python để tạo lại file đúng.'];
    }

    $targetCluster = $targetTrack['cluster_label'];
    $results = [];

    foreach ($allTracks as $track) {
        // Lấy các bài hát có cùng cluster_label, trừ bài gốc
        if (isset($track['cluster_label']) && 
            $track['cluster_label'] == $targetCluster && 
            $track['track_name'] !== $targetTrack['track_name']) {
            
            $results[] = [
                'track_name' => $track['track_name'],
                'artists'    => $track['artists'],
                'score'      => 'Nhóm ' . $targetCluster,
                'type'       => 'Cluster ID'
            ];
        }
    }

    // K-Means không xếp hạng, nên ta trộn ngẫu nhiên (Shuffle) để gợi ý đa dạng
    shuffle($results);

    // Lấy 15 bài ngẫu nhiên
    return array_slice($results, 0, 15);
}

// XỬ LÝ REQUEST TỪ NGƯỜI DÙNG
$tracks = loadData($csvFile);
$results = [];
$errorMsg = '';
$targetInfo = null;
$searchQuery = '';
$selectedModel = 'cosine'; // Mặc định

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $searchQuery = trim($_POST['song_name'] ?? '');
    $selectedModel = $_POST['model'] ?? 'cosine';

    if (!$tracks) {
        $errorMsg = "Lỗi: Không tìm thấy file <b>$csvFile</b>. Vui lòng kiểm tra lại.";
    } elseif ($searchQuery) {
        // 1. Tìm bài hát trong dữ liệu
        $foundTrack = null;
        foreach ($tracks as $t) {
            // Tìm chính xác hoặc gần đúng (case-insensitive)
            if (stripos($t['track_name'], $searchQuery) !== false) {
                $foundTrack = $t;
                break; // Lấy bài đầu tiên tìm thấy
            }
        }

        if ($foundTrack) {
            $targetInfo = $foundTrack;
            
            // 2. Chạy mô hình được chọn
            if ($selectedModel === 'kmeans') {
                $results = runKMeansModel($tracks, $foundTrack);
            } else {
                $results = runCosineModel($tracks, $foundTrack, $features);
            }

            if (isset($results['error'])) {
                $errorMsg = $results['error'];
                $results = [];
            }
        } else {
            $errorMsg = "Không tìm thấy bài hát nào có tên: <b>" . htmlspecialchars($searchQuery) . "</b>";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hệ Thống Gợi Ý Âm Nhạc</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #121212; color: white; margin: 0; padding: 20px; }
        .container { max-width: 1000px; margin: 0 auto; }
        h1 { text-align: center; color: #1DB954; font-size: 2.5em; margin-bottom: 10px; }
        .subtitle { text-align: center; color: #b3b3b3; margin-bottom: 40px; }
        
        /* Form tìm kiếm */
        .search-box { background: #282828; padding: 20px; border-radius: 50px; display: flex; gap: 10px; align-items: center; justify-content: center; box-shadow: 0 4px 15px rgba(0,0,0,0.5); }
        input[type="text"] { padding: 15px 25px; border-radius: 30px; border: none; width: 40%; font-size: 16px; outline: none; }
        select { padding: 15px 25px; border-radius: 30px; border: none; background: #3E3E3E; color: white; font-size: 16px; cursor: pointer; outline: none; }
        button { padding: 15px 40px; border-radius: 30px; border: none; background: #1DB954; color: white; font-weight: bold; font-size: 16px; cursor: pointer; transition: 0.3s; }
        button:hover { background: #1ed760; transform: scale(1.05); }

        /* Kết quả */
        .result-header { margin-top: 40px; padding: 20px; background: rgba(255,255,255,0.1); border-left: 5px solid #1DB954; border-radius: 5px; display: flex; justify-content: space-between; align-items: center; }
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; margin-top: 20px; }
        .card { background: #181818; padding: 20px; border-radius: 10px; transition: 0.3s; border: 1px solid #282828; }
        .card:hover { background: #282828; transform: translateY(-5px); border-color: #1DB954; }
        .card h3 { margin: 0 0 10px 0; font-size: 1.1em; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .card p { color: #b3b3b3; margin: 0; font-size: 0.9em; }
        .tag { display: inline-block; background: #333; padding: 5px 10px; border-radius: 20px; font-size: 0.8em; margin-top: 15px; color: #1DB954; }
        
        .error { text-align: center; color: #ff5555; margin-top: 20px; font-size: 1.2em; }
    </style>
</head>
<body>

<div class="container">
    <h1>🎧 Music Recommender System</h1>
    <p class="subtitle">Hệ thống gợi ý âm nhạc sử dụng mô hình K-Means & Cosine Similarity</p>

    <form method="POST" class="search-box">
        <input type="text" name="song_name" placeholder="Nhập tên bài hát (VD: Shape of You, Hold On...)" value="<?php echo htmlspecialchars($searchQuery); ?>" required>
        
        <select name="model">
            <option value="cosine" <?php echo ($selectedModel == 'cosine') ? 'selected' : ''; ?>>📐 Cosine Similarity</option>
            <option value="kmeans" <?php echo ($selectedModel == 'kmeans') ? 'selected' : ''; ?>>🧩 K-Means</option>
        </select>

        <button type="submit">Tìm kiếm</button>
    </form>

    <?php if ($errorMsg): ?>
        <div class="error"><?php echo $errorMsg; ?></div>
    <?php endif; ?>

    <?php if ($targetInfo && !empty($results)): ?>
        <div class="result-header">
            <div>
                <h2 style="margin:0">Kết quả cho: <?php echo htmlspecialchars($targetInfo['track_name']); ?></h2>
                <p style="margin:5px 0 0 0; color:#ccc">Nghệ sĩ: <?php echo htmlspecialchars($targetInfo['artists']); ?></p>
            </div>
            <div style="text-align: right;">
                <span style="background:#333; padding:5px 15px; border-radius:15px; font-size:0.9em;">
                    Mô hình: <b><?php echo ($selectedModel == 'kmeans') ? 'K-Means (Phân cụm)' : 'Cosine (Độ đo vector)'; ?></b>
                </span>
                <?php if(isset($targetInfo['cluster_label'])): ?>
                    <br><small style="color:#888">Thuộc nhóm (Cluster): <?php echo $targetInfo['cluster_label']; ?></small>
                <?php endif; ?>
            </div>
        </div>

        <div class="grid">
            <?php foreach ($results as $item): ?>
                <div class="card">
                    <h3>🎵 <?php echo htmlspecialchars($item['track_name']); ?></h3>
                    <p>👤 <?php echo htmlspecialchars($item['artists']); ?></p>
                    <span class="tag"><?php echo $item['type']; ?>: <?php echo $item['score']; ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>

</body>
</html>