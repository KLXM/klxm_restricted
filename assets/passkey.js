/**
 * Assets file to handle WebAuthn Passkeys operations using native browser APIs.
 * 
 * Should be loaded on frontend pages requiring WebAuthn (Login) or Backend user pages (Registration).
 */
document.addEventListener('DOMContentLoaded', () => {

    /**
     * Helpers to convert standard arrays/strings to Uint8Arrays representing Base64URL required by WebAuthn
     */
    function base64urlToUint8Array(base64url) {
        let padding = '='.repeat((4 - base64url.length % 4) % 4);
        let base64 = (base64url + padding).replace(/\-/g, '+').replace(/_/g, '/');
        let rawData = window.atob(base64);
        let outputArray = new Uint8Array(rawData.length);
        for (let i = 0; i < rawData.length; ++i) {
            outputArray[i] = rawData.charCodeAt(i);
        }
        return outputArray;
    }

    function uInt8ArrayToBase64url(uint8Array) {
        let rawData = String.fromCharCode.apply(null, uint8Array);
        let base64 = window.btoa(rawData);
        return base64.replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }


    /* --- PASSKEY REGISTRATION --- */
    const registerButton = document.getElementById('klxm-passkey-register');
    if (registerButton) {
        registerButton.addEventListener('click', async (e) => {
            e.preventDefault();
            
            try {
                // 1. Get Options from Server
                const optionsResponse = await fetch('/index.php?rex-api-call=klxm_restricted_passkey_register_options');
                const options = await optionsResponse.json();

                if (!optionsResponse.ok || options.error) {
                    throw new Error(options.error || 'Failed to get registration options');
                }

                // 2. Decode specific buffer fields
                options.publicKey.challenge = base64urlToUint8Array(options.publicKey.challenge);
                options.publicKey.user.id = base64urlToUint8Array(options.publicKey.user.id);
                // Exclude credentials...
                if (options.publicKey.excludeCredentials) {
                    for (let cred of options.publicKey.excludeCredentials) {
                        cred.id = base64urlToUint8Array(cred.id);
                    }
                }

                // 3. Ask Browser to Create Credential
                const credential = await navigator.credentials.create(options);

                // 4. Encode Response for Server Verification
                const credentialData = {
                    id: credential.id,
                    rawId: uInt8ArrayToBase64url(new Uint8Array(credential.rawId)),
                    type: credential.type,
                    response: {
                        attestationObject: uInt8ArrayToBase64url(new Uint8Array(credential.response.attestationObject)),
                        clientDataJSON: uInt8ArrayToBase64url(new Uint8Array(credential.response.clientDataJSON))
                    }
                };
                
                // 5. Verify the new credential via server
                const verifyResponse = await fetch('/index.php?rex-api-call=klxm_restricted_passkey_register_verify', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(credentialData)
                });
                
                const verifyResult = await verifyResponse.json();
                
                if (verifyResult.status) {
                    alert('Passkey erfolgreich registriert!');
                    window.location.reload();
                } else {
                    alert('Fehler bei der Registrierung: ' + (verifyResult.error || 'Unbekannt'));
                }

            } catch (err) {
                console.error("Passkey Registration Error", err);
                alert("Ein Fehler ist aufgetreten: " + err.message);
            }
        });
    }

    
    /* --- PASSKEY LOGIN --- */
    const loginButton = document.getElementById('klxm-passkey-login');
    if (loginButton) {
        loginButton.addEventListener('click', async (e) => {
            e.preventDefault();
            
            try {
                // 1. Get Options from Server
                const optionsResponse = await fetch('/index.php?rex-api-call=klxm_restricted_passkey_login_options');
                const options = await optionsResponse.json();
                
                if (!optionsResponse.ok || options.error) {
                    throw new Error(options.error || 'Login Options konnten nicht geladen werden');
                }

                // 2. Decode Buffer fields
                options.publicKey.challenge = base64urlToUint8Array(options.publicKey.challenge);
                
                if (options.publicKey.allowCredentials) {
                    for (let cred of options.publicKey.allowCredentials) {
                        cred.id = base64urlToUint8Array(cred.id);
                    }
                }
                
                // 3. Ask browser for login credential (Assertion)
                const credential = await navigator.credentials.get(options);
                
                // 4. Encode Response to Server
                const credentialData = {
                    id: credential.id,
                    rawId: uInt8ArrayToBase64url(new Uint8Array(credential.rawId)),
                    type: credential.type,
                    response: {
                        authenticatorData: uInt8ArrayToBase64url(new Uint8Array(credential.response.authenticatorData)),
                        clientDataJSON: uInt8ArrayToBase64url(new Uint8Array(credential.response.clientDataJSON)),
                        signature: uInt8ArrayToBase64url(new Uint8Array(credential.response.signature)),
                        userHandle: credential.response.userHandle ? uInt8ArrayToBase64url(new Uint8Array(credential.response.userHandle)) : null
                    }
                };

                // 5. Verify Login Signature with server
                const verifyResponse = await fetch('/index.php?rex-api-call=klxm_restricted_passkey_login_verify', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(credentialData)
                });
                
                const verifyResult = await verifyResponse.json();
                
                if (verifyResult.status) {
                    window.location.href = verifyResult.redirect || '/';
                } else {
                    alert('Login fehlgeschlagen: ' + (verifyResult.error || 'Unbekannt'));
                }

            } catch (err) {
                 console.error("Passkey Login Error", err);
                 alert("Login abgebrochen: " + err.message);
            }
        });
    }

});