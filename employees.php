<?php
include "db.php";

$errors = [];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $first_name = trim($_POST["first_name"] ?? "");
    $last_name  = trim($_POST["last_name"] ?? "");
    $role       = trim($_POST["role"] ?? "");
    $email      = trim($_POST["email"] ?? "");
    $phone      = trim($_POST["phone"] ?? "");
    $branch_id  = (int)($_POST["branch_id"] ?? 0);

    if ($first_name === "") $errors[] = "First name is required.";
    if ($last_name === "")  $errors[] = "Last name is required.";
    if ($role === "")       $errors[] = "Role is required.";
    if ($email === "")      $errors[] = "Email is required.";
    if ($branch_id <= 0)    $errors[] = "Branch is required.";

    if (count($errors) === 0) {
        $sql = "
            INSERT INTO employee (first_name, last_name, role, email, phone, branch_id)
            VALUES (?, ?, ?, ?, ?, ?)
        ";
        $stmt = mysqli_prepare($conn, $sql);

        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "sssssi", $first_name, $last_name, $role, $email, $phone, $branch_id);

            try {
                mysqli_stmt_execute($stmt);
            } catch (mysqli_sql_exception $e) {
                $errors[] = "Insert failed: email already exists.";
            }

            mysqli_stmt_close($stmt);
        } else {
            $errors[] = "Insert failed (statement error).";
        }
    }
}

$branches = [];
$res = mysqli_query($conn, "SELECT branch_id, name FROM branch ORDER BY name");
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $branches[] = $row;
    }
    mysqli_free_result($res);
}

$q = trim($_GET["q"] ?? "");
$filter_branch = (int)($_GET["branch"] ?? 0);

$sort = $_GET["sort"] ?? "employee_id";
$dir  = strtolower($_GET["dir"] ?? "desc");
$dir  = ($dir === "asc") ? "asc" : "desc";

$allowed_sort = [
    "employee_id" => "e.employee_id",
    "first_name"  => "e.first_name",
    "last_name"   => "e.last_name",
    "role"        => "e.role",
    "email"       => "e.email",
    "branch"      => "b.name"
];

$order_by = $allowed_sort[$sort] ?? "e.employee_id";

$page = (int)($_GET["page"] ?? 1);
if ($page < 1) $page = 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

$where = [];
$params = [];
$types = "";

if ($q !== "") {
    $where[] = "(e.first_name LIKE ? OR e.last_name LIKE ? OR e.email LIKE ? OR e.role LIKE ?)";
    $like = "%" . $q . "%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= "ssss";
}

if ($filter_branch > 0) {
    $where[] = "e.branch_id = ?";
    $params[] = $filter_branch;
    $types .= "i";
}

$where_sql = (count($where) > 0) ? ("WHERE " . implode(" AND ", $where)) : "";

$count_sql = "
    SELECT COUNT(*) AS cnt
    FROM employee e
    JOIN branch b ON b.branch_id = e.branch_id
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
    SELECT
        e.employee_id, e.first_name, e.last_name, e.role, e.email, e.phone,
        b.name AS branch_name
    FROM employee e
    JOIN branch b ON b.branch_id = e.branch_id
    $where_sql
    ORDER BY $order_by $dir
    LIMIT ? OFFSET ?
";

$employees = [];
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
            $employees[] = $row;
        }
        mysqli_free_result($res_list);
    }

    mysqli_stmt_close($stmt);
}

