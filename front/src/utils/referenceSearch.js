export function matchesReference(value, query) {
    const normalize = (text) => String(text ?? "").trim().toLocaleLowerCase("ru-RU");
    const text = normalize(value);
    return normalize(query).split(/\s+/u).filter(Boolean)
        .every((fragment) => text.includes(fragment));
}
