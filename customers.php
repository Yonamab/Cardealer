<?php
include "db.php";

$errors = [];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $first_name = trim($_POST["first_name"] ?? "");
    $last_name  = trim($_POST["last_name"] ?? "");
    $email      = trim($_POST["email"] ?? "");
    $phone      = trim($_POST["phone"] ?? "");
    $address    = trim($_POST["address"] ?? "");
    $tax_code   = trim($_POST["tax_code"] ?? "");

    if ($first_name === "") $errors[] = "First name is required.";
    if ($last_name === "")  $errors[] = "Last name is required.";
    if ($tax_code === "")   $errors[] = "Tax code is required.";

    if (count($errors) === 0) {
        $sql = "
            INSERT INTO customer (first_name, last_name, email, phone, address, tax_code)
            VALUES (?, ?, ?, ?, ?, ?)
        ";

        $stmt = mysqli_prepare($conn, $sql);

        if ($stmt) {
            mysqli_stmt_bind_param(
                $stmt,
                "ssssss",
                $first_name,
                $last_name,
                $email,
                $phone,
                $address,
                $tax_code
            );

            try {
                mysqli_stmt_execute($stmt);
            } catch (mysqli_sql_exception $e) {
                $errors[] = "Insert failed: tax code already exists.";
            }

            mysqli_stmt_close($stmt);
        } else {
            $errors[] = "Insert failed (statement error).";
        }
    }
}

$q = trim($_GET["q"] ?? "");

$sort = $_GET["sort"] ?? "customer_id";
$dir  = strtolower($_GET["dir"] ?? "desc");
$dir  = ($dir === "asc") ? "asc" : "desc";

$allowed_sort = [
    "customer_id" => "customer_id",
    "last_name"   => "last_name",
    "email"       => "email",
    "tax_code"    => "tax_code"
];

$order_by = $allowed_sort[$sort] ?? "customer_id";

$page = (int)($_GET["page"] ?? 1);
if ($page < 1) $page = 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

$where = [];
$params = [];
$types = "";

if ($q !== "") {
    $where[] = "(first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR tax_code LIKE ?)";
    $like = "%" . $q . "%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= "ssss";
}

$where_sql = (count($where) > 0) ? ("WHERE " . implode(" AND ", $where)) : "";

$count_sql = "
    SELECT COUNT(*) AS cnt
    FROM customer
    $where_sql
";

$total_rows = 0;
$stmt = mysqli_prepare($conn, $count_sql);
if ($stmt) {
    if (count($params) > 0) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $res_cnt = mysqli_stmt_get_result($stmt);
    if ($res_cnt && ($row = mysqli_fetch_assoc($res_cnt))) {
        $total_rows = (int)$row["cnt"];
    }
    mysqli_stmt_close($stmt);
}

$total_pages = (int)ceil($total_rows / $per_page);
if ($total_pages < 1) $total_pages = 1;
if ($page > $total_pages) $page = $total_pages;

$list_sql = "
    SELECT customer_id, first_name, last_name, email, phone, address, tax_code
    FROM customer
    $where_sql
    ORDER BY $order_by $dir
    LIMIT ? OFFSET ?
";

$customers = [];
$stmt = mysqli_prepare($conn, $list_sql);
if ($stmt) {
    $params2 = $params;
    $types2 = $types . "ii";
    $params2[] = $per_page;
    $params2[] = $offset;

    mysqli_stmt_bind_param($stmt, $types2, ...$params2);
    mysqli_stmt_execute($stmt);
    $res_list = mysqli_stmt_get_result($stmt);

    if ($res_list) {
        while ($row = mysqli_fetch_assoc($res_list)) {
            $customers[] = $row;
        }
        mysqli_free_result($res_list);
    }

    mysqli_stmt_close($stmt);
}

function build_query($overrides = []) {
    $base = [
        "q" => $_GET["q"] ?? "",
        "sort" => $_GET["sort"] ?? "customer_id",
        "dir" => $_GET["dir"] ?? "desc",
        "page" => $_GET["page"] ?? "1"
    ];

    foreach ($overrides as $k => $v) {
        $base[$k] = $v;
    }

    $clean = [];
    foreach ($base as $k => $v) {
        if ($v !== "" && $v !== null) $clean[$k] = $v;
    }

    return http_build_query($clean);
}

