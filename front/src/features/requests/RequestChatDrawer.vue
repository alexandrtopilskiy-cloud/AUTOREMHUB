<script setup>
import { nextTick, onMounted, onUnmounted, ref } from "vue";
import { marketplaceApi } from "../../api/marketplace.js";

const MAX_FILE_SIZE = 30 * 1024 * 1024;
const MAX_FILES = 10;

const props = defineProps({
    requestId: { type: String, required: true },
    requestNumber: { type: String, required: true },
    user: { type: Object, required: true },
});
const emit = defineEmits(["close", "read"]);

const messages = ref([]);
const messageList = ref(null);
const text = ref("");
const files = ref([]);
const loading = ref(true);
const busy = ref(false);
const error = ref("");
const hasOlder = ref(false);
const olderBusy = ref(false);
const pendingUnread = ref(false);
const preview = ref(null);

let refreshBusy = false;
let readBusy = false;
let active = true;
let lastReadSequence = 0;

function latestSequence() {
    return messages.value.at(-1)?.sequence || 0;
}

function nearBottom() {
    const element = messageList.value;
    return !element || element.scrollHeight - element.scrollTop - element.clientHeight < 90;
}

function scrollBottom() {
    if (messageList.value) messageList.value.scrollTop = messageList.value.scrollHeight;
}

async function markRead() {
    if (readBusy || latestSequence() <= lastReadSequence || !nearBottom()) return;
    readBusy = true;

    try {
        const sequence = latestSequence();
        await marketplaceApi.readChat(props.requestId, sequence);
        if (active) {
            lastReadSequence = sequence;
            pendingUnread.value = false;
            emit("read");
        }
    } catch (exception) {
        if (active) error.value = exception.message;
    } finally {
        readBusy = false;
    }
}

async function load() {
    loading.value = true;
    error.value = "";

    try {
        const result = await marketplaceApi.getChat(props.requestId);
        if (!active) return;
        messages.value = result.messages || [];
        hasOlder.value = result.hasMore;
        await nextTick();
        scrollBottom();
        await markRead();
    } catch (exception) {
        if (active) error.value = exception.message;
    } finally {
        loading.value = false;
    }
}

async function refresh() {
    if (!active || loading.value || refreshBusy) return;
    refreshBusy = true;

    try {
        const shouldScroll = nearBottom();
        const beforeRefresh = latestSequence();
        let after = beforeRefresh;
        let more = false;

        for (let page = 0; page < 4; page++) {
            const result = await marketplaceApi.getChat(
                props.requestId,
                after ? { after } : {},
            );
            if (!active) return;

            if (!after) {
                messages.value = result.messages || [];
                hasOlder.value = result.hasMore;
            } else {
                const existing = new Set(messages.value.map((item) => item.sequence));
                messages.value.push(...(result.messages || []).filter(
                    (item) => !existing.has(item.sequence),
                ));
            }

            more = Boolean(result.hasMore && after);
            const next = latestSequence();
            if (!more || next === after) break;
            after = next;
        }

        if (shouldScroll && !more && latestSequence() > lastReadSequence) {
            await nextTick();
            scrollBottom();
            await markRead();
        } else if (latestSequence() > beforeRefresh) {
            pendingUnread.value = true;
        }
    } catch (exception) {
        if (active) error.value = exception.message;
    } finally {
        refreshBusy = false;
    }
}

async function loadOlder() {
    if (!hasOlder.value || olderBusy.value || !messages.value.length) return;
    olderBusy.value = true;

    try {
        const element = messageList.value;
        const height = element?.scrollHeight || 0;
        const top = element?.scrollTop || 0;
        const result = await marketplaceApi.getChat(props.requestId, {
            before: messages.value[0].sequence,
        });
        if (!active) return;

        messages.value.unshift(...result.messages);
        hasOlder.value = result.hasMore;
        await nextTick();
        if (element) element.scrollTop = top + element.scrollHeight - height;
    } catch (exception) {
        error.value = exception.message;
    } finally {
        olderBusy.value = false;
    }
}

