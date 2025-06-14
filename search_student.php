<?php
// search_student.php
require_once 'config.php'; // 引入資料庫連接設定

$student_id_filter = '';
$student_name_filter = '';
$search_results = [];
$message = '';

if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['search_student'])) {
    $student_id_filter = $_GET['stu_id'] ?? '';
    $student_name_filter = $_GET['name'] ?? '';

    $sql = "SELECT
                s.stu_ID,
                s.name,
                s.gender,
                s.department,
                s.grade,
                s.budget,
                a.dorm_ID,
                a.building,
                a.bed_number,
                d.price AS dorm_price,
                l.bedtime,
                l.AC_temperature,
                l.hygieneLevel,
                l.noiseSensitivity
            FROM
                student s
            LEFT JOIN
                assignment a ON s.stu_ID = a.stu_ID
            LEFT JOIN
                dormitory d ON a.dorm_ID = d.dorm_ID AND a.building = d.building
            LEFT JOIN
                lifestyle l ON s.stu_ID = l.stu_ID
            WHERE
                1=1"; // 初始條件，方便後續追加

    $params = [];
    $conditions = [];

    if (!empty($student_id_filter)) {
        $conditions[] = "s.stu_ID = :stu_id"; // 學號通常是精確匹配
        $params[':stu_id'] = $student_id_filter;
    }
    if (!empty($student_name_filter)) {
        $conditions[] = "s.name LIKE :name";
        $params[':name'] = '%' . $student_name_filter . '%';
    }

    if (empty($conditions)) {
        $message = "請輸入學號或姓名進行搜尋。";
    } else {
        $sql .= " AND " . implode(" AND ", $conditions);

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $search_results = $stmt->fetchAll();
            if (empty($search_results)) {
                $message = "沒有找到符合條件的學生資訊。";
            }
        } catch (\PDOException $e) {
            $message = "資料庫查詢錯誤：" . $e->getMessage();
            error_log("Search Student Error: " . $e->getMessage()); // 記錄錯誤到伺服器日誌
        }
    }
}
?>

<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>學生宿舍系統 - 搜尋學生資訊</title>
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
        .filter-section { background-color: #e9ecef; padding: 20px; border-radius: 5px; margin-bottom: 20px; }
        .filter-section label { margin-right: 10px; }
        .filter-section input[type="text"],
        .filter-section input[type="number"] { padding: 8px; border: 1px solid #ccc; border-radius: 4px; margin-right: 10px; }
        .filter-section button { padding: 8px 15px; background-color: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; }
        .filter-section button:hover { background-color: #0056b3; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        table th, table td { border: 1px solid #ddd; padding: 10px; text-align: left; }
        table th { background-color: #f2f2f2; }
        .message { color: red; text-align: center; margin-top: 20px; }
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

            <div class="filter-section">
                <form action="search_student.php" method="GET">
                    <label for="stu_id">Student ID:</label>
                    <input type="number" name="stu_id" id="stu_id" value="<?php echo htmlspecialchars($student_id_filter); ?>">

                    <label for="name">Name:</label>
                    <input type="text" name="name" id="name" value="<?php echo htmlspecialchars($student_name_filter); ?>">

                    <button type="submit" name="search_student">Search</button>
                </form>
            </div>

            <?php if (!empty($message)): ?>
                <p class="message"><?php echo $message; ?></p>
            <?php endif; ?>

            <?php if (!empty($search_results)): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Student ID</th>
                            <th>Name</th>
                            <th>Gender</th>
                            <th>Department</th>
                            <th>Grade</th>
                            <th>Budget</th>
                            <th>Dorm ID</th>
                            <th>Building</th>
                            <th>Bed Number</th>
                            <th>Dorm Price</th>
                            <th>Bedtime</th>
                            <th>AC Temp</th>
                            <th>Hygiene</th>
                            <th>Noise Sens.</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($search_results as $student): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($student['stu_ID']); ?></td>
                            <td><?php echo htmlspecialchars($student['name']); ?></td>
                            <td><?php echo htmlspecialchars($student['gender'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($student['department'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($student['grade'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($student['budget'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($student['dorm_ID'] ?? '未分配'); ?></td>
                            <td><?php echo htmlspecialchars($student['building'] ?? '未分配'); ?></td>
                            <td><?php echo htmlspecialchars($student['bed_number'] ?? '未分配'); ?></td>
                            <td><?php echo htmlspecialchars($student['dorm_price'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($student['bedtime'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($student['AC_temperature'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($student['hygieneLevel'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($student['noiseSensitivity'] ?? 'N/A'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php elseif (isset($_GET['search_student']) && empty($search_results) && empty($message)): ?>
                <p class="message">沒有找到符合條件的學生資訊。</p>
            <?php endif; ?>

        </div>
    </div>
</body>
</html>