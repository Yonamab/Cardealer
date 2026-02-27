<?php
include "db.php";

$errors_model = [];
$errors_car = [];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";

    if ($action === "add_model") {
        $make        = trim($_POST["make"] ?? "");
        $model_name  = trim($_POST["model_name"] ?? "");
        $model_year  = (int)($_POST["model_year"] ?? 0);
        $trim        = trim($_POST["trim"] ?? "");
        $engine_type = trim($_POST["engine_type"] ?? "");

        if ($make === "")       $errors_model[] = "Make is required.";
        if ($model_name === "") $errors_model[] = "Model name is required.";
        if ($model_year <= 0)   $errors_model[] = "Model year is required.";

        if (count($errors_model) === 0) {
            $sql = "
                INSERT INTO car_model (make, model_name, model_year, trim, engine_type)
                VALUES (?, ?, ?, ?, ?)
            ";

            $stmt = mysqli_prepare($conn, $sql);

            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "ssiss", $make, $model_name, $model_year, $trim, $engine_type);

                try {
                    mysqli_stmt_execute($stmt);
                } catch (mysqli_sql_exception $e) {
                    $errors_model[] = "Insert failed: this car model already exists.";
                }

                mysqli_stmt_close($stmt);
            } else {
                $errors_model[] = "Insert failed (statement error).";
            }
        }
    }

    if ($action === "add_car") {
        $vin        = trim($_POST["vin"] ?? "");
        $color      = trim($_POST["color"] ?? "");
        $mileage    = (int)($_POST["mileage"] ?? 0);
        $list_price = (float)($_POST["list_price"] ?? 0);
        $status     = trim($_POST["status"] ?? "available");
        $model_id   = (int)($_POST["model_id"] ?? 0);
        $branch_id  = (int)($_POST["branch_id"] ?? 0);

        if ($vin === "")          $errors_car[] = "VIN is required.";
        if ($list_price <= 0)     $errors_car[] = "List price must be greater than 0.";
        if ($model_id <= 0)       $errors_car[] = "Model is required.";
        if ($branch_id <= 0)      $errors_car[] = "Branch is required.";
        if ($mileage < 0)         $errors_car[] = "Mileage cannot be negative.";

        if ($status === "") $status = "available";

        if (count($errors_car) === 0) {
            $sql = "
                INSERT INTO car (vin, color, mileage, list_price, status, model_id, branch_id)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ";

            $stmt = mysqli_prepare($conn, $sql);

            if ($stmt) {
                mysqli_stmt_bind_param(
                    $stmt,
                    "ssidssi",
                    $vin,
                    $color,
                    $mileage,
                    $list_price,
                    $status,
                    $model_id,
                    $branch_id
                );

                try {
                    mysqli_stmt_execute($stmt);
                } catch (mysqli_sql_exception $e) {
                    $errors_car[] = "Insert failed: VIN already exists or invalid foreign key.";
                }

                mysqli_stmt_close($stmt);
            } else {
                $errors_car[] = "Insert failed (statement error).";
            }
        }
    }
}

$branches = [];
$res_b = mysqli_query($conn, "SELECT branch_id, name FROM branch ORDER BY name");
if ($res_b) {
    while ($row = mysqli_fetch_assoc($res_b)) {
        $branches[] = $row;
    }
    mysqli_free_result($res_b);
}

$models = [];
$res_m = mysqli_query($conn, "SELECT model_id, make, model_name, model_year, trim FROM car_model ORDER BY make, model_name, model_year");
if ($res_m) {
    while ($row = mysqli_fetch_assoc($res_m)) {
        $models[] = $row;
    }
    mysqli_free_result($res_m);
}

$filter_status = trim($_GET["status"] ?? "");
$filter_model_id = (int)($_GET["model_id"] ?? 0);

$valid_status = ["available", "reserved", "sold"];
$where = [];
$params = [];
$types = "";

if (in_array($filter_status, $valid_status, true)) {
    $where[] = "c.status = ?";
    $params[] = $filter_status;
    $types .= "s";
}

if ($filter_model_id > 0) {
    $where[] = "c.model_id = ?";
    $params[] = $filter_model_id;
    $types .= "i";
}

$where_sql = (count($where) > 0) ? ("WHERE " . implode(" AND ", $where)) : "";

$cars = [];
$sql_list = "
    SELECT
        c.car_id,
        c.vin,
        c.color,
        c.mileage,
        c.list_price,
        c.status,
        b.name AS branch_name,
        cm.make,
        cm.model_name,
        cm.model_year,
        cm.trim
    FROM car c
    JOIN branch b ON b.branch_id = c.branch_id
    JOIN car_model cm ON cm.model_id = c.model_id
    $where_sql
    ORDER BY c.car_id DESC
";

$stmt = mysqli_prepare($conn, $sql_list);
if ($stmt) {
    if (count($params) > 0) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $cars[] = $row;
        }
        mysqli_free_result($res);
    }

    mysqli_stmt_close($stmt);
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Inventory</title>
</head>
<body>

<h1>Inventory</h1>
<p><a href="index.php">Back to Home</a></p>

<h2>Add Car Model</h2>