function selectFiles(event) {
    error.value = "";
    const selected = [...(event.target.files || [])];
    event.target.value = "";

    if (files.value.length + selected.length > MAX_FILES) {
        error.value = "Не более 10 файлов в одном сообщении";
        return;
    }

    if (selected.some((file) => file.size > MAX_FILE_SIZE)) {
        error.value = "Каждый файл должен быть не больше 30 МБ";
        return;
    }

    files.value.push(...selected);
}

async function send() {
    if (busy.value) return;
    const content = text.value.trim();
    if (!content && !files.value.length) return;

    busy.value = true;
    error.value = "";

    try {
        const message = await marketplaceApi.sendChatMessage(
            props.requestId,
            content,
            files.value,
        );
        if (!active) return;

        if (!messages.value.some((item) => item.sequence === message.sequence)) {
            messages.value.push(message);
            messages.value.sort((a, b) => a.sequence - b.sequence);
        }
        text.value = "";
        files.value = [];
        await nextTick();
        scrollBottom();
        await markRead();
    } catch (exception) {
        error.value = exception.message;
    } finally {
        busy.value = false;
    }
}

function onKeydown(event) {
    if (event.key === "Enter" && !event.shiftKey && !event.isComposing) {
        event.preventDefault();
        send();
    }
}

async function viewFile(file) {
    error.value = "";

    try {
        const blob = await marketplaceApi.downloadChatFile(file.id);
        if (!active) return;
        const url = URL.createObjectURL(blob);

        if (file.mimeType.startsWith("image/") || file.mimeType.startsWith("video/")) {
            closePreview();
            preview.value = { url, name: file.name, type: file.mimeType };
        } else {
            const link = document.createElement("a");
            link.href = url;
            link.download = file.name;
            link.click();
            window.setTimeout(() => URL.revokeObjectURL(url), 60_000);
        }
    } catch (exception) {
        error.value = exception.message;
    }
}

function closePreview() {
    if (preview.value) URL.revokeObjectURL(preview.value.url);
    preview.value = null;
}

function onScroll() {
    if (pendingUnread.value && nearBottom()) markRead();
}

function jumpToNew() {
    scrollBottom();
    markRead();
}

function formatTime(value) {
    return new Date(value).toLocaleString("ru-RU", {
        day: "2-digit", month: "2-digit", hour: "2-digit", minute: "2-digit",
    });
}

function sizeMb(bytes) {
    return `${(bytes / 1024 / 1024).toLocaleString("ru-RU", { maximumFractionDigits: 1 })} МБ`;
}

function roleTitle(role) {
    return { customer: "Заказчик", station: "СТО", manager: "УК" }[role] || "Участник";
}

function escapeClose(event) {
    if (event.key === "Escape") {
        if (preview.value) closePreview();
        else emit("close");
    }
}

onMounted(() => {
    document.addEventListener("keydown", escapeClose);
    load();
});
onUnmounted(() => {
    active = false;
    document.removeEventListener("keydown", escapeClose);
    closePreview();
});

defineExpose({ refresh });
</script>

