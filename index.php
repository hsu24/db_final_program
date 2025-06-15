<?php
// index.php
require_once 'config.php'; // 資料庫連接設定

// 初始化變數
$gender_filter = $_GET['gender'] ?? 'Any';
$building_filter = $_GET['building'] ?? '';
$budget_filter = $_GET['budget'] ?? '';
$preferred_bedtime_filter = $_GET['preferred_bedtime'] ?? '';

$min_ac_temp_filter = $_GET['min_ac_temp'] ?? '';
$max_ac_temp_filter = $_GET['max_ac_temp'] ?? '';
$min_hygiene_level_filter = $_GET['min_hygiene_level'] ?? '';
$max_hygiene_level_filter = $_GET['max_hygiene_level'] ?? '';
$min_noise_sensitivity_filter = $_GET['min_noise_sensitivity'] ?? '';
$max_noise_sensitivity_filter = $_GET['max_noise_sensitivity'] ?? '';

$completely_empty_dormitories = [];
$partially_empty_dormitories = [];
$message = '';

// 接收get請求後執行
if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['search_empty_rooms'])) {

    // 計算每間房間(dorm_ID, building)已分配的床位數，以及該房間所有已分配學生的生活習慣平均值，AVG() 在沒有數據時會返回 NULL
    $sql_dorm_summary = "
        SELECT
            a.dorm_ID,
            a.building,
            COUNT(a.stu_ID) AS assigned_beds,
            # 針對 bedtime 的平均值，考慮跨夜的情況
            TIME_FORMAT(
                SEC_TO_TIME(
                    MOD(
                        AVG(
                            CASE
                                WHEN TIME_TO_SEC(l.bedtime) < TIME_TO_SEC('06:00:00') # 假設凌晨6點前算作第二天
                                THEN TIME_TO_SEC(l.bedtime) + (24 * 3600) # 加上一天的秒數
                                ELSE TIME_TO_SEC(l.bedtime)
                            END
                        ),
                        (24 * 3600) # 對結果取模，確保在0到24小時之間
                    )
                ), '%H:%i:%s'
            ) AS avg_bedtime,
            AVG(l.AC_temperature) AS avg_ac_temperature,
            AVG(l.hygieneLevel) AS avg_hygiene_level,
            AVG(l.noiseSensitivity) AS avg_noise_sensitivity
        FROM
            assignment a
        LEFT JOIN
            lifestyle l ON a.stu_ID = l.stu_ID
        GROUP BY
            a.dorm_ID, a.building
    ";

    // 主查詢，獲取宿舍資訊並計算可用床位，同時帶入生活習慣平均值
    $sql = "
        SELECT
            d.dorm_ID,
            d.building,
            d.capacity,
            d.price,
            d.gender,
            (d.capacity - IFNULL(ds.assigned_beds, 0)) AS beds_available,
            IFNULL(ds.assigned_beds, 0) AS actual_assigned_beds, -- [新增] 實際已分配的床位數
            ds.avg_bedtime,
            ds.avg_ac_temperature,
            ds.avg_hygiene_level,
            ds.avg_noise_sensitivity
        FROM
            dormitory d
        LEFT JOIN
            ({$sql_dorm_summary}) ds ON d.dorm_ID = ds.dorm_ID AND d.building = ds.building
        WHERE
            (d.capacity - IFNULL(ds.assigned_beds, 0)) > 0 # 只顯示有空床位的宿舍
    ";

    $params = [];
    $conditions = [];
    $order_by = []; // 用於排序條件

    // 一般篩選條件 (適用於所有寢室，包括完全空置的)
    if ($gender_filter != 'Any') {
        $db_gender_value = ($gender_filter == 'Male') ? 'M' : 'F';
        $conditions[] = "d.gender = :gender_filter";
        $params[':gender_filter'] = $db_gender_value;
    }

    if (!empty($building_filter)) { // 依棟別篩選
        $conditions[] = "d.building LIKE :building_filter";
        $params[':building_filter'] = '%' . $building_filter . '%'; // 模糊搜尋
    }
    if (!empty($budget_filter)) { // 依預算篩選
        $conditions[] = "d.price <= :budget_filter";
        $params[':budget_filter'] = $budget_filter;
    }

    // 就寢時間篩選
    if (!empty($preferred_bedtime_filter)) {
        $preferred_sec = 0;
        $time_parts = explode(':', $preferred_bedtime_filter);
        if (count($time_parts) == 2) {
            $hours = (int)$time_parts[0];
            $minutes = (int)$time_parts[1];
            $preferred_sec = ($hours * 3600) + ($minutes * 60);
        } else {
            $preferred_bedtime_filter = '';
        }

        if (!empty($preferred_bedtime_filter)) {
            $preferred_sec_transformed_for_order = $preferred_sec;
            $six_am_in_sec = (6 * 3600);
            if ($preferred_sec < $six_am_in_sec) {
                 $preferred_sec_transformed_for_order += (24 * 3600);
            }

            // 就寢時間的排序條件，會將 NULL (空寢室) 放在最後面 (因為 ABS(NULL - X) 還是 NULL)
            $order_by[] = "
                CASE WHEN ds.avg_bedtime IS NULL THEN 1 ELSE 0 END, -- NULL 值排在最後
                ABS(
                    (CASE
                        WHEN TIME_TO_SEC(ds.avg_bedtime) < TIME_TO_SEC('06:00:00')
                        THEN TIME_TO_SEC(ds.avg_bedtime) + (24 * 3600)
                        ELSE TIME_TO_SEC(ds.avg_bedtime)
                    END) - :preferred_bedtime_sec_for_order
                ) ASC
            ";
            $params[':preferred_bedtime_sec_for_order'] = $preferred_sec_transformed_for_order;
        }
    }

    // 生活習慣篩選條件 (這些將在 PHP 中應用，且只針對有室友的寢室)
    // 所以 SQL 查詢中不添加這些條件，而是在 PHP 中過濾

    // 組合篩選和排序
    if (!empty($conditions)) {
        $sql .= " AND " . implode(" AND ", $conditions);
    }
    if (!empty($order_by)) {
        $sql .= " ORDER BY " . implode(", ", $order_by);
    }

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $raw_dormitories = $stmt->fetchAll(PDO::FETCH_ASSOC); // 確保獲取關聯陣列

        // 將結果分組為完全空的寢室和有室友的寢室，並應用生活習慣篩選
        foreach ($raw_dormitories as $dorm) {
            if ($dorm['actual_assigned_beds'] == 0) {
                // 完全空的寢室，直接加入完全空的列表，不應用生活習慣篩選
                $completely_empty_dormitories[] = $dorm;
            } else {
                // 有室友的寢室，應用生活習慣篩選
                $pass_lifestyle_filter = true;

                // 檢查 AC Temp
                if (!empty($min_ac_temp_filter) && $min_ac_temp_filter != 'Any') {
                    if (is_null($dorm['avg_ac_temperature']) || $dorm['avg_ac_temperature'] < $min_ac_temp_filter) {
                        $pass_lifestyle_filter = false;
                    }
                }
                if (!empty($max_ac_temp_filter) && $max_ac_temp_filter != 'Any') {
                    if (is_null($dorm['avg_ac_temperature']) || $dorm['avg_ac_temperature'] > $max_ac_temp_filter) {
                        $pass_lifestyle_filter = false;
                    }
                }

                // 檢查 Hygiene Level
                if (!empty($min_hygiene_level_filter) && $min_hygiene_level_filter != 'Any') {
                    if (is_null($dorm['avg_hygiene_level']) || $dorm['avg_hygiene_level'] < $min_hygiene_level_filter) {
                        $pass_lifestyle_filter = false;
                    }
                }
                if (!empty($max_hygiene_level_filter) && $max_hygiene_level_filter != 'Any') {
                    if (is_null($dorm['avg_hygiene_level']) || $dorm['avg_hygiene_level'] > $max_hygiene_level_filter) {
                        $pass_lifestyle_filter = false;
                    }
                }

                // 檢查 Noise Sensitivity
                if (!empty($min_noise_sensitivity_filter) && $min_noise_sensitivity_filter != 'Any') {
                    if (is_null($dorm['avg_noise_sensitivity']) || $dorm['avg_noise_sensitivity'] < $min_noise_sensitivity_filter) {
                        $pass_lifestyle_filter = false;
                    }
                }
                if (!empty($max_noise_sensitivity_filter) && $max_noise_sensitivity_filter != 'Any') {
                    if (is_null($dorm['avg_noise_sensitivity']) || $dorm['avg_noise_sensitivity'] > $max_noise_sensitivity_filter) {
                        $pass_lifestyle_filter = false;
                    }
                }

                if ($pass_lifestyle_filter) {
                    $partially_empty_dormitories[] = $dorm;
                }
            }
        }

        if (empty($completely_empty_dormitories) && empty($partially_empty_dormitories)) {
            $message = "No empty dormitories found matching your criteria.";
        }

    } catch (\PDOException $e) {
        $message = "Database query error: " . $e->getMessage();
        error_log("Search Empty Rooms Error: " . $e->getMessage());
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dormitory System - Search Empty Rooms</title>
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
        .filter-section input[type="number"],
        .filter-section input[type="time"],
        .filter-section select {
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 4px;
            margin-right: 10px;
        }
        .filter-section button { padding: 8px 15px; background-color: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; }
        .filter-section button:hover { background-color: #0056b3; }
        .other-filters {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            border: 1px dashed #ced4da;
            margin-top: 15px;
            color: #6c757d;
        }
        .other-filters .filter-group {
            margin-bottom: 10px;
            display: flex;
            align-items: center;
        }
        .other-filters .filter-group label {
            min-width: 180px;
            display: inline-block;
            text-align: right;
            margin-right: 5px;
        }
        .other-filters .filter-group input,
        .other-filters .filter-group select {
            flex-grow: 1;
            max-width: 150px;
        }

        .other-filters .filter-group input[type="time"] {
            flex-grow: 1;
            max-width: 150px;
        }

        .other-filters .filter-group select {
            flex-grow: 1;
            max-width: 150px;
        }

        .other-filters .filter-group span {
            margin: 0 5px;
        }

        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        table th, table td { border: 1px solid #ddd; padding: 10px; text-align: left; }
        table th { background-color: #f2f2f2; }
        .message { color: red; text-align: center; margin-top: 20px; }

        .results-section {
            margin-top: 30px;
            padding: 15px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            background-color: #fdfdfd;
        }
        .results-section h2 {
            text-align: center;
            color: #007bff;
            margin-top: 0;
            margin-bottom: 20px;
            border-bottom: 2px solid #007bff;
            padding-bottom: 10px;
        }
        .results-section p.no-results-found {
            text-align: center;
            color: #6c757d;
            padding: 10px;
            border: 1px dashed #ced4da;
            border-radius: 5px;
            margin: 20px 0;
        }
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
                    <label for="gender">Gender:</label>
                    <select name="gender" id="gender">
                        <option value="Any" <?php echo ($gender_filter == 'Any') ? 'selected' : ''; ?>>Any</option>
                        <option value="Male" <?php echo ($gender_filter == 'Male') ? 'selected' : ''; ?>>Male</option>
                        <option value="Female" <?php echo ($gender_filter == 'Female') ? 'selected' : ''; ?>>Female</option>
                    </select>

                    <label for="building">Building:</label>
                    <input type="text" name="building" id="building" placeholder="Building" value="<?php echo htmlspecialchars($building_filter); ?>">

                    <label for="budget">Budget (max price):</label>
                    <input type="number" name="budget" id="budget" placeholder="10000" value="<?php echo htmlspecialchars($budget_filter); ?>">

                    <br><br>

                    <div class="other-filters">
                        <h4>Other Filter Criteria (Average values for current occupants):</h4>
                        <p style="font-size: 0.9em; color: #888;">*Lifestyle screening is only applicable to dormitories where students already live.</p>
                        <div class="filter-group">
                            <label for="preferred_bedtime">Preferred Bed time:</label>
                            <input type="time" name="preferred_bedtime" id="preferred_bedtime" value="<?php echo htmlspecialchars($preferred_bedtime_filter); ?>">
                        </div>
                        <div class="filter-group">
                            <label for="min_ac_temp">AC Temp (Min-Max):</label>
                            <select name="min_ac_temp" id="min_ac_temp">
                                <option value="Any" <?php echo ($min_ac_temp_filter == 'Any') ? 'selected' : ''; ?>>Any</option>
                                <?php for ($i = 20; $i <= 30; $i++): ?>
                                    <option value="<?php echo $i; ?>" <?php echo ($min_ac_temp_filter == $i) ? 'selected' : ''; ?>><?php echo $i; ?></option>
                                <?php endfor; ?>
                            </select>
                            <span>-</span>
                            <select name="max_ac_temp" id="max_ac_temp">
                                <option value="Any" <?php echo ($max_ac_temp_filter == 'Any') ? 'selected' : ''; ?>>Any</option>
                                <?php for ($i = 20; $i <= 30; $i++): ?>
                                    <option value="<?php echo $i; ?>" <?php echo ($max_ac_temp_filter == $i) ? 'selected' : ''; ?>><?php echo $i; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label for="min_hygiene_level">Hygiene Level (Min-Max):</label>
                            <select name="min_hygiene_level" id="min_hygiene_level">
                                <option value="Any" <?php echo ($min_hygiene_level_filter == 'Any') ? 'selected' : ''; ?>>Any</option>
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <option value="<?php echo $i; ?>" <?php echo ($min_hygiene_level_filter == $i) ? 'selected' : ''; ?>><?php echo $i; ?> <?php
                                        if ($i == 1) echo '(Extremely untidy)';
                                        else if ($i == 2) echo '(Somewhat untidy)';
                                        else if ($i == 3) echo '(Average cleanliness)';
                                        else if ($i == 4) echo '(Fairly clean)';
                                        else if ($i == 5) echo '(Extremely clean / obsessive)';
                                    ?></option>
                                <?php endfor; ?>
                            </select>
                            <span>-</span>
                            <select name="max_hygiene_level" id="max_hygiene_level">
                                <option value="Any" <?php echo ($max_hygiene_level_filter == 'Any') ? 'selected' : ''; ?>>Any</option>
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <option value="<?php echo $i; ?>" <?php echo ($max_hygiene_level_filter == $i) ? 'selected' : ''; ?>><?php echo $i; ?> <?php
                                        if ($i == 1) echo '(Extremely untidy)';
                                        else if ($i == 2) echo '(Somewhat untidy)';
                                        else if ($i == 3) echo '(Average cleanliness)';
                                        else if ($i == 4) echo '(Fairly clean)';
                                        else if ($i == 5) echo '(Extremely clean / obsessive)';
                                    ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label for="min_noise_sensitivity">Noise Sensitivity (Min-Max):</label>
                            <select name="min_noise_sensitivity" id="min_noise_sensitivity">
                                <option value="Any" <?php echo ($min_noise_sensitivity_filter == 'Any') ? 'selected' : ''; ?>>Any</option>
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <option value="<?php echo $i; ?>" <?php echo ($min_noise_sensitivity_filter == $i) ? 'selected' : ''; ?>><?php echo $i; ?> <?php
                                        if ($i == 1) echo '(Not sensitive at all)';
                                        else if ($i == 2) echo '(Slightly sensitive)';
                                        else if ($i == 3) echo '(Moderately sensitive)';
                                        else if ($i == 4) echo '(Quite sensitive)';
                                        else if ($i == 5) echo '(Extremely sensitive)';
                                    ?></option>
                                <?php endfor; ?>
                            </select>
                            <span>-</span>
                            <select name="max_noise_sensitivity" id="max_noise_sensitivity">
                                <option value="Any" <?php echo ($max_noise_sensitivity_filter == 'Any') ? 'selected' : ''; ?>>Any</option>
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <option value="<?php echo $i; ?>" <?php echo ($max_noise_sensitivity_filter == $i) ? 'selected' : ''; ?>><?php echo $i; ?> <?php
                                        if ($i == 1) echo '(Not sensitive at all)';
                                        else if ($i == 2) echo '(Slightly sensitive)';
                                        else if ($i == 3) echo '(Moderately sensitive)';
                                        else if ($i == 4) echo '(Quite sensitive)';
                                        else if ($i == 5) echo '(Extremely sensitive)';
                                    ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                    <br>
                    <button type="submit" name="search_empty_rooms">Search</button>
                </form>
            </div>

            <?php if (!empty($message)): ?>
                <p class="message"><?php echo $message; ?></p>
            <?php endif; ?>

            <div class="results-section">
                <h2>Completely empty room</h2>
                <?php if (!empty($completely_empty_dormitories)): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Dorm ID</th>
                                <th>Building</th>
                                <th>Gender</th>
                                <th>Capacity</th>
                                <th>Price</th>
                                <th>Beds Available</th>
                                <th>Avg. Bedtime</th>
                                <th>Avg. AC Temp.</th>
                                <th>Avg. Hygiene Level</th>
                                <th>Avg. Noise Sensitivity</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($completely_empty_dormitories as $dorm): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($dorm['dorm_ID']); ?></td>
                                <td><?php echo htmlspecialchars($dorm['building']); ?></td>
                                <td><?php echo htmlspecialchars($dorm['gender']); ?></td>
                                <td><?php echo htmlspecialchars($dorm['capacity']); ?></td>
                                <td><?php echo htmlspecialchars($dorm['price']); ?></td>
                                <td><?php echo htmlspecialchars($dorm['beds_available']); ?></td>
                                <td>N/A</td> <td>N/A</td>
                                <td>N/A</td>
                                <td>N/A</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php elseif (isset($_GET['search_empty_rooms'])): ?>
                    <p class="no-results-found">No completely empty room would qualify.</p>
                <?php endif; ?>
            </div>

            <div class="results-section">
                <h2>Dormitories with available beds but roommates (with lifestyle filter applied)</h2>
                <?php if (!empty($partially_empty_dormitories)): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Dorm ID</th>
                                <th>Building</th>
                                <th>Gender</th>
                                <th>Capacity</th>
                                <th>Price</th>
                                <th>Beds Available</th>
                                <th>Avg. Bedtime</th>
                                <th>Avg. AC Temp.</th>
                                <th>Avg. Hygiene Level</th>
                                <th>Avg. Noise Sensitivity</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($partially_empty_dormitories as $dorm): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($dorm['dorm_ID']); ?></td>
                                <td><?php echo htmlspecialchars($dorm['building']); ?></td>
                                <td><?php echo htmlspecialchars($dorm['gender']); ?></td>
                                <td><?php echo htmlspecialchars($dorm['capacity']); ?></td>
                                <td><?php echo htmlspecialchars($dorm['price']); ?></td>
                                <td><?php echo htmlspecialchars($dorm['beds_available']); ?></td>
                                <td><?php echo htmlspecialchars($dorm['avg_bedtime'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars(is_numeric($dorm['avg_ac_temperature']) ? round($dorm['avg_ac_temperature'], 1) : 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars(is_numeric($dorm['avg_hygiene_level']) ? round($dorm['avg_hygiene_level'], 1) : 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars(is_numeric($dorm['avg_noise_sensitivity']) ? round($dorm['avg_noise_sensitivity'], 1) : 'N/A'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php elseif (isset($_GET['search_empty_rooms']) && !empty($building_filter) || !empty($budget_filter) || $gender_filter != 'Any' || !empty($preferred_bedtime_filter) || !empty($min_ac_temp_filter) && $min_ac_temp_filter != 'Any' || !empty($max_ac_temp_filter) && $max_ac_temp_filter != 'Any' || !empty($min_hygiene_level_filter) && $min_hygiene_level_filter != 'Any' || !empty($max_hygiene_level_filter) && $max_hygiene_level_filter != 'Any' || !empty($min_noise_sensitivity_filter) && $min_noise_sensitivity_filter != 'Any' || !empty($max_noise_sensitivity_filter) && $max_noise_sensitivity_filter != 'Any' ): ?>
                     <p class="no-results-found">Dormitories with no available beds but with roommates are eligible.</p>
                <?php endif; ?>
            </div>

        </div>
    </div>
</body>
</html>