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
    <!-- ważne dla telefonów -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- style desktop -->
    <link rel="stylesheet" href="style.css">
    <!-- style mobilne -->
    <link rel="stylesheet" href="mobile.css">

    <title>Logowanie</title>

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

<!-- ================== WERSJA DESKTOPOWA (Twoja dotychczasowa) ================== -->
<div class="layout-desktop">
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
            <input type="text"
                   name="login"
                   id="login"
                   value="<?php echo htmlspecialchars($_POST['login'] ?? ''); ?>"><br><br>

            <label for="haslo">Hasło:</label><br>
            <input type="password" name="haslo" id="haslo"><br><br>

            <input type="submit" value="Zaloguj">
        </form>

        <p><a href="register.php">Nie mam konta – rejestracja rodzica</a></p>
    </div>
</div>

<!-- ================== WERSJA MOBILNA (nowy layout) ================== -->
<div class="layout-mobile">
    <div class="app">
        <header class="app-header">
            <div class="app-header__left">
                <span class="app-logo">💰</span>
                <span class="app-title">Kieszonkowe</span>
            </div>
        </header>

        <?php if (!empty($errors)): ?>
            <section class="summary-row">
                <article class="summary-card">
                    <div class="summary-label">Błąd logowania</div>
                    <div class="summary-value summary-value--negative" style="font-size:0.9rem;">
                        <?php foreach ($errors as $e): ?>
                            <?php echo htmlspecialchars($e); ?><br>
                        <?php endforeach; ?>
                    </div>
                </article>
            </section>
        <?php else: ?>
            <section class="period-bar">
                <div class="period-main">Zaloguj się</div>
                <div class="period-sub">Podaj login i hasło, aby wejść do systemu</div>
            </section>
        <?php endif; ?>

        <main class="content">
            <article class="child-card">
                <div class="child-card__body">
                    <form method="post" class="mobile-form">
                        <label class="mobile-label" for="login_mobile">
                            Login
                            <input
                                type="text"
                                name="login"
                                id="login_mobile"
                                class="mobile-input"
                                value="<?php echo htmlspecialchars($_POST['login'] ?? ''); ?>"
                                required
                            >
                        </label>

                        <label class="mobile-label" for="haslo_mobile">
                            Hasło
                            <input
                                type="password"
                                name="haslo"
                                id="haslo_mobile"
                                class="mobile-input"
                                required
                            >
                        </label>

                        <div class="child-card__actions">
                            <button type="submit" class="btn btn-primary">
                                Zaloguj się
                            </button>
                        </div>
                    </form>
                </div>
            </article>

            <p style="text-align:center; margin-top:12px;">
                <a href="register.php">Nie mam konta – rejestracja rodzica</a>
            </p>
        </main>

        <nav class="bottom-nav" aria-label="Nawigacja dolna">
            <button class="bottom-nav__item bottom-nav__item--active">
                <span class="bottom-nav__icon">🔐</span>
                <span class="bottom-nav__label">Logowanie</span>
            </button>
        </nav>
    </div>
</div>

</body>
</html>