<template>
    <aside class="request-chat-drawer" role="dialog" aria-label="Чат заявки">
        <header class="chat-heading">
            <div>
                <strong>Чат заявки {{ requestNumber }}</strong>
                <small>Заказчик · СТО · УК</small>
            </div>
            <button type="button" aria-label="Закрыть чат" @click="emit('close')">×</button>
        </header>

        <div ref="messageList" class="chat-history" @scroll.passive="onScroll">
            <button
                v-if="hasOlder"
                class="chat-older"
                type="button"
                :disabled="olderBusy"
                @click="loadOlder"
            >
                {{ olderBusy ? "Загружаем…" : "Показать ранние сообщения" }}
            </button>
            <p v-if="loading" class="chat-empty">Загружаем переписку…</p>
            <p v-else-if="!messages.length" class="chat-empty">
                Чат создан. Напишите первое сообщение участникам заявки.
            </p>
            <article
                v-for="message in messages"
                :key="message.id"
                class="chat-message"
                :class="{ mine: message.sender.id === user.systemId }"
            >
                <div class="chat-author">
                    <strong>{{ message.sender.name }}</strong>
                    <span class="chat-role" :title="roleTitle(message.sender.role)">{{ message.sender.roleMark }}</span>
                </div>
                <div class="chat-bubble">
                    <p v-if="message.text">{{ message.text }}</p>
                    <button
                        v-for="file in message.files"
                        :key="file.id"
                        class="chat-file"
                        type="button"
                        :title="'Открыть ' + file.name"
                        @click="viewFile(file)"
                    >
                        <span aria-hidden="true">▣</span>
                        <span class="chat-file-name">{{ file.name }}</span>
                        <small>{{ sizeMb(file.size) }}</small>
                    </button>
                    <time :datetime="message.createdAt">{{ formatTime(message.createdAt) }}</time>
                </div>
            </article>
        </div>

        <button v-if="pendingUnread" class="chat-new" type="button" @click="jumpToNew">
            Новые сообщения ↓
        </button>

        <div class="chat-compose">
            <p v-if="error" class="error-message" role="alert">{{ error }}</p>
            <div v-if="files.length" class="chat-pending">
                <span v-for="(file, index) in files" :key="index">
                    {{ file.name }}
                    <button type="button" :aria-label="'Убрать ' + file.name" @click="files.splice(index, 1)">×</button>
                </span>
            </div>
            <form @submit.prevent="send">
                <label class="chat-attach" title="Прикрепить файл до 30 МБ">
                    <span aria-hidden="true">＋</span>
                    <span class="sr-only">Прикрепить файл</span>
                    <input type="file" multiple :disabled="busy" @change="selectFiles" />
                </label>
                <textarea
                    v-model="text"
                    aria-label="Сообщение участникам заявки"
                    placeholder="Напишите сообщение…"
                    rows="2"
                    maxlength="5000"
                    :disabled="busy"
                    @keydown="onKeydown"
                />
                <button class="chat-send" type="submit" :disabled="busy || (!text.trim() && !files.length)">
                    {{ busy ? "…" : "➤" }}
                    <span class="sr-only">Отправить</span>
                </button>
            </form>
            <small>Фото, видео и документы — до 30 МБ на файл. Отправленное нельзя изменить или удалить.</small>
        </div>

        <div v-if="preview" class="chat-preview" role="dialog" :aria-label="preview.name">
            <div class="chat-preview-head">
                <strong>{{ preview.name }}</strong>
                <button type="button" aria-label="Закрыть просмотр" @click="closePreview">×</button>
            </div>
            <img v-if="preview.type.startsWith('image/')" :src="preview.url" :alt="preview.name" />
            <video v-else :src="preview.url" controls preload="metadata" />
        </div>
    </aside>
</template>

