import { marketplaceApi } from '../../api/marketplace.js';

/** Скачать PDF-файл (Blob) под указанным именем. */
export function saveBlob(blob, name) {
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = name;
  link.click();
  window.setTimeout(() => URL.revokeObjectURL(url), 1000);
}

/** Отправить PDF на печать через скрытый фрейм (без всплывающих окон). */
export function printBlob(blob) {
  const url = URL.createObjectURL(blob);
  const frame = document.createElement('iframe');
  frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0';
  frame.src = url;
  frame.onload = () => {
    try {
      frame.contentWindow.focus();
      frame.contentWindow.print();
    } catch {
      window.open(url, '_blank', 'noopener');
    }
    window.setTimeout(() => {
      frame.remove();
      URL.revokeObjectURL(url);
    }, 60_000);
  };
  document.body.appendChild(frame);
}

/**
 * Предзаполненный лист приёма-передачи ТС: PDF или печать.
 *
 * @param {object} request ЗР
 * @param {"in"|"out"} direction
 * @param {"pdf"|"print"} mode
 */
export async function handoverSheet(request, direction, mode) {
  const blob = await marketplaceApi.handoverSheetPdf(request.id, direction);
  if (mode === 'print') {
    printBlob(blob);
    return;
  }
  const digits = String(request.number || '').replace(/^\D+-/, '');
  saveBlob(blob, `APP-${digits}-${direction === 'in' ? 1 : 2} Лист приёма-передачи ТС.pdf`);
}
