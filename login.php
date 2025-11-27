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
<body>
    <div class="login-container">
        <h1>Kieszonkowe – logowanie</h1>

        <p>
            Zaloguj się <strong>odciskiem palca / passkey</strong>.
        </p>

        <button type="button" onclick="webauthnLoginNoUsername()" class="primary-button">
            Zaloguj odciskiem palca / passkey
        </button>

        <p style="margin-top:1em;font-size:0.9em;opacity:0.7;">
            Jeśli coś nie działa, możesz awaryjnie użyć
            <a href="login_password.php">logowania hasłem</a>.
        </p>
    </div>

    <script src="webauthn.js"></script>
</body>
</html>
