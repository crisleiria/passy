// resources/js/lib/crypto.js

// Utilitário: Converte string para ArrayBuffer (necessário para a API de criptografia)
const str2ab = (str) => new TextEncoder().encode(str);

/**
 * 1. Deriva uma chave (AES-GCM) a partir da Master Password do utilizador.
 * Usamos PBKDF2 para transformar a password numa chave criptográfica forte.
 */
export async function deriveKey(password, salt = 'salt_fixo_do_projeto_passy') {
    // Importa a password como "material de chave" bruto
    const keyMaterial = await window.crypto.subtle.importKey(
        "raw",
        str2ab(password),
        { name: "PBKDF2" },
        false,
        ["deriveKey"]
    );

    // Deriva a chave final usada para encriptar/desencriptar
    // Nota: Num cenário ideal de tese, o "salt" devia ser aleatório e guardado no user,
    // mas para este protótipo, um salt fixo funciona.
    return window.crypto.subtle.deriveKey(
        {
            name: "PBKDF2",
            salt: str2ab(salt),
            iterations: 100000, // Alto número de iterações para dificultar brute-force
            hash: "SHA-256",
        },
        keyMaterial,
        { name: "AES-GCM", length: 256 },
        false,
        ["encrypt", "decrypt"]
    );
}

/**
 * 2. Encripta os dados (String -> Base64 Encriptado)
 */
export async function encryptClientSide(data, key) {
    // Gera um Vetor de Inicialização (IV) aleatório de 12 bytes
    // Isto garante que se encriptares "senha123" duas vezes, o resultado é sempre diferente.
    const iv = window.crypto.getRandomValues(new Uint8Array(12));
    const encodedData = str2ab(data);

    const encryptedContent = await window.crypto.subtle.encrypt(
        { name: "AES-GCM", iv: iv },
        key,
        encodedData
    );

    // Precisamos de enviar o IV junto com o texto cifrado para conseguir desencriptar depois.
    // Vamos juntar tudo num array só: [IV (12 bytes) + Conteúdo Encriptado]
    const combined = new Uint8Array(iv.length + new Uint8Array(encryptedContent).length);
    combined.set(iv);
    combined.set(new Uint8Array(encryptedContent), iv.length);

    // Converte o array de bytes para String Base64 para poder ser enviado via JSON/Laravel
    return btoa(String.fromCharCode(...combined));
}

/**
 * 3. Desencripta os dados (Base64 Encriptado -> String)
 */
export async function decryptClientSide(encryptedBase64, key) {
    try {
        // Converte Base64 de volta para array de bytes
        const combined = new Uint8Array(
            atob(encryptedBase64).split("").map((c) => c.charCodeAt(0))
        );

        // Separa o IV (primeiros 12 bytes) do conteúdo real
        const iv = combined.slice(0, 12);
        const data = combined.slice(12);

        // Tenta desencriptar
        const decryptedBuffer = await window.crypto.subtle.decrypt(
            { name: "AES-GCM", iv: iv },
            key,
            data
        );

        return new TextDecoder().decode(decryptedBuffer);
    } catch (e) {
        console.error("Erro na desencriptação:", e);
        // Retorna um texto de erro ou string vazia se a password estiver errada
        return "⛔ Falha ao abrir (Master Password errada?)";
    }
}
