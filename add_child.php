<?php
// add_child.php
require_once 'config.php';
// zakaz cache'owania chronionych stron
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
// Musi być zalogowany
if (!isset($_SESSION['user_id'], $_SESSION['rola'])) {
    header('Location: login.php');
    exit;
}

// Tylko rodzic
if ($_SESSION['rola'] !== 'rodzic') {
    http_response_code(403);
    echo 'Brak uprawnień.';
    exit;
}

$rodzic_id = $_SESSION['user_id'];

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');
    $imie  = trim($_POST['imie'] ?? '');
    $haslo = $_POST['haslo']  ?? '';
    $haslo2 = $_POST['haslo2'] ?? '';
    $kieszonkowe = trim($_POST['kieszonkowe'] ?? '');

    if ($login === '' || $imie === '' || $haslo === '' || $haslo2 === '' || $kieszonkowe === '') {
        $errors[] = 'Wszystkie pola są wymagane.';
    }

    if ($haslo !== $haslo2) {
        $errors[] = 'Hasła nie są identyczne.';
    }

    if (strlen($haslo) < 4) {
        $errors[] = 'Hasło dziecka powinno mieć co najmniej 4 znaki (możesz ustawić prostsze niż dla dorosłego).';
    }

    // Sprawdzenie czy kieszonkowe to poprawna liczba
    if (!preg_match('/^\d+(\.\d{1,2})?$/', $kieszonkowe)) {
        $errors[] = 'Kieszonkowe musi być kwotą w formacie np. 10, 10.5, 10.50.';
    }

    // Jeśli brak błędów do tej pory, sprawdzamy login
    if (empty($errors)) {
        $stmt = $mysqli->prepare('SELECT id FROM uzytkownicy WHERE login = ? LIMIT 1');
        if (!$stmt) {
            $errors[] = 'Błąd przygotowania zapytania (SELECT).';
        } else {
            $stmt->bind_param('s', $login);
            $stmt->execute();
            $stmt->store_result();

            if ($stmt->num_rows > 0) {
                $errors[] = 'Ten login jest już zajęty. Wybierz inny.';
            }
            $stmt->close();
        }
    }

    if (empty($errors)) {
        $haslo_hash = password_hash($haslo, PASSWORD_DEFAULT);
        $rola = 'dziecko';
        $kieszonkowe_val = (float)$kieszonkowe;

        $stmt = $mysqli->prepare('
            INSERT INTO uzytkownicy (login, haslo_hash, imie, rola, rodzic_id, kieszonkowe_tygodniowe)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        if (!$stmt) {
            $errors[] = 'Błąd przygotowania zapytania (INSERT).';
        } else {
            $stmt->bind_param('ssssid', $login, $haslo_hash, $imie, $rola, $rodzic_id, $kieszonkowe_val);

            if ($stmt->execute()) {
                $success = 'Dodano dziecko: ' . htmlspecialchars($imie) . ' (login: ' . htmlspecialchars($login) . ').';
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
    <title>Dodaj dziecko</title>
</head>
<body>
<div class="container">
    <h1>Dodaj dziecko</h1>
    <p><a href="index.php">&larr; Powrót do panelu</a></p>

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
            <?php echo $success; ?>
        </div>
    <?php endif; ?>

    <form method="post">
        <label for="imie">Imię dziecka:</label><br>
        <input type="text" name="imie" id="imie" 
               value="<?php echo htmlspecialchars($_POST['imie'] ?? ''); ?>"><br><br>

        <label for="login">Login dla dziecka:</label><br>
        <input type="text" name="login" id="login"
               value="<?php echo htmlspecialchars($_POST['login'] ?? ''); ?>"><br><br>

        <label for="haslo">Hasło dziecka:</label><br>
        <input type="password" name="haslo" id="haslo"><br><br>

        <label for="haslo2">Powtórz hasło:</label><br>
        <input type="password" name="haslo2" id="haslo2"><br><br>

        <label for="kieszonkowe">Kieszonkowe tygodniowe (zł):</label><br>
        <input type="text" name="kieszonkowe" id="kieszonkowe"
               placeholder="np. 20, 20.5, 20.50"
               value="<?php echo htmlspecialchars($_POST['kieszonkowe'] ?? ''); ?>"><br><br>

        <input type="submit" value="Dodaj dziecko">
    </form>
</div> <!--container -->
</body>
</html>
