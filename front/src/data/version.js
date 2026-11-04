import packageMetadata from "../../package.json" with { type: "json" };

/**
 * Версия под логотипом в формате x.xx.xxx:
 * x — первый рабочий релиз, xx — номер обновления (+1 на каждое обновление),
 * xxx — номер обновления в течение одного дня.
 * В package.json хранится semver без ведущих нулей (0.39.1 → 0.39.001).
 */
export function formatVersion(version) {
    const [major = "0", minor = "0", patch = "0"] = String(version).split(".");

    return `${major}.${minor.padStart(2, "0")}.${patch.padStart(3, "0")}`;
}

export const APP_VERSION = `ver. ${formatVersion(packageMetadata.version)}`;
