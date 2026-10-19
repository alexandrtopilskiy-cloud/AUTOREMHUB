<script>
import { matchesReference } from "./utils/referenceSearch.js";
import LoginForm from "./features/auth/LoginForm.vue";
import RepairEditor from "./features/requests/RepairEditor.vue";
import UnsavedChangesDialog from "./features/requests/UnsavedChangesDialog.vue";
import RequestPreview from "./features/requests/RequestPreview.vue";
import SupplierEditor from "./features/catalogs/SupplierEditor.vue";
import CatalogEditor from "./features/catalogs/CatalogEditor.vue";
import VehicleApprovalReview from "./features/catalogs/VehicleApprovalReview.vue";
import VehicleCatalogEditor from "./features/catalogs/VehicleCatalogEditor.vue";
import PersonCatalogEditor from "./features/catalogs/PersonCatalogEditor.vue";
import UserCatalogEditor from "./features/catalogs/UserCatalogEditor.vue";
import VehicleReferenceCatalog from "./features/catalogs/VehicleReferenceCatalog.vue";
import RecommendationPanel from "./features/requests/RecommendationPanel.vue";
import VehicleModelCreateDialog from "./features/catalogs/VehicleModelCreateDialog.vue";
import CatalogImportWizard from "./features/catalogs/CatalogImportWizard.vue";
import RequestChatDrawer from "./features/requests/RequestChatDrawer.vue";
import VehicleHistory from "./features/vehicles/VehicleHistory.vue";
import { createRequestStore } from "./stores/requestStore.js";
import { marketplaceApi, restoreToken, clearToken } from "./api/marketplace.js";
import { http } from "./api/client.js";
import { workflow, statusClass } from "./data/workflow.js";
import { APP_VERSION } from "./data/version.js";
import {
    money,
    total,
    formatDateTime,
    catalogNames,
    catalogFields,
} from "./data/permissions.js";

const LIVE_REFRESH_MS = 10_000;
const DOCUMENT_LOCK_HEARTBEAT_MS = 45_000;
const SERVICE_MESSAGE_MS = 15_000;
const WEEKDAY_LABELS = [
    "",
    "Пн.",
    "Вт.",
    "Ср.",
    "Чт.",
    "Пт.",
    "Сб.",
    "Вск."];
const REQUEST_COLUMNS = {
    document: "Документ / дата",
    vehicle: "Автомобиль / заказчик",
    status: "Статус",
    amount: "Сумма, ₽",
};
const REQUEST_FILTER_PLACEHOLDERS = {
    document: "Номер или дата",
    vehicle: "ТС, госномер, заказчик",
    status: "Статус или кабинет",
    amount: "Сумма",
};

