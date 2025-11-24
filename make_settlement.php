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
$today     = date('Y-m-d');   // faktyczna dzisiejsza data (limit)

// To będzie domyślna / wybrana data rozliczenia
$settlement_date = $today;

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

    // wczytaj wybraną datę rozliczenia
    $data_rozliczenia = trim($_POST['data_rozliczenia'] ?? '');
    if ($data_rozliczenia === '') {
        $data_rozliczenia = $today;  // jak puste – przyjmij dzisiaj
    }

    // walidacja formatu
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_rozliczenia)) {
        $errors[] = 'Nieprawidłowy format daty rozliczenia. Użyj YYYY-MM-DD.';
    } else {
        if ($data_rozliczenia > $today) {
            $errors[] = 'Data rozliczenia nie może być w przyszłości.';
        } else {
            $settlement_date = $data_rozliczenia;
        }
    }

    $dziecko_id = intval($_POST['dziecko_id'] ?? 0);

    if ($dziecko_id <= 0) {
        $errors[] = 'Wybierz dziecko.';
    }

    // sprawdź, czy dziecko należy do tego rodzica i pobierz kieszonkowe
    $kieszonkowe_tyg = null;
    $imie_dziecka = '';

    if ($dziecko_id > 0 && empty($errors)) {
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

    // 1. Ustal datę odniesienia do liczenia tygodni (start_ref)
    $start_ref   = null;  // data, od której liczymy pełne tygodnie
    $had_previous = false; // czy były wcześniej rozliczenia?

    if (empty($errors)) {
        // Spróbuj znaleźć ostatnie rozliczenie
        $stmt = $mysqli->prepare('
            SELECT okres_do
            FROM rozliczenia
            WHERE dziecko_id = ?
            ORDER BY okres_do DESC
            LIMIT 1
        ');
        if ($stmt) {
            $stmt->bind_param('i', $dziecko_id);
            $stmt->execute();
            $stmt->bind_result($last_okres_do);
            if ($stmt->fetch()) {
                $start_ref = $last_okres_do;
                $had_previous = true;
            }
            $stmt->close();
        } else {
            $errors[] = 'Błąd przy odczycie ostatniego rozliczenia.';
        }

        // Jeśli brak rozliczeń – użyj daty pierwszego potrącenia
        if ($start_ref === null) {
            $stmt = $mysqli->prepare('
                SELECT MIN(data_zdarzenia) AS min_data
                FROM potracenia
                WHERE dziecko_id = ?
            ');
            if ($stmt) {
                $stmt->bind_param('i', $dziecko_id);
                $stmt->execute();
                $stmt->bind_result($min_data);
                $stmt->fetch();
                $stmt->close();

                if ($min_data !== null) {
                    $start_ref   = $min_data;
                    $had_previous = false;
                } else {
                    $errors[] = 'Brak potrąceń – nie ma czego rozliczać.';
                }
            } else {
                $errors[] = 'Błąd przy odczycie pierwszego potrącenia.';
            }
        }
    }

    // 2. Policz ile pełnych tygodni minęło od start_ref do wybranej daty rozliczenia
    $okres_od   = null;   // początek okresu rozliczenia (inclusive)
    $okres_do   = null;   // koniec okresu rozliczenia (inclusive)
    $full_weeks = 0;

    if (empty($errors)) {
        if ($start_ref > $settlement_date) {
            $errors[] = 'Data rozliczenia jest wcześniejsza niż ostatnie rozliczenie lub pierwsze potrącenie. Wybierz późniejszą datę.';
        } else {
            $d1 = new DateTime($start_ref);
            $d2 = new DateTime($settlement_date);
            $diff = $d1->diff($d2);
            $days = (int)$diff->days;

            // ile pełnych tygodni 7-dniowych minęło
            $full_weeks = intdiv($days, 7);

            if ($full_weeks < 1) {
                $errors[] = 'Do wybranej daty nie minął jeszcze pełny tydzień od ostatniego rozliczenia / pierwszego potrącenia.';
            } else {
                // okres_do = start_ref + (full_weeks * 7 dni)
                $okres_do = date('Y-m-d', strtotime($start_ref . ' + ' . ($full_weeks * 7) . ' days'));
                if ($okres_do > $settlement_date) {
                    $okres_do = $settlement_date;
                }

                // okres_od (inclusive):
                // - przy pierwszym rozliczeniu: od pierwszej daty potrącenia
                // - przy kolejnych: dzień po poprzednim okres_do
                if ($had_previous) {
                    $okres_od = date('Y-m-d', strtotime($start_ref . ' + 1 day'));
                } else {
                    $okres_od = $start_ref;
                }
            }
        }
    }

    // 3. Sprawdź, czy nie ma już takiego rozliczenia
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

    // 4. Policz sumę nierozliczonych potrąceń w tym zakresie [okres_od, okres_do]
    $suma_potracen = 0.0;
    if (empty($errors)) {
        $stmt = $mysqli->prepare('
            SELECT IFNULL(SUM(kwota), 0) AS suma
            FROM potracenia
            WHERE dziecko_id = ?
              AND rozliczone = 0
              AND data_zdarzenia >= ?
              AND data_zdarzenia <= ?
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

    // 5. Oblicz brutto/netto
    if (empty($errors)) {
        $brutto        = (float)$kieszonkowe_tyg * $full_weeks;
        $suma_potracen = (float)$suma_potracen;
        $netto         = $brutto - $suma_potracen;

        if ($netto < 0) {
            $netto = 0.0;
            $info = 'Uwaga: suma potrąceń przekroczyła należne kieszonkowe za ' . $full_weeks . ' tydzień/tygodnie. Kwotę do wypłaty ustawiono na 0 zł.';
        }

        // przygotuj datetime rozliczenia (użyj wybranej daty + aktualny czas)
        $data_rozliczenia_dt = $settlement_date . ' ' . date('H:i:s');

        // 6. Zapisz rozliczenie (tym razem jawnie ustawiamy data_rozliczenia)
        $stmt = $mysqli->prepare('
            INSERT INTO rozliczenia
                (dziecko_id, okres_od, okres_do, kieszonkowe_brutto,
                 suma_potracen, kieszonkowe_netto, data_rozliczenia, rozliczyl_id)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?)
        ');
        if (!$stmt) {
            $errors[] = 'Błąd przygotowania zapytania (INSERT rozliczenia).';
        } else {
            $stmt->bind_param(
                'issddssi',
                $dziecko_id,
                $okres_od,
                $okres_do,
                $brutto,
                $suma_potracen,
                $netto,
                $data_rozliczenia_dt,
                $rodzic_id
            );

            if ($stmt->execute()) {
                $rozliczenie_id = $stmt->insert_id;
                $stmt->close();

                // 7. Oznacz potrącenia jako rozliczone w [okres_od, okres_do]
                $stmt2 = $mysqli->prepare('
                    UPDATE potracenia
                    SET rozliczone = 1,
                        rozliczenie_id = ?
                    WHERE dziecko_id = ?
                      AND rozliczone = 0
                      AND data_zdarzenia >= ?
                      AND data_zdarzenia <= ?
                ');
                if ($stmt2) {
                    $stmt2->bind_param('iiss', $rozliczenie_id, $dziecko_id, $okres_od, $okres_do);
                    $stmt2->execute();
                    $stmt2->close();
                }

                $success = 'Rozliczenie wykonane: ' . htmlspecialchars($imie_dziecka) .
                           ' – okres ' . $okres_od . ' → ' . $okres_do .
                           ' (' . $full_weeks . ' pełne tygodnie), brutto ' .
                           number_format($brutto, 2) . ' zł, potrącenia ' .
                           number_format($suma_potracen, 2) . ' zł, do wypłaty ' .
                           number_format($netto, 2) . ' zł. (data rozliczenia: ' .
                           htmlspecialchars($settlement_date) . ')';
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
    <title>Rozliczenie (pełne tygodnie)</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="container">
    <h1>Rozliczenie kieszonkowego (pełne tygodnie)</h1>
    <div class="actions">
        <a href="index.php" class="button button-secondary">&larr; Powrót do panelu</a>
    </div>

    <p>
        System rozlicza <strong>pełne tygodnie</strong> od ostatniego rozliczenia
        (albo od pierwszego potrącenia, jeśli to pierwsze rozliczenie).<br>
        Przykład: jeśli minęły 2 pełne tygodnie, brutto = 2 × tygodniowe kieszonkowe.
    </p>

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

            <label for="data_rozliczenia">Data rozliczenia:</label>
            <input type="date" name="data_rozliczenia" id="data_rozliczenia"
                   value="<?php echo htmlspecialchars($settlement_date); ?>">

            <br><br>
            <input type="submit" value="Wykonaj rozliczenie">
        </form>

    <?php endif; ?>

</div>
</body>
</html>
