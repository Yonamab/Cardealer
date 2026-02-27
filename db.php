<?php

$host = "localhost";
$user = "root";
$password = "";
$database = "car_dealership";

$conn = mysqli_connect($host, $user, $password, $database);

if (!$conn) {
    die("Database connection failed");
}

?>