export default {
    name: "App",
    components: {
        LoginForm,
        RepairEditor,
        UnsavedChangesDialog,
        RequestPreview,
        CatalogEditor,
        SupplierEditor,
        VehicleApprovalReview,
        VehicleCatalogEditor,
        PersonCatalogEditor,
        UserCatalogEditor,
        VehicleReferenceCatalog,
        CatalogImportWizard,
        RecommendationPanel,
        VehicleModelCreateDialog,
        RequestChatDrawer,
        VehicleHistory,
    },
    data() {
        return {
            user: null,
            checking: true,
            fatal: "",
            toast: "",
            toastTimer: null,
            fatalTimer: null,
            saveErrorTimer: null,
            notificationTimer: null,
            liveRefreshBusy: false,
            lockTimer: null,
            documentLocks: {},
            logoutBusy: false,
            catalogImportKind: "",
            vehicleModelCreateOpen: false,
            vehicleReferenceTotal: 0,
            store: createRequestStore(marketplaceApi),
            tabs: [],
            activeId: "",
            pendingCloseTabId: "",
            selectedDraftIds: [],
            closeBusy: false,
            chatRequestId: "",
            chatOpening: false,
            panel: {
                menu: true,
                work: true,
                state: true,
                left: 224,
                right: 330,
            },
            resizeCleanup: null,
            appVersion: APP_VERSION,
        };
    },
    computed: {
        active() {
            return this.tabs.find((t) => t.id === this.activeId);
        },
        current() {
            return this.active?.kind === "request"
                ? this.store.get(this.active.recordId)
                : null;
        },
        currentView() {
            if (!this.current || !this.active?.lockDenied) {
                return this.current;
            }

            const permissions = Object.fromEntries(
                Object.entries(this.current.permissions || {}).map(([key, value]) => [
                    key,
                    Array.isArray(value)
                        ? []
                        : typeof value === "boolean"
                          ? false
                          : value,
                ],
                ),
            );

            return { ...this.current, permissions };
        },
        activeRecordView() {
            const record = this.active?.record;

            if (!record || !this.active?.lockDenied) {
                return record;
            }

            return {
                ...record,
                permissions: {
                    ...(record.permissions || {}),
                    canEdit: false,
                    canDelete: false,
                    canReview: false,
                },
            };
        },
        pendingCloseTab() {
            return this.tabs.find((tab) => tab.id === this.pendingCloseTabId);
        },
        isRequestList() {
            return ["requests", "action", "archive"].includes(this.active?.key);
        },
        requestColumns() {
            return REQUEST_COLUMNS;
        },
        activeFiltersApplied() {
            return Object.values(this.active?.filters || {}).some(
                (value) => String(value || "").trim() !== "",
            );
        },
        layout() {
            return {
                "--left": (this.panel.menu ? this.panel.left : 48) + "px",
                "--right": (this.panel.state ? this.panel.right : 48) + "px",
                "--middle": this.panel.work ? "minmax(280px,1fr)" : "48px",
            };
        },
        catalogKeys() {
            if (this.user?.role === "customer") {
                return ["vehicles", "drivers", "contactPersons"];
            }

            if (this.user?.role === "manager") {
                return [
                    "vehicles",
                    "vehicleModels",
                    "drivers",
                    "contactPersons",
                    "workTypes",
                    "normApplicabilities",
                    "partManufacturers",
                    "suppliers",
                    "parts",
                    "customers",
                    "stations",
                    "managers",
                    "users",
                    "vehicleApprovals",
                    "changeRegistry",
                ];
            }

            return this.user?.role === "station" ? ["parts", "partManufacturers", "suppliers"] : [];
        },
        awaiting() {
            return this.store.requests.filter((r) => r.permissions.requiresAction ?? r.permissions.canAdvance,
            )
                .length;
        },
        unreadNotifications() {
            return this.store.notifications.filter(
                (notification) => !notification.readAt,
            ).length;
        },
        chatRequestNumber() {
            return (
                this.store.get(this.chatRequestId)?.number
                || this.store.chatSummaries.find(
                    (item) => item.requestId === this.chatRequestId,
                )?.requestNumber
                || "");
        },
        unreadChatSummaries() {
            return this.store.chatSummaries.filter((chat) => chat.unreadCount > 0,
            );
        },
        selectableDrafts() {
            return this.active?.key === "requests"
                ? this.listed.filter((item) => item.permissions?.canDeleteDraft)
                : [];
        },
        selectedDraftCount() {
            const available = new Set(this.selectableDrafts.map((item) => item.id),
            );

            return this.selectedDraftIds.filter((id) => available.has(id)).length;
        },
        allVisibleDraftsSelected() {
            return (
                this.selectableDrafts.length > 0
                && this.selectableDrafts.every((item) =>
                    this.selectedDraftIds.includes(item.id),
                )
            );
        },
        listed() {
            if (this.active?.kind !== "list") return [];
            let rows = this.isRequestList
                ? this.store.requests
                : this.active.key === "changeRegistry"
                  ? this.store.changeRegistry
                  : this.store.records.filter((r) => r.kind === this.active.key,
                    );
            if (this.active.key === "action")
                rows = rows.filter((r) => r.permissions.requiresAction ?? r.permissions.canAdvance,
                );
            if (this.active.key === "archive")
                rows = rows.filter((r) => r.stage === 11);
            const filters = this.active.filters || {};

            return rows.filter((row) =>
                Object.entries(filters).every(([key, value]) => {
                    const query = String(value || "").toLowerCase().trim();

                    return matchesReference(this.filterCell(row, key), query);
                }),
            );
        },
        selected() {
            if (this.current) return this.current;
            if (this.active?.kind === "record") return this.active.record;
            return (
                this.listed.find((r) => r.id === this.active?.selection) || null
            );
        },
        canCreateCatalog() {
            if (["parts", "partManufacturers", "suppliers"].includes(this.active?.key,
                )) return ["manager", "station"].includes(this.user?.role);
            if (this.active?.key === "vehicles") {
                return ["customer", "manager"].includes(this.user?.role);
            }

            if (["drivers", "contactPersons"].includes(this.active?.key)) {
                return ["customer", "manager"].includes(this.user?.role);
            }

            return (
                this.user?.role === "manager"
                && [
                    "workTypes",
                    "normApplicabilities",
                    "suppliers",
                    "parts",
                    "customers",
                    "stations",
                    "managers",
                    "users",
                ].includes(
                    this.active?.key)
            );
        },
        canImportCatalog() {
            return (
                this.user?.role === "manager"
                && this.active?.kind === "list"
                && [
                    "vehicles",
                    "vehicleModels",
                    "drivers",
                    "contactPersons",
                    "workTypes",
                    "normApplicabilities",
                    "parts",
                    "customers",
                    "stations",
                    "managers",
                    "users",
                ].includes(this.active?.key));
        },
        missingLaborHourRate() {
            return (
                this.user?.role !== "customer"
                && this.current?.vehicleId
                && this.current?.laborHourRate == null
                && this.current?.permissions?.editableFields?.includes("works")
            );
        },
    },
    methods: {
        money,
        total,
        formatDateTime,
        statusClass,
        catalogNamesValue(key) {
            return catalogNames[key];
        },
        columns(key) {
            const fields = { ...(catalogFields[key] || {}) };

            if (key === "vehicles" && this.user?.role === "customer") {
                delete fields.company;
                delete fields.laborHourRate;
                delete fields.laborHourRateOfficial;
                delete fields.laborHourRateOther;
            }

            return fields;
        },
        requestFilterPlaceholder(key) {
            return REQUEST_FILTER_PLACEHOLDERS[key] || "Фильтр";
        },
        requestColumnValue(item, key) {
            if (key === "document") {
                return [item.number, formatDateTime(item.createdAt)]
                    .filter(Boolean)
                    .join(" ");
            }

            if (key === "vehicle") {
                return [
                    item.vehicle || "Автомобиль не выбран",
                    item.plate || "Пустой черновик",
                    this.user?.role === "station" ? "" : item.company,
                ]
                    .filter(Boolean)
                    .join(" ");
            }

            if (key === "status") {
                const stage = workflow[item.stage] || {};

                return [
                    item.statusLabel || stage.status,
                    stage.owner,
                    item.overdueSeconds ? "Превышен плановый срок" : "",
                ]
                    .filter(Boolean)
                    .join(" ");
            }

            if (key === "amount") {
                const amount = total(item);

                return `${amount} ${money(amount)}`;
            }

            return "";
        },
        filterCell(item, key) {
            return this.isRequestList
                ? this.requestColumnValue(item, key)
                : this.cell(item, key);
        },
        step(stage) {
            return workflow[stage];
        },

        notify(text) {
            this.toast = text;
            clearTimeout(this.toastTimer);
            this.toastTimer = setTimeout(() => {
                if (this.toast === text) this.toast = "";
            }, SERVICE_MESSAGE_MS);

            if (this.fatal === text) {
                clearTimeout(this.fatalTimer);
                this.fatalTimer = setTimeout(() => {
                    if (this.fatal === text) this.fatal = "";
                }, SERVICE_MESSAGE_MS);
            }

            if (this.store.saveError === text) {
                clearTimeout(this.saveErrorTimer);
                this.saveErrorTimer = setTimeout(() => {
                    if (this.store.saveError === text) {
                        this.store.saveError = "";
                    }
                }, SERVICE_MESSAGE_MS);
            }
        },

        chatSummary(requestId) {
            return this.store.chatSummaries.find((chat) => chat.requestId === requestId,
            );
        },

        async openChatFor(requestId, createIfMissing = false) {
            if (!requestId || this.chatOpening) return;
            this.chatOpening = true;

            try {
                if (!this.chatSummary(requestId)) {
                    if (!createIfMissing) await this.store.refreshChatSummaries();
                    if (!this.chatSummary(requestId)) {
                        if (!createIfMissing) throw new Error("Чат заявки не найден");
                        await marketplaceApi.createChat(requestId);
                        await this.store.refreshChatSummaries();
                    }
                }

                this.chatRequestId = requestId;
            } catch (error) {
                this.notify(error.message);
            } finally {
                this.chatOpening = false;
            }
        },

        async openUnreadChat(chat) {
            try {
                let request = this.store.get(chat.requestId);
                if (!request) {
                    request = await marketplaceApi.getRequest(chat.requestId);
                    this.store.accept(request);
                }
                await this.openRecord(request);
                await this.openChatFor(chat.requestId);
            } catch (error) {
                this.notify(error.message);
            }
        },

        chatRead() {
            this.store.refreshChatSummaries().catch((error) => {
                this.notify(error.message);
            });
        },

        async login(user) {
            this.user = user;
            this.tabs = [];
            this.activeId = "";
            this.documentLocks = {};
            this.selectedDraftIds = [];
            this.store.reset();
            this.chatRequestId = "";
            try {
                const saved = JSON.parse(
                    localStorage.getItem("sto-layout-" + user.login) || "null",
                );
                if (saved)
                    for (const k of Object.keys(this.panel))
                        if (typeof saved[k] === typeof this.panel[k])
                            this.panel[k] = saved[k];
            } catch {}
            if (window.innerWidth < 760)
                this.panel = {
                    ...this.panel,
                    menu: false,
                    state: false,
                    work: true,
                };
            await this.openList();
            await this.refresh();
            this.startNotificationPolling();
            this.startLockHeartbeat();
        },

        async refresh() {
            try {
                await this.store.refresh();

                if (this.active?.key === "changeRegistry") {
                    await this.store.refreshChangeRegistry();
                }

                await this.synchronizeRequestLocks();
                this.pruneDraftSelection();

                this.fatal = "";
            } catch (e) {
                this.fatal = e.message;
                this.notify(e.message);
            }
        },

        async logout() {
            if (this.store.dirty) {
                this.notify(
                    "Сохраните изменения или закройте изменённые документы без сохранения",
                );

                return;
            }

            this.logoutBusy = true;
            try {
                this.stopNotificationPolling();
                this.stopLockHeartbeat();
                await this.store.flush();
                await marketplaceApi.logout();
                this.store.reset();
                this.chatRequestId = "";
                this.user = null;
                this.tabs = [];
                this.documentLocks = {};
            } catch (e) {
                this.notify(e.message);
                if (this.user) {
                    this.startNotificationPolling();
                    this.startLockHeartbeat();
                }
            } finally {
                this.logoutBusy = false;
            }
        },

        startNotificationPolling() {
            this.stopNotificationPolling();
            this.notificationTimer = window.setInterval(() => {
                this.liveRefresh();
            }, LIVE_REFRESH_MS);
        },

        async liveRefresh() {
            if (
                this.liveRefreshBusy
                || this.store.busy
                || this.store.loading
                || !this.user
                || document.hidden
            ) {
                return;
            }

            this.liveRefreshBusy = true;

            try {
                await this.store.refreshLive();
                await this.$refs.requestChat?.refresh();

                if (this.active?.key === "changeRegistry") {
                    await this.store.refreshChangeRegistry();
                }

                await this.synchronizeRequestLocks();
                this.pruneDraftSelection();
                this.fatal = "";
            } catch (error) {
                // Фоновая синхронизация повторится автоматически. Её сетевой
                // таймаут не является ошибкой пользовательского сохранения.
                if (error.status === 401) return;
            } finally {
                this.liveRefreshBusy = false;
            }
        },

        stopNotificationPolling() {
            if (this.notificationTimer !== null) {
                window.clearInterval(this.notificationTimer);
                this.notificationTimer = null;
            }
        },

        startLockHeartbeat() {
            this.stopLockHeartbeat();
            this.lockTimer = window.setInterval(() => {
                this.heartbeatLocks();
            }, DOCUMENT_LOCK_HEARTBEAT_MS);
        },

        stopLockHeartbeat() {
            if (this.lockTimer !== null) {
                window.clearInterval(this.lockTimer);
                this.lockTimer = null;
            }
        },

        heartbeatLocks() {
            for (const [tabId, lock] of Object.entries(this.documentLocks)) {
                marketplaceApi.heartbeatDocumentLock(lock.id)
                    .then((renewed) => {
                        this.documentLocks[tabId] = renewed;
                    })
                    .catch((error) => {
                        if ([404, 423].includes(error.status)) {
                            delete this.documentLocks[tabId];
                        }

                        if (error.status === 423) {
                            this.notify(error.message);
                        }
                    });
            }
        },

        handleVisibilityChange() {
            if (!document.hidden && this.user) {
                this.liveRefresh();
            }
        },

        lockDescriptor(tab) {
            if (tab.kind === "request") {
                const request = this.store.get(tab.recordId);

                if (!this.requestNeedsLock(request)) {
                    return null;
                }

                return {
                    type: "request",
                    key: tab.recordId,
                    label: tab.title,
                };
            }

            if (
                tab.kind === "record"
                && !tab.record?.isNew
                && (
                    tab.record?.permissions?.canEdit
                    || tab.record?.permissions?.canReview
                )
            ) {
                return {
                    type: "catalog_record",
                    key: `${tab.record.kind}:${tab.recordId}`,
                    label: tab.title,
                };
            }

            return null;
        },

        requestNeedsLock(request) {
            if (!request?.permissions) {
                return false;
            }

            return ((request.permissions.editableFields || []).length > 0
                || [
                    "canAdvance",
                    "canReturn",
                    "canStationDecide",
                    "canCustomerSlotDecide",
                    "canEditParts",
                    "canDiagnose",
                    "canUpload",
                    "canManageParts",
                    "canIssueInvoice",
                    "canSubmitPayment",
                    "canVerifyPayment",
                    "canDeleteDraft",
                ].some((key) => request.permissions[key] === true));
        },

        async synchronizeRequestLocks() {
            for (const tab of this.tabs.filter((item) => item.kind === "request",
            )) {
                const needsLock = this.requestNeedsLock(this.store.get(tab.recordId),
                );

                if (!needsLock) {
                    tab.lockDenied = false;
                    tab.lockRetryAt = 0;
                    this.releaseTabLock(tab.id);
                    continue;
                }

                if (this.documentLocks[tab.id]) {
                    tab.lockDenied = false;
                    continue;
                }

                if (tab.lockRetryAt && tab.lockRetryAt > Date.now()) {
                    continue;
                }

                const acquired = await this.acquireTabLock(tab);
                tab.lockDenied = !acquired;
                tab.lockRetryAt = acquired ? 0 : Date.now() + 30_000;
            }
        },

        async acquireTabLock(tab) {
            if (this.documentLocks[tab.id]) return true;

            const descriptor = this.lockDescriptor(tab);

            if (!descriptor) return true;

            try {
                this.documentLocks[tab.id] = await marketplaceApi.acquireDocumentLock(
                    descriptor.type,
                    descriptor.key,
                    descriptor.label,
                );

                return true;
            } catch (error) {
                this.notify(error.message);

                return false;
            }
        },

        releaseTabLock(id) {
            const lock = this.documentLocks[id];

            if (!lock) return;

            delete this.documentLocks[id];
            marketplaceApi.releaseDocumentLock(lock.id).catch(() => {});
        },

        async openNotification(item) {
            try {
                await this.store.readNotification(item.id);

                if (item.targetType === "partManufacturer") {
                    await this.store.refreshCatalogs(true);
                    await this.openList("partManufacturers");
                    const record = this.store.records.find((row) => row.id === item.targetId,
                    );
                    if (record) await this.openRecord(record, "record");
                    return;
                }
                if (item.targetType === "vehicleApproval") {
                    await this.refresh();
                    const targetList = this.user.role === "manager"
                        ? "vehicleApprovals"
                        : "vehicles";

                    if (!(await this.openList(targetList))) return;

                    const approval = this.store.records.find(
                        (record) => record.id === item.targetId,
                    );

                    if (approval && this.user.role === "manager") {
                        await this.openRecord(approval, "record");
                    }

                    return;
                }

                let request = this.store.get(item.requestId);

                if (!request) {
                    request = await marketplaceApi.getRequest(item.requestId);
                    this.store.accept(request);
                }

                await this.openRecord(request);
            } catch (exception) {
                this.notify(exception.message);
            }
        },

        layoutSave() {
            try {
                localStorage.setItem(
                    "sto-layout-" + this.user.login,
                    JSON.stringify(this.panel),
                );
            } catch {}
        },

        collapse(key) {
            this.panel[key] = !this.panel[key];
            if (!this.panel.menu && !this.panel.work && !this.panel.state)
                this.panel.work = true;
            this.layoutSave();
        },

        showPanel(key) {
            this.panel[key] = true;
            if (window.innerWidth < 760)
                for (const k of ["menu", "work", "state"])
                    this.panel[k] = k === key;
            this.layoutSave();
        },

        resize(side, event) {
            if (window.innerWidth < 760) return;
            this.resizeCleanup?.();
            event.preventDefault();
            const x = event.clientX,
                start = this.panel[side];
            const move = (e) => {
                this.panel[side] = Math.max(
                    side === "left" ? 180 : 260,
                    Math.min(
                        side === "left" ? 360 : 500,
                        start + (e.clientX - x) * (side === "left" ? 1 : -1),
                    ),
                );
            };
            const end = () => {
                window.removeEventListener("pointermove", move);
                window.removeEventListener("pointerup", end);
                this.layoutSave();
                this.resizeCleanup = null;
            };
            this.resizeCleanup = end;
            window.addEventListener("pointermove", move);
            window.addEventListener("pointerup", end);
        },

        resizeKey(side, event) {
            if (!["ArrowLeft", "ArrowRight"].includes(event.key)) return;
            event.preventDefault();
            this.panel[side] = Math.max(
                side === "left" ? 180 : 260,
                Math.min(
                    side === "left" ? 360 : 500,
                    this.panel[side] +
                        (event.key === "ArrowRight" ? 16 : -16) *
                            (side === "left" ? 1 : -1),
                ),
            );
            this.layoutSave();
        },

        async activate(id) {
            if (this.active && this.$refs.center)
                this.active.scroll = this.$refs.center.scrollTop;
            this.activeId = id;
            if (this.chatRequestId && this.active?.recordId !== this.chatRequestId) {
                this.chatRequestId = "";
            }
            this.showPanel("work");
            await this.$nextTick();
            if (this.$refs.center)
                this.$refs.center.scrollTop = this.active?.scroll || 0;
        },

        async openList(key = "requests") {
            const id = "list:" + key;
            let tab = this.tabs.find((item) => item.id === id);

            if (!tab) {
                tab = {
                    id,
                    kind: "list",
                    key,
                    title:
                        {
                            requests: "Заявки на ремонт",
                            action: "Ожидают действия",
                            archive: "Архив",
                        }[key] || catalogNames[key],
                    filters: {},
                    selection: null,
                };

                if (!(await this.acquireTabLock(tab))) return false;
                this.tabs.push(tab);
            } else if (!(await this.acquireTabLock(tab))) {
                return false;
            }
            await this.activate(id);

            if (key === "changeRegistry") {
                this.store.refreshChangeRegistry().catch((error) => {
                    this.notify(error.message);
                });
            }

            return true;
        },

        async openRecord(record, kind = "request") {
            const id = kind + ":" + record.id;
            let tab = this.tabs.find((item) => item.id === id);

            if (!tab) {
                tab = {
                    id,
                    kind,
                    recordId: record.id,
                    record:
                        kind === "record"
                            ? JSON.parse(JSON.stringify(record))
                            : null,
                    title:
                        record.number ||
                        record.vehicle ||
                        record.name ||
                        record.fullName ||
                        "Новая запись",
                };

                const acquired = await this.acquireTabLock(tab);
                tab.lockDenied = !acquired;
                tab.lockRetryAt = acquired ? 0 : Date.now() + 30_000;
                this.tabs.push(tab);
            } else if (!this.documentLocks[tab.id]) {
                const acquired = await this.acquireTabLock(tab);
                tab.lockDenied = !acquired;
                tab.lockRetryAt = acquired ? 0 : Date.now() + 30_000;
            }
            await this.activate(id);

            return true;
        },

        newCatalog() {
            const record = {
                id: "new-" + Date.now(),
                kind: this.active.key,
                permissions: {
                    canEdit: true,
                    canDelete: false,
                    requiresApproval: this.user.role === "customer",
                },
                isNew: true,
                organizationId: this.user.organizationId,
            ...(this.active.key === "parts" ? { createdByOrganization: this.user.organization } : {}),
            };
            this.openRecord(record, "record");
        },

        async createWorkFromRequest(target) {
            const requestId = this.current?.id;
            const requestTabId = this.activeId;

            if (!requestId) {
                return;
            }

            await this.openRecord({
                id: "new-" + Date.now(),
                kind: "workTypes",
                name: String(target?.name || "").trim(),
                group: "Прочее",
                active: true,
                permissions: { canEdit: true },
                isNew: true,
                _requestWorkTarget: {
                    requestId,
                    requestTabId,
                    rowId: target?.rowId || "",
                    clientId: target?.clientId || "",
                    index: Number.isInteger(target?.index) ? target.index : -1,
                },
            }, "record",
            );
        },

        async createPartFromRequest(target) {
            const requestId = this.current?.id;
            const requestTabId = this.activeId;

            if (!requestId) {
                return;
            }

            await this.openRecord({
                id: "new-" + Date.now(),
                kind: "parts",
                    createdByOrganization: this.user.organization,
                name: String(target?.name || "").trim(),
                article: String(target?.article || "").trim(),
                manufacturer: "",
                unit: target?.unit || "шт.",
                active: true,
                permissions: { canEdit: true },
                isNew: true,
                _requestPartTarget: {
                    requestId,
                    requestTabId,
                    partId: target?.partId || "",
                    clientId: target?.clientId || "",
                    index: Number.isInteger(target?.index) ? target.index : -1,
                    scroll: this.$refs.center?.scrollTop || 0,
                },
            }, "record",
            );
        },

        openVehicle(id) {
            const tabId = "vehicle-history:" + id;
            if (!this.tabs.some((t) => t.id === tabId))
                this.tabs.push({
                    id: tabId,
                    kind: "vehicle-history",
                    recordId: id,
                    title: "История автомобиля",
                });
            this.activate(tabId);
        },

        async closeTab(id) {
            const t = this.tabs.find((t) => t.id === id);

            if (!t) {
                return;
            }

            if (t.kind === "request" && this.store.pending[t.recordId]) {
                this.pendingCloseTabId = id;

                return;
            }

            this.removeTab(id);
        },

        removeTab(id) {
            const i = this.tabs.findIndex((t) => t.id === id);

            if (i < 0) {
                return;
            }

            if (this.tabs[i].recordId === this.chatRequestId) {
                this.chatRequestId = "";
            }

            this.releaseTabLock(id);
            this.tabs.splice(i, 1);
            if (!this.tabs.length) this.openList();
            else if (this.activeId === id)
                this.activate(this.tabs[Math.max(0, i - 1)].id);
        },

        async closeUnsaved(saveChanges) {
            const tab = this.pendingCloseTab;

            if (!tab) {
                this.pendingCloseTabId = "";

                return;
            }

            this.closeBusy = true;

            try {
                if (saveChanges) {
                    await this.store.save(tab.recordId);
                } else {
                    this.store.discard(tab.recordId);
                }

                const id = tab.id;
                this.pendingCloseTabId = "";
                this.removeTab(id);
            } catch (e) {
                this.notify(e.message);
            } finally {
                this.closeBusy = false;
            }
        },

        select(item) {
            this.active.selection = item.id;
            this.panel.state = true;
            if (window.innerWidth < 760) this.showPanel("state");
        },

        openListed(item) {
            if (this.active?.key === "changeRegistry") {
                return;
            }

            this.openRecord(item, this.isRequestList ? "request" : "record");
        },

        async create() {
            try {
                const r = await this.store.create();
                if (r) await this.openRecord(r);
            } catch (e) {
                this.notify(e.message);
            }
        },

        toggleDraftSelection(id) {
            const index = this.selectedDraftIds.indexOf(id);

            if (index >= 0) {
                this.selectedDraftIds.splice(index, 1);
            } else {
                this.selectedDraftIds.push(id);
            }
        },

        toggleAllDrafts() {
            const visibleIds = this.selectableDrafts.map((item) => item.id);

            if (this.allVisibleDraftsSelected) {
                this.selectedDraftIds = this.selectedDraftIds.filter(
                    (id) => !visibleIds.includes(id),
                );
            } else {
                this.selectedDraftIds = [...new Set([
                    ...this.selectedDraftIds,
                    ...visibleIds]),
                ];
            }
        },

        pruneDraftSelection() {
            const available = new Set(
                this.store.requests
                    .filter((item) => item.permissions?.canDeleteDraft)
                    .map((item) => item.id),
            );

            this.selectedDraftIds = this.selectedDraftIds.filter(
                (id) => available.has(id),
            );
        },

        async deleteDrafts(ids) {
            const selectedIds = [...new Set(ids)].filter((id) =>
                this.store.get(id)?.permissions?.canDeleteDraft,
            );

            if (!selectedIds.length) {
                return;
            }

            const suffix = selectedIds.length === 1
                ? "Этот черновик будет удалён без возможности восстановления."
                : `Будут удалены черновики: ${selectedIds.length}.`;

            if (!window.confirm(`${suffix} Продолжить?`)) {
                return;
            }

            try {
                const deletedIds = await this.store.deleteDrafts(selectedIds);
                const deleted = new Set(deletedIds);

                for (const tab of this.tabs) {
                    if (tab.kind === "request" && deleted.has(tab.recordId)) {
                        this.releaseTabLock(tab.id);
                    }
                }

                this.tabs = this.tabs.filter(
                    (tab) => tab.kind !== "request" || !deleted.has(tab.recordId),
                );
                this.selectedDraftIds = this.selectedDraftIds.filter(
                    (id) => !deleted.has(id),
                );

                if (!this.tabs.some((tab) => tab.id === this.activeId)) {
                    if (this.tabs.length) {
                        await this.activate(this.tabs[0].id);
                    } else {
                        await this.openList();
                    }
                }

                this.notify(
                    deletedIds.length === 1
                        ? "Черновик удалён"
                        : `Удалено черновиков: ${deletedIds.length}`,
                );
            } catch (error) {
                this.notify(error.message);
            }
        },

        deleteCurrentDraft() {
            if (this.current?.permissions?.canDeleteDraft) {
                this.deleteDrafts([this.current.id]);
            }
        },

        touch() {
            if (this.current) this.store.touch(this.current.id);
        },

        async deferRecommendations(selection) {
            if (!this.current || this.store.pending[this.current.id] || this.store.busy) return;
            this.store.busy = true;
            try {
                const result = await marketplaceApi.deferRecommendations(this.current.id, this.current.revision, selection,
                );
                this.store.accept(result.request);
                this.notify(`Созданы рекомендации ${result.recommendation.number}`,
                );
            } catch (error) { this.notify(error.message); }
            finally { this.store.busy = false; }
        },
        async openRecommendations(request) {
            const id = 'recommendations:' + request.vehicleId;
            if (!this.tabs.some((tab ) => tab.id === id)) this.tabs.push({ id, kind: 'recommendations', vehicleId: request.vehicleId, requestId: request.id, title: `Рекомендации · ${request.vin}` ,
                });
            await this.activate(id);
        },
        async recommendationsRestored(request) {
            this.store.accept(request);
            this.notify('Позиции перенесены в ' + request.number);
            await this.openRecord(request);
        },
        async vehicleModelCreated() {
            this.vehicleModelCreateOpen = false;
            const tab = this.tabs.find((tab ) => tab.kind === 'list' && tab.key === 'vehicleModels',
            );
            if (tab) { await this.activate(tab.id); this.vehicleReferenceReloadToken += 1; }
            this.notify('Запись добавлена в справочник ТС');
        },
        createVehicleModel(record) { return marketplaceApi.createVehicleCatalogVariant(record); },
        async save() {
            try {
                await this.store.save(this.current.id);
                this.notify("Сохранено");
            } catch (e) {
                this.notify(e.message);
            }
        },

        async saveAndExit() {
            const tabId = this.activeId;
            const requestId = this.current?.id;

            if (!requestId) {
                return;
            }

            try {
                await this.store.save(requestId);
                this.notify("Сохранено");
                this.removeTab(tabId);
            } catch (e) {
                this.notify(e.message);
            }
        },

        async perform(method, ...args) {
            const key = this.current?.id;
            if (!key) throw new Error("Заявка не выбрана");
            try {
                const result = await this.store.act(key, method, ...args);
                await this.synchronizeRequestLocks();

                return result;
            } catch (e) {
                this.notify(e.message);
                throw e;
            }
        },

        async saveCatalog(record, form) {
            const saved = record.isNew
                ? await marketplaceApi.createCatalog(record.kind, form)
                : await marketplaceApi.updateCatalog(record.id, form);
            this.store.upsertRecord(saved);

            if (
                [
                    "customers",
                    "stations",
                    "managers",
                    "workTypes",
                    "timeNorms",
                    "normApplicabilities",
                "suppliers",
                    "parts",
                ].includes(record.kind)
            ) {
                await this.store.refreshCatalogs(true);
            }

            return (
                this.store.records.find((item) => item.id === saved.id)
                || saved);
        },

        async saveVehicleCatalog(record, form) {
            const result = record.isNew
                ? await marketplaceApi.createVehicleCatalog(form)
                : await marketplaceApi.updateVehicleCatalog(record.id, form);

            this.store.upsertRecord(result.record);

            return result;
        },

        async saveOrganizationPerson(record, form) {
            const saved = record.isNew
                ? await marketplaceApi.createOrganizationPerson(form)
                : await marketplaceApi.updateOrganizationPerson(record.id, form,
                  );

            this.store.upsertRecord(saved);

            return saved;
        },

        async deleteOrganizationPerson(record) {
            const result = await marketplaceApi.deleteOrganizationPerson(record.id,
            );

            this.store.removeRecord(record.id);

            return result;
        },

        async deleteVehicleCatalog(record) {
            const result = await marketplaceApi.deleteVehicleCatalog(record.id);

            if (result.record) {
                this.store.upsertRecord(result.record);
            } else {
                this.store.removeRecord(record.id);
            }

            return result;
        },

        async reviewVehicleChange(record, decision, comment) {
            const result = await marketplaceApi.reviewVehicleChange(
                record.id,
                decision,
                comment,
            );

            await this.store.refreshCatalogs();
            await this.store.refreshNotifications();

            return result;
        },

        loadCatalogHistory(kind, recordId) {
            return marketplaceApi.catalogHistory(kind, recordId);
        },

        openCatalogImport() {
            if (this.canImportCatalog) {
                this.catalogImportKind = this.active.key;
            }
        },

        async catalogImported(result) {
            try {
                if (result.catalog === "vehicleModels") {
                    this.vehicleReferenceReloadToken++;
                } else {
                    await this.store.refreshCatalogs(true);
                }

                const imported = result.summary?.acceptedWorks
                    ?? result.imported
                    ?? 0;

                this.notify(`Импортировано записей: ${imported}`);
            } catch (error) {
                this.notify(
                    `Импорт выполнен, но список не обновился: ${error.message}`,
                );
            }
        },

        catalogSaved(record, pendingApproval = false) {
            const tab = this.active;
            const requestWorkTarget = tab?.record?._requestWorkTarget;
            const requestPartTarget = tab?.record?._requestPartTarget;

            tab.record = record;
            tab.recordId = record.id;
            tab.title =
                record.vehicle
                || record.name
                || record.fullName
                || catalogNames[record.kind];
            this.notify(
                pendingApproval
                    ? "Изменения отправлены на согласование УК"
                    : "Справочник сохранён",
            );

            if (record.kind === "workTypes" && requestWorkTarget) {
                const request = this.store.get(requestWorkTarget.requestId);
                const work = request?.works?.find(
                    (item) =>
                        requestWorkTarget.rowId
                            ? item.id === requestWorkTarget.rowId
                            : requestWorkTarget.clientId
                              ? item._clientId === requestWorkTarget.clientId
                              : false,
                ) || request?.works?.[requestWorkTarget.index];

                if (work) {
                    work.workTypeId = record.id;
                    work.name = record.name;
                    work.type = "Работа";
                    this.store.touch(request.id);
                }

                const catalogTabId = tab.id;
                this.removeTab(catalogTabId);

                if (this.tabs.some((item) => item.id === requestWorkTarget.requestTabId,
                    )) {
                    this.activate(requestWorkTarget.requestTabId);
                }

                this.notify(
                    work
                        ? "Работа добавлена в справочник и выбрана в заявке"
                        : "Работа добавлена в справочник",
                );
            }

            if (record.kind === "parts" && requestPartTarget) {
                const request = this.store.get(requestPartTarget.requestId);
                const part = request?.parts?.find((item ) => requestPartTarget.partId ? item.id === requestPartTarget.partId : item._clientId === requestPartTarget.clientId,
                    ) || request?.parts?.[requestPartTarget.index];

                if (part) {
                    part.catalogPartId = record.id;
                    part.name = record.name;
                    part.article = record.article || "";
                    part.manufacturer = record.manufacturer || "";
                    part.unit = record.unit || "шт.";
                    part.supplierId = record.supplierId || "";
                    part.price = record.salePrice ?? 0;
                    this.store.touch(request.id);
                }

                const catalogTabId = tab.id;
                this.removeTab(catalogTabId);

                if (this.tabs.some((item) => item.id === requestPartTarget.requestTabId,
                    )) {
                    const originScroll = requestPartTarget.scroll ?? this.tabs.find((item ) => item.id === requestPartTarget.requestTabId,
                        )?.scroll ?? 0;
                    Promise.resolve(this.activate(requestPartTarget.requestTabId),
                    ).then(() => {
                        const origin = this.tabs.find((item ) => item.id === requestPartTarget.requestTabId,
                        );
                        if (origin) origin.scroll = originScroll;
                        if (this.$refs?.center) this.$refs.center.scrollTop = originScroll;
                    });
                }

                this.notify(
                    part
                        ? "Запчасть добавлена в справочник и выбрана в заявке"
                        : "Запчасть добавлена в справочник",
                );
            }
        },

        catalogSavedAndExit(record, pendingApproval = false) {
            const tabId = this.activeId;

            this.catalogSaved(record, pendingApproval);

            if (this.tabs.some((tab) => tab.id === tabId)) {
                this.removeTab(tabId);
            }
        },

        closeChatOutside(event) {
            if (
                !this.chatRequestId
                || event.target.closest(".request-chat-drawer")
                || event.target.closest(".request-chat-button")
            ) {
                return;
            }

            this.chatRequestId = "";
        },

        updateOverflowTitle(event) {
            const element = event.target.closest(
                "input, select, textarea, td, th, output, "
                    + ".work-search-results button, .reference-picker-options button",
            );

            if (!element || !event.currentTarget.contains(element)) {
                return;
            }

            if (element.hasAttribute("title") && !element.dataset.overflowTitle) {
                return;
            }

            const text = element instanceof HTMLSelectElement
                ? element.selectedOptions[0]?.textContent
                : element instanceof HTMLInputElement
                  || element instanceof HTMLTextAreaElement
                    ? element.value
                    : element.textContent;
            const overflows = element.scrollWidth > element.clientWidth + 1
                || element.scrollHeight > element.clientHeight + 1;

            if (overflows && String(text || "").trim()) {
                element.title = String(text).trim().replace(/\s+/g, " ");
                element.dataset.overflowTitle = "true";
            } else if (element.dataset.overflowTitle) {
                element.removeAttribute("title");
                delete element.dataset.overflowTitle;
            }
        },

        vehicleDeleted(result) {
            const activeId = this.activeId;

            this.removeTab(activeId);
            this.openList("vehicles");
            this.notify(
                result.pendingApproval
                    ? "Удаление отправлено на согласование УК"
                    : "Автомобиль удалён",
            );
        },

        personDeleted() {
            const key = this.active?.record?.kind || "contactPersons";
            const activeId = this.activeId;

            this.removeTab(activeId);
            this.openList(key);
            this.notify("Запись удалена");
        },

        vehicleChangeReviewed() {
            const activeId = this.activeId;

            this.removeTab(activeId);
            this.openList("vehicleApprovals");
            this.notify("Решение сохранено");
        },

        async resolve(choice) {
            try {
                await this.store.resolveConflict(choice);
            } catch (e) {
                this.notify(e.message);
            }
        },

        cell(item, key) {
            if (item.kind === "partManufacturers" && key === "status") {
                return ({ approved: "Согласован", pending: "Ожидает согласования УК", rejected: "Отклонён" ,
                    }[item.status] || item.status);
            }

            const value = item[key];

            if (
                item.kind === "workTypes"
                && key === "laborHourRate"
                && (value === null || value === undefined || value === "")
            ) {
                return "Не указана";
            }

            if (key.endsWith("At")) {
                return formatDateTime(value);
            }
            if (["parentId", "workTypeId", "supplierId", "manufacturerId",
                ].includes(key)) {
                const rows = [
                    ...this.store.records,
                    ...Object.values(this.store.references).flat(),
                ];
                return rows.find((r) => r.id === value)?.name || "—";
            }
            if (typeof value === "boolean") return value ? "Да" : "Нет";
            if (Array.isArray(value)) {
                if (key === "workingDays") {
                    return value
                        .map((day) => WEEKDAY_LABELS[Number(day)] || day)
                        .join(", ");
                }

                if (key === "accessRights") {
                    const labels = Object.fromEntries(
                        (this.store.references.accessRights || []).map((right) => [
                            right.value,
                            right.label],
                        ),
                    );

                    return value.map((entry) => labels[entry] || entry).join(", ");
                }

                return value
                    .map((entry) => entry.fullName || entry.name || entry)
                    .join(", ");
            }
            return value === 0 ? 0 : value || "—";
        },

        guardUnload(e) {
            if (this.store.dirty) {
                e.preventDefault();
                e.returnValue = "";
            }
        },

        expireSession() {
            if (!this.user) {
                return;
            }

            clearToken();
            this.stopNotificationPolling();
            this.stopLockHeartbeat();
            this.documentLocks = {};
            this.store.reset();
            this.chatRequestId = "";
            this.user = null;
            this.tabs = [];
            this.activeId = "";
            this.selectedDraftIds = [];
            const message = "Сессия завершена. Возможно, выполнен вход с другого устройства.";
            this.fatal = message;
            this.notify(message);
        },
    },

    async mounted() {
        window.addEventListener("beforeunload", this.guardUnload);
        document.addEventListener("visibilitychange", this.handleVisibilityChange,
        );
        document.addEventListener("pointerdown", this.closeChatOutside);

        http.setUnauthorizedHandler(() => this.expireSession());

        try {
            restoreToken();
            await this.login(await marketplaceApi.getSession());
        } catch (e) {
            if (e.status !== 401) {
                this.fatal = e.message;
                this.notify(e.message);
            }
        } finally {
            this.checking = false;
        }
    },

    beforeUnmount() {
        this.resizeCleanup?.();
        this.stopNotificationPolling();
        this.stopLockHeartbeat();
        clearTimeout(this.toastTimer);
        clearTimeout(this.fatalTimer);
        clearTimeout(this.saveErrorTimer);
        window.removeEventListener("beforeunload", this.guardUnload);
        document.removeEventListener("visibilitychange", this.handleVisibilityChange,
        );
        document.removeEventListener("pointerdown", this.closeChatOutside);
        http.setUnauthorizedHandler(null);
        this.store.reset();
    },
};
</script>

