<script setup>
import { ref } from 'vue';
import { marketplaceApi } from '../../api/marketplace.js';

const props = defineProps({
  initialLogin: {
    type: String,
    default: '',
  },
  lockedLogin: {
    type: Boolean,
    default: false,
  },
});

const emit = defineEmits(['login']);

const login = ref(props.initialLogin);
const password = ref('');
const showPassword = ref(false);
const error = ref('');
const busy = ref(false);
const takeoverRequired = ref(false);

async function submit(force = false) {
  error.value = '';
  busy.value = true;

  try {
    const user = await marketplaceApi.login(login.value, password.value, force);

    password.value = '';
    takeoverRequired.value = false;
    emit('login', user);
  } catch (e) {
    error.value = e.message;
    takeoverRequired.value = e.details?.error?.code === 'SESSION_ACTIVE';
  } finally {
    busy.value = false;
  }
}

function cancelTakeover() {
  takeoverRequired.value = false;
  error.value = '';
  password.value = '';
}

function togglePassword() {
  showPassword.value = !showPassword.value;
}
</script>

<template>
  <main class="login-page">
    <section class="login-intro">
      <div class="brand brand-login">
        <img
          class="brand-logo"
          src="/autoremhub-logo.png"
          width="1000"
          height="188"
          alt="AUTOREMHUB"
        />
      </div>

      <div class="login-title">
        <span class="eyebrow">Единая цифровая платформа</span>
        <h1>Каждому участнику —<br>свой кабинет.</h1>
        <p>
          Заявка объединяет Заказчика, СТО-партнёра и управляющую компанию
          на каждом этапе ремонта.
        </p>
        <div class="login-roles">
          <span>Заказчик</span>
          <span>СТО-партнёр</span>
          <span>УК</span>
        </div>
      </div>

      <p class="login-note">
        Платформа обслуживания автопарков · <span>autoremhub.ru</span>
      </p>
    </section>

    <section class="login-side">
      <form class="login-card" @submit.prevent="submit(false)">
        <span class="eyebrow">Вход в платформу</span>
        <h2>{{ lockedLogin ? 'Подтвердите вход' : 'Личный кабинет' }}</h2>
        <p class="muted">
          {{
            lockedLogin
              ? 'Сессия истекла. Введите пароль, чтобы продолжить работу с открытыми формами.'
              : 'Введите данные своей учётной записи.'
          }}
        </p>

        <label>
          Логин
          <input
            v-model="login"
            :readonly="lockedLogin"
            name="username"
            autocomplete="username"
            required
            placeholder="Введите логин"
            autofocus
          />
        </label>

        <label>
          Пароль
          <div class="password-field">
            <input
              v-model="password"
              name="password"
              :type="showPassword ? 'text' : 'password'"
              autocomplete="current-password"
              required
              placeholder="Введите пароль"
            />
            <button
              type="button"
              :aria-label="showPassword ? 'Скрыть пароль' : 'Показать пароль'"
              @click="togglePassword"
            >
              {{ showPassword ? 'Скрыть' : 'Показать' }}
            </button>
          </div>
        </label>

        <p v-if="error" role="alert" class="error-message">{{ error }}</p>

        <div v-if="takeoverRequired" class="session-choice">
          <p>
            Можно отменить вход либо завершить прежнюю сессию и продолжить на
            этом устройстве.
          </p>
          <div class="catalog-actions">
            <button type="button" class="ghost-btn" :disabled="busy" @click="cancelTakeover">
              Отмена
            </button>
            <button type="button" class="primary-btn" :disabled="busy" @click="submit(true)">
              Выйти на другом устройстве и войти
            </button>
          </div>
        </div>

        <button v-else class="primary-btn login-submit" :disabled="busy">
          {{ busy ? 'Входим…' : 'Войти в кабинет' }}
          <span>→</span>
        </button>
      </form>
    </section>
  </main>
</template>
