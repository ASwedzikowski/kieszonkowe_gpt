<?php
require_once __DIR__ . '/config.php';

// Jeśli już zalogowany → od razu do głównej strony
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Logowanie – Kieszonkowe</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="mobile.css">
</head>
<body class="login-body">
    <div class="login-page">
        <div class="login-card">
            <div class="login-card__header">
                <span class="login-logo">💰</span>
                <div>
                    <h1 class="login-title">Kieszonkowe</h1>
                    <p class="login-subtitle">Wybierz sposób logowania</p>
                </div>
            </div>

            <div class="login-card__content">
                <button
                    type="button"
                    class="button login-button login-button--primary"
                    onclick="webauthnLoginNoUsername()"
                >
                    <span class="login-button__icon">🖐️</span>
                    <span>Zaloguj odciskiem</span>
                </button>

                <a
                    href="login_password.php"
                    class="button login-button login-button--secondary"
                >
                    <span class="login-button__icon">🔑</span>
                    <span>Zaloguj hasłem</span>
                </a>
            </div>

            <p class="login-help">
                Jeśli logowanie odciskiem palca nie działa na tym urządzeniu,
                zawsze możesz skorzystać z logowania hasłem.
            </p>
        </div>
    </div>

    <script src="webauthn.js?v=3"></script>
</body>
</html>