<template>
    <div v-if="checking" class="loading-page">
        <img
            src="/autoremhub-mark.png"
            width="512"
            height="512"
            alt=""
        />
        <span>Открываем AUTOREMHUB…</span>
    </div>

    <template v-else-if="!user">
        <LoginForm @login="login" />
        <p v-if="fatal" class="connection-error">{{ fatal }}</p>
    </template>

    <div v-else class="app-shell" @pointerover="updateOverflowTitle">
        <header class="topbar">
            <div class="brand brand-topbar">
                <div class="brand-logo-stack">
                    <img
                        class="brand-logo"
                        src="/autoremhub-logo.png"
                        width="1000"
                        height="188"
                        alt="AUTOREMHUB"
                    />
                    <small class="app-version">{{ appVersion }}</small>
                </div>
                <div class="brand-context">
                    <small>Личный кабинет</small>
                </div>
            </div>
            <div class="role-badge">{{ user.label }}</div>
            <div class="user-chip">
                <span class="avatar">{{
                    user.login.slice(0, 2).toUpperCase()
                }}</span>
                <div>
                    <strong>{{ user.fullName || user.displayName || user.login }}</strong>
                    <small>{{ user.organization }}</small>
                </div>
                <button
                    class="logout-btn"
                    :disabled="logoutBusy || store.busy"
                    @click="logout"
                >
                    Выйти
                </button>
            </div>
        </header>

        <nav class="mobile-panels">
            <button @click="showPanel('menu')">Меню</button>
            <button @click="showPanel('work')">Рабочая область</button>
            <button @click="showPanel('state')">Состояние</button>
        </nav>

        <main
            class="workspace"
            :style="layout"
            :class="{
                'menu-off': !panel.menu,
                'work-off': !panel.work,
                'state-off': !panel.state,
            }"
        >
            <aside class="area menu-area" :class="{ collapsed: !panel.menu }">
                <header class="area-head">
                    <h2 v-if="panel.menu">Меню</h2>
                    <button
                        class="collapse-btn"
                        :aria-expanded="panel.menu"
                        aria-label="Свернуть или развернуть меню"
                        @click="collapse('menu')"
                    >
                        {{ panel.menu ? "‹" : "›" }}
                    </button>
                </header>
                <div v-if="panel.menu" class="area-scroll menu-scroll">
                    <span class="nav-caption">Документы</span>
                    <button
                        class="nav-item"
                        :class="{ active: active?.key === 'requests' }"
                        @click="openList()"
                    >
                        ▤ Заявки на ремонт <b>{{ store.requests.length }}</b>
                    </button>
                    <button
                        class="nav-item"
                        :class="{ active: active?.key === 'action' }"
                        @click="openList('action')"
                    >
                        ◷ Ожидают действия <b>{{ awaiting }}</b>
                    </button>
                    <button
                        class="nav-item"
                        :class="{ active: active?.key === 'archive' }"
                        @click="openList('archive')"
                    >
                        ▣ Архив
                    </button>
                    <span class="nav-caption notification-caption">
                        Уведомления
                        <b v-if="unreadNotifications">{{ unreadNotifications }}</b>
                    </span>
                    <div class="notification-list">
                        <button
                            v-for="item in store.notifications.slice(0, 5)"
                            :key="item.id"
                            class="notification-item"
                            :class="{ unread: !item.readAt }"
                            @click="openNotification(item)"
                        >
                            <strong>{{ item.title }}</strong>
                            <small>{{ item.message }}</small>
                            <time>{{ formatDateTime(item.createdAt) }}</time>
                        </button>
                        <p v-if="!store.notifications.length" class="muted">
                            Новых уведомлений нет.
                        </p>
                    </div>
                    <span class="nav-caption notification-caption">
                        Сообщения по заявкам
                        <b v-if="store.chatUnreadTotal">{{ store.chatUnreadTotal }}</b>
                    </span>
                    <div v-if="unreadChatSummaries.length" class="notification-list">
                        <button
                            v-for="chat in unreadChatSummaries.slice(0, 5)"
                            :key="chat.requestId"
                            class="notification-item unread"
                            @click="openUnreadChat(chat)"
                        >
                            <strong>{{ chat.requestNumber }} · чат</strong>
                            <small>Непрочитанных сообщений: {{ chat.unreadCount }}</small>
                        </button>
                    </div>
                    <template v-if="catalogKeys.length">
                        <span class="nav-caption">Справочники</span>
                        <button
                            v-for="key in catalogKeys"
                            :key="key"
                            class="nav-item"
                            :class="{ active: active?.key === key }"
                            @click="openList(key)"
                        >
                            {{ catalogNamesValue(key) }}
                        </button>
                    </template>
                    <div class="menu-info">
                        <strong>{{ user.organization }}</strong>
                        <p>{{ user.label }}</p>
                    </div>
                </div>
                <button v-else class="vertical-label" @click="collapse('menu')">
                    Меню
                </button>
            </aside>

            <div
                class="splitter"
                role="separator"
                aria-label="Ширина меню"
                aria-orientation="vertical"
                tabindex="0"
                @pointerdown="resize('left', $event)"
                @keydown="resizeKey('left', $event)"
            />

            <section class="area work-area" :class="{ collapsed: !panel.work }">
                <header class="area-head">
                    <span v-if="panel.work" class="area-caption"
                        >Рабочая область</span
                    >
                    <span
                        v-if="panel.work"
                        class="save-status"
                        :class="{ error: store.saveError }"
                        >{{
                            store.saveError
                                ? "Ошибка сохранения"
                                : store.busy
                                  ? "Выполняем операцию…"
                                  : store.dirty
                                    ? "Есть несохранённые изменения"
                                    : "Изменения сохранены"
                        }}</span
                    >
                    <button
                        class="collapse-btn"
                        aria-label="Свернуть или развернуть рабочую область"
                        :aria-expanded="panel.work"
                        @click="collapse('work')"
                    >
                        {{ panel.work ? "‹" : "›" }}
                    </button>
                </header>

                <template v-if="panel.work">
                    <div class="virtual-tabs" role="tablist">
                        <div
                            v-for="t in tabs"
                            :key="t.id"
                            class="virtual-tab"
                            :class="{ active: t.id === activeId }"
                        >
                            <button
                                :title="t.title"
                                role="tab"
                                :aria-selected="t.id === activeId"
                                @click="activate(t.id)"
                            >
                                {{ t.title
                                }}{{ store.pending[t.recordId] ? " •" : "" }}
                            </button>
                            <button
                                class="tab-close"
                                :aria-label="'Закрыть ' + t.title"
                                @click="closeTab(t.id)"
                            >
                                ×
                            </button>
                        </div>
                    </div>

                    <div
                        v-if="fatal || store.saveError"
                        role="alert"
                        class="error-banner"
                    >
                        {{ fatal || store.saveError
                        }}<button @click="refresh">Повторить</button>
                    </div>
                    <div v-if="store.conflict" class="conflict-banner">
                        <strong>Заявка изменилась в другом кабинете</strong>
                        <button @click="resolve('mine')">
                            Сохранить мои поля
                        </button>
                        <button @click="resolve('server')">
                            Принять серверную версию
                        </button>
                    </div>
                    <div v-if="missingLaborHourRate" class="warning-banner">
                        <strong>Стоимость нормо/часа не указана.</strong>
                        <template v-if="user.role === 'manager'">
                            Откройте «Справочник ТС Заказчиков», заполните стоимость для VIN
                            {{ current.vin }} и сохраните запись.
                        </template>
                        <template v-else>
                            Обратитесь в УК: стоимость для VIN {{ current.vin }}
                            заполняется в справочнике ТС.
                        </template>
                    </div>

                    <div
                        ref="center"
                        class="area-scroll work-scroll"
                        :class="{
                            'work-catalog-scroll':
                                active?.kind === 'list' &&
                                active.key === 'workTypes',
                        }"
                    >
                        <template v-if="active?.kind === 'list'">
                            <div
                                class="list-heading"
                                :class="{
                                    'request-list-heading': isRequestList,
                                }"
                            >
                                <div>
                                    <span class="eyebrow">{{
                                        isRequestList
                                            ? "Документы"
                                            : "Справочники"
                                    }}</span>
                                    <h1>
                                        {{ active.title }}
                                        <span class="count">{{
                                            active.key === 'vehicleModels'
                                                ? vehicleReferenceTotal
                                                : listed.length
                                        }}</span>
                                    </h1>
                                </div>
                                <div class="list-heading-actions">
                                    <button
                                        v-if="
                                            isRequestList &&
                                            user.permissions.canCreateRequest
                                        "
                                        class="primary-btn"
                                        :disabled="store.busy"
                                        @click="create"
                                    >
                                        ＋ Новая заявка
                                    </button>
                                    <button
                                        v-if="canCreateCatalog"
                                        class="primary-btn"
                                        @click="newCatalog"
                                    >
                                        Добавить запись
                                    </button>
                                    <button
                                        v-if="canImportCatalog"
                                        class="ghost-btn"
                                        @click="openCatalogImport"
                                    >
                                        Импорт из Excel
                                    </button>
                                    <button v-if="
                                            user.role === 'manager' &&
                                            active.key === 'vehicleModels'
                                        "
                                        class="ghost-btn" @click="vehicleModelCreateOpen = true">Добавить ТС</button>
                                    <button
                                        v-if="selectedDraftCount"
                                        class="danger-btn"
                                        :disabled="store.busy"
                                        @click="deleteDrafts(selectedDraftIds)"
                                    >
                                        Удалить черновики ({{ selectedDraftCount }})
                                    </button>
                                </div>
                            </div>
                            <p class="list-hint">
                                <template v-if="active.key === 'changeRegistry'">
                                    Реестр доступен только для просмотра.
                                </template>
                                <template v-else-if="active.key === 'vehicleModels'">
                                    Внутренний справочник доступен УК для просмотра,
                                    фильтрации и импорта.
                                </template>
                                <template v-else>
                                    Один клик — предпросмотр · Двойной клик или
                                    Enter — открыть вкладку
                                </template>
                            </p>
                            <VehicleReferenceCatalog
                                v-if="active.key === 'vehicleModels'"
                                :reload-token="vehicleReferenceReloadToken"
                                @total="vehicleReferenceTotal = $event"
                            />
                            <template v-else>
                            <div class="registry-wrap">
                                <table class="registry">
                                    <thead>
                                        <tr v-if="isRequestList">
                                            <th v-if="
                                                        active.key ===
                                                        'requests'
                                                    "
                                                    class="selection-col">
                                                <input
                                                    type="checkbox"
                                                    aria-label="Выбрать все доступные черновики"
                                                    :checked="
                                                            allVisibleDraftsSelected
                                                        "
                                                        :disabled="
                                                            !selectableDrafts.length
                                                        "
                                                        @change="
                                                            toggleAllDrafts
                                                        "
                                                    />
                                            </th>
                                            <th
                                                v-for="(
                                                        name, key
                                                    ) in requestColumns"
                                                    :key="key"
                                            >
                                                {{ name }}
                                            </th>
                                            <th />
                                        </tr>
                                        <tr v-else>
                                            <th
                                                v-for="(
                                                        name, key
                                                    ) in columns(active.key)"
                                                    :key="key"
                                            >
                                                {{ name }}
                                            </th>
                                            <th />
                                        </tr>
                                        <tr
                                            v-if="isRequestList"
                                            class="column-filter-row request-filter-row"
                                        >
                                            <th
                                                v-if="
                                                        active.key ===
                                                        'requests'
                                                    "
                                                    class="selection-col"
                                            />
                                            <th
                                                v-for="(
                                                        name, key
                                                    ) in requestColumns"
                                                    :key="key"
                                            >
                                                <input
                                                    v-model="
                                                            active.filters[key]
                                                        "
                                                        :aria-label="`Фильтр: ${name}`"
                                                    :placeholder="
                                                            requestFilterPlaceholder(
                                                                key,
                                                            )
                                                        "
                                                    />
                                            </th>
                                            <th />
                                        </tr>
                                        <tr v-else class="column-filter-row">
                                            <th
                                                v-for="(
                                                        name, key
                                                    ) in columns(active.key)"
                                                    :key="key"
                                            >
                                                <input
                                                    v-model="
                                                            active.filters[key]
                                                        "
                                                        :aria-label="`Фильтр: ${name}`"
                                                    placeholder="Фильтр"
                                                />
                                            </th>
                                            <th />
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr
                                            v-for="item in listed"
                                            :key="item.id"
                                            tabindex="0"
                                            :class="{
                                                    selected:
                                                        active.selection ===
                                                        item.id,
                                                }"
                                                @click="select(item)"
                                            @dblclick="openListed(item)"
                                            @keydown.enter="
                                                    openListed(item)
                                                "
                                            >
                                            <template v-if="isRequestList">
                                                <td v-if="
                                                            active.key ===
                                                            'requests'
                                                        "
                                                        class="selection-col">
                                                    <input
                                                        v-if="
                                                                item.permissions
                                                                    ?.canDeleteDraft
                                                            "
                                                            type="checkbox"
                                                        aria-label="Выбрать черновик для удаления"
                                                        :checked="
                                                                selectedDraftIds.includes(
                                                                    item.id,
                                                                )
                                                            "
                                                            @click.stop
                                                        @change="
                                                                toggleDraftSelection(
                                                                    item.id,
                                                                )
                                                            "
                                                        />
                                                </td>
                                                <td>
                                                    <strong>{{
                                                        item.number
                                                    }}</strong
                                                    ><small>{{
                                                        formatDateTime(
                                                            item.createdAt,
                                                        )
                                                    }}</small>
                                                </td>
                                                <td>
                                                    <strong>{{
                                                        item.vehicle ||
                                                        "Автомобиль не выбран"
                                                    }}</strong
                                                    ><small
                                                        >{{
                                                            item.plate ||
                                                            "Пустой черновик"
                                                        }}
                                                        <template
                                                            v-if="
                                                                    item.company &&
                                                                    user.role !==
                                                                        'station'
                                                                "
                                                            >
                                                            · {{ item.company }}
                                                        </template></small
                                                    >
                                                </td>
                                                <td>
                                                    <span
                                                        class="status"
                                                        :class="
                                                                statusClass(
                                                                    item.stage,
                                                                    item.permissions,
                                                                )
                                                            "
                                                            >{{
                                                            item.statusLabel || step(item.stage).status}}</span
                                                    ><small>{{
                                                        step(item.stage).owner
                                                    }}</small
                                                    ><small
                                                        v-if="
                                                                item.overdueSeconds
                                                            "
                                                            class="overdue"
                                                        >Превышен плановый
                                                        срок</small
                                                    >
                                                </td>
                                                <td>
                                                    {{ money(total(item)) }}
                                                </td>
                                            </template>
                                            <template v-else>
                                                <td
                                                    v-for="(
                                                            label, key
                                                        ) in columns(
                                                            active.key,
                                                        )"
                                                        :key="key"
                                                >
                                                    {{ cell(item, key) }}
                                                </td>
                                            </template>
                                            <td>
                                                <button
                                                    v-if="
                                                            active.key !==
                                                            'changeRegistry'
                                                        "
                                                        class="open-row"
                                                    :aria-label="
                                                            'Открыть ' +
                                                            (item.number ||
                                                                item.vehicle ||
                                                                item.name)
                                                        "
                                                        @click.stop="
                                                            openListed(item)
                                                        "
                                                    >
                                                    ↗
                                                </button>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div v-if="!listed.length" class="empty-state">
                                <h3>
                                    {{
                                        store.loading
                                            ? "Загружаем данные…"
                                            : "Записей пока нет"
                                    }}
                                </h3>
                                <p>
                                    {{
                                        activeFiltersApplied
                                            ? "Измените параметры фильтрации."
                                            : isRequestList
                                              ? "Новая заявка откроется с пустыми полями."
                                              : "Справочник ещё не заполнен."
                                    }}
                                </p>
                            </div>
                            </template>
                        </template>

                        <template v-else-if="current">
                            <div class="document-toolbar">
                                <button class="ghost-btn" @click="openList()">
                                    ← К списку
                                </button>
                                <button
                                    v-if="current.permissions?.canDeleteDraft"
                                    class="danger-btn"
                                    :disabled="store.busy"
                                    @click="deleteCurrentDraft"
                                >
                                    Удалить черновик
                                </button>
                            </div>
                            <RepairEditor
                                :key="current.id"
                                :request="currentView"
                                :user="user"
                                :records="store.records"
                                :references="store.references"
                                :busy="store.busy"
                                :dirty="Boolean(store.pending[current.id])"
                                :chat-created="Boolean(chatSummary(current.id))"
                                :chat-unread="
                                    chatSummary(current.id)?.unreadCount || 0
                                "
                                :chat-busy="chatOpening"
                                :perform="perform"
                                @touch="touch"
                                @save="save"
                                @save-and-exit="saveAndExit"
                                @chat="openChatFor(current.id, true)"
                                @vehicle-history="openVehicle"
                                @create-work="createWorkFromRequest"
                                @create-part="createPartFromRequest"
                                @defer-recommendations="deferRecommendations"
                                @recommendations="openRecommendations(current)"
                            />
                        </template>

                        <VehicleCatalogEditor
                            v-else-if="
                                active?.kind === 'record' &&
                                active.record.kind === 'vehicles'
                            "
                            :key="active.id"
                            :record="activeRecordView"
                            :records="store.records"
                            :user="user"
                            :save-record="saveVehicleCatalog"
                            :delete-record="deleteVehicleCatalog"
                            @saved="catalogSaved"
                            @saved-and-exit="catalogSavedAndExit"
                            @deleted="vehicleDeleted"
                        />
                        <PersonCatalogEditor
                            v-else-if="
                                active?.kind === 'record' &&
                                ['contactPersons', 'drivers'].includes(
                                    active.record.kind,
                                )
                            "
                            :key="active.id"
                            :record="activeRecordView"
                            :records="store.records"
                            :user="user"
                            :save-record="saveOrganizationPerson"
                            :delete-record="deleteOrganizationPerson"
                            @saved="catalogSaved"
                            @saved-and-exit="catalogSavedAndExit"
                            @deleted="personDeleted"
                        />
                        <VehicleApprovalReview
                            v-else-if="
                                active?.kind === 'record' &&
                                active.record.kind === 'vehicleApprovals'
                            "
                            :key="active.id"
                            :record="activeRecordView"
                            :review="reviewVehicleChange"
                            @reviewed="vehicleChangeReviewed"
                        />
                        <UserCatalogEditor
                            v-else-if="
                                active?.kind === 'record' &&
                                active.record.kind === 'users'
                            "
                            :key="active.id"
                            :record="activeRecordView"
                            :references="store.references"
                            :save-record="saveCatalog"
                            @saved="catalogSaved"
                            @saved-and-exit="catalogSavedAndExit"
                        />
                        <SupplierEditor
                            v-else-if="
                                active?.kind === 'record' &&
                                active.record.kind === 'suppliers'
                            "
                            :key="active.id"
                            :record="activeRecordView"
                            :references="store.references"
                            :save-record="saveCatalog"
                            @saved="catalogSaved"
                            @saved-and-exit="catalogSavedAndExit"
                        />
                        <CatalogEditor
                            v-else-if="active?.kind === 'record'"
                            :key="active.id"
                            :record="activeRecordView"
                            :records="store.records"
                            :references="store.references"
                            :save-record="saveCatalog"
                            :load-history="loadCatalogHistory"
                            @saved="catalogSaved"
                            @saved-and-exit="catalogSavedAndExit"
                            @vehicle-history="openVehicle"
                            @open-record="openRecord($event, 'record')"
                        />
                        <RecommendationPanel v-else-if="active?.kind === 'recommendations'" :key="active.id" :vehicle-id="active.vehicleId" :requests="store.requests" :pending="store.pending" @restored="recommendationsRestored" />
                        <VehicleHistory
                            v-else-if="active?.kind === 'vehicle-history'"
                            :vehicle-id="active.recordId"
                        />
                    </div>
                </template>

                <button v-else class="vertical-label" @click="collapse('work')">
                    Рабочая область
                </button>
            </section>

            <div
                class="splitter"
                role="separator"
                aria-label="Ширина состояния"
                aria-orientation="vertical"
                tabindex="0"
                @pointerdown="resize('right', $event)"
                @keydown="resizeKey('right', $event)"
            />

            <aside class="area state-area" :class="{ collapsed: !panel.state }">
                <header class="area-head">
                    <h2 v-if="panel.state">Состояние документа</h2>
                    <button
                        class="collapse-btn"
                        aria-label="Свернуть или развернуть состояние"
                        :aria-expanded="panel.state"
                        @click="collapse('state')"
                    >
                        {{ panel.state ? "›" : "‹" }}
                    </button>
                </header>
                <div v-if="panel.state" class="area-scroll">
                    <RequestPreview
                        :item="selected"
                        :kind="current || isRequestList ? 'request' : 'record'"
                    />
                </div>
                <button
                    v-else
                    class="vertical-label"
                    @click="collapse('state')"
                >
                    Состояние
                </button>
            </aside>
        </main>
        <RequestChatDrawer
            v-if="chatRequestId"
            :key="chatRequestId"
            ref="requestChat"
            :request-id="chatRequestId"
            :request-number="chatRequestNumber"
            :user="user"
            @close="chatRequestId = ''"
            @read="chatRead"
        />
    </div>

    <UnsavedChangesDialog
        v-if="pendingCloseTab"
        :title="pendingCloseTab.title"
        :busy="closeBusy"
        @save="closeUnsaved(true)"
        @discard="closeUnsaved(false)"
    />

    <VehicleModelCreateDialog v-if="vehicleModelCreateOpen" :create-model="createVehicleModel" @created="vehicleModelCreated" @close="vehicleModelCreateOpen = false" />
    <CatalogImportWizard
        v-if="catalogImportKind"
        :kind="catalogImportKind"
        :title="catalogNamesValue(catalogImportKind)"
        @close="catalogImportKind = ''"
        @imported="catalogImported"
    />

    <div v-if="toast" class="toast" role="status">{{ toast }}</div>
</template>
