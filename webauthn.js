// webauthn.js

function base64urlToArrayBuffer(base64url) {
    const pad = '='.repeat((4 - base64url.length % 4) % 4);
    const base64 = (base64url + pad).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = atob(base64);
    const outputArray = new Uint8Array(rawData.length);
    for (let i = 0; i < rawData.length; ++i) {
        outputArray[i] = rawData.charCodeAt(i);
    }
    return outputArray.buffer;
}

function arrayBufferToBase64url(buffer) {
    const bytes = new Uint8Array(buffer);
    let binary = '';
    for (let i = 0; i < bytes.byteLength; i++) {
        binary += String.fromCharCode(bytes[i]);
    }
    const base64 = btoa(binary);
    return base64.replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

async function webauthnRegister() {
    try {
        // 1. Poproś serwer o parametry rejestracji
        const res = await fetch('webauthn_register_begin.php', {
            credentials: 'include'
        });

        if (!res.ok) {
            const txt = await res.text();
            console.error('HTTP error', res.status, txt);
            alert('Błąd HTTP webauthn_register_begin.php: ' + res.status);
            return;
        }
        const createArgs = await res.json();

        // 2. Zamiana base64url → ArrayBuffer dla pól binarnych
        const publicKey = createArgs.publicKey || createArgs; // w zależności jak zwróci PHP
        publicKey.challenge = base64urlToArrayBuffer(publicKey.challenge);
        publicKey.user.id   = base64urlToArrayBuffer(publicKey.user.id);

        if (publicKey.excludeCredentials) {
            publicKey.excludeCredentials = publicKey.excludeCredentials.map(c => ({
                type: c.type,
                id: base64urlToArrayBuffer(c.id)
            }));
        }

        // 3. Wywołanie WebAuthn w przeglądarce
        const credential = await navigator.credentials.create({ publicKey });

        // 4. Przygotowanie danych do wysłania na serwer
        const attestation = {
            id: arrayBufferToBase64url(credential.rawId),
            type: credential.type,
            response: {
                clientDataJSON:    arrayBufferToBase64url(credential.response.clientDataJSON),
                attestationObject: arrayBufferToBase64url(credential.response.attestationObject),
            }
        };

const res2 = await fetch('webauthn_register_finish.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    credentials: 'include',
    body: JSON.stringify(attestation)
});

// Nie przerywamy przy !ok – chcemy zobaczyć JSON z błędem
const textBody = await res2.text();
console.log('finish raw response:', textBody);

if (!textBody) {
    alert('webAuthn finish: pusta odpowiedź z serwera (HTTP ' + res2.status + ')');
    return;
}

let result;
try {
    result = JSON.parse(textBody);
} catch (e) {
    alert('webAuthn finish: odpowiedź nie jest JSON-em (HTTP ' + res2.status + '): ' + textBody);
    return;
}

if (result.success) {
    alert('Dodano logowanie odciskiem palca / passkey 🎉');
} else {
    alert(
        'Błąd rejestracji (HTTP ' + res2.status + '): ' +
        (result.error || 'nieznany błąd')
    );
}



    } catch (e) {
        console.error(e);
        alert('Błąd WebAuthn w przeglądarce: ' + e.message);
    }
}


