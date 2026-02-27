<?php
include "db.php";

$errors_sale = [];
$errors_item = [];

$created_sale_id = (int)($_GET["sale_id"] ?? 0);

$customers = [];
$employees = [];
$cars = [];
$sales = [];
$sale_items = [];

$res = mysqli_query($conn, "SELECT customer_id, first_name, last_name FROM customer ORDER BY last_name, first_name");
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $customers[] = $row;
    }
    mysqli_free_result($res);
}

$res = mysqli_query($conn, "SELECT employee_id, first_name, last_name FROM employee ORDER BY last_name, first_name");
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $employees[] = $row;
    }
    mysqli_free_result($res);
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";

    if ($action === "create_sale") {
        $sale_date = trim($_POST["sale_date"] ?? "");
        $customer_id = (int)($_POST["customer_id"] ?? 0);
        $employee_id = (int)($_POST["employee_id"] ?? 0);

        if ($sale_date === "") $errors_sale[] = "Sale date is required.";
        if ($customer_id <= 0) $errors_sale[] = "Customer is required.";
        if ($employee_id <= 0) $errors_sale[] = "Employee is required.";

        if (count($errors_sale) === 0) {
            $sql = "INSERT INTO sale (sale_date, total_amount, customer_id, employee_id) VALUES (?, 0.00, ?, ?)";
            $stmt = mysqli_prepare($conn, $sql);

            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "sii", $sale_date, $customer_id, $employee_id);

                try {
                    mysqli_stmt_execute($stmt);
                    $new_id = (int)mysqli_insert_id($conn);
                    mysqli_stmt_close($stmt);
                    header("Location: sales.php?sale_id=" . $new_id);
                    exit;
                } catch (mysqli_sql_exception $e) {
                    $errors_sale[] = "Create sale failed.";
                }

                mysqli_stmt_close($stmt);
            } else {
                $errors_sale[] = "Create sale failed (statement error).";
            }
        }
    }

    if ($action === "add_item") {
        $sale_id = (int)($_POST["sale_id"] ?? 0);
        $car_id = (int)($_POST["car_id"] ?? 0);
        $sale_price = (float)($_POST["sale_price"] ?? 0);

        if ($sale_id <= 0) $errors_item[] = "Sale ID is missing.";
        if ($car_id <= 0) $errors_item[] = "Car is required.";
        if ($sale_price <= 0) $errors_item[] = "Sale price must be greater than 0.";

        if (count($errors_item) === 0) {
            $stmt_check = mysqli_prepare($conn, "SELECT status FROM car WHERE car_id = ?");
            $car_status = "";
            if ($stmt_check) {
                mysqli_stmt_bind_param($stmt_check, "i", $car_id);
                mysqli_stmt_execute($stmt_check);
                $res_check = mysqli_stmt_get_result($stmt_check);
                if ($res_check && ($row = mysqli_fetch_assoc($res_check))) {
                    $car_status = $row["status"];
                }
                mysqli_stmt_close($stmt_check);
            }

            if ($car_status !== "available") {
                $errors_item[] = "This car is not available (already reserved or sold).";
            }
        }

        if (count($errors_item) === 0) {
            $sql = "INSERT INTO sale_item (sale_id, car_id, sale_price) VALUES (?, ?, ?)";
            $stmt = mysqli_prepare($conn, $sql);

            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "iid", $sale_id, $car_id, $sale_price);

                try {
                    mysqli_stmt_execute($stmt);
                } catch (mysqli_sql_exception $e) {
                    $errors_item[] = "Add item failed.";
                }

                mysqli_stmt_close($stmt);
            } else {
                $errors_item[] = "Add item failed (statement error).";
            }

            if (count($errors_item) === 0) {
                $stmt2 = mysqli_prepare($conn, "UPDATE car SET status = 'reserved' WHERE car_id = ?");
                if ($stmt2) {
                    mysqli_stmt_bind_param($stmt2, "i", $car_id);
                    mysqli_stmt_execute($stmt2);
                    mysqli_stmt_close($stmt2);
                }

                $stmt3 = mysqli_prepare(
                    $conn,
                    "UPDATE sale
                     SET total_amount = (SELECT COALESCE(SUM(sale_price),0) FROM sale_item WHERE sale_id = ?)
                     WHERE sale_id = ?"
                );
                if ($stmt3) {
                    mysqli_stmt_bind_param($stmt3, "ii", $sale_id, $sale_id);
                    mysqli_stmt_execute($stmt3);
                    mysqli_stmt_close($stmt3);
                }

                header("Location: sales.php?sale_id=" . $sale_id);
                exit;
            }

            $created_sale_id = $sale_id;
        }
    }
}

$sql_cars = "
    SELECT
        c.car_id,
        c.vin,
        c.list_price,
        cm.make,
        cm.model_name,
        cm.model_year
    FROM car c
    JOIN car_model cm ON cm.model_id = c.model_id
    WHERE c.status = 'available'
    ORDER BY c.car_id DESC
";
$res = mysqli_query($conn, $sql_cars);
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $cars[] = $row;
    }
    mysqli_free_result($res);
}

$sql_sales = "
    SELECT
        s.sale_id,
        s.sale_date,
        s.total_amount,
        c.first_name AS cust_first,
        c.last_name AS cust_last,
        e.first_name AS emp_first,
        e.last_name AS emp_last
    FROM sale s
    JOIN customer c ON c.customer_id = s.customer_id
    JOIN employee e ON e.employee_id = s.employee_id
    WHERE EXISTS (SELECT 1 FROM sale_item si WHERE si.sale_id = s.sale_id)
    ORDER BY s.sale_id DESC
";
$res = mysqli_query($conn, $sql_sales);
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $sales[] = $row;
    }
    mysqli_free_result($res);
}

