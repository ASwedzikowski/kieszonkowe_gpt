<?php
// pending_deductions.php
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

$rola       = $_SESSION['rola'];
$session_id = (int)$_SESSION['user_id'];

// filtr okresu
$allowed_periods = ['all', '7', '30', '90'];
$period = $_GET['period'] ?? 'all';
if (!in_array($period, $allowed_periods, true)) {
    $period = 'all';
}

// Ustalenie, jakie dziecko oglądamy
$child_id = 0;

if ($rola === 'rodzic') {
    // rodzic może podejrzeć swoje dziecko (child_id z GET)
    $child_id = isset($_GET['child_id']) ? (int)$_GET['child_id'] : 0;
    if ($child_id <= 0) {
        die('Nieprawidłowy identyfikator dziecka.');
    }

    // Sprawdź, czy dziecko należy do tego rodzica
    $stmt = $mysqli->prepare('
        SELECT imie, login, kieszonkowe_tygodniowe
        FROM uzytkownicy
        WHERE id = ? AND rodzic_id = ? AND rola = "dziecko" AND aktywny = 1
        LIMIT 1
    ');
    if (!$stmt) {
        die('Błąd przygotowania zapytania.');
    }
    $stmt->bind_param('ii', $child_id, $session_id);
    $stmt->execute();
    $stmt->bind_result($imie_dziecka, $login_dziecka, $kieszonkowe_tyg);
    if (!$stmt->fetch()) {
        $stmt->close();
        die('Nie znaleziono dziecka lub brak uprawnień.');
    }
    $stmt->close();

} elseif ($rola === 'dziecko') {
    // dziecko zawsze ogląda TYLKO siebie, ignorujemy ewentualny child_id z GET
    $child_id = $session_id;

    $stmt = $mysqli->prepare('
        SELECT imie, login, kieszonkowe_tygodniowe
        FROM uzytkownicy
        WHERE id = ? AND rola = "dziecko" AND aktywny = 1
        LIMIT 1
    ');
    if (!$stmt) {
        die('Błąd przygotowania zapytania.');
    }
    $stmt->bind_param('i', $child_id);
    $stmt->execute();
    $stmt->bind_result($imie_dziecka, $login_dziecka, $kieszonkowe_tyg);
    if (!$stmt->fetch()) {
        $stmt->close();
        die('Nie znaleziono konta dziecka.');
    }
    $stmt->close();
} else {
    http_response_code(403);
    die('Brak uprawnień.');
}

// Zbuduj warunek daty dla filtra
$dateCondition = '';
$params = [$child_id];
$param_types = 'i';

if ($period !== 'all') {
    $days = (int)$period;
    $from_date = date('Y-m-d', strtotime("-{$days} days"));
    $dateCondition = ' AND p.data_zdarzenia >= ? ';
    $params[] = $from_date;
    $param_types .= 's';
}

// Pobierz nierozliczone potrącenia z uwzględnieniem filtra
$potracenia = [];
$suma = 0.0;

$query = '
    SELECT p.data_zdarzenia, t.nazwa, p.kwota, p.opis, p.utworzone_at
    FROM potracenia p
    JOIN typy_potracen t ON p.typ_id = t.id
    WHERE p.dziecko_id = ? AND p.rozliczone = 0
    ' . $dateCondition . '
    ORDER BY p.data_zdarzenia DESC, p.id DESC
';

$stmt = $mysqli->prepare($query);
if ($stmt) {
    $stmt->bind_param($param_types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $potracenia[] = $row;
        $suma += (float)$row['kwota'];
    }
    $stmt->close();
}

// data od – do wyświetlenia (dla desktop i mobile)
$from_date_display = null;
if ($period !== 'all') {
    $days = (int)$period;
    $from_date_display = date('Y-m-d', strtotime("-{$days} days"));
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Nierozliczone potrącenia</title>

    <!-- ważne dla telefonów -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- style desktop -->
    <link rel="stylesheet" href="style.css">
    <!-- style mobilne -->
    <link rel="stylesheet" href="mobile.css">

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
        <h1>Nierozliczone potrącenia dla:
            <?php echo htmlspecialchars($imie_dziecka); ?>
            (login: <?php echo htmlspecialchars($login_dziecka); ?>)
        </h1>

        <div class="actions">
            <a href="index.php" class="button button-secondary">&larr; Powrót do panelu</a>
        </div>

        <p>Tygodniowe kieszonkowe:
            <strong><?php echo number_format((float)$kieszonkowe_tyg, 2); ?> zł</strong>
        </p>

        <!-- Formularz filtrowania -->
        <h3>Filtrowanie</h3>
        <form method="get">
            <?php if ($rola === 'rodzic'): ?>
                <input type="hidden" name="child_id" value="<?php echo (int)$child_id; ?>">
            <?php endif; ?>

            <label for="period">Pokaż potrącenia z okresu:</label>
            <select name="period" id="period">
                <option value="all" <?php if ($period === 'all') echo 'selected'; ?>>Cały okres (wszystkie)</option>
                <option value="7" <?php if ($period === '7') echo 'selected'; ?>>Ostatnie 7 dni</option>
                <option value="30" <?php if ($period === '30') echo 'selected'; ?>>Ostatnie 30 dni</option>
                <option value="90" <?php if ($period === '90') echo 'selected'; ?>>Ostatnie 90 dni</option>
            </select>

            <br><br>
            <input type="submit" value="Filtruj">
        </form>

        <?php if ($period !== 'all' && $from_date_display !== null): ?>
            <div class="alert-info">
                Pokazuję potrącenia od <strong><?php echo htmlspecialchars($from_date_display); ?></strong> do dzisiaj.
            </div>
        <?php endif; ?>

        <?php if (empty($potracenia)): ?>
            <p>Brak nierozliczonych potrąceń w wybranym okresie 🎉</p>
        <?php else: ?>

            <p>Łączna suma nierozliczonych potrąceń w wybranym okresie:
                <strong><?php echo number_format($suma, 2); ?> zł</strong>
            </p>

            <div class="table-wrapper">
                <table>
                    <tr>
                        <th>Data zdarzenia</th>
                        <th>Typ</th>
                        <th>Kwota</th>
                        <th>Opis</th>
                        <th>Dodano (data/czas)</th>
                    </tr>
                    <?php foreach ($potracenia as $p): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($p['data_zdarzenia']); ?></td>
                            <td><?php echo htmlspecialchars($p['nazwa']); ?></td>
                            <td><?php echo number_format($p['kwota'], 2); ?> zł</td>
                            <td><?php echo htmlspecialchars($p['opis'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($p['utworzone_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        <?php endif; ?>

    </div>
</div>

<!-- ================== WERSJA MOBILNA ================== -->
<div class="layout-mobile">
    <div class="app">
        <header class="app-header">
            <div class="app-header__left">
                <span class="app-logo">💰</span>
                <span class="app-title">Potrącenia</span>
            </div>
            <!--
            <button class="icon-button" onclick="window.location.href='index.php'">
                ⬅ Powrót do panelu
            </button>
            -->
            <div class="child-card__actions">
                <a href="index.php" class="btn btn-secondary">
                    ← Powrót do panelu
                </a>
            </div>
        </header>

        <section class="period-bar">
            <div class="period-main">
                <?php echo htmlspecialchars($imie_dziecka); ?> – nierozliczone potrącenia
            </div>
            <div class="period-sub">
                Tygodniowe: <?php echo number_format((float)$kieszonkowe_tyg, 2, ',', ' '); ?> zł
            </div>
        </section>

        <section class="summary-row">
            <article class="summary-card">
                <div class="summary-label">Łączna suma potrąceń</div>
                <div class="summary-value summary-value--negative">
                    <?php echo number_format($suma, 2, ',', ' '); ?> zł
                </div>
            </article>

            <?php if ($period !== 'all' && $from_date_display !== null): ?>
                <article class="summary-card">
                    <div class="summary-label">Zakres dat</div>
                    <div class="summary-value" style="font-size:0.9rem;">
                        Od <?php echo htmlspecialchars($from_date_display); ?> do dziś
                    </div>
                </article>
            <?php endif; ?>
        </section>

<main class="content">
            <!-- Powrót do panelu w wersji mobilnej -->
            <article class="child-card">

                <div class="child-card__body">
                    <form method="get" class="mobile-form">
                        <?php if ($rola === 'rodzic'): ?>
                            <input type="hidden" name="child_id" value="<?php echo (int)$child_id; ?>">
                        <?php endif; ?>

                        <label class="mobile-label" for="period_mobile">
                            Pokaż potrącenia z okresu:
                            <select name="period" id="period_mobile" class="mobile-input">
                                <option value="all" <?php if ($period === 'all') echo 'selected'; ?>>Cały okres</option>
                                <option value="7" <?php if ($period === '7') echo 'selected'; ?>>Ostatnie 7 dni</option>
                                <option value="30" <?php if ($period === '30') echo 'selected'; ?>>Ostatnie 30 dni</option>
                                <option value="90" <?php if ($period === '90') echo 'selected'; ?>>Ostatnie 90 dni</option>
                            </select>
                        </label>

                        <div class="child-card__actions">
                            <button type="submit" class="btn btn-primary">
                                Filtruj
                            </button>
                        </div>
                    </form>
                </div>
            </article>

            <?php if (empty($potracenia)): ?>
                <p style="padding: 12px;">Brak nierozliczonych potrąceń w wybranym okresie 🎉</p>
            <?php else: ?>
                <?php foreach ($potracenia as $p): ?>
                    <article class="child-card">
                        <div class="child-card__header">
                            <div>
                                <div class="child-name">
                                    <?php echo htmlspecialchars($p['nazwa']); ?>
                                </div>
                                <div class="child-meta">
                                    Data zdarzenia: <?php echo htmlspecialchars($p['data_zdarzenia']); ?>
                                </div>
                            </div>
                            <div class="child-balance child-balance--negative">
                                -<?php echo number_format($p['kwota'], 2, ',', ' '); ?> zł
                            </div>
                        </div>

                        <?php if (!empty($p['opis']) || !empty($p['utworzone_at'])): ?>
                            <div class="child-card__body">
                                <?php if (!empty($p['opis'])): ?>
                                    <div class="child-stats">
                                        <?php echo htmlspecialchars($p['opis']); ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($p['utworzone_at'])): ?>
                                    <div class="child-meta" style="margin-top:4px;">
                                        Dodano: <?php echo htmlspecialchars($p['utworzone_at']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </main>
    </div>
</div>

</body>
</html>
