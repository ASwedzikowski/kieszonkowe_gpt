<?php
// make_settlement.php
require_once 'config.php';

// Musi być zalogowany rodzic
if (!isset($_SESSION['user_id'], $_SESSION['rola'])) {
    header('Location: login.php');
    exit;
}

if ($_SESSION['rola'] !== 'rodzic') {
    http_response_code(403);
    echo 'Brak uprawnień (tylko rodzic może robić rozliczenia).';
    exit;
}

$rodzic_id = (int)$_SESSION['user_id'];
$today     = date('Y-m-d');

$errors  = [];
$success = '';
$info    = '';
$children = [];

// --- pobierz dzieci danego rodzica ---
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dziecko_id = intval($_POST['dziecko_id'] ?? 0);

    if ($dziecko_id <= 0) {
        $errors[] = 'Wybierz dziecko.';
    }

    // sprawdź, czy dziecko należy do tego rodzica i pobierz kieszonkowe
    $kieszonkowe_tyg = null;
    $imie_dziecka = '';

    if ($dziecko_id > 0) {
        $stmt = $mysqli->prepare('
            SELECT imie, kieszonkowe_tygodniowe
            FROM uzytkownicy
            WHERE id = ? AND rodzic_id = ? AND rola = "dziecko" AND aktywny = 1
            LIMIT 1
        ');
        if ($stmt) {
            $stmt->bind_param('ii', $dziecko_id, $rodzic_id);
            $stmt->execute();
            $stmt->bind_result($imie_dziecka, $kieszonkowe_tyg);
            if (!$stmt->fetch()) {
                $errors[] = 'Wybrane dziecko nie należy do Twojego konta.';
            }
            $stmt->close();
        } else {
            $errors[] = 'Błąd przy sprawdzaniu dziecka.';
        }
    }

    if ($kieszonkowe_tyg === null && empty($errors)) {
        $errors[] = 'Dla dziecka nie ustawiono tygodniowego kieszonkowego.';
    }

    // znajdź najstarszą nierozliczoną datę potrącenia (do dzisiaj włącznie)
    $okres_od = null;
    if (empty($errors)) {
        $stmt = $mysqli->prepare('
            SELECT MIN(data_zdarzenia) AS min_data
            FROM potracenia
            WHERE dziecko_id = ?
              AND rozliczone = 0
              AND data_zdarzenia <= ?
        ');
        if ($stmt) {
            $stmt->bind_param('is', $dziecko_id, $today);
            $stmt->execute();
            $stmt->bind_result($okres_od);
            $stmt->fetch();
            $stmt->close();
        } else {
            $errors[] = 'Błąd przy odczycie najstarszego potrącenia.';
        }

        if ($okres_od === null) {
            $errors[] = 'Brak nierozliczonych potrąceń do rozliczenia (do ' . $today . ').';
        }
    }

    // ustaw okres_do na dzisiaj
    $okres_do = $today;

    // sprawdź, czy nie ma już rozliczenia o dokładnie takim zakresie (raczej nie będzie, ale dla pewności)
    if (empty($errors)) {
        $stmt = $mysqli->prepare('
            SELECT id
            FROM rozliczenia
            WHERE dziecko_id = ? AND okres_od = ? AND okres_do = ?
            LIMIT 1
        ');
        if ($stmt) {
            $stmt->bind_param('iss', $dziecko_id, $okres_od, $okres_do);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows > 0) {
                $errors[] = 'Rozliczenie dla tego dziecka i tego zakresu dat już istnieje.';
            }
            $stmt->close();
        } else {
            $errors[] = 'Błąd przy sprawdzaniu istniejącego rozliczenia.';
        }
    }

    // policz sumę nierozliczonych potrąceń w tym zakresie (do dzisiaj)
    $suma_potracen = 0.0;
    if (empty($errors)) {
        $stmt = $mysqli->prepare('
            SELECT IFNULL(SUM(kwota), 0) AS suma
            FROM potracenia
            WHERE dziecko_id = ?
              AND rozliczone = 0
              AND data_zdarzenia BETWEEN ? AND ?
        ');
        if ($stmt) {
            $stmt->bind_param('iss', $dziecko_id, $okres_od, $okres_do);
            $stmt->execute();
            $stmt->bind_result($suma_potracen);
            $stmt->fetch();
            $stmt->close();
        } else {
            $errors[] = 'Błąd przy liczeniu sumy potrąceń.';
        }
    }

    if (empty($errors)) {
        $brutto = (float)$kieszonkowe_tyg;
        $suma_potracen = (float)$suma_potracen;
        $netto = $brutto - $suma_potracen;

        if ($netto < 0) {
            $netto = 0.0;
            $info = 'Uwaga: suma potrąceń przekroczyła kieszonkowe, ustawiono kwotę do wypłaty na 0 zł.';
        }

        // wstaw rozliczenie
        $stmt = $mysqli->prepare('
            INSERT INTO rozliczenia
                (dziecko_id, okres_od, okres_do, kieszonkowe_brutto,
                 suma_potracen, kieszonkowe_netto, rozliczyl_id)
            VALUES
                (?, ?, ?, ?, ?, ?, ?)
        ');
        if (!$stmt) {
            $errors[] = 'Błąd przygotowania zapytania (INSERT rozliczenia).';
        } else {
            $stmt->bind_param(
                'issdddi',
                $dziecko_id,
                $okres_od,
                $okres_do,
                $brutto,
                $suma_potracen,
                $netto,
                $rodzic_id
            );

            if ($stmt->execute()) {
                $rozliczenie_id = $stmt->insert_id;
                $stmt->close();

                // oznacz potrącenia jako rozliczone (tylko do dziś)
                $stmt2 = $mysqli->prepare('
                    UPDATE potracenia
                    SET rozliczone = 1,
                        rozliczenie_id = ?
                    WHERE dziecko_id = ?
                      AND rozliczone = 0
                      AND data_zdarzenia BETWEEN ? AND ?
                ');
                if ($stmt2) {
                    $stmt2->bind_param('iiss', $rozliczenie_id, $dziecko_id, $okres_od, $okres_do);
                    $stmt2->execute();
                    $stmt2->close();
                }

                $success = 'Rozliczenie wykonane: ' . htmlspecialchars($imie_dziecka) .
                           ' – okres ' . $okres_od . ' → ' . $okres_do .
                           ', brutto ' . number_format($brutto, 2) . ' zł, potrącenia ' .
                           number_format($suma_potracen, 2) . ' zł, do wypłaty ' .
                           number_format($netto, 2) . ' zł.';
            } else {
                $errors[] = 'Błąd przy zapisie rozliczenia: ' . $stmt->error;
                $stmt->close();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Rozliczenie (do teraz)</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="container">
    <h1>Rozliczenie kieszonkowego (do dzisiaj)</h1>
    <div class="actions">
        <a href="index.php" class="button button-secondary">&larr; Powrót do panelu</a>
    </div>

    <p>Aktualna data (koniec okresu rozliczenia): <strong><?php echo htmlspecialchars($today); ?></strong></p>
    <p>System rozliczy <strong>wszystkie nierozliczone potrącenia do tej daty włącznie</strong>.  
       Potrącenia z datą w przyszłości nie zostaną uwzględnione.</p>

    <?php if (!empty($errors)): ?>
        <div class="alert-error">
            <ul>
                <?php foreach ($errors as $e): ?>
                    <li><?php echo htmlspecialchars($e); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert-success">
            <?php echo $success; ?>
        </div>
    <?php endif; ?>

    <?php if ($info): ?>
        <div class="alert-info">
            <?php echo htmlspecialchars($info); ?>
        </div>
    <?php endif; ?>

    <?php if (empty($children)): ?>
        <p>Nie masz jeszcze dodanych dzieci. Najpierw dodaj dziecko w panelu rodzica.</p>
    <?php else: ?>

        <form method="post">
            <label for="dziecko_id">Dziecko do rozliczenia:</label>
            <select name="dziecko_id" id="dziecko_id">
                <option value="">-- wybierz --</option>
                <?php foreach ($children as $child): ?>
                    <option value="<?php echo (int)$child['id']; ?>"
                        <?php if (!empty($_POST['dziecko_id']) && $_POST['dziecko_id'] == $child['id']) echo 'selected'; ?>>
                        <?php 
                            echo htmlspecialchars($child['imie']) 
                                 . ' (login: ' . htmlspecialchars($child['login']) . 
                                 ', kieszonkowe: ' . htmlspecialchars($child['kieszonkowe_tygodniowe']) . ' zł)';
                        ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <br><br>
            <input type="submit" value="Wykonaj rozliczenie do dzisiaj">
        </form>

    <?php endif; ?>

</div>
</body>
</html>
