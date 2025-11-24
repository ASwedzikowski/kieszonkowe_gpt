<?php
// config.php

$DB_HOST = 'localhost';
$DB_USER = 'kieszonkowe';
$DB_PASS = 'fPxYSAzbp91qOI(s';
$DB_NAME = 'kieszonkowe';

$mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);

if ($mysqli->connect_errno) {
    die('Błąd połączenia z bazą danych: ' . $mysqli->connect_error);
}

$mysqli->set_charset('utf8mb4');

session_start(); // jedna linijka tutaj – sesja dostępna w całej aplikacji
