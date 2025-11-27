<?php
// webauthn_login_nouser_begin.php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/webauthn_init.php';

use lbuchs\WebAuthn\Binary\ByteBuffer;

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Metoda niedozwolona (użyj POST)']);
        return;
    }

    // Tu NIE znamy jeszcze użytkownika → nie podajemy credentialIds
    $getArgs = $webAuthn->getGetArgs(
        [],     // pusta lista → authenticator sam wybierze passkeye dla tej domeny
        60,     // timeout
        false,  // allowUsb
        false,  // allowNfc
        false,  // allowBle
        true,   // allowHybrid (np. skan QR)
        true,   // allowInternal (passkey w urządzeniu)
        true    // requireUserVerification (odcisk/PIN wymagany)
    );

    // challenge zapisujemy jako STRING, nie obiekt
    $chObj = $webAuthn->getChallenge();
    if (is_object($chObj) && method_exists($chObj, 'getBinaryString')) {
        $challenge = $chObj->getBinaryString();
    } else {
        $challenge = (string)$chObj;
    }
    webauthn_store_challenge('login_nouser', $challenge);

    echo json_encode($getArgs);

} catch (\Throwable $e) {
    http_response_code(200); // na dev dla czytelnego błędu
    echo json_encode([
        'error' => 'Błąd w webauthn_login_nouser_begin: ' . $e->getMessage(),
    ]);
}
