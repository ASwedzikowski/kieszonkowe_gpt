<?php
// passkeys.php – panel zarządzania kluczami WebAuthn / passkeyami
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$rola   = $_SESSION['rola'] ?? '';
$imie   = $_SESSION['imie'] ?? '';
$komunikat = null;
$blad = null;

// Obsługa usuwania klucza
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $deleteId = (int)$_POST['delete_id'];

    $stmt = $mysqli->prepare('DELETE FROM webauthn_credentials WHERE id = ? AND user_id = ?');
    if ($stmt) {
        $stmt->bind_param('ii', $deleteId, $userId);
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $komunikat = 'Klucz został usunięty. Logowanie tym passkeyem nie będzie już możliwe.';
        } else {
            $blad = 'Nie udało się usunąć klucza (być może już nie istnieje).';
        }
        $stmt->close();
    } else {
        $blad = 'Błąd bazy danych podczas usuwania klucza.';
    }
}

// Pobranie listy kluczy dla zalogowanego użytkownika
$stmt = $mysqli->prepare('
    SELECT id, credential_id, created_at, last_used, sign_count
    FROM webauthn_credentials
    WHERE user_id = ?
    ORDER BY created_at ASC
');
$klucze = [];
if ($stmt) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->bind_result($id, $credBin, $createdAt, $lastUsed, $signCount);
    while ($stmt->fetch()) {
        // Tworzymy krótki „odcisk” ID z credential_id (HEX)
        $shortId = strtoupper(substr(bin2hex($credBin), 0, 16));
        $klucze[] = [
            'id'         => $id,
            'short_id'   => $shortId,
            'created_at' => $createdAt,
            'last_used'  => $lastUsed,
            'sign_count' => $signCount,
        ];
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Odciski palca / passkey – Kieszonkowe</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- jeśli masz style.css / mobile.css, podepnij je tutaj -->
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="mobile.css">
    <style>
        .passkeys-container {
            max-width: 800px;
            margin: 1.5rem auto;
            padding: 1rem;
            background: #ffffff;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.08);
        }
        .passkeys-header {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            gap: .5rem;
            flex-wrap: wrap;
        }
        .passkeys-header h1 {
            margin: 0;
            font-size: 1.4rem;
        }
        .passkeys-header .user-info {
            font-size: .9rem;
            opacity: .8;
        }
        .msg-ok {
            background: #e6ffef;
            border: 1px solid #8ad4a0;
            padding: .5rem .75rem;
            border-radius: 6px;
            margin: .75rem 0;
            font-size: .9rem;
        }
        .msg-error {
            background: #ffecec;
            border: 1px solid #e08c8c;
            padding: .5rem .75rem;
            border-radius: 6px;
            margin: .75rem 0;
            font-size: .9rem;
        }
        table.passkeys-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: .75rem;
            font-size: .9rem;
        }
        table.passkeys-table th,
        table.passkeys-table td {
            padding: .5rem .4rem;
            border-bottom: 1px solid #eee;
            text-align: left;
        }
        table.passkeys-table th {
            font-weight: 600;
            background: #f6f7fb;
        }
        table.passkeys-table tr:last-child td {
            border-bottom: none;
        }
        .badge {
            display: inline-block;
            padding: 0.15rem 0.4rem;
            border-radius: 4px;
            font-size: .75rem;
            background: #f0f0f0;
        }
        .btn-delete {
            border: none;
            background: #e74c3c;
            color: #fff;
            padding: .2rem .6rem;
            border-radius: 4px;
            font-size: .8rem;
            cursor: pointer;
        }
        .btn-delete:hover {
            background: #c0392b;
        }
        .empty-info {
            font-size: .9rem;
            opacity: .8;
            margin-top: .5rem;
        }
        .add-passkey-btn {
            display: inline-block;
            margin-top: .8rem;
            margin-bottom: .4rem;
            padding: .4rem .9rem;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-size: .9rem;
            font-weight: 500;
            background: #2666cc;
            color: #fff;
        }
        .add-passkey-btn:hover {
            background: #1f55aa;
        }
        @media (max-width: 600px) {
            table.passkeys-table th:nth-child(3),
            table.passkeys-table td:nth-child(3) {
                display: none; /* ukrywamy sign_count na małych ekranach */
            }
        }
    </style>
</head>
<body>

<div class="passkeys-container">
    <div class="passkeys-header">
        <h1>Odciski palca / passkey</h1>
        <div class="user-info">
            Zalogowany: <strong><?php echo htmlspecialchars($imie ?: $rola, ENT_QUOTES, 'UTF-8'); ?></strong>
            (<?php echo htmlspecialchars($rola, ENT_QUOTES, 'UTF-8'); ?>)
        </div>
    </div>

    <p style="font-size:.9rem; opacity:.85; margin-top:.4rem;">
        Tutaj możesz zobaczyć listę urządzeń / kluczy używanych do logowania
        odciskiem palca / passkey. Możesz też dodać nowy odcisk palca dla tego konta.
    </p>

    <button type="button" class="add-passkey-btn" onclick="webauthnRegister()">
        Dodaj nowy odcisk palca / passkey
    </button>

    <?php if ($komunikat): ?>
        <div class="msg-ok"><?php echo htmlspecialchars($komunikat, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <?php if ($blad): ?>
        <div class="msg-error"><?php echo htmlspecialchars($blad, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if (empty($klucze)): ?>
        <div class="empty-info">
            Nie masz jeszcze zarejestrowanych kluczy WebAuthn/passkey.
            <br>Użyj przycisku <strong>„Dodaj nowy odcisk palca / passkey”</strong> powyżej.
        </div>
    <?php else: ?>
        <table class="passkeys-table">
            <thead>
            <tr>
                <th>Klucz</th>
                <th>Dodany</th>
                <th>Licznik użyć</th>
                <th>Ostatnie logowanie</th>
                <th>Akcje</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($klucze as $k): ?>
                <tr>
                    <td>
                        <span class="badge">
                            ID #<?php echo (int)$k['id']; ?> –
                            <?php echo htmlspecialchars($k['short_id'], ENT_QUOTES, 'UTF-8'); ?>…
                        </span>
                    </td>
                    <td><?php echo htmlspecialchars($k['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo (int)$k['sign_count']; ?></td>
                    <td>
                        <?php
                        echo $k['last_used']
                            ? htmlspecialchars($k['last_used'], ENT_QUOTES, 'UTF-8')
                            : '<span style="opacity:.6;">jeszcze nie użyto</span>';
                        ?>
                    </td>
                    <td>
                        <form method="post" style="margin:0;" onsubmit="return confirm('Na pewno usunąć ten klucz?');">
                            <input type="hidden" name="delete_id" value="<?php echo (int)$k['id']; ?>">
                            <button type="submit" class="btn-delete">Usuń</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <p style="margin-top:1rem; font-size:.9rem;">
        <a href="index.php">&larr; Powrót do panelu głównego</a>
    </p>
</div>

<!-- potrzebne, żeby działało webauthnRegister() -->
<script src="webauthn.js?v=3"></script>
</body>
</html>
