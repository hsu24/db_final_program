<?php
// update_lifestyle.php
require_once 'config.php'; // 資料庫連接設定

$message = '';
$current_stu_id = $_GET['stu_id'] ?? ''; // 抓取學生ID

// 用於儲存學生的當前資訊
$student_info_to_edit = [
    'budget' => '',
    'bedtime' => '',
    'AC_temperature' => '',
    'hygieneLevel' => '',
    'noiseSensitivity' => ''
];

// 1. 如果有學生 ID，獲取其當前生活習慣和預算
if (!empty($current_stu_id)) {
    try {
        // 從 student 表格獲取 budget
        $stmt_student = $pdo->prepare("SELECT budget FROM student WHERE stu_ID = :stu_id");
        $stmt_student->execute([':stu_id' => $current_stu_id]);
        $student_data = $stmt_student->fetch();
        if ($student_data) {
            $student_info_to_edit['budget'] = $student_data['budget'];
        }

        // 從 lifestyle 表格獲取其他生活習慣
        $stmt_lifestyle = $pdo->prepare("SELECT bedtime, AC_temperature, hygieneLevel, noiseSensitivity FROM lifestyle WHERE stu_ID = :stu_id");
        $stmt_lifestyle->execute([':stu_id' => $current_stu_id]);
        $lifestyle_data = $stmt_lifestyle->fetch();
        if ($lifestyle_data) {
            $student_info_to_edit['bedtime'] = $lifestyle_data['bedtime'];
            $student_info_to_edit['AC_temperature'] = $lifestyle_data['AC_temperature'];
            $student_info_to_edit['hygieneLevel'] = $lifestyle_data['hygieneLevel'];
            $student_info_to_edit['noiseSensitivity'] = $lifestyle_data['noiseSensitivity'];
        }
    } catch (\PDOException $e) {
        $message = "Failed to load student living habits information: " . $e->getMessage();
        error_log("Load Lifestyle Error: " . $e->getMessage());
    }
} else {
    $message = "Unable to update lifestyle without specifying student ID.";
}


