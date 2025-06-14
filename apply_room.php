<?php
// apply_room.php
require_once 'config.php'; // 引入資料庫連接設定

$message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['apply_room'])) {
    $dorm_id_input = $_POST['dorm_id'] ?? '';
    $building_input = $_POST['building'] ?? '';
    $student_id_input = $_POST['stu_id'] ?? '';
    $student_name_input = $_POST['name'] ?? '';
    $student_gender_input = $_POST['gender'] ?? ''; // 新增性別
    $student_department_input = $_POST['department'] ?? ''; // 新增系所
    $student_grade_input = $_POST['grade'] ?? ''; // 新增年級
    $student_budget_input = $_POST['budget'] ?? ''; // 新增預算


    // 簡單的輸入驗證
    if (empty($dorm_id_input) || empty($building_input) || empty($student_id_input) || empty($student_name_input) || empty($student_gender_input)) {
        $message = "請填寫所有必填欄位 (宿舍ID, 樓棟, 學號, 姓名, 性別)。";
    } else {
        try {
            $pdo->beginTransaction(); // 開始事務

            // 1. 檢查學生是否已經存在
            $stmt = $pdo->prepare("SELECT stu_ID FROM student WHERE stu_ID = :stu_id");
            $stmt->execute([':stu_id' => $student_id_input]);
            $existing_student = $stmt->fetch();

            if (!$existing_student) {
                // 如果學生不存在，則插入新學生記錄
                $stmt = $pdo->prepare("INSERT INTO student (stu_ID, name, gender, department, grade, budget) VALUES (:stu_id, :name, :gender, :department, :grade, :budget)");
                $stmt->execute([
                    ':stu_id' => $student_id_input,
                    ':name' => $student_name_input,
                    ':gender' => $student_gender_input,
                    ':department' => $student_department_input,
                    ':grade' => $student_grade_input,
                    ':budget' => $student_budget_input
                ]);
            } else {
                // 如果學生存在，檢查是否已分配宿舍
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM assignment WHERE stu_ID = :stu_id");
                $stmt->execute([':stu_id' => $student_id_input]);
                if ($stmt->fetchColumn() > 0) {
                    $message = "此學生已分配宿舍。";
                    $pdo->rollBack();
                    return;
                }
                // 學生存在但未分配宿舍，可以繼續分配
            }

            // 2. 查找宿舍資訊並檢查是否有空床位
            $stmt = $pdo->prepare("SELECT dorm_ID, building, capacity, price FROM dormitory WHERE dorm_ID = :dorm_id AND building = :building_name");
            $stmt->execute([':dorm_id' => $dorm_id_input, ':building_name' => $building_input]);
            $dormitory = $stmt->fetch();

            if (!$dormitory) {
                $message = "找不到指定的宿舍。";
                $pdo->rollBack();
                return;
            }

            // 獲取該宿舍已分配的床位號
            $stmt = $pdo->prepare("SELECT bed_number FROM assignment WHERE dorm_ID = :dorm_id AND building = :building_name ORDER BY bed_number ASC");
            $stmt->execute([':dorm_id' => $dorm_id_input, ':building_name' => $building_input]);
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
                $message = "此宿舍已滿，沒有空床位。";
                $pdo->rollBack();
                return;
            }

            // 3. 插入新的分配記錄
            $stmt = $pdo->prepare("INSERT INTO assignment (stu_ID, dorm_ID, building, bed_number) VALUES (:stu_id, :dorm_id, :building_name, :bed_number)");
            $stmt->execute([
                ':stu_id' => $student_id_input,
                ':dorm_id' => $dormitory['dorm_ID'],
                ':building_name' => $dormitory['building'],
                ':bed_number' => $next_bed_number
            ]);

            // 4. 可選：插入或更新 lifestyle 資訊 (根據您的需求)
            // 這裡假設學生申請房間時不會輸入 lifestyle 資訊，如果需要，請在此處添加表單欄位和插入/更新邏輯
            // 例如：
            // $bedtime_input = $_POST['bedtime'] ?? NULL;
            // $ac_temp_input = $_POST['ac_temperature'] ?? NULL;
            // ...
            // $stmt = $pdo->prepare("INSERT INTO lifestyle (stu_ID, bedtime, AC_temperature, hygieneLevel, noiseSensitivity) VALUES (:stu_id, :bedtime, :ac_temp, :hygiene, :noise) ON DUPLICATE KEY UPDATE bedtime = VALUES(bedtime), AC_temperature = VALUES(AC_temperature), hygieneLevel = VALUES(hygieneLevel), noiseSensitivity = VALUES(noiseSensitivity)");
            // $stmt->execute([...]);

            $pdo->commit(); // 提交事務
            $message = "房間申請成功！您的床位號是: " . $next_bed_number;

        } catch (\PDOException $e) {
            $pdo->rollBack(); // 如果發生錯誤，回滾事務
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
                    <div class="form-group">
                        <label for="dorm_id">Dorm ID:</label>
                        <input type="number" name="dorm_id" id="dorm_id" required>
                    </div>
                    <div class="form-group">
                        <label for="building">Building:</label>
                        <input type="text" name="building" id="building" required>
                    </div>
                    <hr style="margin: 20px 0;">
                    <h2>學生資訊</h2>
                    <div class="form-group">
                        <label for="stu_id">Student ID:</label>
                        <input type="number" name="stu_id" id="stu_id" required>
                    </div>
                    <div class="form-group">
                        <label for="name">Name:</label>
                        <input type="text" name="name" id="name" required>
                    </div>
                    <div class="form-group">
                        <label for="gender">Gender:</label>
                        <select name="gender" id="gender" required>
                            <option value="">請選擇</option>
                            <option value="M">男</option>
                            <option value="F">女</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="department">Department (Optional):</label>
                        <input type="text" name="department" id="department">
                    </div>
                    <div class="form-group">
                        <label for="grade">Grade (Optional):</label>
                        <input type="number" name="grade" id="grade">
                    </div>
                    <div class="form-group">
                        <label for="budget">Budget (Optional):</label>
                        <input type="number" name="budget" id="budget">
                    </div>
                    <!-- 生活習慣資訊 (Lifestyle) 暫不在此處收集，若有需要請自行添加 -->
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
</body>
</html>