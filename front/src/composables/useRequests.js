import { computed, reactive, ref, watch } from 'vue'

const STORAGE_KEY = 'sto-marketplace-vue3-requests-v1'
const emptyFields = {
  company: '', vehicle: '', vin: '', plate: '', mileage: '', contact: '',
  reason: '', diagnostics: '', description: '', station: '', slot: '',
  stationSlot: '', deadline: '', routeComment: '',
}

const seedRequests = () => [{
  id: 'RP-0248', stage: 1, createdAt: '2026-09-06T10:42:00.000Z', updatedAt: '2026-09-06T10:44:00.000Z',
  company: 'ООО «СеверТранс»', vehicle: 'Jetour X70 Plus, 2026', vin: 'LVUDB21B2RA042817',
  plate: 'К123АА 178', mileage: '24 650', contact: 'Иванов Сергей · +7 921 555-18-20',
  reason: 'Техническое обслуживание', diagnostics: 'Диагностика ходовой части',
  description: 'Плановое ТО. Дополнительно проверить стук спереди справа при проезде неровностей.',
  station: 'A78-Сервис · Коломяжский пр., 10Щ', slot: '2026-09-08T11:00', stationSlot: '2026-09-08T13:00',
  deadline: 'До 09.09.2026, 18:00', routeComment: '',
  works: [
    { name: 'Техническое обслуживание', type: 'Работа', qty: 1, price: 7500 },
    { name: 'Диагностика ходовой части', type: 'Работа', qty: 1, price: 2500 },
    { name: 'Масло моторное 5W-30', type: 'Материал', qty: 6, price: 1250 },
  ],
  history: [
    { time: '06.09 · 10:42', who: 'Заказчик', text: 'Создана заявка RP-0248' },
    { time: '06.09 · 10:44', who: 'УК', text: 'Заявка принята на ручную проверку' },
  ],
}]

const load = () => {
  try {
    const saved = JSON.parse(localStorage.getItem(STORAGE_KEY))
    if (Array.isArray(saved) && saved.length) return saved
  } catch { /* начальные данные будут восстановлены */ }
  return seedRequests()
}

export function useRequests() {
  const requests = ref(load())
  const currentId = ref(requests.value[0].id)
  const current = computed(() => requests.value.find((item) => item.id === currentId.value))

  watch(requests, (value) => localStorage.setItem(STORAGE_KEY, JSON.stringify(value)), { deep: true, immediate: true })

  function nextId() {
    const max = requests.value.reduce((result, item) => Math.max(result, Number(item.id.replace(/\D/g, '')) || 0), 0)
    return `RP-${String(max + 1).padStart(4, '0')}`
  }

  function createRequest() {
    const timestamp = new Date().toISOString()
    const request = reactive({
      id: nextId(), stage: 0, createdAt: timestamp, updatedAt: timestamp,
      ...emptyFields, works: [],
      history: [{ time: formatDateTime(timestamp), who: 'Заказчик', text: 'Создан пустой черновик заявки' }],
    })
    requests.value.unshift(request)
    currentId.value = request.id
    return request
  }

  function openRequest(id) { currentId.value = id }
  function touch() { if (current.value) current.value.updatedAt = new Date().toISOString() }
  function addWork() { current.value.works.push({ name: '', type: 'Работа', qty: 1, price: 0 }); touch() }
  function removeWork(index) { current.value.works.splice(index, 1); touch() }
  function addHistory(who, text) {
    current.value.history.push({ time: new Date().toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' }), who, text })
    touch()
  }

  return { requests, currentId, current, createRequest, openRequest, touch, addWork, removeWork, addHistory }
}

export function formatDate(value) { return new Date(value).toLocaleDateString('ru-RU') }
export function formatDateTime(value) {
  return new Date(value).toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })
}
