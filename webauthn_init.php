<?php
// webauthn_init.php

require_once __DIR__ . '/vendor/autoload.php'; // NAJPIERW vendor
require_once __DIR__ . '/config.php';          // POTEM config (i session_start)

use lbuchs\WebAuthn\WebAuthn;
use lbuchs\WebAuthn\Binary\ByteBuffer;

// NAZWA I DOMENA APLIKACJI:
$rpName = 'Kieszonkowe';
$rpId   = 'kieszonkowe.swedzikowski.pl'; // np. 'kieszonkowe.twojadomena.pl' – bez https://

// true = używamy base64url w JSON-ach
$webAuthn = new WebAuthn($rpName, $rpId, null, true);
ByteBuffer::$useBase64UrlEncoding = true;

// Helpery do przechowywania challenge w sesji
function webauthn_store_challenge(string $type, $challenge): void {
    $_SESSION['webauthn_challenge_'.$type] = $challenge;
}
function webauthn_get_challenge(string $type) {
    return $_SESSION['webauthn_challenge_'.$type] ?? null;
}
