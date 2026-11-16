/**
 * Подписание УКЕП в браузере через КриптоПро CSP + «КриптоПро ЭЦП Browser
 * plug-in» (библиотека crypto-pro, грузится только при подписании).
 * Подпись — открепленная CMS (CAdES-BES) в base64, как ждёт СБИС
 * в «Вложение.Подпись.Файл.ДвоичныеДанные».
 */

/** Алгоритмы хеширования КриптоПро (CADESCOM_HASH_ALGORITHM_*). */
const HASH_GOST_2012_256 = 101;
const HASH_GOST_2012_512 = 102;
/** ОИД ключа ГОСТ Р 34.10-2012 512 бит. */
const OID_GOST_2012_512 = "1.2.643.7.1.1.1.2";

let library = null;

async function cryptoPro() {
    if (!library) library = import("crypto-pro");
    try {
        return await library;
    } catch (error) {
        library = null;
        throw error;
    }
}

/** Понятное сообщение, если плагин или КриптоПро не установлены. */
export function cryptoProErrorMessage(error) {
    const text = String(error?.message || error || "");
    if (/plugin|плагин|cadesplugin|не удалось загрузить|недоступ/i.test(text)) {
        return "Не найден «КриптоПро ЭЦП Browser plug-in». Установите плагин и расширение браузера, затем обновите страницу. " + text;
    }
    return text || "Ошибка КриптоПро";
}

export function base64ToArrayBuffer(base64) {
    const binary = atob(String(base64).replace(/\s+/g, ""));
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i += 1) bytes[i] = binary.charCodeAt(i);
    return bytes.buffer;
}

/** Сертификаты пользователя с закрытым ключом: [{thumbprint, name, issuer, validTo, valid}]. */
export async function listCertificates() {
    const { getUserCertificates } = await cryptoPro();
    const certificates = await getUserCertificates(true);
    const now = Date.now();
    return certificates
        .map((certificate) => ({
            thumbprint: certificate.thumbprint,
            name: certificate.name,
            issuer: certificate.issuerName,
            validTo: certificate.validTo,
            valid: !certificate.validTo || new Date(certificate.validTo).getTime() > now,
            certificate,
        }))
        .sort((a, b) => Number(b.valid) - Number(a.valid));
}

async function hashAlgorithm(certificate) {
    try {
        const algorithm = await certificate.getAlgorithm();
        return algorithm?.oid === OID_GOST_2012_512 ? HASH_GOST_2012_512 : HASH_GOST_2012_256;
    } catch {
        return HASH_GOST_2012_256;
    }
}

/**
 * Подписывает содержимое вложений.
 * @param {{thumbprint: string, certificate: object}} certificate
 * @param {Array<{id: string, content: string}>} attachments content — base64
 * @returns {Promise<Array<{id: string, signature: string}>>}
 */
export async function signAttachments(certificate, attachments) {
    const { createHash, createDetachedSignature } = await cryptoPro();
    const hashedAlgorithm = await hashAlgorithm(certificate.certificate);
    const signatures = [];
    for (const attachment of attachments) {
        const hash = await createHash(base64ToArrayBuffer(attachment.content), { hashedAlgorithm });
        const signature = await createDetachedSignature(certificate.thumbprint, hash, { hashedAlgorithm });
        signatures.push({ id: attachment.id, signature: String(signature).replace(/\s+/g, "") });
    }
    return signatures;
}
