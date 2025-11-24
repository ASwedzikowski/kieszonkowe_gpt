<?php
// index.php
require_once 'config.php';

// Sprawdzenie, czy użytkownik jest zalogowany
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$imie    = $_SESSION['imie'] ?? '';
$rola    = $_SESSION['rola'] ?? '';

?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <!-- ważne dla telefonu -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- STARE STYLE (desktop) -->
    <link rel="stylesheet" href="style.css">

    <!-- NOWE STYLE MOBILNE -->
    <link rel="stylesheet" href="mobile.css">

    <title>Panel główny</title>

    <!-- przełącznik layoutów -->
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

<!-- ================== WERSJA DESKTOPOWA (to co było) ================== -->
<div class="layout-desktop">
    <div class="container">
        <h1>Witaj, <?php echo htmlspecialchars($imie); ?>!</h1>
        <p>Zalogowano jako: <strong><?php echo htmlspecialchars($rola); ?></strong></p>
        <p><a href="logout.php">Wyloguj</a></p>
        <hr>

        <?php if ($rola === 'rodzic'): ?>

            <!-- ================== PANEL RODZICA ================== -->

            <h2>Panel rodzica</h2>

            <p><a href="add_child.php">➕ Dodaj dziecko</a></p>
            <!-- ten link może zostać jako „ogólny” (wtedy w add_deduction.php wybierasz dziecko z listy) -->
            <p><a href="add_deduction.php">➖ Dodaj potrącenie</a></p>
            <p><a href="make_settlement.php">✅ Rozlicz tydzień</a></p>

            <h3>Twoje dzieci</h3>
            <?php
            // Pobieramy dzieci powiązane z tym rodzicem
            $stmt = $mysqli->prepare('
                SELECT id, imie, login, kieszonkowe_tygodniowe
                FROM uzytkownicy
                WHERE rodzic_id = ? AND rola = "dziecko" AND aktywny = 1
                ORDER BY imie
            ');
            if ($stmt) {
                $stmt->bind_param('i', $user_id);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows === 0) {
                    echo '<p>Nie dodałeś jeszcze żadnego dziecka.</p>';
                } else {

                    // Przygotuj pomocnicze zapytania dla każdej iteracji
                    $stmtSum = $mysqli->prepare('
                        SELECT IFNULL(SUM(kwota), 0) AS suma
                        FROM potracenia
                        WHERE dziecko_id = ? AND rozliczone = 0
                    ');

                    $stmtLast = $mysqli->prepare('
                        SELECT okres_od, okres_do, kieszonkowe_brutto,
                               suma_potracen, kieszonkowe_netto, data_rozliczenia
                        FROM rozliczenia
                        WHERE dziecko_id = ?
                        ORDER BY data_rozliczenia DESC
                        LIMIT 1
                    ');

                    echo '<table border="1" cellpadding="5" cellspacing="0">';
                    echo '<tr>
                            <th>Imię</th>
                            <th>Login</th>
                            <th>Kieszonkowe tygodniowe</th>
                            <th>Suma nierozliczonych potrąceń</th>
                            <th>Ostatnie rozliczenie</th>
                            <th>Akcje</th>
                          </tr>';

                    while ($row = $result->fetch_assoc()) {
                        $child_id = (int)$row['id'];

                        // --- suma nierozliczonych potrąceń ---
                        $suma_nierozliczonych = 0.0;
                        if ($stmtSum) {
                            $stmtSum->bind_param('i', $child_id);
                            $stmtSum->execute();
                            $stmtSum->bind_result($suma_nierozliczonych);
                            $stmtSum->fetch();
                            $stmtSum->free_result();
                        }

                        // --- ostatnie rozliczenie ---
                        $last_okres_od = null;
                        $last_okres_do = null;
                        $last_brutto   = null;
                        $last_suma     = null;
                        $last_netto    = null;
                        $last_data     = null;

                        if ($stmtLast) {
                            $stmtLast->bind_param('i', $child_id);
                            $stmtLast->execute();
                            $stmtLast->bind_result(
                                $last_okres_od,
                                $last_okres_do,
                                $last_brutto,
                                $last_suma,
                                $last_netto,
                                $last_data
                            );
                            $stmtLast->fetch();
                            $stmtLast->free_result();
                        }

                        echo '<tr>';
                        echo '<td>' . htmlspecialchars($row['imie']) . '</td>';
                        echo '<td>' . htmlspecialchars($row['login']) . '</td>';
                        echo '<td>' . htmlspecialchars($row['kieszonkowe_tygodniowe']) . ' zł</td>';

                        // komórka z sumą nierozliczonych – klikalna
                        echo '<td>';
                        if ($suma_nierozliczonych > 0) {
                            echo '<a href="pending_deductions.php?child_id=' . $child_id . '">'
                               . number_format($suma_nierozliczonych, 2)
                               . ' zł</a>';
                        } else {
                            echo '0,00 zł';
                        }
                        echo '</td>';

                        // komórka z ostatnim rozliczeniem
                        echo '<td>';
                        if ($last_data !== null) {
                            echo '<small>';
                            echo 'Okres: ' . htmlspecialchars($last_okres_od) . ' &ndash; ' . htmlspecialchars($last_okres_do) . '<br>';
                            echo 'Brutto: ' . number_format($last_brutto, 2) . ' zł, ';
                            echo 'potrącenia: ' . number_format($last_suma, 2) . ' zł<br>';
                            echo '<strong>Do wypłaty: ' . number_format($last_netto, 2) . ' zł</strong><br>';
                            echo 'Rozliczono: ' . htmlspecialchars($last_data);
                            echo '</small>';
                        } else {
                            echo '<small>Brak rozliczeń</small>';
                        }
                        echo '</td>';

                        // NOWA KOLUMNA: AKCJE – z dziecko_id w URL
                        echo '<td>';
                        echo '<a href="add_deduction.php?dziecko_id=' . $child_id . '">➖&nbsp;Odpisz</a>';
                        // tutaj można dodać kolejne akcje, np. rozliczenie tylko tego dziecka
                        echo '</td>';

                        echo '</tr>';
                    }
                    echo '</table>';

                    if ($stmtSum)  $stmtSum->close();
                    if ($stmtLast) $stmtLast->close();
                }

                $stmt->close();
            } else {
                echo '<p>Błąd: nie udało się pobrać listy dzieci.</p>';
            }
            ?>

        <?php elseif ($rola === 'dziecko'): ?>

            <!-- ================== PANEL DZIECKA ================== -->

            <?php
            // 1. Pobierz tygodniowe kieszonkowe tego dziecka
            $kieszonkowe_tyg = 0.0;
            $stmt = $mysqli->prepare('
                SELECT kieszonkowe_tygodniowe
                FROM uzytkownicy
                WHERE id = ? AND rola = "dziecko" AND aktywny = 1
                LIMIT 1
            ');
            if ($stmt) {
                $stmt->bind_param('i', $user_id);
                $stmt->execute();
                $stmt->bind_result($kieszonkowe_tyg);
                $stmt->fetch();
                $stmt->close();
            }

            if ($kieszonkowe_tyg === null) {
                $kieszonkowe_tyg = 0.0;
            }
            $kieszonkowe_tyg = (float)$kieszonkowe_tyg;

            // 2. Suma nierozliczonych potrąceń
            $suma_nierozliczonych = 0.0;
            $stmt = $mysqli->prepare('
                SELECT IFNULL(SUM(kwota), 0) AS suma
                FROM potracenia
                WHERE dziecko_id = ? AND rozliczone = 0
            ');
            if ($stmt) {
                $stmt->bind_param('i', $user_id);
                $stmt->execute();
                $stmt->bind_result($suma_nierozliczonych);
                $stmt->fetch();
                $stmt->close();
            }
            $suma_nierozliczonych = (float)$suma_nierozliczonych;

            // 3. Teoretyczna kwota do wypłaty "gdyby rozliczyć teraz"
            $teoretyczne_netto = $kieszonkowe_tyg - $suma_nierozliczonych;
            if ($teoretyczne_netto < 0) {
                $teoretyczne_netto = 0.0;
            }
            ?>

            <h2>Panel dziecka</h2>

            <h3>Podsumowanie</h3>
            <p>Tygodniowe kieszonkowe: <strong><?php echo number_format($kieszonkowe_tyg, 2); ?> zł</strong></p>
            <p>Aktualnie odpisane (nierozliczone jeszcze):
                <strong><?php echo number_format($suma_nierozliczonych, 2); ?> zł</strong></p>
            <p>Gdyby dziś było rozliczenie, dostał(a)byś:
                <strong><?php echo number_format($teoretyczne_netto, 2); ?> zł</strong></p>
            <p>
                <a class="button" href="pending_deductions.php">
                    Szczegóły nierozliczonych potrąceń
                </a>
            </p>

            <hr>

            <?php
            // 4. Ostatnie potrącenia (np. 10 ostatnich)
            $potracenia = [];
            $stmt = $mysqli->prepare('
                SELECT p.data_zdarzenia, t.nazwa, p.kwota, p.opis, p.rozliczone
                FROM potracenia p
                JOIN typy_potracen t ON p.typ_id = t.id
                WHERE p.dziecko_id = ?
                ORDER BY p.data_zdarzenia DESC, p.id DESC
                LIMIT 10
            ');
            if ($stmt) {
                $stmt->bind_param('i', $user_id);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $potracenia[] = $row;
                }
                $stmt->close();
            }

            // 5. Historia rozliczeń (np. 10 ostatnich)
            $rozliczenia = [];
            $stmt = $mysqli->prepare('
                SELECT okres_od, okres_do, kieszonkowe_brutto, suma_potracen,
                       kieszonkowe_netto, data_rozliczenia
                FROM rozliczenia
                WHERE dziecko_id = ?
                ORDER BY data_rozliczenia DESC
                LIMIT 10
            ');
            if ($stmt) {
                $stmt->bind_param('i', $user_id);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $rozliczenia[] = $row;
                }
                $stmt->close();
            }
            ?>

            <h3>Ostatnie potrącenia</h3>
            <?php if (empty($potracenia)): ?>
                <p>Nie masz jeszcze żadnych potrąceń 🎉</p>
            <?php else: ?>
                <table border="1" cellpadding="5" cellspacing="0">
                    <tr>
                        <th>Data zdarzenia</th>
                        <th>Za co</th>
                        <th>Kwota</th>
                        <th>Opis</th>
                        <th>Status</th>
                    </tr>
                    <?php foreach ($potracenia as $p): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($p['data_zdarzenia']); ?></td>
                            <td><?php echo htmlspecialchars($p['nazwa']); ?></td>
                            <td><?php echo number_format($p['kwota'], 2); ?> zł</td>
                            <td><?php echo htmlspecialchars($p['opis'] ?? ''); ?></td>
                            <td>
                                <?php echo $p['rozliczone'] ? 'Rozliczone' : 'Jeszcze nie rozliczone'; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>

            <hr>

            <h3>Historia rozliczeń</h3>
            <?php if (empty($rozliczenia)): ?>
                <p>Nie masz jeszcze żadnych rozliczeń.</p>
            <?php else: ?>
                <table border="1" cellpadding="5" cellspacing="0">
                    <tr>
                        <th>Okres</th>
                        <th>Kieszonkowe brutto</th>
                        <th>Suma potrąceń</th>
                        <th>Do wypłaty</th>
                        <th>Data rozliczenia</th>
                    </tr>
                    <?php foreach ($rozliczenia as $r): ?>
                        <tr>
                            <td>
                                <?php
                                    echo htmlspecialchars($r['okres_od'])
                                         . ' &ndash; '
                                         . htmlspecialchars($r['okres_do']);
                                ?>
                            </td>
                            <td><?php echo number_format($r['kieszonkowe_brutto'], 2); ?> zł</td>
                            <td><?php echo number_format($r['suma_potracen'], 2); ?> zł</td>
                            <td><?php echo number_format($r['kieszonkowe_netto'], 2); ?> zł</td>
                            <td><?php echo htmlspecialchars($r['data_rozliczenia']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>

        <?php else: ?>

            <p>Nieznana rola użytkownika. Skontaktuj się z administratorem.</p>

        <?php endif; ?>
    </div> <!--container -->
</div> <!-- layout-desktop -->

<!-- ================== WERSJA MOBILNA (nowy layout) ================== -->
<div class="layout-mobile">
    <div class="app">
        <header class="app-header">
            <div class="app-header__left">
                <span class="app-logo">💰</span>
                <span class="app-title">Kieszonkowe</span>
            </div>
            <button class="icon-button" aria-label="Wyloguj" onclick="window.location.href='logout.php'">
                ⎋
            </button>
        </header>

        <?php if ($rola === 'rodzic'): ?>

            <?php
            // Zebranie danych do podsumowania i kart dzieci
            $children = [];
            $totalOutstanding = 0.0;
            $totalLastNet     = 0.0;
            $totalWeekly      = 0.0;

            $stmtChildren = $mysqli->prepare('
                SELECT id, imie, login, kieszonkowe_tygodniowe
                FROM uzytkownicy
                WHERE rodzic_id = ? AND rola = "dziecko" AND aktywny = 1
                ORDER BY imie
            ');
            if ($stmtChildren) {
                $stmtChildren->bind_param('i', $user_id);
                $stmtChildren->execute();
                $resChildren = $stmtChildren->get_result();

                $stmtSumM = $mysqli->prepare('
                    SELECT IFNULL(SUM(kwota), 0) AS suma
                    FROM potracenia
                    WHERE dziecko_id = ? AND rozliczone = 0
                ');
                $stmtLastM = $mysqli->prepare('
                    SELECT okres_od, okres_do, kieszonkowe_brutto,
                           suma_potracen, kieszonkowe_netto, data_rozliczenia
                    FROM rozliczenia
                    WHERE dziecko_id = ?
                    ORDER BY data_rozliczenia DESC
                    LIMIT 1
                ');

                while ($row = $resChildren->fetch_assoc()) {
                    $childId = (int)$row['id'];
                    $weekly  = (float)$row['kieszonkowe_tygodniowe'];
                    $totalWeekly += $weekly;

                    // suma nierozliczonych
                    $outstanding = 0.0;
                    if ($stmtSumM) {
                        $stmtSumM->bind_param('i', $childId);
                        $stmtSumM->execute();
                        $stmtSumM->bind_result($outstanding);
                        $stmtSumM->fetch();
                        $stmtSumM->free_result();
                    }

                    // ostatnie rozliczenie
                    $last_okres_od = null;
                    $last_okres_do = null;
                    $last_brutto   = null;
                    $last_suma     = null;
                    $last_netto    = null;
                    $last_data     = null;

                    if ($stmtLastM) {
                        $stmtLastM->bind_param('i', $childId);
                        $stmtLastM->execute();
                        $stmtLastM->bind_result(
                            $last_okres_od,
                            $last_okres_do,
                            $last_brutto,
                            $last_suma,
                            $last_netto,
                            $last_data
                        );
                        $stmtLastM->fetch();
                        $stmtLastM->free_result();
                    }

                    $totalOutstanding += (float)$outstanding;
                    if ($last_netto !== null) {
                        $totalLastNet += (float)$last_netto;
                    }

                    $children[] = [
                        'id'           => $childId,
                        'imie'         => $row['imie'],
                        'login'        => $row['login'],
                        'weekly'       => $weekly,
                        'outstanding'  => (float)$outstanding,
                        'last_okres_od'=> $last_okres_od,
                        'last_okres_do'=> $last_okres_do,
                        'last_brutto'  => $last_brutto,
                        'last_suma'    => $last_suma,
                        'last_netto'   => $last_netto,
                        'last_data'    => $last_data,
                    ];
                }

                if ($stmtSumM)   $stmtSumM->close();
                if ($stmtLastM)  $stmtLastM->close();
                $stmtChildren->close();
            }
            ?>

            <section class="period-bar">
                <div class="period-main">Podsumowanie kieszonkowego</div>
                <div class="period-sub">Widok dla rodzica</div>
            </section>

            <section class="summary-row">
                <article class="summary-card">
                    <div class="summary-label">Do wypłaty (ostatnie rozliczenia)</div>
                    <div class="summary-value">
                        <?php echo number_format($totalLastNet, 2, ',', ' '); ?> zł
                    </div>
                </article>
                <article class="summary-card">
                    <div class="summary-label">Nierozliczone potrącenia</div>
                    <div class="summary-value summary-value--negative">
                        <?php echo number_format($totalOutstanding, 2, ',', ' '); ?> zł
                    </div>
                </article>
                <article class="summary-card">
                    <div class="summary-label">Tygodniowe kieszonkowe dzieci</div>
                    <div class="summary-value">
                        <?php echo number_format($totalWeekly, 2, ',', ' '); ?> zł
                    </div>
                </article>
            </section>

            <main class="content">
                <h2 class="section-title">Dzieci</h2>

                <?php if (empty($children)): ?>
                    <p>Nie dodałeś jeszcze żadnego dziecka.</p>
                    <p><a href="add_child.php" class="btn btn-primary">➕ Dodaj dziecko</a></p>
                <?php else: ?>
                    <?php foreach ($children as $c): ?>
                        <article class="child-card">
                            <div class="child-card__header">
                                <div>
                                    <div class="child-name">
                                        <?php echo htmlspecialchars($c['imie']); ?>
                                    </div>
                                    <div class="child-meta">
                                        Login: <?php echo htmlspecialchars($c['login']); ?><br>
                                        <?php if ($c['last_data'] !== null): ?>
                                            Ostatnie rozliczenie:
                                            <?php echo htmlspecialchars($c['last_data']); ?>
                                        <?php else: ?>
                                            Brak rozliczeń
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="child-balance <?php echo ($c['last_netto'] ?? 0) >= 0 ? 'child-balance--positive' : 'child-balance--negative'; ?>">
                                    <?php if ($c['last_netto'] !== null): ?>
                                        <?php echo number_format($c['last_netto'], 2, ',', ' '); ?>zł
                                    <?php else: ?>
                                        0,00zł
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="child-card__body">
                                <div class="child-stats">
                                    Tygodniowe:
                                    <?php echo number_format($c['weekly'], 2, ',', ' '); ?>&nbsp;zł ·<br>
                                    Nierozliczone potrącenia:
                                    <a href="pending_deductions.php?child_id=<?php echo (int)$c['id']; ?>">
                                        <span class="summary-value<?php echo ($c['outstanding'] > 0 ? ' summary-value--negative' : ''); ?>">
                                            <?php echo number_format($c['outstanding'], 2, ',', ' '); ?>&nbsp;zł
                                        </span>
                                    </a>
                                </div>
                            </div>
                            <div class="child-card__actions">
                                <!-- add_event.php możesz mieć lub usunąć, ważny jest link do add_deduction.php -->
                                <!-- <a href="add_event.php?dziecko_id=<?php echo (int)$c['id']; ?>" class="btn btn-secondary">+ Zdarzenie</a> -->
                                <a href="add_deduction.php?dziecko_id=<?php echo (int)$c['id']; ?>" class="btn btn-secondary">− Potrącenie</a>
                                <a href="make_settlement.php?dziecko_id=<?php echo (int)$c['id']; ?>" class="btn btn-primary">Rozlicz</a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </main>

        <?php elseif ($rola === 'dziecko'): ?>

            <?php
            // Te same dane co w panelu dziecka, ale w formie kart
            $kieszonkowe_tyg_m = 0.0;
            $stmt = $mysqli->prepare('
                SELECT kieszonkowe_tygodniowe
                FROM uzytkownicy
                WHERE id = ? AND rola = "dziecko" AND aktywny = 1
                LIMIT 1
            ');
            if ($stmt) {
                $stmt->bind_param('i', $user_id);
                $stmt->execute();
                $stmt->bind_result($kieszonkowe_tyg_m);
                $stmt->fetch();
                $stmt->close();
            }
            if ($kieszonkowe_tyg_m === null) {
                $kieszonkowe_tyg_m = 0.0;
            }
            $kieszonkowe_tyg_m = (float)$kieszonkowe_tyg_m;

            $suma_nierozliczonych_m = 0.0;
            $stmt = $mysqli->prepare('
                SELECT IFNULL(SUM(kwota), 0) AS suma
                FROM potracenia
                WHERE dziecko_id = ? AND rozliczone = 0
            ');
            if ($stmt) {
                $stmt->bind_param('i', $user_id);
                $stmt->execute();
                $stmt->bind_result($suma_nierozliczonych_m);
                $stmt->fetch();
                $stmt->close();
            }
            $suma_nierozliczonych_m = (float)$suma_nierozliczonych_m;

            $teoretyczne_netto_m = $kieszonkowe_tyg_m - $suma_nierozliczonych_m;
            if ($teoretyczne_netto_m < 0) {
                $teoretyczne_netto_m = 0.0;
            }
            ?>

            <section class="period-bar">
                <div class="period-main">Twoje kieszonkowe</div>
                <div class="period-sub">Widok dla dziecka</div>
            </section>

            <section class="summary-row">
                <article class="summary-card">
                    <div class="summary-label">Tygodniowe kieszonkowe</div>
                    <div class="summary-value">
                        <?php echo number_format($kieszonkowe_tyg_m, 2, ',', ' '); ?> zł
                    </div>
                </article>
                <article class="summary-card">
                    <div class="summary-label">Odpisane (nierozliczone)</div>
                    <div class="summary-value summary-value--negative">
                        <?php echo number_format($suma_nierozliczonych_m, 2, ',', ' '); ?> zł
                    </div>
                </article>
                <article class="summary-card">
                    <div class="summary-label">Gdyby dziś było rozliczenie</div>
                    <div class="summary-value">
                        <?php echo number_format($teoretyczne_netto_m, 2, ',', ' '); ?> zł
                    </div>
                </article>
            </section>

            <main class="content">
                <h2 class="section-title">Szczegóły</h2>
                <p>
                    <a class="btn btn-primary" href="pending_deductions.php">
                        Szczegóły nierozliczonych potrąceń
                    </a>
                </p>
            </main>

        <?php else: ?>

            <main class="content">
                <p>Nieznana rola użytkownika. Skontaktuj się z administratorem.</p>
            </main>

        <?php endif; ?>

        <nav class="bottom-nav" aria-label="Nawigacja dolna">
            <button class="bottom-nav__item bottom-nav__item--active">
                <span class="bottom-nav__icon">👨‍👩‍👧</span>
                <span class="bottom-nav__label">
                    <?php echo ($rola === 'rodzic') ? 'Dzieci' : 'Podsumowanie'; ?>
                </span>
            </button>
            <button class="bottom-nav__item" onclick="window.location.href='logout.php'">
                <span class="bottom-nav__icon">⎋</span>
                <span class="bottom-nav__label">Wyloguj</span>
            </button>
        </nav>
    </div> <!-- .app -->
</div> <!-- .layout-mobile -->

</body>
</html>
