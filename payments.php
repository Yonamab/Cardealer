<?php
include "db.php";

$errors = [];

$sales = [];
$sql_sales = "
    SELECT
        s.sale_id,
        s.sale_date,
        s.total_amount,
        COALESCE(SUM(p.amount), 0) AS total_paid,
        (s.total_amount - COALESCE(SUM(p.amount), 0)) AS remaining
    FROM sale s
    LEFT JOIN payment p ON p.sale_id = s.sale_id
    GROUP BY s.sale_id, s.sale_date, s.total_amount
    HAVING (s.total_amount - COALESCE(SUM(p.amount), 0)) > 0
    ORDER BY s.sale_id DESC
";
$res = mysqli_query($conn, $sql_sales);

if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $sales[] = $row;
    }
    mysqli_free_result($res);
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $sale_id = (int)($_POST["sale_id"] ?? 0);
    $payment_date = trim($_POST["payment_date"] ?? "");
    $amount = (float)($_POST["amount"] ?? 0);
    $method = trim($_POST["method"] ?? "");

    if ($sale_id <= 0)        $errors[] = "Sale is required.";
    if ($payment_date === "") $errors[] = "Payment date is required.";
    if ($amount <= 0)         $errors[] = "Amount must be greater than 0.";
    if ($method === "")       $errors[] = "Method is required.";

    $total_amount = 0;
    $total_paid_before = 0;
    $remaining_before = 0;

    if ($sale_id > 0) {
        $stmt = mysqli_prepare(
            $conn,
            "
            SELECT
                s.total_amount AS total_amount,
                COALESCE(SUM(p.amount), 0) AS total_paid,
                (s.total_amount - COALESCE(SUM(p.amount), 0)) AS remaining
            FROM sale s
            LEFT JOIN payment p ON p.sale_id = s.sale_id
            WHERE s.sale_id = ?
            GROUP BY s.sale_id, s.total_amount
            "
        );

        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $sale_id);
            mysqli_stmt_execute($stmt);
            $res_info = mysqli_stmt_get_result($stmt);

            if ($res_info && ($row = mysqli_fetch_assoc($res_info))) {
                $total_amount = (float)$row["total_amount"];
                $total_paid_before = (float)$row["total_paid"];
                $remaining_before = (float)$row["remaining"];
            }

            mysqli_stmt_close($stmt);
        }
    }

    if ($sale_id > 0 && $remaining_before <= 0) {
        $errors[] = "This sale is already fully paid.";
    }

    if ($amount > 0 && $remaining_before > 0 && $amount > $remaining_before) {
        $errors[] = "Amount cannot exceed remaining balance (" . $remaining_before . ").";
    }

    if (count($errors) === 0) {
        $stmt = mysqli_prepare($conn, "INSERT INTO payment (payment_date, amount, method, sale_id) VALUES (?, ?, ?, ?)");

        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "sdsi", $payment_date, $amount, $method, $sale_id);

            try {
                mysqli_stmt_execute($stmt);
            } catch (mysqli_sql_exception $e) {
                $errors[] = "Insert failed.";
            }

            mysqli_stmt_close($stmt);
        } else {
            $errors[] = "Insert failed (statement error).";
        }
    }

    if (count($errors) === 0) {
        $total_paid_after = 0;

        $stmt = mysqli_prepare(
            $conn,
            "
            SELECT COALESCE(SUM(p.amount), 0) AS total_paid
            FROM payment p
            WHERE p.sale_id = ?
            "
        );

        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $sale_id);
            mysqli_stmt_execute($stmt);
            $res_paid = mysqli_stmt_get_result($stmt);

            if ($res_paid && ($row = mysqli_fetch_assoc($res_paid))) {
                $total_paid_after = (float)$row["total_paid"];
            }

            mysqli_stmt_close($stmt);
        }

        if ($total_paid_after >= $total_amount) {
            $stmt2 = mysqli_prepare(
                $conn,
                "
                UPDATE car
                SET status = 'sold'
                WHERE car_id IN (SELECT car_id FROM sale_item WHERE sale_id = ?)
                "
            );

            if ($stmt2) {
                mysqli_stmt_bind_param($stmt2, "i", $sale_id);
                mysqli_stmt_execute($stmt2);
                mysqli_stmt_close($stmt2);
            }
        }
    }

    $sales = [];
    $res = mysqli_query($conn, $sql_sales);
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $sales[] = $row;
        }
        mysqli_free_result($res);
    }
}

$payments = [];
$sql_list = "
    SELECT
        p.payment_id,
        p.payment_date,
        p.amount,
        p.method,
        p.sale_id,
        s.sale_date,
        s.total_amount
    FROM payment p
    JOIN sale s ON s.sale_id = p.sale_id
    ORDER BY p.payment_id DESC
";
$res2 = mysqli_query($conn, $sql_list);

if ($res2) {
    while ($row = mysqli_fetch_assoc($res2)) {
        $payments[] = $row;
    }
    mysqli_free_result($res2);
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Payments</title>
</head>
<body>

<h1>Payments</h1>
<p><a href="index.php">Back to Home</a></p>

<?php if (count($sales) === 0): ?>
    <p>No unpaid sales found. Create a sale first or all sales are fully paid.</p>
<?php endif; ?>

<?php if (count($errors) > 0): ?>
    <ul>
        <?php foreach ($errors as $msg): ?>
            <li><?php echo htmlspecialchars($msg); ?></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<h2>Add Payment</h2>

<form method="post" action="payments.php">

    <p>
        <label>Sale:</label><br>
        <select name="sale_id" required>
            <option value="">-- Select sale --</option>
            <?php foreach ($sales as $s): ?>
                <?php
                    $label = "Sale #"
                        . $s["sale_id"]
                        . " (" . $s["sale_date"] . ") "
                        . "Total: " . $s["total_amount"]
                        . " Remaining: " . $s["remaining"];
                ?>
                <option value="<?php echo (int)$s["sale_id"]; ?>">
                    <?php echo htmlspecialchars($label); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </p>

    <p>
        <label>Payment date:</label><br>
        <input type="date" name="payment_date" required>
    </p>

    <p>
        <label>Amount:</label><br>
        <input type="number" step="0.01" name="amount" required>
    </p>

    <p>
        <label>Method:</label><br>
        <input type="text" name="method" required>
    </p>

    <p>
        <button type="submit" <?php echo (count($sales) === 0) ? "disabled" : ""; ?>>
            Add Payment
        </button>
    </p>

</form>

<h2>Payment List</h2>

<table border="1" cellpadding="6" cellspacing="0">
    <tr>
        <th>Payment ID</th>
        <th>Date</th>
        <th>Amount</th>
        <th>Method</th>
        <th>Sale ID</th>
        <th>Sale Date</th>
        <th>Sale Total</th>
    </tr>

    <?php foreach ($payments as $p): ?>
        <tr>
            <td><?php echo (int)$p["payment_id"]; ?></td>
            <td><?php echo htmlspecialchars($p["payment_date"]); ?></td>
            <td><?php echo htmlspecialchars($p["amount"]); ?></td>
            <td><?php echo htmlspecialchars($p["method"]); ?></td>
            <td><?php echo (int)$p["sale_id"]; ?></td>
            <td><?php echo htmlspecialchars($p["sale_date"]); ?></td>
            <td><?php echo htmlspecialchars($p["total_amount"]); ?></td>
        </tr>
    <?php endforeach; ?>
</table>

</body>
</html>