function sort_link($col) {
    $current_sort = $_GET["sort"] ?? "customer_id";
    $current_dir = strtolower($_GET["dir"] ?? "desc");
    $new_dir = "asc";

    if ($current_sort === $col && $current_dir === "asc") {
        $new_dir = "desc";
    }

    return "customers.php?" . build_query([
        "sort" => $col,
        "dir" => $new_dir,
        "page" => 1
    ]);
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Customers</title>
</head>
<body>

<h1>Customers</h1>
<p><a href="index.php">Back to Home</a></p>

<?php if (count($errors) > 0): ?>
    <ul>
        <?php foreach ($errors as $msg): ?>
            <li><?php echo htmlspecialchars($msg); ?></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<h2>Add Customer</h2>

<form method="post" action="customers.php">
    <p><input type="text" name="first_name" placeholder="First name" required></p>
    <p><input type="text" name="last_name" placeholder="Last name" required></p>
    <p><input type="email" name="email" placeholder="Email"></p>
    <p><input type="text" name="phone" placeholder="Phone"></p>
    <p><input type="text" name="address" placeholder="Address"></p>
    <p><input type="text" name="tax_code" placeholder="Tax code" required></p>
    <p><button type="submit">Add</button></p>
</form>

<h2>Search</h2>

<form method="get" action="customers.php">
    <input type="text" name="q" value="<?php echo htmlspecialchars($q); ?>">
    <input type="hidden" name="sort" value="<?php echo htmlspecialchars($_GET["sort"] ?? "customer_id"); ?>">
    <input type="hidden" name="dir" value="<?php echo htmlspecialchars($_GET["dir"] ?? "desc"); ?>">
    <button type="submit">Search</button>
    <a href="customers.php">Reset</a>
</form>

<h2>Customer List</h2>

<p>
    Showing <?php echo (int)count($customers); ?> of <?php echo (int)$total_rows; ?> result(s).
</p>

<table border="1" cellpadding="6" cellspacing="0">
    <tr>
        <th><a href="<?php echo htmlspecialchars(sort_link("customer_id")); ?>">ID</a></th>
        <th>First</th>
        <th><a href="<?php echo htmlspecialchars(sort_link("last_name")); ?>">Last</a></th>
        <th><a href="<?php echo htmlspecialchars(sort_link("email")); ?>">Email</a></th>
        <th>Phone</th>
        <th>Address</th>
        <th><a href="<?php echo htmlspecialchars(sort_link("tax_code")); ?>">Tax Code</a></th>
    </tr>

    <?php foreach ($customers as $c): ?>
        <tr>
            <td><?php echo (int)$c["customer_id"]; ?></td>
            <td><?php echo htmlspecialchars($c["first_name"]); ?></td>
            <td><?php echo htmlspecialchars($c["last_name"]); ?></td>
            <td><?php echo htmlspecialchars($c["email"] ?? ""); ?></td>
            <td><?php echo htmlspecialchars($c["phone"] ?? ""); ?></td>
            <td><?php echo htmlspecialchars($c["address"] ?? ""); ?></td>
            <td><?php echo htmlspecialchars($c["tax_code"]); ?></td>
        </tr>
    <?php endforeach; ?>
</table>

<?php if ($total_pages > 1): ?>
    <p>
        <?php if ($page > 1): ?>
            <a href="customers.php?<?php echo htmlspecialchars(build_query(["page" => $page - 1])); ?>">Prev</a>
        <?php endif; ?>

        Page <?php echo (int)$page; ?> of <?php echo (int)$total_pages; ?>

        <?php if ($page < $total_pages): ?>
            <a href="customers.php?<?php echo htmlspecialchars(build_query(["page" => $page + 1])); ?>">Next</a>
        <?php endif; ?>
    </p>
<?php endif; ?>

</body>
</html>
