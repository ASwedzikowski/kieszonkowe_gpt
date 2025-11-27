<?php
// webauthn_login_nouser_finish.php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/webauthn_init.php';

use lbuchs\WebAuthn\Binary\ByteBuffer;

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Metoda niedozwolona']);
        return;
    }

    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Brak danych z przeglądarki']);
        return;
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Błędny JSON']);
        return;
    }

    $challenge = webauthn_get_challenge('login_nouser');
    if (!$challenge) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Brak challenge w sesji']);
        return;
    }

    if (
        !isset($data['response']['clientDataJSON']) ||
        !isset($data['response']['authenticatorData']) ||
        !isset($data['response']['signature']) ||
        !isset($data['id'])
    ) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Brak wymaganych pól']);
        return;
    }

    // base64url -> binaria
    $clientDataJSON    = ByteBuffer::fromBase64Url($data['response']['clientDataJSON'])->getBinaryString();
    $authenticatorData = ByteBuffer::fromBase64Url($data['response']['authenticatorData'])->getBinaryString();
    $signature         = ByteBuffer::fromBase64Url($data['response']['signature'])->getBinaryString();
    $credentialIdBin   = ByteBuffer::fromBase64Url($data['id'])->getBinaryString();

    // Szukamy klucza w bazie
    $stmt = $mysqli->prepare('
        SELECT user_id, public_key, sign_count
        FROM webauthn_credentials
        WHERE credential_id = ?
        LIMIT 1
    ');
    if (!$stmt) {
        throw new RuntimeException('DB error (prepare select credential): ' . $mysqli->error);
    }
    $stmt->bind_param('s', $credentialIdBin);
    $stmt->execute();
    $stmt->bind_result($userId, $publicKey, $prevSignCount);

    if (!$stmt->fetch()) {
        $stmt->close();
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Nieznany klucz WebAuthn']);
        return;
    }
    $stmt->close();

    // Weryfikacja podpisu
    $ok = $webAuthn->processGet(
        $clientDataJSON,
        $authenticatorData,
        $signature,
        $publicKey,
        $challenge,
        $prevSignCount,
        true,  // requireUserVerification
        true   // requireUserPresent
    );

    if (!$ok) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Nieudana weryfikacja WebAuthn']);
        return;
    }

    $newSignCount = $webAuthn->getSignatureCounter() ?? $prevSignCount;

    // Aktualizacja licznika
    $stmt = $mysqli->prepare('
        UPDATE webauthn_credentials
        SET sign_count = ?, last_used = NOW()
        WHERE credential_id = ?
    ');
    if ($stmt) {
        $stmt->bind_param('is', $newSignCount, $credentialIdBin);
        $stmt->execute();
        $stmt->close();
    }

    // Ustawiamy normalną sesję z tabeli uzytkownicy
    $stmt = $mysqli->prepare('SELECT rola, imie, login FROM uzytkownicy WHERE id = ?');
    if (!$stmt) {
        throw new RuntimeException('DB error (prepare select user): ' . $mysqli->error);
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->bind_result($rola, $imie, $login);
    if (!$stmt->fetch()) {
        $stmt->close();
        throw new RuntimeException('Użytkownik nie istnieje');
    }
    $stmt->close();

    $_SESSION['user_id'] = $userId;
    $_SESSION['rola']    = $rola;
    $_SESSION['imie']    = $imie;
    $_SESSION['login']   = $login;

    // dostosuj nazwy plików docelowych do swojego projektu
    $redirect = ($rola === 'rodzic') ? 'index.php' : 'index.php';

    echo json_encode(['success' => true, 'redirect' => $redirect]);

} catch (\Throwable $e) {
    http_response_code(200);
    echo json_encode([
        'success' => false,
        'error'   => 'Błąd w webauthn_login_nouser_finish: ' . $e->getMessage(),
    ]);
}
