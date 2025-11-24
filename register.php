<?php
// register.php
require_once 'config.php';

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');
    $haslo = $_POST['haslo'] ?? '';
    $haslo2 = $_POST['haslo2'] ?? '';
    $imie = trim($_POST['imie'] ?? '');

    // Proste walidacje
    if ($login === '' || $haslo === '' || $haslo2 === '' || $imie === '') {
        $errors[] = 'Wszystkie pola są wymagane.';
    }

    if ($haslo !== $haslo2) {
        $errors[] = 'Hasła nie są identyczne.';
    }

    if (strlen($haslo) < 6) {
        $errors[] = 'Hasło powinno mieć co najmniej 6 znaków.';
    }

    if (empty($errors)) {
        // Sprawdź, czy login już istnieje
        $stmt = $mysqli->prepare('SELECT id FROM uzytkownicy WHERE login = ? LIMIT 1');
        if (!$stmt) {
            $errors[] = 'Błąd przygotowania zapytania (SELECT).';
        } else {
            $stmt->bind_param('s', $login);
            $stmt->execute();
            $stmt->store_result();

            if ($stmt->num_rows > 0) {
                $errors[] = 'Taki login już istnieje. Wybierz inny.';
            }
            $stmt->close();
        }
    }

    if (empty($errors)) {
        $haslo_hash = password_hash($haslo, PASSWORD_DEFAULT);
        $rola = 'rodzic'; // na początek rejestrujemy tylko rodzica

        $stmt = $mysqli->prepare('
            INSERT INTO uzytkownicy (login, haslo_hash, imie, rola)
            VALUES (?, ?, ?, ?)
        ');

        if (!$stmt) {
            $errors[] = 'Błąd przygotowania zapytania (INSERT).';
        } else {
            $stmt->bind_param('ssss', $login, $haslo_hash, $imie, $rola);

            if ($stmt->execute()) {
                $success = 'Konto zostało utworzone. Możesz się zalogować.';
            } else {
                $errors[] = 'Błąd przy zapisie do bazy: ' . $stmt->error;
            }

            $stmt->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="style.css">
    <title>Rejestracja rodzica</title>
</head>
<body>
<div class="container">
    <h1>Rejestracja rodzica</h1>

    <?php if (!empty($errors)): ?>
        <div style="color:red;">
            <ul>
                <?php foreach ($errors as $e): ?>
                    <li><?php echo htmlspecialchars($e); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div style="color:green;">
            <?php echo htmlspecialchars($success); ?>
        </div>
    <?php endif; ?>

    <form method="post">
        <label for="login">Login:</label><br>
        <input type="text" name="login" id="login" value="<?php echo htmlspecialchars($_POST['login'] ?? ''); ?>"><br><br>

        <label for="imie">Imię:</label><br>
        <input type="text" name="imie" id="imie" value="<?php echo htmlspecialchars($_POST['imie'] ?? ''); ?>"><br><br>

        <label for="haslo">Hasło:</label><br>
        <input type="password" name="haslo" id="haslo"><br><br>

        <label for="haslo2">Powtórz hasło:</label><br>
        <input type="password" name="haslo2" id="haslo2"><br><br>

        <input type="submit" value="Zarejestruj">
    </form>

    <p><a href="login.php">Mam już konto – logowanie</a></p>
</div> <!--container -->
</body>
</html>