async function webauthnLogin() {
    try {
        // Obsługa zarówno desktopowego #login, jak i mobilnego #login_mobile
        const desktopField = document.getElementById('login');
        const mobileField  = document.getElementById('login_mobile');

        let loginField = null;

        // Priorytet: to pole, w którym faktycznie coś wpisano
        if (desktopField && desktopField.value.trim() !== '') {
            loginField = desktopField;
        } else if (mobileField && mobileField.value.trim() !== '') {
            loginField = mobileField;
        } else if (desktopField) {
            loginField = desktopField;
        } else if (mobileField) {
            loginField = mobileField;
        }

        if (!loginField || loginField.value.trim() === '') {
            alert('Najpierw wpisz login (konto, które chcesz zalogować).');
            return;
        }

        const login = loginField.value.trim();

        // 1. Poproś serwer o parametry logowania
        const body = new URLSearchParams();
        body.append('login', login);

        const res = await fetch('webauthn_login_begin.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            credentials: 'include',
            body: body.toString()
        });

        const text1 = await res.text();
        console.log('login_begin raw:', text1);
        if (!text1) {
            alert('Błąd: pusta odpowiedź z webauthn_login_begin.php');
            return;
        }

        let beginData;
        try {
            beginData = JSON.parse(text1);
        } catch (e) {
            alert('Błąd: odpowiedź z webauthn_login_begin.php nie jest JSON-em: ' + e.message);
            return;
        }

        if (beginData.error) {
            alert('Błąd logowania WebAuthn (begin): ' + beginData.error);
            return;
        }

        const publicKey = beginData.publicKey || beginData;

        // 2. Konwersja base64url -> ArrayBuffer
        publicKey.challenge = base64urlToArrayBuffer(publicKey.challenge);

        if (publicKey.allowCredentials) {
            publicKey.allowCredentials = publicKey.allowCredentials.map(c => ({
                type: c.type,
                id: base64urlToArrayBuffer(c.id),
                transports: c.transports || ['internal']
            }));
        }

        // 3. Wywołanie WebAuthn w przeglądarce
        const assertion = await navigator.credentials.get({ publicKey });

        // 4. Przygotuj dane dla PHP
        const payload = {
            id: arrayBufferToBase64url(assertion.rawId),
            type: assertion.type,
            response: {
                clientDataJSON:    arrayBufferToBase64url(assertion.response.clientDataJSON),
                authenticatorData: arrayBufferToBase64url(assertion.response.authenticatorData),
                signature:         arrayBufferToBase64url(assertion.response.signature),
                userHandle:        assertion.response.userHandle
                    ? arrayBufferToBase64url(assertion.response.userHandle)
                    : null
            }
        };

        const res2 = await fetch('webauthn_login_finish.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            credentials: 'include',
            body: JSON.stringify(payload)
        });

        const text2 = await res2.text();
        console.log('login_finish raw:', text2);

        if (!text2) {
            alert('webAuthn finish: pusta odpowiedź z serwera');
            return;
        }

        let result;
        try {
            result = JSON.parse(text2);
        } catch (e) {
            alert('webAuthn finish: odpowiedź nie jest JSON-em: ' + e.message);
            return;
        }

        if (result.success) {
            // tu ewentualnie możesz zmienić adres na swój panel rodzica
            window.location.href = result.redirect || 'index.php';
        } else {
            alert('Błąd logowania WebAuthn: ' + (result.error || 'nieznany błąd'));
        }

    } catch (e) {
        console.error(e);
        alert('Błąd WebAuthn w przeglądarce: ' + e.message);
    }
}


async function webauthnLoginNoUsername() {
    try {
        // 1. Pobierz od serwera parametry logowania dla passkey (bez loginu)
        const res = await fetch('webauthn_login_nouser_begin.php', {
            method: 'POST',
            credentials: 'include'
        });

        const text1 = await res.text();
        console.log('login_nouser_begin raw:', text1);

        if (!text1) {
            alert('Pusta odpowiedź z webauthn_login_nouser_begin.php');
            return;
        }

        let beginData;
        try {
            beginData = JSON.parse(text1);
        } catch (e) {
            alert('Odpowiedź z webauthn_login_nouser_begin.php nie jest JSON-em: ' + e.message);
            return;
        }

        if (beginData.error) {
            alert('Błąd (begin): ' + beginData.error);
            return;
        }

        const publicKey = beginData.publicKey || beginData;

        // 2. challenge base64url -> ArrayBuffer
        publicKey.challenge = base64urlToArrayBuffer(publicKey.challenge);

        if (publicKey.allowCredentials) {
            publicKey.allowCredentials = publicKey.allowCredentials.map(c => ({
                type: c.type,
                id: base64urlToArrayBuffer(c.id),
                transports: c.transports || ['internal']
            }));
        }

        // 3. WebAuthn get() – system pokaże listę passkey / konto do wyboru
        const assertion = await navigator.credentials.get({ publicKey });

        // 4. Dane dla serwera
        const payload = {
            id: arrayBufferToBase64url(assertion.rawId),
            type: assertion.type,
            response: {
                clientDataJSON:    arrayBufferToBase64url(assertion.response.clientDataJSON),
                authenticatorData: arrayBufferToBase64url(assertion.response.authenticatorData),
                signature:         arrayBufferToBase64url(assertion.response.signature),
                userHandle:        assertion.response.userHandle
                    ? arrayBufferToBase64url(assertion.response.userHandle)
                    : null
            }
        };

        const res2 = await fetch('webauthn_login_nouser_finish.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            credentials: 'include',
            body: JSON.stringify(payload)
        });

        const text2 = await res2.text();
        console.log('login_nouser_finish raw:', text2);

        if (!text2) {
            alert('Pusta odpowiedź z webauthn_login_nouser_finish.php');
            return;
        }

        let result;
        try {
            result = JSON.parse(text2);
        } catch (e) {
            alert('Finish: odpowiedź nie jest JSON-em: ' + e.message);
            return;
        }

        if (result.success) {
            window.location.href = result.redirect || 'index.php';
        } else {
            alert('Błąd logowania (no username): ' + (result.error || 'nieznany błąd'));
        }

    } catch (e) {
        console.error(e);
        alert('Błąd WebAuthn (no username): ' + e.message);
    }
}



console.log('webauthn.js załadowany');
// Koniec webauthn.js