if ($created_sale_id > 0) {
    $stmt = mysqli_prepare(
        $conn,
        "
        SELECT
            si.car_id,
            si.sale_price,
            car.vin,
            cm.make,
            cm.model_name,
            cm.model_year
        FROM sale_item si
        JOIN car ON car.car_id = si.car_id
        JOIN car_model cm ON cm.model_id = car.model_id
        WHERE si.sale_id = ?
        ORDER BY si.car_id DESC
        "
    );

    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $created_sale_id);
        mysqli_stmt_execute($stmt);
        $res_items = mysqli_stmt_get_result($stmt);

        if ($res_items) {
            while ($row = mysqli_fetch_assoc($res_items)) {
                $sale_items[] = $row;
            }
            mysqli_free_result($res_items);
        }

        mysqli_stmt_close($stmt);
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Sales</title>
</head>
<body>

<h1>Sales</h1>
<p><a href="index.php">Back to Home</a></p>

<h2>Create Sale</h2>

<?php if (count($errors_sale) > 0): ?>
    <ul>
        <?php foreach ($errors_sale as $msg): ?>
            <li><?php echo htmlspecialchars($msg); ?></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php if (count($customers) === 0): ?>
    <p>No customers found. Please add customers first.</p>
<?php endif; ?>

<?php if (count($employees) === 0): ?>
    <p>No employees found. Please add employees first.</p>
<?php endif; ?>

<form method="post" action="sales.php">
    <input type="hidden" name="action" value="create_sale">

    <p>
        <label>Sale date:</label><br>
        <input type="date" name="sale_date" required>
    </p>

    <p>
        <label>Customer:</label><br>
        <select name="customer_id" required>
            <option value="">-- Select customer --</option>
            <?php foreach ($customers as $c): ?>
                <option value="<?php echo (int)$c["customer_id"]; ?>">
                    <?php echo htmlspecialchars($c["last_name"] . " " . $c["first_name"]); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </p>

    <p>
        <label>Employee:</label><br>
        <select name="employee_id" required>
            <option value="">-- Select employee --</option>
            <?php foreach ($employees as $e): ?>
                <option value="<?php echo (int)$e["employee_id"]; ?>">
                    <?php echo htmlspecialchars($e["last_name"] . " " . $e["first_name"]); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </p>

    <p>
        <button type="submit" <?php echo (count($customers) === 0 || count($employees) === 0) ? "disabled" : ""; ?>>
            Create Sale
        </button>
    </p>
</form>

<h2>Add Sale Item</h2>

<?php if ($created_sale_id > 0): ?>
    <p>Current Sale ID: <?php echo (int)$created_sale_id; ?></p>
<?php else: ?>
    <p>Create a sale first to get a Sale ID.</p>
<?php endif; ?>

<?php if (count($errors_item) > 0): ?>
    <ul>
        <?php foreach ($errors_item as $msg): ?>
            <li><?php echo htmlspecialchars($msg); ?></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php if (count($cars) === 0): ?>
    <p>No available cars found. Add cars first or all cars are reserved/sold.</p>
<?php endif; ?>

<form method="post" action="sales.php">
    <input type="hidden" name="action" value="add_item">
    <input type="hidden" name="sale_id" value="<?php echo (int)$created_sale_id; ?>">

    <p>
        <label>Car:</label><br>
        <select name="car_id" required>
            <option value="">-- Select car --</option>
            <?php foreach ($cars as $car): ?>
                <?php $label = $car["make"] . " " . $car["model_name"] . " " . $car["model_year"] . " - VIN: " . $car["vin"]; ?>
                <option value="<?php echo (int)$car["car_id"]; ?>">
                    <?php echo htmlspecialchars($label); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </p>

    <p>
        <label>Sale price:</label><br>
        <input type="number" step="0.01" name="sale_price" required>
    </p>

    <p>
        <button type="submit" <?php echo ($created_sale_id <= 0 || count($cars) === 0) ? "disabled" : ""; ?>>
            Add Item
        </button>
    </p>
</form>

<?php if ($created_sale_id > 0): ?>
    <h3>Items for Sale ID <?php echo (int)$created_sale_id; ?></h3>

    <table border="1" cellpadding="6" cellspacing="0">
        <tr>
            <th>Car ID</th>
            <th>VIN</th>
            <th>Model</th>
            <th>Sale Price</th>
        </tr>
        <?php foreach ($sale_items as $it): ?>
            <?php $ml = $it["make"] . " " . $it["model_name"] . " " . $it["model_year"]; ?>
            <tr>
                <td><?php echo (int)$it["car_id"]; ?></td>
                <td><?php echo htmlspecialchars($it["vin"]); ?></td>
                <td><?php echo htmlspecialchars($ml); ?></td>
                <td><?php echo htmlspecialchars($it["sale_price"]); ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>

<h2>Sales List</h2>

<table border="1" cellpadding="6" cellspacing="0">
    <tr>
        <th>Sale ID</th>
        <th>Date</th>
        <th>Total</th>
        <th>Customer</th>
        <th>Employee</th>
    </tr>

    <?php foreach ($sales as $s): ?>
        <tr>
            <td><?php echo (int)$s["sale_id"]; ?></td>
            <td><?php echo htmlspecialchars($s["sale_date"]); ?></td>
            <td><?php echo htmlspecialchars($s["total_amount"]); ?></td>
            <td><?php echo htmlspecialchars($s["cust_last"] . " " . $s["cust_first"]); ?></td>
            <td><?php echo htmlspecialchars($s["emp_last"] . " " . $s["emp_first"]); ?></td>
        </tr>
    <?php endforeach; ?>
</table>

</body>
</html>
