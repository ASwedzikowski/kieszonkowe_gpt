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

// --- ustal dziecko przekazane w URL (opcjonalnie) ---
$selected_child_id = 0;
$selected_child    = null;

if (isset($_GET['dziecko_id'])) {
    $tmp_id = (int)$_GET['dziecko_id'];
    foreach ($children as $ch) {
        if ((int)$ch['id'] === $tmp_id) {
            $selected_child_id = $tmp_id;
            $selected_child    = $ch;
            break;
        }
    }
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
    // jeśli dziecko ustalone z URL i nie ma w POST, użyj tego z URL
    $dziecko_id_post = intval($_POST['dziecko_id'] ?? 0);
    if ($dziecko_id_post <= 0 && $selected_child_id > 0) {
        $dziecko_id = $selected_child_id;
    } else {
        $dziecko_id = $dziecko_id_post;
    }

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
    <!-- ważne dla telefonów -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="mobile.css">
    <title>Dodaj potrącenie</title>

    <style>
      .layout-desktop { display: block; }
      .layout-mobile  { display: none; }

      @media (max-width: 768px) {
        .layout-desktop { display: none; }
        .layout-mobile  { display: block; }
      }
    </style>
</head>
<body>

<!-- ================== WERSJA DESKTOPOWA ================== -->
<div class="layout-desktop">
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
                <?php if ($selected_child_id > 0 && $selected_child): ?>
                    <p>
                        <strong>Dziecko:</strong><br>
                        <?php 
                            echo htmlspecialchars($selected_child['imie'])
                                 . ' (login: ' . htmlspecialchars($selected_child['login'])
                                 . ', kieszonkowe: ' . htmlspecialchars($selected_child['kieszonkowe_tygodniowe']) . ' zł)';
                        ?>
                    </p>
                    <input type="hidden" name="dziecko_id" value="<?php echo (int)$selected_child_id; ?>">
                <?php else: ?>
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
                <?php endif; ?>

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

                <label for="data_zdarzenia">Data zdarzenia:</label><br>
                    <input
                        type="date"
                        name="data_zdarzenia"
                        id="data_zdarzenia"
                        value="<?php echo htmlspecialchars($_POST['data_zdarzenia'] ?? date('Y-m-d')); ?>"
                    >
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
</div>

<!-- ================== WERSJA MOBILNA ================== -->
<div class="layout-mobile">
    <div class="app">
        <header class="app-header">
            <div class="app-header__left">
                <span class="app-logo">💰</span>
                <span class="app-title">Dodaj potrącenie</span>
            </div>
            <button class="icon-button" onclick="window.location.href='index.php'">
                ⬅
            </button>
        </header>

        <!-- Podsumowanie / komunikaty -->
        <?php if (!empty($errors)): ?>
            <section class="summary-row">
                <article class="summary-card">
                    <div class="summary-label">Błąd</div>
                    <div class="summary-value summary-value--negative" style="font-size:0.9rem;">
                        <?php foreach ($errors as $e): ?>
                            <?php echo htmlspecialchars($e); ?><br>
                        <?php endforeach; ?>
                    </div>
                </article>
            </section>
        <?php elseif ($success): ?>
            <section class="summary-row">
                <article class="summary-card">
                    <div class="summary-label">Sukces</div>
                    <div class="summary-value" style="font-size:0.9rem;">
                        <?php echo htmlspecialchars($success); ?>
                    </div>
                </article>
            </section>
        <?php else: ?>
            <section class="period-bar">
                <div class="period-main">Nowe potrącenie</div>
                <div class="period-sub">
                    <?php if ($selected_child && $selected_child_id > 0): ?>
                        Dla: <?php echo htmlspecialchars($selected_child['imie']); ?>
                    <?php else: ?>
                        Wybierz dziecko, typ i kwotę
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>

        <main class="content">
            <!-- Powrót do panelu -->
            <article class="child-card">
                <div class="child-card__actions">
                    <a href="index.php" class="btn btn-secondary">
                        ← Powrót do panelu
                    </a>
                </div>
            </article>

            <?php if (empty($children)): ?>
                <p style="padding: 12px;">
                    Nie masz jeszcze dodanych dzieci. Najpierw dodaj dziecko w panelu rodzica.
                </p>
            <?php elseif (empty($types)): ?>
                <p style="padding: 12px;">
                    Brak skonfigurowanych typów potrąceń. Dodaj je w tabeli
                    <code>typy_potracen</code> (np. przez phpMyAdmin/SQL).
                </p>
            <?php else: ?>

                <article class="child-card">
                    <div class="child-card__body">
                        <form method="post" class="mobile-form">
                            <?php if ($selected_child_id > 0 && $selected_child): ?>
                                <div class="mobile-label">
                                    Dziecko
                                    <div style="font-weight: 600; margin-top: 4px;">
                                        <?php 
                                            echo htmlspecialchars($selected_child['imie'])
                                                 . ' (login: ' . htmlspecialchars($selected_child['login'])
                                                 . ', kieszonkowe: ' . htmlspecialchars($selected_child['kieszonkowe_tygodniowe']) . ' zł)';
                                        ?>
                                    </div>
                                </div>
                                <input type="hidden" name="dziecko_id" value="<?php echo (int)$selected_child_id; ?>">
                            <?php else: ?>
                                <label class="mobile-label" for="dziecko_id_mobile">
                                    Dziecko
                                    <select name="dziecko_id" id="dziecko_id_mobile" class="mobile-input">
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
                                </label>
                            <?php endif; ?>

                            <label class="mobile-label" for="typ_id_mobile">
                                Typ potrącenia
                                <select name="typ_id" id="typ_id_mobile" class="mobile-input">
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
                            </label>

                            <label class="mobile-label" for="kwota_mobile">
                                Kwota potrącenia (zł)
                                <input type="text"
                                       name="kwota"
                                       id="kwota_mobile"
                                       class="mobile-input"
                                       placeholder="pozostaw puste, aby użyć domyślnej"
                                       value="<?php echo htmlspecialchars($_POST['kwota'] ?? ''); ?>">
                            </label>

                            <label class="mobile-label" for="data_zdarzenia_mobile">
                                Data zdarzenia
                                <input
                                    type="date"
                                    name="data_zdarzenia"
                                    id="data_zdarzenia_mobile"
                                    class="mobile-input"
                                    value="<?php echo htmlspecialchars($_POST['data_zdarzenia'] ?? date('Y-m-d')); ?>"
                                >
                            </label>

                            <label class="mobile-label" for="opis_mobile">
                                Dodatkowy opis (opcjonalnie)
                                <input type="text"
                                       name="opis"
                                       id="opis_mobile"
                                       class="mobile-input"
                                       value="<?php echo htmlspecialchars($_POST['opis'] ?? ''); ?>">
                            </label>

                            <div class="child-card__actions">
                                <button type="submit" class="btn btn-primary">
                                    Dodaj potrącenie
                                </button>
                            </div>
                        </form>
                    </div>
                </article>

            <?php endif; ?>
        </main>
    </div>
</div>

</body>
</html>