// 2. 處理表單提交 (更新操作)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_lifestyle'])) {
    $stu_id_to_update = $_POST['stu_id'] ?? '';
    $new_budget = $_POST['budget'] ?? NULL;
    $new_bedtime = $_POST['bedtime'] ?? NULL;
    $new_ac_temperature = $_POST['ac_temperature'] ?? NULL;
    $new_hygiene_level = $_POST['hygiene_level'] ?? NULL;
    $new_noise_sensitivity = $_POST['noise_sensitivity'] ?? NULL;

    if (empty($stu_id_to_update)) {
        $message = "Invalid student ID.";
    } else {
        try {
            $pdo->beginTransaction();

            // 更新 student 表格中的 budget
            $stmt_update_student = $pdo->prepare("UPDATE student SET budget = :budget WHERE stu_ID = :stu_id");
            $stmt_update_student->execute([
                ':budget' => ($new_budget === '') ? NULL : $new_budget, // 允許 NULL
                ':stu_id' => $stu_id_to_update
            ]);

            // 插入或更新 lifestyle 表格
            // 使用 ON DUPLICATE KEY UPDATE 語句，如果 stu_ID 存在就更新，否則就插入
            $stmt_update_lifestyle = $pdo->prepare("
                INSERT INTO lifestyle (stu_ID, bedtime, AC_temperature, hygieneLevel, noiseSensitivity)
                VALUES (:stu_id, :bedtime, :ac_temp, :hygiene, :noise)
                ON DUPLICATE KEY UPDATE
                    bedtime = VALUES(bedtime),
                    AC_temperature = VALUES(AC_temperature),
                    hygieneLevel = VALUES(hygieneLevel),
                    noiseSensitivity = VALUES(noiseSensitivity)
            ");
            $stmt_update_lifestyle->execute([
                ':stu_id' => $stu_id_to_update,
                ':bedtime' => ($new_bedtime === '') ? NULL : $new_bedtime,
                ':ac_temp' => ($new_ac_temperature === '') ? NULL : $new_ac_temperature,
                ':hygiene' => ($new_hygiene_level === '') ? NULL : $new_hygiene_level,
                ':noise' => ($new_noise_sensitivity === '') ? NULL : $new_noise_sensitivity
            ]);

            $pdo->commit();
            $message = "Lifestyle and budget updated successfully!";

            // 更新後重新載入最新的資料到表單，確保顯示最新值
            $stmt_student = $pdo->prepare("SELECT budget FROM student WHERE stu_ID = :stu_id");
            $stmt_student->execute([':stu_id' => $stu_id_to_update]);
            $student_data = $stmt_student->fetch();
            if ($student_data) {
                $student_info_to_edit['budget'] = $student_data['budget'];
            }

            $stmt_lifestyle = $pdo->prepare("SELECT bedtime, AC_temperature, hygieneLevel, noiseSensitivity FROM lifestyle WHERE stu_ID = :stu_id");
            $stmt_lifestyle->execute([':stu_id' => $stu_id_to_update]);
            $lifestyle_data = $stmt_lifestyle->fetch();
            if ($lifestyle_data) {
                $student_info_to_edit['bedtime'] = $lifestyle_data['bedtime'];
                $student_info_to_edit['AC_temperature'] = $lifestyle_data['AC_temperature'];
                $student_info_to_edit['hygieneLevel'] = $lifestyle_data['hygieneLevel'];
                $student_info_to_edit['noiseSensitivity'] = $lifestyle_data['noiseSensitivity'];
            }

        } catch (\PDOException $e) {
            $pdo->rollBack();
            $message = "Update failed: " . $e->getMessage();
            error_log("Update Lifestyle Error: " . $e->getMessage());
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dormitory System - Update Lifestyle Preferences</title>
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
        .form-group input[type="time"] {
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
            <button onclick="location.href='apply_room.php'">Apply for a Room</button>
            <button class="active" onclick="location.href='search_student.php'">Search Student's Information</button>
        </div>
        <div class="main-content">
            <h1>Student Dormitory System</h1>

            <div class="form-section">
                <h2>Update Lifestyle for Student ID: <?php echo htmlspecialchars($current_stu_id); ?></h2>
                <form action="update_lifestyle.php" method="POST">
                    <input type="hidden" name="stu_id" value="<?php echo htmlspecialchars($current_stu_id); ?>">

                    <div class="form-group">
                        <label for="budget">Budget :</label>
                        <input type="number" name="budget" id="budget" value="<?php echo htmlspecialchars($student_info_to_edit['budget']); ?>">
                    </div>
                    <div class="form-group">
                        <label for="bedtime">Bed time (HH:MM:SS) :</label>
                        <input type="time" name="bedtime" id="bedtime" step="1" value="<?php echo htmlspecialchars($student_info_to_edit['bedtime']); ?>">
                    </div>
                    <div class="form-group">
                        <label for="ac_temperature">AC temperature :</label>
                        <input type="number" name="ac_temperature" id="ac_temperature" value="<?php echo htmlspecialchars($student_info_to_edit['AC_temperature']); ?>">
                    </div>
                    <div class="form-group">
                        <label for="hygiene_level">Hygiene Level :</label>
                        <input type="number" name="hygiene_level" id="hygiene_level" value="<?php echo htmlspecialchars($student_info_to_edit['hygieneLevel']); ?>">
                    </div>
                    <div class="form-group">
                        <label for="noise_sensitivity">Noise Sensitivity :</label>
                        <input type="number" name="noise_sensitivity" id="noise_sensitivity" value="<?php echo htmlspecialchars($student_info_to_edit['noiseSensitivity']); ?>">
                    </div>
                    <div class="form-group">
                        <button type="submit" name="update_lifestyle">Update</button>
                    </div>
                </form>
            </div>

            <?php if (!empty($message)): ?>
                <p class="message <?php echo (strpos($message, '成功') !== false || strpos($message, 'success') !== false) ? 'success' : 'error'; ?>">
                    <?php echo $message; ?>
                </p>
            <?php endif; ?>

        </div>
    </div>
</body>
</html>