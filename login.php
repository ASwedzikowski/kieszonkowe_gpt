<?php
// login.php
require_once 'config.php';

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');
    $haslo = $_POST['haslo'] ?? '';

    if ($login === '' || $haslo === '') {
        $errors[] = 'Podaj login i hasło.';
    } else {
        $stmt = $mysqli->prepare('
            SELECT id, haslo_hash, imie, rola
            FROM uzytkownicy
            WHERE login = ? AND aktywny = 1
            LIMIT 1
        ');

        if (!$stmt) {
            $errors[] = 'Błąd przygotowania zapytania.';
        } else {
            $stmt->bind_param('s', $login);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            $stmt->close();

            if (!$user) {
                // celowo ogólny komunikat
                $errors[] = 'Nieprawidłowy login lub hasło.';
            } else {
                if (password_verify($haslo, $user['haslo_hash'])) {
                    // OK – logujemy
                    session_regenerate_id(true); // bezpieczeństwo

                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['imie']    = $user['imie'];
                    $_SESSION['rola']    = $user['rola'];

                    // Na razie prosto – przekierujmy na index.php
                    header('Location: index.php');
                    exit;
                } else {
                    $errors[] = 'Nieprawidłowy login lub hasło.';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="style.css">
    <title>Logowanie</title>
</head>
<body>
<div class="container">
    <h1>Logowanie</h1>

    <?php if (!empty($errors)): ?>
        <div style="color:red;">
            <ul>
                <?php foreach ($errors as $e): ?>
                    <li><?php echo htmlspecialchars($e); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post">
        <label for="login">Login:</label><br>
        <input type="text" name="login" id="login" value="<?php echo htmlspecialchars($_POST['login'] ?? ''); ?>"><br><br>

        <label for="haslo">Hasło:</label><br>
        <input type="password" name="haslo" id="haslo"><br><br>

        <input type="submit" value="Zaloguj">
    </form>

    <p><a href="register.php">Nie mam konta – rejestracja rodzica</a></p>
</div>
</body>
</html>
