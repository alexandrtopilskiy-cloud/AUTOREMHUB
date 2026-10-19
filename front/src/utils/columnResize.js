const MIN_COLUMN_WIDTH = 90;

/**
 * Изменяет ширину ближайшего заголовка таблицы перетаскиванием границы.
 * Ширина сохраняется на время жизни открытой виртуальной вкладки.
 *
 * @param {PointerEvent} event
 */
export function startColumnResize(event) {
    const header = event.currentTarget?.closest("th");

    if (!header) return;

    event.preventDefault();

    const startX = event.clientX;
    const startWidth = header.getBoundingClientRect().width;
    const handle = event.currentTarget;

    const move = (nextEvent) => {
        const width = Math.max(
            MIN_COLUMN_WIDTH,
            startWidth + nextEvent.clientX - startX,
        );

        header.style.width = `${width}px`;
    };
    const stop = () => {
        window.removeEventListener("pointermove", move);
        window.removeEventListener("pointerup", stop);
        document.body.classList.remove("is-resizing-column");
    };

    handle.setPointerCapture?.(event.pointerId);
    document.body.classList.add("is-resizing-column");
    window.addEventListener("pointermove", move);
    window.addEventListener("pointerup", stop, { once: true });
}
