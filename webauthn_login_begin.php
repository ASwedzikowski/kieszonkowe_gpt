<?php
// webauthn_login_begin.php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/webauthn_init.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Metoda niedozwolona (użyj POST)']);
        return;
    }

    if (!isset($_POST['login'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Brak parametru login']);
        return;
    }

    $login = trim($_POST['login']);
    if ($login === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Login jest pusty']);
        return;
    }

    // Szukamy użytkownika po loginie
    $stmt = $mysqli->prepare('SELECT id, rola, aktywny FROM uzytkownicy WHERE login = ?');
    if (!$stmt) {
        throw new RuntimeException('DB error (prepare uzytkownicy): ' . $mysqli->error);
    }
    $stmt->bind_param('s', $login);
    $stmt->execute();
    $stmt->bind_result($userId, $rola, $aktywny);

    if (!$stmt->fetch()) {
        $stmt->close();
        http_response_code(400);
        echo json_encode(['error' => 'Nie znaleziono użytkownika o podanym loginie']);
        return;
    }
    $stmt->close();

    if ((int)$aktywny !== 1) {
        http_response_code(400);
        echo json_encode(['error' => 'Konto użytkownika jest nieaktywne']);
        return;
    }

    // Pobierz listę credentiali dla użytkownika
    $stmt = $mysqli->prepare('SELECT credential_id FROM webauthn_credentials WHERE user_id = ?');
    if (!$stmt) {
        throw new RuntimeException('DB error (prepare webauthn_credentials): ' . $mysqli->error);
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->bind_result($credIdBin);

    $credentialIds = [];
    while ($stmt->fetch()) {
        if ($credIdBin !== null) {
            $credentialIds[] = $credIdBin; // binarne ID
        }
    }
    $stmt->close();

    if (empty($credentialIds)) {
        http_response_code(400);
        echo json_encode(['error' => 'Dla tego użytkownika nie zarejestrowano żadnych kluczy WebAuthn']);
        return;
    }

    // Generujemy parametry logowania – wg dokumentacji getGetArgs() 
    $getArgs = $webAuthn->getGetArgs(
        $credentialIds, // lista binarnych credential_id
        60,             // timeout (s)
        false,          // allowUsb
        false,          // allowNfc
        false,          // allowBle
        true,           // allowHybrid (np. skan QR, zewn. urządzenia)
        true,           // allowInternal (passkey w urządzeniu)
        'preferred'     // requireUserVerification ('required'/'preferred'/'discouraged' lub bool)
    );

    // Challenge – zamieniamy na ZWYKŁY STRING, bez ByteBuffer w instanceof
    $challengeObj = $webAuthn->getChallenge();
    if (is_object($challengeObj) && method_exists($challengeObj, 'getBinaryString')) {
        $challenge = $challengeObj->getBinaryString();
    } else {
        $challenge = (string)$challengeObj;
    }
    webauthn_store_challenge('login', $challenge);

    // Zapamiętujemy ID użytkownika na czas logowania
    $_SESSION['webauthn_login_user_id'] = (int)$userId;

    echo json_encode($getArgs);

} catch (\Throwable $e) {
    // Na czas debugowania ZAWSZE zwracamy JSON z opisem błędu
    http_response_code(200);
    echo json_encode([
        'error' => 'Błąd w webauthn_login_begin: ' . $e->getMessage(),
    ]);
}
