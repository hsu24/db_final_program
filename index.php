<?php
// index.php
require_once 'config.php'; // 引入資料庫連接設定

// 初始化變數
$building_filter = '';
$budget_filter = '';
$empty_dormitories = [];
$message = '';

if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['search_empty_rooms'])) {
    $building_filter = $_GET['building'] ?? '';
    $budget_filter = $_GET['budget'] ?? '';

    // 計算每個宿舍已分配的床位數
    $sql_assigned_beds = "
        SELECT dorm_ID, building, COUNT(stu_ID) AS assigned_beds
        FROM assignment
        GROUP BY dorm_ID, building
    ";

    // 主查詢，獲取宿舍資訊並計算可用床位
    $sql = "
        SELECT
            d.dorm_ID,
            d.building,
            d.capacity,
            d.price,
            (d.capacity - IFNULL(a.assigned_beds, 0)) AS beds_available
        FROM
            dormitory d
        LEFT JOIN
            ({$sql_assigned_beds}) a ON d.dorm_ID = a.dorm_ID AND d.building = a.building
        WHERE
            (d.capacity - IFNULL(a.assigned_beds, 0)) > 0 -- 只顯示有空床位的宿舍
    ";

    $params = [];
    $conditions = [];

    if (!empty($building_filter)) {
        $conditions[] = "d.building LIKE :building_filter";
        $params[':building_filter'] = '%' . $building_filter . '%'; // 模糊搜尋
    }
    if (!empty($budget_filter)) {
        // 假設預算篩選是基於宿舍價格
        $conditions[] = "d.price <= :budget_filter";
        $params[':budget_filter'] = $budget_filter;
    }

    if (!empty($conditions)) {
        $sql .= " AND " . implode(" AND ", $conditions);
    }

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $empty_dormitories = $stmt->fetchAll();
        if (empty($empty_dormitories)) {
            $message = "沒有找到符合條件的空宿舍。";
        }
    } catch (\PDOException $e) {
        $message = "資料庫查詢錯誤：" . $e->getMessage();
        error_log("Search Empty Rooms Error: " . $e->getMessage()); // 記錄錯誤到伺服器日誌
    }
}
?>

<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>學生宿舍系統 - 搜尋空房</title>
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
        .filter-section select { padding: 8px; border: 1px solid #ccc; border-radius: 4px; margin-right: 10px; }
        .filter-section button { padding: 8px 15px; background-color: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; }
        .filter-section button:hover { background-color: #0056b3; }
        .other-filters { border: 1px dashed #ccc; padding: 15px; text-align: center; color: #666; margin-top: 15px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        table th, table td { border: 1px solid #ddd; padding: 10px; text-align: left; }
        table th { background-color: #f2f2f2; }
        .message { color: red; text-align: center; margin-top: 20px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="sidebar">
            <button class="active" onclick="location.href='index.php'">Search Empty Rooms</button>
            <button onclick="location.href='apply_room.php'">Apply for a Room</button>
            <button onclick="location.href='search_student.php'">Search Student's Information</button>
        </div>
        <div class="main-content">
            <h1>Student Dormitory System</h1>

            <div class="filter-section">
                <form action="index.php" method="GET">
                    <!-- 性別篩選已移除，因為 dormitory 表格沒有 gender_allowed 欄位 -->
                    <!-- 如果需要，請在 dormitory 表格中添加相關欄位 -->
                    <!--
                    <label for="gender">Gender:</label>
                    <select name="gender" id="gender">
                        <option value="any">Any</option>
                        <option value="M">Male</option>
                        <option value="F">Female</option>
                    </select>
                    -->

                    <label for="building">Building:</label>
                    <input type="text" name="building" id="building" placeholder="Building" value="<?php echo htmlspecialchars($building_filter); ?>">

                    <label for="budget">Budget (max price):</label>
                    <input type="text" name="budget" id="budget" placeholder="10000" value="<?php echo htmlspecialchars($budget_filter); ?>">

                    <button type="submit" name="search_empty_rooms">Search</button>
                </form>
                <div class="other-filters">
                    Other Filter Criteria (e.g., Bed time, AC temperature, Hygiene Level, Noise Sensitivity) - *These require corresponding fields in the 'dormitory' table or a related table for filtering.*
                </div>
            </div>

            <?php if (!empty($message)): ?>
                <p class="message"><?php echo $message; ?></p>
            <?php endif; ?>

            <?php if (!empty($empty_dormitories)): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Dorm ID</th>
                            <th>Building</th>
                            <th>Capacity</th>
                            <th>Price</th>
                            <!-- 如果 dormitory 表格有這些欄位，請解除註釋 -->
                            <!-- <th>Bed time</th>
                            <th>AC temperature</th>
                            <th>Hygiene Level</th>
                            <th>Noise Sensitivity</th> -->
                            <th>Beds Available</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($empty_dormitories as $dorm): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($dorm['dorm_ID']); ?></td>
                            <td><?php echo htmlspecialchars($dorm['building']); ?></td>
                            <td><?php echo htmlspecialchars($dorm['capacity']); ?></td>
                            <td><?php echo htmlspecialchars($dorm['price']); ?></td>
                            <!-- 如果 dormitory 表格有這些欄位，請解除註釋 -->
                            <!-- <td><?php //echo htmlspecialchars($dorm['bed_time']); ?></td>
                            <td><?php //echo htmlspecialchars($dorm['AC_temperature']); ?></td>
                            <td><?php //echo htmlspecialchars($dorm['hygieneLevel']); ?></td>
                            <td><?php //echo htmlspecialchars($dorm['noiseSensitivity']); ?></td> -->
                            <td><?php echo htmlspecialchars($dorm['beds_available']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php elseif (isset($_GET['search_empty_rooms']) && empty($empty_dormitories) && empty($message)): ?>
                <p class="message">沒有找到符合條件的空宿舍。</p>
            <?php endif; ?>

        </div>
    </div>
</body>
</html>