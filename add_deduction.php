<?php
// add_deduction.php
require_once 'config.php';

// Musi być zalogowany rodzic
if (!isset($_SESSION['user_id'], $_SESSION['rola'])) {
    header('Location: login.php');
    exit;
}

if ($_SESSION['rola'] !== 'rodzic') {
    http_response_code(403);
    echo 'Brak uprawnień (tylko rodzic może dodawać potrącenia).';
    exit;
}

$rodzic_id = $_SESSION['user_id'];

$errors = [];
$success = '';

// --- pobierz dzieci danego rodzica ---
$children = [];
$stmt = $mysqli->prepare('
    SELECT id, imie, login, kieszonkowe_tygodniowe
    FROM uzytkownicy
    WHERE rodzic_id = ? AND rola = "dziecko" AND aktywny = 1
    ORDER BY imie
');
if ($stmt) {
    $stmt->bind_param('i', $rodzic_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $children[] = $row;
    }
    $stmt->close();
}

// --- pobierz typy potrąceń ---
$types = [];
$result = $mysqli->query('
    SELECT id, nazwa, domyslna_kwota, opis
    FROM typy_potracen
    WHERE aktywny = 1
    ORDER BY nazwa
');
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $types[] = $row;
    }
}

// Obsługa formularza
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dziecko_id     = intval($_POST['dziecko_id'] ?? 0);
    $typ_id         = intval($_POST['typ_id'] ?? 0);
    $kwota_input    = trim($_POST['kwota'] ?? '');
    $opis           = trim($_POST['opis'] ?? '');
    $data_zdarzenia = trim($_POST['data_zdarzenia'] ?? '');

    // Walidacje podstawowe
    if ($dziecko_id <= 0 || $typ_id <= 0) {
        $errors[] = 'Wybierz dziecko i typ potrącenia.';
    }

    // Sprawdź, czy dziecko faktycznie należy do tego rodzica
    if ($dziecko_id > 0) {
        $stmt = $mysqli->prepare('
            SELECT id FROM uzytkownicy 
            WHERE id = ? AND rodzic_id = ? AND rola = "dziecko" AND aktywny = 1
            LIMIT 1
        ');
        if ($stmt) {
            $stmt->bind_param('ii', $dziecko_id, $rodzic_id);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows === 0) {
                $errors[] = 'Wybrane dziecko nie należy do Twojego konta.';
            }
            $stmt->close();
        } else {
            $errors[] = 'Błąd sprawdzania dziecka.';
        }
    }

    // Pobierz domyślną kwotę i sprawdź typ potrącenia
    $domyslna_kwota = null;
    if ($typ_id > 0) {
        $stmt = $mysqli->prepare('
            SELECT domyslna_kwota 
            FROM typy_potracen 
            WHERE id = ? AND aktywny = 1
            LIMIT 1
        ');
        if ($stmt) {
            $stmt->bind_param('i', $typ_id);
            $stmt->execute();
            $stmt->bind_result($domyslna_kwota);
            if (!$stmt->fetch()) {
                $errors[] = 'Nieprawidłowy typ potrącenia.';
            }
            $stmt->close();
        } else {
            $errors[] = 'Błąd sprawdzania typu potrącenia.';
        }
    }

    // Ustalenie kwoty: albo z formularza, albo domyślna
    $kwota = null;
    if ($kwota_input === '') {
        // używamy domyślnej kwoty
        if ($domyslna_kwota === null) {
            $errors[] = 'Brak domyślnej kwoty dla tego typu potrącenia.';
        } else {
            $kwota = (float)$domyslna_kwota;
        }
    } else {
        // sprawdź format kwoty
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $kwota_input)) {
            $errors[] = 'Kwota musi być w formacie np. 5, 5.5, 5.50.';
        } else {
            $kwota = (float)$kwota_input;
        }
    }

    // Data zdarzenia – jeśli pusta, przyjmij dzisiaj
    if ($data_zdarzenia === '') {
        $data_zdarzenia = date('Y-m-d');
    } else {
        // prosta walidacja formatu YYYY-MM-DD
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_zdarzenia)) {
            $errors[] = 'Nieprawidłowy format daty. Użyj YYYY-MM-DD.';
        }
    }

    if (empty($errors) && $kwota !== null) {
        $stmt = $mysqli->prepare('
            INSERT INTO potracenia
                (dziecko_id, typ_id, kwota, opis, data_zdarzenia, utworzyl_id)
            VALUES
                (?, ?, ?, ?, ?, ?)
        ');
        if (!$stmt) {
            $errors[] = 'Błąd przygotowania zapytania (INSERT).';
        } else {
            $stmt->bind_param(
                'iidssi',
                $dziecko_id,
                $typ_id,
                $kwota,
                $opis,
                $data_zdarzenia,
                $rodzic_id
            );

            if ($stmt->execute()) {
                $success = 'Dodano potrącenie.';
                // wyczyść pola formularza po sukcesie
                $_POST = [];
            } else {
                $errors[] = 'Błąd zapisu do bazy: ' . $stmt->error;
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
    <title>Dodaj potrącenie</title>
</head>
<body>
<div class="container">
    <h1>Dodaj potrącenie</h1>
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
            <?php echo htmlspecialchars($success); ?>
        </div>
    <?php endif; ?>

    <?php if (empty($children)): ?>
        <p>Nie masz jeszcze dodanych dzieci. Najpierw dodaj dziecko w panelu rodzica.</p>
    <?php elseif (empty($types)): ?>
        <p>Brak skonfigurowanych typów potrąceń. Dodaj je w tabeli <code>typy_potracen</code> (np. przez phpMyAdmin/SQL).</p>
    <?php else: ?>

        <form method="post">
            <label for="dziecko_id">Dziecko:</label><br>
            <select name="dziecko_id" id="dziecko_id">
                <option value="">-- wybierz --</option>
                <?php foreach ($children as $child): ?>
                    <option value="<?php echo (int)$child['id']; ?>"
                        <?php if (!empty($_POST['dziecko_id']) && $_POST['dziecko_id'] == $child['id']) echo 'selected'; ?>>
                        <?php 
                            echo htmlspecialchars($child['imie']) 
                                 . ' (login: ' . htmlspecialchars($child['login']) . ', kieszonkowe: ' 
                                 . htmlspecialchars($child['kieszonkowe_tygodniowe']) . ' zł)';
                        ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <br><br>

            <label for="typ_id">Typ potrącenia:</label><br>
            <select name="typ_id" id="typ_id">
                <option value="">-- wybierz --</option>
                <?php foreach ($types as $t): ?>
                    <option value="<?php echo (int)$t['id']; ?>"
                        <?php if (!empty($_POST['typ_id']) && $_POST['typ_id'] == $t['id']) echo 'selected'; ?>>
                        <?php 
                            echo htmlspecialchars($t['nazwa']) . ' (domyślnie: ' 
                                 . htmlspecialchars($t['domyslna_kwota']) . ' zł)';
                        ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <br><br>

            <label for="kwota">Kwota potrącenia (zł):</label><br>
            <input type="text" name="kwota" id="kwota"
                   placeholder="pozostaw puste, aby użyć domyślnej"
                   value="<?php echo htmlspecialchars($_POST['kwota'] ?? ''); ?>">
            <br><br>

            <label for="data_zdarzenia">Data zdarzenia (YYYY-MM-DD):</label><br>
            <input type="text" name="data_zdarzenia" id="data_zdarzenia"
                   placeholder="np. <?php echo date('Y-m-d'); ?>"
                   value="<?php echo htmlspecialchars($_POST['data_zdarzenia'] ?? ''); ?>">
            <br><br>

            <label for="opis">Dodatkowy opis (opcjonalnie):</label><br>
            <input type="text" name="opis" id="opis"
                   style="width: 300px;"
                   value="<?php echo htmlspecialchars($_POST['opis'] ?? ''); ?>">
            <br><br>

            <input type="submit" value="Dodaj potrącenie">
        </form>

    <?php endif; ?>
</div> <!--container -->
</body>
</html>
