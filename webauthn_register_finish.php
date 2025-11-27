<?php
// webauthn_register_finish.php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/webauthn_init.php';

use lbuchs\WebAuthn\Binary\ByteBuffer;

// Łapiemy WSZYSTKO: wyjątki + Error (parse, type itp.)
try {

    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Brak zalogowanego użytkownika']);
        return;
    }

    $userId = (int)$_SESSION['user_id'];

    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Brak danych z przeglądarki']);
        return;
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Błędny JSON z przeglądarki']);
        return;
    }

$challenge = webauthn_get_challenge('register');
if (!$challenge) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Brak challenge w sesji']);
    return;
}

    // Sprawdzenie, czy są wymagane pola
    if (
        !isset($data['response']['clientDataJSON']) ||
        !isset($data['response']['attestationObject'])
    ) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Brak wymaganych pól w odpowiedzi JS']);
        return;
    }

    // Dane z JS są base64url – konwersja na binaria
    $clientDataJSON    = ByteBuffer::fromBase64Url($data['response']['clientDataJSON'])->getBinaryString();
    $attestationObject = ByteBuffer::fromBase64Url($data['response']['attestationObject'])->getBinaryString();


$result = $webAuthn->processCreate(
    $clientDataJSON,
    $attestationObject,
    $challenge,          // teraz to ZAWSZE string
    false,
    true,
    true,
    true
);

    // Co zwraca processCreate:
    // Upewniamy się, że credentialId to string (nie ByteBuffer/obiekt)
    $credentialId = $result->credentialId;
    if ($credentialId instanceof \lbuchs\WebAuthn\Binary\ByteBuffer) {
        $credentialId = $credentialId->getBinaryString();
    } else {
        $credentialId = (string)$credentialId;
    }
    $publicKey    = $result->credentialPublicKey;   // string (PEM)
    $signCount    = $webAuthn->getSignatureCounter() ?? 0;

    // Zapis do bazy
    // zapis do bazy – credential_id jako string (binarny)
    $stmt = $mysqli->prepare('
        INSERT INTO webauthn_credentials (user_id, credential_id, public_key, sign_count)
        VALUES (?, ?, ?, ?)
    ');
    if (!$stmt) {
        throw new \RuntimeException('DB error (prepare insert): ' . $mysqli->error);
    }
    $stmt->bind_param(
        'issi',
        $userId,
        $credentialId,
        $publicKey,
        $signCount
    );
    $stmt->execute();
    $stmt->close();

    echo json_encode([
    'success'  => true,
    'redirect' => 'passkeys.php'
]);


} catch (\Throwable $e) {
    // Na razie NIE dajemy 500, żeby JS spokojnie odczytał JSON
    // http_response_code(500);
    http_response_code(200);

    echo json_encode([
        'success' => false,
        'error'   => 'Błąd rejestracji klucza: ' . $e->getMessage(),
        // Na dev możesz sobie tymczasowo odkomentować:
        // 'trace'   => $e->getTraceAsString(),
    ]);
}

