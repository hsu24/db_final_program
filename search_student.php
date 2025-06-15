<?php
// search_student.php
require_once 'config.php'; // 資料庫連接設定

$student_id_filter = '';
$student_name_filter = '';
$current_student_info = []; // 用於儲存當前學生的資訊
$roommate_info = []; // 用於儲存室友的資訊
$message = '';
$display_results = false; // 控制是否顯示搜尋結果區塊

if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['search_student'])) {
    $student_id_filter = $_GET['stu_id'] ?? '';
    $student_name_filter = $_GET['name'] ?? '';

    if (empty($student_id_filter) && empty($student_name_filter)) {
        $message = "Please enter your student ID or name to search.";
    } else {
        // 1. 搜尋主要學生資訊
        $sql_current_student = "SELECT
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
                                1=1";

        $params_current = [];
        $conditions_current = [];

        if (!empty($student_id_filter)) {
            $conditions_current[] = "s.stu_ID = :stu_id";
            $params_current[':stu_id'] = $student_id_filter;
        }
        if (!empty($student_name_filter)) {
            $conditions_current[] = "s.name = :name";
            $params_current[':name'] = $student_name_filter;
        }

        if (!empty($conditions_current)) {
            $sql_current_student .= " AND " . implode(" AND ", $conditions_current);
        }

        try {
            $stmt_current = $pdo->prepare($sql_current_student);
            $stmt_current->execute($params_current);
            $current_student_info = $stmt_current->fetch(); // 獲取單個學生資訊

            if (!$current_student_info) {
                $message = "No matching students were found.";
            } else {
                $display_results = true;

                // 2. 如果學生有分配宿舍，則搜尋室友資訊
                if ($current_student_info['dorm_ID'] && $current_student_info['building']) {
                    $sql_roommates = "SELECT
                                        s.name,
                                        a.bed_number,
                                        s.department,
                                        s.grade,
                                        l.bedtime,
                                        l.AC_temperature,
                                        l.hygieneLevel,
                                        l.noiseSensitivity
                                    FROM
                                        student s
                                    JOIN
                                        assignment a ON s.stu_ID = a.stu_ID
                                    LEFT JOIN
                                        lifestyle l ON s.stu_ID = l.stu_ID
                                    WHERE
                                        a.dorm_ID = :dorm_id
                                        AND a.building = :building
                                        AND s.stu_ID != :current_stu_id"; // 排除自己

                    $stmt_roommates = $pdo->prepare($sql_roommates);
                    $stmt_roommates->execute([
                        ':dorm_id' => $current_student_info['dorm_ID'],
                        ':building' => $current_student_info['building'],
                        ':current_stu_id' => $current_student_info['stu_ID']
                    ]);
                    $roommate_info = $stmt_roommates->fetchAll();
                } else {
                    $message = "This student has not yet been assigned a dormitory.";
                }
            }
        } catch (\PDOException $e) {
            $message = "Database query error: " . $e->getMessage();
            error_log("Search Student Info Error: " . $e->getMessage());
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dormitory System - Search Student Information</title>
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
        .search-section, .info-section { background-color: #e9ecef; padding: 20px; border-radius: 5px; margin-bottom: 20px; }
        .search-section label { margin-right: 10px; }
        .search-section input[type="text"],
        .search-section input[type="number"] { padding: 8px; border: 1px solid #ccc; border-radius: 4px; margin-right: 10px; }
        .search-section button { padding: 8px 15px; background-color: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; }
        .search-section button:hover { background-color: #0056b3; }

        .info-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px 20px; margin-bottom: 20px; }
        .info-grid div { display: flex; align-items: center; }
        .info-grid label { font-weight: bold; margin-right: 5px; color: #555; }
        .info-grid span { color: #333; }
        .info-separator { border-top: 1px dashed #ccc; margin: 20px 0; }
        .update-button { padding: 10px 20px; background-color: #28a745; color: white; border: none; border-radius: 4px; cursor: pointer; margin-top: 15px; }
        .update-button:hover { background-color: #218838; }

        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        table th, table td { border: 1px solid #ddd; padding: 10px; text-align: left; }
        table th { background-color: #f2f2f2; }
        .message { color: red; text-align: center; margin-top: 20px; }
        .section-title { font-size: 1.2em; font-weight: bold; margin-bottom: 15px; color: #333; }
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

            <div class="search-section">
                <form action="search_student.php" method="GET">
                    <label for="stu_id">Student ID:</label>
                    <input type="number" name="stu_id" id="stu_id" value="<?php echo htmlspecialchars($student_id_filter); ?>">

                    <label for="name">Student Name:</label>
                    <input type="text" name="name" id="name" value="<?php echo htmlspecialchars($student_name_filter); ?>">

                    <button type="submit" name="search_student">Search</button>
                </form>
            </div>

            <?php if (!empty($message)): ?>
                <p class="message"><?php echo $message; ?></p>
            <?php endif; ?>

            <?php if ($display_results && !empty($current_student_info)): ?>
                <div class="info-section">
                    <div class="section-title">Information of Student:</div>
                    <div class="info-grid">
                        <div><label>Student ID :</label><span><?php echo htmlspecialchars($current_student_info['stu_ID'] ?? 'N/A'); ?></span></div>
                        <div><label>Student Name :</label><span><?php echo htmlspecialchars($current_student_info['name'] ?? 'N/A'); ?></span></div>
                        <div><label>Gender :</label><span><?php echo htmlspecialchars($current_student_info['gender'] ?? 'N/A'); ?></span></div>
                        <div><label>Department :</label><span><?php echo htmlspecialchars($current_student_info['department'] ?? 'N/A'); ?></span></div>
                        <div><label>Grade :</label><span><?php echo htmlspecialchars($current_student_info['grade'] ?? 'N/A'); ?></span></div>
                        <div><label>Budget :</label><span><?php echo htmlspecialchars($current_student_info['budget'] ?? 'N/A'); ?></span></div>
                        <div><label>Dormitory Building :</label><span><?php echo htmlspecialchars($current_student_info['building'] ?? 'N/A'); ?></span></div>
                        <div><label>Room :</label><span><?php echo htmlspecialchars($current_student_info['dorm_ID'] ?? 'N/A'); ?></span></div>
                        <div><label>Bed Number :</label><span><?php echo htmlspecialchars($current_student_info['bed_number'] ?? 'N/A'); ?></span></div>
                    </div>

                    <div class="info-grid">
                        <div><label>Bed time :</label><span><?php echo htmlspecialchars($current_student_info['bedtime'] ?? 'N/A'); ?></span></div>
                        <div><label>AC temperature :</label><span><?php echo htmlspecialchars($current_student_info['AC_temperature'] ?? 'N/A'); ?></span></div>
                        <div><label>Hygiene Level :</label><span><?php echo htmlspecialchars($current_student_info['hygieneLevel'] ?? 'N/A'); ?></span></div>
                        <div><label>Noise Sensitivity :</label><span><?php echo htmlspecialchars($current_student_info['noiseSensitivity'] ?? 'N/A'); ?></span></div>
                    </div>

                    <form action="update_lifestyle.php" method="GET">
                        <input type="hidden" name="stu_id" value="<?php echo htmlspecialchars($current_student_info['stu_ID']); ?>">
                        <button type="submit" class="update-button">Update Life Style</button>
                    </form>

                    <div class="info-separator"></div>

                    <div class="section-title">Information of Roommate(s):</div>
                    <?php if (!empty($roommate_info)): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Bed Number</th>
                                    <th>Department</th>
                                    <th>Grade</th>
                                    <th>Bed time</th>
                                    <th>AC temperature</th>
                                    <th>Hygiene Level</th>
                                    <th>Noise Sensitivity</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($roommate_info as $roommate): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($roommate['name'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($roommate['bed_number'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($roommate['department'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($roommate['grade'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($roommate['bedtime'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($roommate['AC_temperature'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($roommate['hygieneLevel'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($roommate['noiseSensitivity'] ?? 'N/A'); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p>No roommate information found, or the student has not been assigned a dormitory yet.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        </div>
    </div>
</body>
</html>