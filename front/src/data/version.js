import packageMetadata from "../../package.json" with { type: "json" };

export const APP_VERSION = `ver. ${packageMetadata.version}`;