<style scoped>
.request-chat-drawer{position:fixed;top:56px;right:0;bottom:0;z-index:30;width:min(440px,100vw);background:#fff;border-left:1px solid #c9dce5;box-shadow:-14px 0 36px #14395034;display:flex;flex-direction:column;color:var(--ink)}
.chat-heading{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:15px 18px;border-bottom:1px solid var(--line)}
.chat-heading strong{display:block;font-size:16px}.chat-heading small{display:block;margin-top:3px;color:var(--muted);font-size:12px}.chat-heading button,.chat-preview-head button{border:0;font-size:25px;line-height:1;padding:3px 8px}
.chat-history{min-height:0;flex:1;overflow-y:auto;background:linear-gradient(140deg,#f5fafb,#edf5f7);padding:17px;display:flex;flex-direction:column;gap:13px}
.chat-empty{margin:auto;text-align:center;color:var(--muted);font-size:14px;max-width:260px}.chat-older{align-self:center;color:var(--brand-teal-dark);background:#fff;font-size:13px;border-radius:20px;padding:6px 12px}
.chat-message{align-self:flex-start;max-width:88%;min-width:130px}.chat-message.mine{align-self:flex-end}.chat-author{display:flex;align-items:center;gap:5px;margin:0 8px 4px;color:#136e80;font-size:12px;overflow-wrap:anywhere}.mine .chat-author{justify-content:flex-end;color:var(--brand-navy)}
.chat-role{display:inline-grid;place-items:center;min-width:19px;height:19px;padding:0 3px;border-radius:5px;background:#d4e9ed;color:#0a6977;font-size:11px;font-weight:800}.chat-bubble{padding:10px 12px;background:#fff;border:1px solid #d8e7eb;border-radius:12px 12px 12px 3px;box-shadow:0 2px 8px #163b4d0b;overflow-wrap:anywhere}.mine .chat-bubble{background:#def3f4;border-color:#bce5e7;border-radius:12px 12px 3px 12px}.chat-bubble p{margin:0;white-space:pre-wrap;font-size:14px;line-height:1.45}.chat-bubble time{display:block;text-align:right;color:#758e9e;font-size:11px;margin-top:5px}
.chat-file{display:flex;align-items:center;gap:8px;width:100%;min-width:0;margin-top:8px;padding:8px;text-align:left;background:#f6fbfc;border-color:#d5e6e9;border-radius:7px;color:#145d76}.chat-file-name{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px}.chat-file small{color:#758e9e;white-space:nowrap;font-size:11px}
.chat-new{align-self:center;margin:-36px 0 6px;z-index:1;border-radius:18px;background:var(--brand-navy);color:#fff;box-shadow:0 4px 14px #173f5a46;font-size:13px}.chat-compose{padding:11px 14px;border-top:1px solid var(--line);background:#fff}.chat-compose form{display:flex;gap:7px;align-items:flex-end}.chat-compose textarea{min-width:0;flex:1;min-height:40px;max-height:110px;resize:vertical;padding:9px 10px;font-size:14px;line-height:1.3}.chat-compose > small{display:block;color:var(--muted);font-size:11px;line-height:1.4;margin:7px 2px 0}.chat-attach{width:39px;height:39px;display:grid;place-items:center;border:1px solid var(--line);border-radius:7px;cursor:pointer;font-size:24px;color:var(--brand-teal-dark)}.chat-attach:focus-within{outline:3px solid var(--blue-focus);outline-offset:2px}.chat-attach input{position:absolute;width:1px;height:1px;opacity:0;overflow:hidden}.chat-send{height:39px;min-width:39px;padding:7px;background:var(--brand-navy);border-color:var(--brand-navy);color:#fff}.chat-pending{display:flex;gap:5px;overflow-x:auto;margin-bottom:7px}.chat-pending span{display:flex;align-items:center;gap:4px;max-width:180px;overflow:hidden;white-space:nowrap;padding:3px 7px;border:1px solid #cce1e6;border-radius:6px;background:#f4fafa;font-size:12px}.chat-pending button{border:0;background:transparent;padding:0 3px}.chat-compose .error-message{font-size:13px;margin:0 0 7px}
.chat-preview{position:absolute;inset:0;background:#102c3af0;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:16px;z-index:2;color:#fff}.chat-preview-head{width:100%;display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:12px}.chat-preview-head strong{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:14px}.chat-preview-head button{background:#fff}.chat-preview img,.chat-preview video{max-width:100%;max-height:calc(100% - 60px);object-fit:contain}.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
@media(max-width:759px){.request-chat-drawer{width:calc(100vw - 16px);border-radius:12px 0 0 0}.chat-history{padding:12px}.chat-compose{padding:9px}}
</style>
