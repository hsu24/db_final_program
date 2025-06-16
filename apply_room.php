<?php
// apply_room.php
require_once 'config.php'; // 引入資料庫連接設定

// 從資料庫抓出所有不同的 building（棟別）
$buildingStmt = $pdo->query("SELECT DISTINCT building
                             FROM dormitory
                             ORDER BY building");
$buildings = $buildingStmt->fetchAll(PDO::FETCH_COLUMN);

$message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    //讀取學生輸入資訊
    $dorm_id_input = $_POST['dorm_ID'] ?? '';
    $building_input = $_POST['building'] ?? '';
    $student_id_input = $_POST['stu_ID'] ?? '';
    $student_name_input = $_POST['name'] ?? '';


    // 簡單的輸入驗證
    if (empty($dorm_id_input) || empty($building_input) || empty($student_id_input) || empty($student_name_input)) {
        $message = "請填寫所有必填欄位 (宿舍ID, 樓棟, 學號, 姓名)。";
    } else {
        try {
            // 先檢查 dormitory 是否存在
            $sql = "SELECT *
                    FROM dormitory
                    WHERE dorm_ID = :dorm_ID AND building = :building";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':dorm_ID' => $dorm_id_input,
                ':building' => $building_input
            ]);

            $stmt->execute();
            $dormitory = $stmt->fetch();

            if (!$dormitory) {
                $message = "找不到指定的宿舍，請確認 Dorm ID 與 Building 是否正確。";
                echo "<script>
                            alert('找不到指定的宿舍，請確認 Dorm ID 與 Building 是否正確。');
                            window.location.href = 'apply_room.php';
                    </script>";
            } else {
                //檢查學生是否存在
                $stmt = $pdo->prepare("SELECT *
                                       FROM student
                                       WHERE stu_ID = :stu_ID AND name = :name");
                $stmt->execute([
                    ':stu_ID' => $student_id_input,
                    ':name' => $student_name_input
                ]);
                $existing_student = $stmt->fetch();

                if (!$existing_student) {
                    $message = "找不到學生資料，請確認 Student ID 與 Name 是否正確。";
                    echo "<script>
                                alert('找不到學生資料，請確認 Student ID 與 Name 是否正確。');
                                window.location.href = 'apply_room.php';
                        </script>";
                }
                // 從資料表中取出 budget 值
                $student_budget_input = $existing_student['budget'];
                // 預算檢查
                if ($dormitory['price'] > $student_budget_input && !isset($_POST['confirm_overbudget'])) {
                    echo "<form id='overBudgetForm' method='POST' action='apply_room.php'>";
                    echo "<input type='hidden' name='stu_ID' value='" . htmlspecialchars($student_id_input) . "'>";
                    echo "<input type='hidden' name='name' value='" . htmlspecialchars($student_name_input) . "'>";
                    echo "<input type='hidden' name='budget' value='" . htmlspecialchars($student_budget_input) . "'>";
                    echo "<input type='hidden' name='building' value='" . htmlspecialchars($building_input) . "'>";
                    echo "<input type='hidden' name='dorm_ID' value='" . htmlspecialchars($dorm_id_input) . "'>";
                    echo "<input type='hidden' name='confirm_overbudget' value='1'>";
                    echo "</form>";

                    echo "<script>
                        if (confirm('此房間價格 ({$dormitory['price']} 元) 超出學生預算（{$student_budget_input} 元），是否仍要申請？')) {
                            document.getElementById('overBudgetForm').submit();
                        } else {
                            alert('已取消申請，返回申請頁面');
                            window.location.href = 'apply_room.php';
                        }
                    </script>";
                    exit;
                }

                $pdo->beginTransaction(); // 開始事務

                if (!$existing_student) {
                    $message = "找不到學生資料，請確認 Student ID 與 Name 是否正確。";
                    echo "<script>
                                alert('找不到學生資料，請確認 Student ID 與 Name 是否正確。');
                                window.location.href = 'apply_room.php';
                        </script>";
                } else {
                    if (isset($_POST['confirm_reassign'])) {
                        // 若為重新分配，先刪除原有assignment
                        $stmt = $pdo->prepare("DELETE FROM assignment WHERE stu_ID = :stu_ID");
                        $stmt->execute([':stu_ID' => $student_id_input]);
                    }

                    //檢查是否已分配宿舍
                    $stmt = $pdo->prepare("SELECT COUNT(*)
                                           FROM assignment
                                           WHERE stu_ID = :stu_ID");
                    $stmt->execute([':stu_ID' => $student_id_input]);
                    if ($stmt->fetchColumn() > 0 && !isset($_POST['confirm_reassign'])) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        echo "<form id='confirmReassignForm' method='POST' action='apply_room.php'>";
                        echo "<input type='hidden' name='stu_ID' value='" . htmlspecialchars($student_id_input) . "'>";
                        echo "<input type='hidden' name='name' value='" . htmlspecialchars($student_name_input) . "'>";
                        echo "<input type='hidden' name='budget' value='" . htmlspecialchars($student_budget_input) . "'>";
                        echo "<input type='hidden' name='building' value='" . htmlspecialchars($building_input) . "'>";
                        echo "<input type='hidden' name='dorm_ID' value='" . htmlspecialchars($dorm_id_input) . "'>";
                        echo "<input type='hidden' name='confirm_reassign' value='1'>";
                        echo "<input type='hidden' name='confirm_overbudget' value='1'>";
                        echo "</form>";

                        echo "<script>
                            if (confirm('此學生已經分配過房間，是否要重新分配？')) {
                                document.getElementById('confirmReassignForm').submit();
                            } else {
                                alert('已取消申請，返回宿舍申請頁面');
                                window.location.href = 'apply_room.php';
                            }
                        </script>";
                        exit;
                    }
                }

                //查找宿舍資訊並檢查是否有空床位
                $stmt = $pdo->prepare("SELECT dorm_ID, building, capacity, price
                                       FROM dormitory
                                       WHERE dorm_ID = :dorm_ID AND building = :building_name");
                $stmt->execute([':dorm_ID' => $dorm_id_input, ':building_name' => $building_input]);
                $dormitory = $stmt->fetch();

                if (!$dormitory) {
                    $message = "找不到指定的宿舍。";
                    $pdo->rollBack();
                }
                //判斷學生性別
                $stmt = $pdo->prepare("SELECT gender
                                       FROM student
                                       WHERE stu_ID = :stu_ID AND name = :name");
                $stmt->execute([
                    ':stu_ID' => $_POST['stu_ID'],
                    ':name' => $_POST['name']
                ]);
                $studentGender = $stmt->fetchColumn();
                // 判斷宿舍性別限制
                $stmt = $pdo->prepare("SELECT gender
                                       FROM dormitory
                                       WHERE building = :building LIMIT 1");
                $stmt->execute([
                    ':building' => $_POST['building']
                ]);
                $buildingGender = $stmt->fetchColumn();
                //判斷性別是否符合
                if ($studentGender !== $buildingGender) {
                    echo "<script>
                        alert('所選棟別的性別與學生性別不符，請重新選擇。');
                        window.location.href = 'apply_room.php';
                    </script>";
                    exit;
                }

                // 獲取該宿舍已分配的床位號
                $stmt = $pdo->prepare("SELECT bed_number
                                       FROM assignment
                                       WHERE dorm_ID = :dorm_ID AND building = :building_name
                                       ORDER BY bed_number ASC");
                $stmt->execute([':dorm_ID' => $dorm_id_input, ':building_name' => $building_input]);
                $assigned_beds = $stmt->fetchAll(PDO::FETCH_COLUMN);

                $next_bed_number = 1;
                for ($i = 1; $i <= $dormitory['capacity']; $i++) {
                    if (!in_array($i, $assigned_beds)) {
                        $next_bed_number = $i;
                        break;
                    }
                    $next_bed_number++; // 如果找到的是已分配的床位，繼續找下一個
                }

                if ($next_bed_number > $dormitory['capacity']) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    echo "<script>
                        alert('此房間已滿，請選擇其他房號。');
                        window.location.href = 'apply_room.php';
                    </script>";
                    exit;

                }

                //插入新的分配記錄
                $stmt = $pdo->prepare("INSERT INTO assignment (stu_ID, dorm_ID, building, bed_number)
                                       VALUES (:stu_ID, :dorm_ID, :building, :bed_number)");
                $stmt->execute([
                    ':stu_ID' => $student_id_input,
                    ':dorm_ID' => $dormitory['dorm_ID'],
                    ':building' => $dormitory['building'],
                    ':bed_number' => $next_bed_number
                ]);;

                $pdo->commit(); // 提交事務

                $message = "房間申請成功！您的床位號是: " . $next_bed_number;
            }

        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }// 如果發生錯誤，回滾事務
            $message = "申請失敗：" . $e->getMessage();
            error_log("Apply Room Error: " . $e->getMessage()); // 記錄錯誤到伺服器日誌
        }

    }
}
?>

<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>學生宿舍系統 - 申請房間</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 0; background-color: #f4f4f4; }
        .container { display: flex; width: 100%; max-width: 1200px; margin: 20px auto; background-color: #fff; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        .sidebar { width: 200px; background-color: #f0f0f0; padding: 20px; border-right: 1px solid #ddd; }
        .sidebar button {
            display: block;
            width: 100%;
            padding: 10px;
            margin-bottom: 10px;
            border: none;
            background-color: #007bff;
            color: white;
            text-align: center;
            text-decoration: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
        }
        .sidebar button.active { background-color: #0056b3; }
        .sidebar button:hover { opacity: 0.9; }
        .main-content { flex-grow: 1; padding: 20px; }
        h1 { text-align: center; color: #333; margin-bottom: 30px; }
        .form-section { background-color: #e9ecef; padding: 20px; border-radius: 5px; margin-bottom: 20px; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: bold; }
        .form-group input[type="text"],
        .form-group input[type="number"],
        .form-group select {
            width: calc(100% - 22px);
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 16px;
        }
        .form-group button {
            padding: 10px 20px;
            background-color: #007bff;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
        }
        .form-group button:hover { background-color: #0056b3; }
        .message { margin-top: 20px; padding: 10px; border-radius: 5px; }
        .message.success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .message.error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
    </style>
</head>
<body>
    <div class="container">
        <div class="sidebar">
            <button onclick="location.href='index.php'">Search Empty Rooms</button>
            <button class="active" onclick="location.href='apply_room.php'">Apply for a Room</button>
            <button onclick="location.href='search_student.php'">Search Student's Information</button>
        </div>
        <div class="main-content">
            <h1>Student Dormitory System</h1>

            <div class="form-section">
                <form action="apply_room.php" method="POST">
                    <h2>申請宿舍</h2>
                    <!-- 棟別 -->
                    <div class="form-group">
                        <label for="building">Building:</label>
                        <select name="building" id="building" required>
                            <option value="">請選擇棟別</option>
                            <?php foreach ($buildings as $building): ?>
                                <option value="<?= htmlspecialchars($building) ?>"><?= htmlspecialchars($building) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- 房號 -->
                    <div class="form-group">
                        <label for="dorm_ID">Room Number:</label>
                        <input type="number" name="dorm_ID" id="dorm_ID" required>
                    </div>


                    <!-- 學號 -->
                    <div class="form-group">
                        <label for="stu_ID">Student ID:</label>
                        <input type="number" name="stu_ID" id="stu_ID" required>
                    </div>

                    <!-- 姓名 -->
                    <div class="form-group">
                        <label for="name">Name:</label>
                        <input type="text" name="name" id="name" required>
                    </div>

                    <div class="form-group">
                        <button type="submit" name="apply_room">Apply</button>
                    </div>

                </form>
            </div>

            <?php if (!empty($message)): ?>
                <p class="message <?php echo (strpos($message, '成功') !== false) ? 'success' : 'error'; ?>">
                    <?php echo $message; ?>
                </p>
            <?php endif; ?>

        </div>
    </div>
    <?php if (!empty($should_reset) && $should_reset): ?>
<script>
// 顯示 alert 訊息
alert("此學生已經被分配宿舍，請勿重複申請。");

// 清空所有 input 和 select 欄位
document.addEventListener("DOMContentLoaded", function () {
    const form = document.querySelector("form");
    if (form) {
        form.reset();
    }
});
</script>
<?php endif; ?>

</body>
</html>