<?php if (count($errors_model) > 0): ?>
    <ul>
        <?php foreach ($errors_model as $msg): ?>
            <li><?php echo htmlspecialchars($msg); ?></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<form method="post" action="inventory.php">
    <input type="hidden" name="action" value="add_model">

    <p>
        <label>Make:</label><br>
        <input type="text" name="make" required>
    </p>

    <p>
        <label>Model name:</label><br>
        <input type="text" name="model_name" required>
    </p>

    <p>
        <label>Model year:</label><br>
        <input type="number" name="model_year" required>
    </p>

    <p>
        <label>Trim:</label><br>
        <input type="text" name="trim">
    </p>

    <p>
        <label>Engine type:</label><br>
        <input type="text" name="engine_type">
    </p>

    <p>
        <button type="submit">Add Model</button>
    </p>
</form>

<h2>Add Car</h2>

<?php if (count($errors_car) > 0): ?>
    <ul>
        <?php foreach ($errors_car as $msg): ?>
            <li><?php echo htmlspecialchars($msg); ?></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php if (count($branches) === 0): ?>
    <p>No branches found. Insert at least one branch first.</p>
<?php endif; ?>

<?php if (count($models) === 0): ?>
    <p>No car models found. Add a car model first.</p>
<?php endif; ?>

<form method="post" action="inventory.php">
    <input type="hidden" name="action" value="add_car">

    <p>
        <label>VIN:</label><br>
        <input type="text" name="vin" required>
    </p>

    <p>
        <label>Color:</label><br>
        <input type="text" name="color">
    </p>

    <p>
        <label>Mileage:</label><br>
        <input type="number" name="mileage" value="0" min="0">
    </p>

    <p>
        <label>List price:</label><br>
        <input type="number" step="0.01" name="list_price" required>
    </p>

    <p>
        <label>Status:</label><br>
        <select name="status">
            <option value="available">available</option>
            <option value="reserved">reserved</option>
            <option value="sold">sold</option>
        </select>
    </p>

    <p>
        <label>Model:</label><br>
        <select name="model_id" required>
            <option value="">-- Select model --</option>
            <?php foreach ($models as $m): ?>
                <?php
                    $label = $m["make"] . " " . $m["model_name"] . " " . $m["model_year"];
                    if (($m["trim"] ?? "") !== "") $label .= " (" . $m["trim"] . ")";
                ?>
                <option value="<?php echo (int)$m["model_id"]; ?>">
                    <?php echo htmlspecialchars($label); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </p>

    <p>
        <label>Branch:</label><br>
        <select name="branch_id" required>
            <option value="">-- Select branch --</option>
            <?php foreach ($branches as $b): ?>
                <option value="<?php echo (int)$b["branch_id"]; ?>">
                    <?php echo htmlspecialchars($b["name"]); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </p>

    <p>
        <button
            type="submit"
            <?php echo (count($branches) === 0 || count($models) === 0) ? "disabled" : ""; ?>
        >
            Add Car
        </button>
    </p>
</form>

<h2>Filter Cars</h2>

<form method="get" action="inventory.php">
    <p>
        <label>Status:</label><br>
        <select name="status">
            <option value="">-- All --</option>
            <option value="available" <?php echo ($filter_status === "available") ? "selected" : ""; ?>>available</option>
            <option value="reserved" <?php echo ($filter_status === "reserved") ? "selected" : ""; ?>>reserved</option>
            <option value="sold" <?php echo ($filter_status === "sold") ? "selected" : ""; ?>>sold</option>
        </select>
    </p>

    <p>
        <label>Model:</label><br>
        <select name="model_id">
            <option value="">-- All --</option>
            <?php foreach ($models as $m): ?>
                <?php
                    $label = $m["make"] . " " . $m["model_name"] . " " . $m["model_year"];
                    if (($m["trim"] ?? "") !== "") $label .= " (" . $m["trim"] . ")";
                ?>
                <option
                    value="<?php echo (int)$m["model_id"]; ?>"
                    <?php echo ((int)$m["model_id"] === $filter_model_id) ? "selected" : ""; ?>
                >
                    <?php echo htmlspecialchars($label); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </p>

    <p>
        <button type="submit">Apply</button>
        <a href="inventory.php">Reset</a>
    </p>
</form>

<h2>Car List</h2>

<table border="1" cellpadding="6" cellspacing="0">
    <tr>
        <th>ID</th>
        <th>VIN</th>
        <th>Model</th>
        <th>Color</th>
        <th>Mileage</th>
        <th>Price</th>
        <th>Status</th>
        <th>Branch</th>
    </tr>

    <?php foreach ($cars as $c): ?>
        <?php
            $model_label = $c["make"] . " " . $c["model_name"] . " " . $c["model_year"];
            if (($c["trim"] ?? "") !== "") $model_label .= " (" . $c["trim"] . ")";
        ?>
        <tr>
            <td><?php echo (int)$c["car_id"]; ?></td>
            <td><?php echo htmlspecialchars($c["vin"]); ?></td>
            <td><?php echo htmlspecialchars($model_label); ?></td>
            <td><?php echo htmlspecialchars($c["color"] ?? ""); ?></td>
            <td><?php echo (int)$c["mileage"]; ?></td>
            <td><?php echo htmlspecialchars($c["list_price"]); ?></td>
            <td><?php echo htmlspecialchars($c["status"]); ?></td>
            <td><?php echo htmlspecialchars($c["branch_name"]); ?></td>
        </tr>
    <?php endforeach; ?>
</table>

</body>
</html>
