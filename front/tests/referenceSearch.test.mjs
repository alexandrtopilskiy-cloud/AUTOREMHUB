import test from "node:test";
import assert from "node:assert/strict";
import { matchesReference } from "../src/utils/referenceSearch.js";

test("reference search matches internal fragments in any order", () => {
    assert.equal(matchesReference("Toyota Camry", "am ry"), true);
    assert.equal(matchesReference("MCV10 · 1996 · 2001", "2001 cv"), true);
    assert.equal(matchesReference("Пружина сервопривода дроссельной заслонки - с/у", "пруж дрос"), true);
    assert.equal(matchesReference("Toyota Camry", "cam cor"), false);
});
test("reference search ignores case and whitespace; percent is literal", () => {
    assert.equal(matchesReference("Toyota Camry", "  AM   TOY "), true);
    assert.equal(matchesReference("Toyota Camry", "%"), false);
    assert.equal(matchesReference("100%", "%"), true);
    assert.equal(matchesReference("Любое значение", ""), true);
});
