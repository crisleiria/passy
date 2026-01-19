// resources/js/lib/crypto.js
// Zero-knowledge encryption with PIN-based key derivation

const str2ab = (str) => new TextEncoder().encode(str);

export const ab2b64 = (buffer) => {
    let binary = '';
    const bytes = new Uint8Array(buffer);
    for (let i = 0; i < bytes.length; i++) binary += String.fromCharCode(bytes[i]);
    return btoa(binary);
};

// Alias for clarity
export const arrayBufferToBase64 = ab2b64;

const b642ab = (base64) => {
    const binary = atob(base64);
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
    return bytes;
};

/**
 * Generate a random AES-256 Master Key
 */
export async function generateMasterKey() {
    return window.crypto.subtle.generateKey(
        { name: 'AES-GCM', length: 256 },
        true, // extractable
        ['encrypt', 'decrypt']
    );
}

/**
 * Generate random salt for PBKDF2
 */
export function generateSalt() {
    return window.crypto.getRandomValues(new Uint8Array(32));
}

/**
 * Export CryptoKey to Base64 string
 */
export async function exportKeyToBase64(key) {
    const rawKey = await window.crypto.subtle.exportKey('raw', key);
    return ab2b64(rawKey);
}

/**
 * Import Base64 string to CryptoKey
 */
export async function importKeyFromBase64(base64Key) {
    const keyArray = b642ab(base64Key);
    return window.crypto.subtle.importKey(
        'raw', keyArray,
        { name: 'AES-GCM', length: 256 },
        true,
        ['encrypt', 'decrypt']
    );
}

/**
 * Derive AES-256 key from PIN using PBKDF2
 * @param {string} pin - User's PIN
 * @param {Uint8Array|string} salt - Salt (ArrayBuffer or Base64)
 */
export async function deriveKeyFromPIN(pin, salt) {
    const saltBuffer = typeof salt === 'string' ? b642ab(salt) : salt;

    const keyMaterial = await window.crypto.subtle.importKey(
        'raw', str2ab(pin), 'PBKDF2', false, ['deriveKey']
    );

    return window.crypto.subtle.deriveKey(
        {
            name: 'PBKDF2',
            salt: saltBuffer,
            iterations: 600000, // High for security
            hash: 'SHA-256',
        },
        keyMaterial,
        { name: 'AES-GCM', length: 256 },
        true,
        ['encrypt', 'decrypt']
    );
}

/**
 * Wrap (encrypt) the Master Key with a PIN-derived key
 */
export async function wrapMasterKey(masterKey, wrappingKey) {
    const exportedKey = await window.crypto.subtle.exportKey('raw', masterKey);
    const keyBase64 = ab2b64(new Uint8Array(exportedKey));
    return encryptClientSide(keyBase64, wrappingKey);
}

/**
 * Unwrap (decrypt) the Master Key with a PIN-derived key
 */
export async function unwrapMasterKey(wrappedKey, unwrappingKey) {
    const keyBase64 = await decryptClientSide(wrappedKey, unwrappingKey);
    return importKeyFromBase64(keyBase64);
}

/**
 * Encrypt data (String -> Base64 with IV)
 */
export async function encryptClientSide(data, key) {
    const iv = window.crypto.getRandomValues(new Uint8Array(12));
    const encodedData = str2ab(data);

    const encryptedContent = await window.crypto.subtle.encrypt(
        { name: 'AES-GCM', iv: iv },
        key,
        encodedData
    );

    // Combine IV + encrypted content
    const combined = new Uint8Array(iv.length + new Uint8Array(encryptedContent).length);
    combined.set(iv);
    combined.set(new Uint8Array(encryptedContent), iv.length);

    return btoa(String.fromCharCode(...combined));
}

/**
 * Decrypt data (Base64 with IV -> String)
 */
export async function decryptClientSide(encryptedBase64, key) {
    const combined = new Uint8Array(
        atob(encryptedBase64).split('').map((c) => c.charCodeAt(0))
    );

    const iv = combined.slice(0, 12);
    const data = combined.slice(12);

    const decryptedBuffer = await window.crypto.subtle.decrypt(
        { name: 'AES-GCM', iv: iv },
        key,
        data
    );

    return new TextDecoder().decode(decryptedBuffer);
}

// Legacy function for backward compatibility
export async function deriveKey(password, salt = 'salt_fixo_do_projeto_passy') {
    const keyMaterial = await window.crypto.subtle.importKey(
        'raw', str2ab(password), { name: 'PBKDF2' }, false, ['deriveKey']
    );

    return window.crypto.subtle.deriveKey(
        {
            name: 'PBKDF2',
            salt: str2ab(salt),
            iterations: 100000,
            hash: 'SHA-256',
        },
        keyMaterial,
        { name: 'AES-GCM', length: 256 },
        false,
        ['encrypt', 'decrypt']
    );
}
