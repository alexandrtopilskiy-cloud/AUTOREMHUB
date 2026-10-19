<script>
import { matchesReference } from "../../utils/referenceSearch.js";
import { marketplaceApi } from "../../api/marketplace.js";

const XLSX_TYPE = "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet";
const MAX_FILE_SIZE = 30 * 1024 * 1024;

export default {
    name: "CatalogImportWizard",
    props: {
        kind: { type: String, required: true },
        title: { type: String, required: true },
    },
    emits: ["close", "imported"],
    data() {
        return {
            xlsxType: XLSX_TYPE,
            step: 1,
            file: null,
            templateDownloaded: false,
            downloading: false,
            importing: false,
            error: "",
            conflicts: [],
            result: null,
            preview: null,
            previewTab: "errors",
            previewQuery: "",
            previewPage: 1,
            previewPageSize: 50,
        };
    },
    computed: {
        workCatalogMode() {
            return this.kind === "workTypes";
        },
        steps() {
            return this.workCatalogMode
                ? ["Файл", "Проверка", "Сверка", "Готово"]
                : ["Шаблон", "Файл", "Проверка", "Готово"];
        },
        nextDisabled() {
            if (this.downloading || this.importing) return true;

            if (this.workCatalogMode) {
                if (this.step === 1) return !this.file;
                if (this.step === 3) return !this.preview?.canApply;

                return this.step === 4;
            }

            if (this.step === 1) return !this.templateDownloaded;
            if (this.step === 2) return !this.file;

            return this.step === 4;
        },
        previewRows() {
            if (!this.preview) return [];
            if (this.previewTab === "works") return this.preview.works || [];
            if (this.previewTab === "norms") return this.preview.norms || [];

            return this.preview.errors || [];
        },
        filteredPreviewRows() {
            const query = this.previewQuery
                .trim()
                .toLocaleLowerCase("ru-RU");

            if (!query) return this.previewRows;

            return this.previewRows.filter((row) =>
                matchesReference(JSON.stringify(row), query),
            );
        },
        previewPageCount() {
            return Math.max(
                1,
                Math.ceil(
                    this.filteredPreviewRows.length / this.previewPageSize,
                ),
            );
        },
        visiblePreviewRows() {
            const offset = (this.previewPage - 1) * this.previewPageSize;

            return this.filteredPreviewRows.slice(
                offset,
                offset + this.previewPageSize,
            );
        },
        nextLabel() {
            if (this.importing) {
                return this.workCatalogMode && this.step === 3
                    ? "Применяем…"
                    : "Проверяем…";
            }
            if (this.workCatalogMode && this.step === 2) {
                return "Проверить файл";
            }
            if (this.workCatalogMode && this.step === 3) {
                return "Применить новый справочник";
            }

            return "Далее";
        },
    },
    watch: {
        previewTab() {
            this.previewPage = 1;
        },
        previewQuery() {
            this.previewPage = 1;
        },
    },
    methods: {
        close() {
            if (!this.importing) this.$emit("close");
        },
        async downloadTemplate() {
            this.downloading = true;
            this.error = "";

            try {
                const { blob, filename } = await marketplaceApi.downloadCatalogTemplate(
                    this.kind,
                );
                const url = URL.createObjectURL(blob);
                const link = document.createElement("a");
                link.href = url;
                link.download = filename;
                document.body.appendChild(link);
                link.click();
                link.remove();
                URL.revokeObjectURL(url);
                this.templateDownloaded = true;
            } catch (error) {
                this.error = error.message;
            } finally {
                this.downloading = false;
            }
        },
        selectFile(event) {
            const input = event.target;
            const file = input.files?.[0] || null;
            this.error = "";
            this.preview = null;
            this.result = null;
            this.conflicts = [];

            if (!file) {
                this.file = null;
                return;
            }

            const extensionValid = file.name.toLowerCase().endsWith(".xlsx");
            const typeValid = !file.type || file.type === XLSX_TYPE;

            if (!extensionValid || !typeValid) {
                this.file = null;
                input.value = "";
                this.error = this.workCatalogMode
                    ? "Выберите XLSX с листами «Работы» и «Нормы времени»."
                    : "Выберите файл XLSX, полученный из шаблона этого справочника.";
                return;
            }

            if (file.size > MAX_FILE_SIZE) {
                this.file = null;
                input.value = "";
                this.error = "Размер XLSX не должен превышать 30 МБ.";
                return;
            }

            this.file = file;
        },
        back() {
            if (this.step <= 1 || this.step >= 4 || this.importing) return;

            if (this.workCatalogMode && this.step === 3) {
                this.step = 1;
                this.preview = null;
            } else {
                this.step--;
            }

            this.error = "";
        },
        async next() {
            this.error = "";

            if (this.workCatalogMode) {
                if (this.step === 1) {
                    this.step = 2;
                    return;
                }
                if (this.step === 2) {
                    await this.previewWorkCatalog();
                    return;
                }
                if (this.step === 3) {
                    await this.applyWorkCatalog();
                }

                return;
            }

            if (this.step < 3) {
                this.step++;
                return;
            }

            await this.verifyAndImport();
        },
        async previewWorkCatalog() {
            if (!this.file || this.importing) return;

            this.importing = true;

            try {
                this.preview = await marketplaceApi.previewWorkCatalog(this.file);
                this.previewTab = this.preview.errors?.length
                    ? "errors"
                    : "works";
                this.step = 3;
            } catch (error) {
                this.error = error.message;
            } finally {
                this.importing = false;
            }
        },
        async applyWorkCatalog() {
            if (!this.file || !this.preview?.canApply || this.importing) return;

            this.importing = true;

            try {
                this.result = await marketplaceApi.applyWorkCatalog(
                    this.file,
                    this.preview.checksum,
                );
                this.step = 4;
                this.$emit("imported", this.result);
            } catch (error) {
                this.error = error.message;
            } finally {
                this.importing = false;
            }
        },
        async verifyAndImport() {
            if (!this.file || this.importing) return;

            this.importing = true;
            this.conflicts = [];

            try {
                const result = await marketplaceApi.importCatalogExcel(
                    this.kind,
                    this.file,
                );
                this.result = result;
                this.step = 4;
                this.$emit("imported", result);
            } catch (error) {
                const result = error.details?.result;

                if (Array.isArray(result?.conflicts) && result.conflicts.length) {
                    this.conflicts = result.conflicts;
                    this.step = 2;
                    this.file = null;
                    if (this.$refs.fileInput) this.$refs.fileInput.value = "";
                    this.error = `Выявлено конфликтов: ${result.conflicts.length}. Исправьте указанные ячейки и загрузите файл повторно.`;
                } else if (error.status === 422) {
                    this.step = 2;
                    this.file = null;
                    if (this.$refs.fileInput) this.$refs.fileInput.value = "";
                    this.error = error.message;
                } else {
                    this.error = error.message;
                }
            } finally {
                this.importing = false;
            }
        },
        hours(value) {
            return value === null || value === ""
                ? "Не задана"
                : `${Number(value).toLocaleString("ru-RU")} ч`;
        },
    },
};
</script>

