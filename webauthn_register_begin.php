<?php
// webauthn_register_begin.php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/webauthn_init.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Brak zalogowanego użytkownika']);
    exit;
}

$userId = (int)$_SESSION['user_id'];

// Pobierz dane użytkownika
$stmt = $mysqli->prepare('SELECT login, imie FROM uzytkownicy WHERE id = ? AND aktywny = 1');
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['error' => 'DB error (prepare uzytkownicy)']);
    exit;
}
$stmt->bind_param('i', $userId);
$stmt->execute();
$stmt->bind_result($login, $imie);
if (!$stmt->fetch()) {
    $stmt->close();
    http_response_code(400);
    echo json_encode(['error' => 'Nie znaleziono użytkownika']);
    exit;
}
$stmt->close();

// userId dla WebAuthn – może być stringiem z ID z bazy
$webauthnUserId      = (string)$userId;
$webauthnUserName    = $login;
$webauthnDisplayName = $imie;

// lista istniejących credentiali – żeby nie rejestrować 100x tego samego
$stmt = $mysqli->prepare('SELECT credential_id FROM webauthn_credentials WHERE user_id = ?');
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['error' => 'DB error (prepare webauthn_credentials)']);
    exit;
}
$stmt->bind_param('i', $userId);
$stmt->execute();
$stmt->bind_result($credBin);

$excludeCredentialIds = [];
while ($stmt->fetch()) {
    if ($credBin !== null) {
        $excludeCredentialIds[] = $credBin; // binarny credential_id
    }
}
$stmt->close();

// Generujemy parametry rejestracji
$createArgs = $webAuthn->getCreateArgs(
    $webauthnUserId,
    $webauthnUserName,
    $webauthnDisplayName,
    60,
    true,        // requireResidentKey = true → prosimy o passkey
    true,        // requireUserVerification = true → wymóg odcisku/PIN
    null,        // authenticatorAttachment (null = dowolny)
    $excludeCredentialIds
);

// getChallenge może być ByteBuffer – zamieniamy na binarny string
$challengeObj = $webAuthn->getChallenge();
if ($challengeObj instanceof \lbuchs\WebAuthn\Binary\ByteBuffer) {
    $challenge = $challengeObj->getBinaryString();
} else {
    // gdyby zwróciło już string
    $challenge = (string)$challengeObj;
}

webauthn_store_challenge('register', $challenge);

// Zwracamy dane do JS
echo json_encode($createArgs);