function build_query($overrides = []) {
    $base = [
        "q" => $_GET["q"] ?? "",
        "branch" => $_GET["branch"] ?? "",
        "sort" => $_GET["sort"] ?? "employee_id",
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
    $current_sort = $_GET["sort"] ?? "employee_id";
    $current_dir = strtolower($_GET["dir"] ?? "desc");
    $new_dir = "asc";

    if ($current_sort === $col && $current_dir === "asc") {
        $new_dir = "desc";
    }

    return "employees.php?" . build_query([
        "sort" => $col,
        "dir" => $new_dir,
        "page" => 1
    ]);
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Employees</title>
</head>
<body>

<h1>Employees</h1>
<p><a href="index.php">Back to Home</a></p>

<?php if (count($branches) === 0): ?>
    <p>No branches found. Please insert at least one branch in the database first.</p>
<?php endif; ?>

<?php if (count($errors) > 0): ?>
    <ul>
        <?php foreach ($errors as $msg): ?>
            <li><?php echo htmlspecialchars($msg); ?></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<h2>Add Employee</h2>

<form method="post" action="employees.php">
    <p>
        <label>First name:</label><br>
        <input type="text" name="first_name" required>
    </p>

    <p>
        <label>Last name:</label><br>
        <input type="text" name="last_name" required>
    </p>

    <p>
        <label>Role:</label><br>
        <input type="text" name="role" required>
    </p>

    <p>
        <label>Email:</label><br>
        <input type="email" name="email" required>
    </p>

    <p>
        <label>Phone:</label><br>
        <input type="text" name="phone">
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
        <button type="submit" <?php echo (count($branches) === 0) ? "disabled" : ""; ?>>Add</button>
    </p>
</form>

<h2>Search / Filter</h2>

<form method="get" action="employees.php">
    <p>
        <label>Search:</label><br>
        <input type="text" name="q" value="<?php echo htmlspecialchars($q); ?>">
    </p>

    <p>
        <label>Branch:</label><br>
        <select name="branch">
            <option value="">-- All branches --</option>
            <?php foreach ($branches as $b): ?>
                <option
                    value="<?php echo (int)$b["branch_id"]; ?>"
                    <?php echo ((int)$b["branch_id"] === $filter_branch) ? "selected" : ""; ?>
                >
                    <?php echo htmlspecialchars($b["name"]); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </p>

    <input type="hidden" name="sort" value="<?php echo htmlspecialchars($_GET["sort"] ?? "employee_id"); ?>">
    <input type="hidden" name="dir" value="<?php echo htmlspecialchars($_GET["dir"] ?? "desc"); ?>">

    <p>
        <button type="submit">Apply</button>
        <a href="employees.php">Reset</a>
    </p>
</form>

<h2>Employee List</h2>

<p>
    Showing <?php echo (int)count($employees); ?> of <?php echo (int)$total_rows; ?> result(s).
</p>

<table border="1" cellpadding="6" cellspacing="0">
    <tr>
        <th><a href="<?php echo htmlspecialchars(sort_link("employee_id")); ?>">ID</a></th>
        <th><a href="<?php echo htmlspecialchars(sort_link("first_name")); ?>">First</a></th>
        <th><a href="<?php echo htmlspecialchars(sort_link("last_name")); ?>">Last</a></th>
        <th><a href="<?php echo htmlspecialchars(sort_link("role")); ?>">Role</a></th>
        <th><a href="<?php echo htmlspecialchars(sort_link("email")); ?>">Email</a></th>
        <th>Phone</th>
        <th><a href="<?php echo htmlspecialchars(sort_link("branch")); ?>">Branch</a></th>
    </tr>

    <?php foreach ($employees as $e): ?>
        <tr>
            <td><?php echo (int)$e["employee_id"]; ?></td>
            <td><?php echo htmlspecialchars($e["first_name"]); ?></td>
            <td><?php echo htmlspecialchars($e["last_name"]); ?></td>
            <td><?php echo htmlspecialchars($e["role"]); ?></td>
            <td><?php echo htmlspecialchars($e["email"]); ?></td>
            <td><?php echo htmlspecialchars($e["phone"] ?? ""); ?></td>
            <td><?php echo htmlspecialchars($e["branch_name"]); ?></td>
        </tr>
    <?php endforeach; ?>
</table>

<?php if ($total_pages > 1): ?>
    <p>
        <?php if ($page > 1): ?>
            <a href="employees.php?<?php echo htmlspecialchars(build_query(["page" => $page - 1])); ?>">Prev</a>
        <?php endif; ?>

        Page <?php echo (int)$page; ?> of <?php echo (int)$total_pages; ?>

        <?php if ($page < $total_pages): ?>
            <a href="employees.php?<?php echo htmlspecialchars(build_query(["page" => $page + 1])); ?>">Next</a>
        <?php endif; ?>
    </p>
<?php endif; ?>

</body>
</html>