<template>
    <div class="catalog-import-backdrop" @click.self="close">
        <section
            class="catalog-import-dialog"
            :class="{ 'work-catalog-import-dialog': workCatalogMode }"
            role="dialog"
            aria-modal="true"
            :aria-label="`Импорт: ${title}`"
        >
            <header class="catalog-import-head">
                <div>
                    <span class="eyebrow">Импорт из Excel</span>
                    <h2>{{ title }}</h2>
                </div>
                <button
                    class="icon-btn"
                    aria-label="Закрыть импорт"
                    :disabled="importing"
                    @click="close"
                >
                    ×
                </button>
            </header>

            <ol class="catalog-import-steps" aria-label="Этапы импорта">
                <li
                    v-for="(label, index) in steps"
                    :key="label"
                    :class="{
                        active: step === index + 1,
                        completed: step > index + 1,
                    }"
                >
                    <span>{{ index + 1 }}</span>{{ label }}
                </li>
            </ol>

            <div class="catalog-import-content">
                <p v-if="error" class="form-error" role="alert">{{ error }}</p>

                <template v-if="step === 1">
                    <template v-if="workCatalogMode">
                        <h3>Выберите новый справочник работ</h3>
                        <p>
                            Требуется XLSX с листами «Работы» и «Нормы времени».
                            Данные сначала проходят полную сверку без изменения БД.
                        </p>
                        <label class="catalog-file-picker">
                            <span>{{ file ? file.name : "Выбрать XLSX-файл" }}</span>
                            <input
                                ref="fileInput"
                                type="file"
                                :accept="`.xlsx,${xlsxType}`"
                                @change="selectFile"
                            />
                        </label>
                        <p class="muted-note">Максимальный размер файла - 30 МБ.</p>
                    </template>
                    <template v-else>
                        <h3>Скачайте шаблон справочника</h3>
                        <p>
                            Заголовки и порядок полей уже соответствуют структуре API.
                            Не переименовывайте и не удаляйте столбцы.
                        </p>
                        <button
                            class="primary-btn"
                            :disabled="downloading"
                            @click="downloadTemplate"
                        >
                            {{ downloading ? "Формируем…" : "Скачать шаблон XLSX" }}
                        </button>
                        <p v-if="templateDownloaded" class="import-success-note">
                            Шаблон скачан. Заполните его и переходите далее.
                        </p>
                    </template>
                </template>

                <template v-else-if="step === 2">
                    <template v-if="workCatalogMode">
                        <h3>Предварительная проверка</h3>
                        <p>
                            Файл <strong>{{ file?.name }}</strong> будет проверен
                            целиком: коды, дубликаты, нормы, применимость и связи с
                            автомобильными справочниками.
                        </p>
                        <p>
                            На этом этапе активный справочник и заявки не изменяются.
                        </p>
                    </template>
                    <template v-else>
                        <h3>Загрузите заполненный шаблон</h3>
                        <label class="catalog-file-picker">
                            <span>{{ file ? file.name : "Выбрать XLSX-файл" }}</span>
                            <input
                                ref="fileInput"
                                type="file"
                                :accept="`.xlsx,${xlsxType}`"
                                @change="selectFile"
                            />
                        </label>
                        <p class="muted-note">Максимальный размер файла - 30 МБ.</p>

                        <div v-if="conflicts.length" class="catalog-conflicts">
                            <h3>Найдены конфликты</h3>
                            <div class="catalog-conflict-table-wrap">
                                <table class="catalog-conflict-table">
                                    <thead>
                                        <tr>
                                            <th>Строка</th>
                                            <th>Ячейка</th>
                                            <th>Столбец</th>
                                            <th>Значение</th>
                                            <th>Причина</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr
                                            v-for="(conflict, index) in conflicts"
                                            :key="`${conflict.cell}-${index}`"
                                        >
                                            <td>{{ conflict.row }}</td>
                                            <td><strong>{{ conflict.cell }}</strong></td>
                                            <td>{{ conflict.column }}</td>
                                            <td :title="conflict.value">
                                                {{ conflict.value || "—" }}
                                            </td>
                                            <td>{{ conflict.message }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </template>
                </template>

                <template v-else-if="step === 3">
                    <template v-if="workCatalogMode && preview">
                        <div class="work-import-summary">
                            <article>
                                <span>Принято работ</span>
                                <strong>{{ preview.summary.acceptedWorks }}</strong>
                            </article>
                            <article>
                                <span>Принято норм</span>
                                <strong>{{ preview.summary.acceptedNorms }}</strong>
                            </article>
                            <article>
                                <span>Норм с часами</span>
                                <strong>{{ preview.summary.confirmedNorms }}</strong>
                            </article>
                            <article :class="{ warning: preview.summary.emptyNorms }">
                                <span>Пустых норм</span>
                                <strong>{{ preview.summary.emptyNorms }}</strong>
                            </article>
                            <article :class="{ danger: preview.summary.errors }">
                                <span>Ошибок</span>
                                <strong>{{ preview.summary.errors }}</strong>
                            </article>
                        </div>

                        <p
                            v-for="warning in preview.warnings"
                            :key="warning.code"
                            class="work-import-warning"
                        >
                            {{ warning.message }} ({{ warning.count }})
                        </p>

                        <div class="work-import-tabs">
                            <button
                                :class="{ active: previewTab === 'errors' }"
                                @click="previewTab = 'errors'"
                            >
                                Ошибки {{ preview.errors.length }}
                            </button>
                            <button
                                :class="{ active: previewTab === 'works' }"
                                @click="previewTab = 'works'"
                            >
                                Работы {{ preview.works.length }}
                            </button>
                            <button
                                :class="{ active: previewTab === 'norms' }"
                                @click="previewTab = 'norms'"
                            >
                                Нормы времени {{ preview.norms.length }}
                            </button>
                            <input
                                v-model="previewQuery"
                                type="search"
                                placeholder="Поиск по результатам"
                            />
                        </div>

                        <div class="catalog-conflict-table-wrap work-import-table-wrap">
                            <table
                                v-if="previewTab === 'errors'"
                                class="catalog-conflict-table"
                            >
                                <thead>
                                    <tr>
                                        <th>Лист</th>
                                        <th>Ячейка</th>
                                        <th>Код</th>
                                        <th>Значение</th>
                                        <th>Описание</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="row in visiblePreviewRows"
                                        :key="`${row.sheet}-${row.cell}-${row.code}`"
                                    >
                                        <td>{{ row.sheet }}</td>
                                        <td>{{ row.cell }}</td>
                                        <td>{{ row.code || "VALIDATION_ERROR" }}</td>
                                        <td :title="row.value">{{ row.value }}</td>
                                        <td :title="row.message">{{ row.message }}</td>
                                    </tr>
                                    <tr v-if="!visiblePreviewRows.length">
                                        <td colspan="5">Ошибок не найдено</td>
                                    </tr>
                                </tbody>
                            </table>

                            <table
                                v-else-if="previewTab === 'works'"
                                class="catalog-conflict-table"
                            >
                                <thead>
                                    <tr>
                                        <th>Код</th>
                                        <th>Группа</th>
                                        <th>Наименование работы</th>
                                        <th>ТО</th>
                                        <th>Источник</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="row in visiblePreviewRows" :key="row.code">
                                        <td>{{ row.code }}</td>
                                        <td>{{ row.group_name }}</td>
                                        <td :title="row.name">{{ row.name }}</td>
                                        <td>{{ row.is_maintenance ? "Да" : "Нет" }}</td>
                                        <td :title="row.source_name">{{ row.source_name }}</td>
                                    </tr>
                                </tbody>
                            </table>

                            <table v-else class="catalog-conflict-table">
                                <thead>
                                    <tr>
                                        <th>norm_id</th>
                                        <th>Код работы</th>
                                        <th>Применимость</th>
                                        <th>Норма</th>
                                        <th>Источник</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="row in visiblePreviewRows" :key="row.normId">
                                        <td>{{ row.normId }}</td>
                                        <td>{{ row.workCode }}</td>
                                        <td :title="row.displayCode">{{ row.displayCode }}</td>
                                        <td :class="{ 'empty-work-norm': row.hours === null }">
                                            {{ hours(row.hours) }}
                                        </td>
                                        <td :title="row.source">{{ row.source }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="work-import-pagination">
                            <span>
                                Показано {{ visiblePreviewRows.length }} из
                                {{ filteredPreviewRows.length }}
                            </span>
                            <button
                                :disabled="previewPage === 1"
                                @click="previewPage--"
                            >
                                ←
                            </button>
                            <span>{{ previewPage }} / {{ previewPageCount }}</span>
                            <button
                                :disabled="previewPage === previewPageCount"
                                @click="previewPage++"
                            >
                                →
                            </button>
                        </div>
                    </template>
                    <template v-else>
                        <h3>Проверка перед импортом</h3>
                        <p>
                            Файл <strong>{{ file?.name }}</strong> будет проверен
                            целиком: формат полей, дубликаты и все связи со
                            справочниками.
                        </p>
                        <p>
                            Если найдётся хотя бы один конфликт, данные в БД не
                            изменятся.
                        </p>
                    </template>
                </template>

                <template v-else>
                    <div class="catalog-import-success">
                        <span aria-hidden="true">✓</span>
                        <h3>Импорт завершён</h3>
                        <p v-if="workCatalogMode">
                            Применено работ: {{ result?.summary?.acceptedWorks || 0 }}.<br />
                            Применено норм: {{ result?.summary?.acceptedNorms || 0 }}.<br />
                            Предыдущий активный справочник больше не используется.
                        </p>
                        <p v-else>
                            Проверено строк: {{ result?.rows || 0 }}.<br />
                            Добавлено записей: {{ result?.imported || 0 }}.
                        </p>
                    </div>
                </template>
            </div>

            <footer class="catalog-import-actions">
                <button
                    v-if="step > 1 && step < 4"
                    class="ghost-btn"
                    :disabled="importing"
                    @click="back"
                >
                    Назад
                </button>
                <button v-if="step === 4" class="primary-btn" @click="close">
                    Закрыть
                </button>
                <button
                    v-else
                    class="primary-btn"
                    :disabled="nextDisabled"
                    @click="next"
                >
                    {{ nextLabel }}
                </button>
            </footer>
        </section>
    </div>
</template>
