<?php
// config.php

$DB_HOST = 'localhost';
$DB_USER = 'arturhos_kieszonkowe';
$DB_PASS = '8KUmSfE5nDdJxYENnGk5';
$DB_NAME = 'arturhos_kieszonkowe';

$mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);

if ($mysqli->connect_errno) {
    die('Błąd połączenia z bazą danych: ' . $mysqli->connect_error);
}

$mysqli->set_charset('utf8mb4');

session_start(); // jedna linijka tutaj – sesja dostępna w całej aplikacji
