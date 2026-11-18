<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  fioService,
  type FioConnection,
  type FioConnectionInput,
  type FioTransaction,
  type FioInvoiceCandidate,
  type FioPaymentCandidate,
} from '@/scripts/api/services/fio.service'
import { usePaymentStore } from '@/scripts/features/company/payments/store'
import { handleApiError } from '@/scripts/utils/error-handling'

const { t, locale } = useI18n()
const paymentStore = usePaymentStore()
const connections = ref<FioConnection[]>([])
const transactions = ref<FioTransaction[]>([])
const status = ref('pending')
const page = ref(1)
const lastPage = ref(1)
const total = ref(0)
const loading = ref(true)
const busy = ref(false)
const error = ref('')
const success = ref('')
const showForm = ref(false)
const editing = ref<number>()
const disconnecting = ref<number>()
const form = reactive({
  name: '',
  token: '',
  sync_from: new Date().toLocaleDateString('en-CA', {
    timeZone: 'Europe/Prague',
  }),
  enabled: true,
  auto_match: true,
  payment_method_id: 'default',
})
const reviewing = ref<FioTransaction | null>(null)
const candidates = ref<FioInvoiceCandidate[]>([])
const existing = ref<FioPaymentCandidate[]>([])
const invoiceId = ref('')
const paymentId = ref('')
const exchangeRate = ref('')
const selectedInvoice = computed(() =>
  candidates.value.find((i) => i.id === Number(invoiceId.value)),
)
const methodOptions = computed(() => [
  { id: 'default', label: t('fio.company_default') },
  ...paymentStore.paymentModes.map((m) => ({
    id: String(m.id),
    label: m.name,
  })),
])
const statusOptions = computed(() =>
  ['pending', 'matched', 'ignored'].map((id) => ({
    id,
    label: t(`fio.statuses.${id}`),
  })),
)
const invoiceOptions = computed(() => [
  { id: '', label: t('fio.choose_invoice') },
  ...candidates.value.map((i) => ({
    id: String(i.id),
    label: `${i.number} · ${i.customer} · ${money(i.due_amount, i.currency_code)} · VS ${i.variable_symbol ?? '—'}`,
  })),
])
const paymentOptions = computed(() => [
  { id: '', label: t('fio.choose_payment') },
  ...existing.value.map((p) => ({
    id: String(p.id),
    label: `${p.number} · ${p.customer ?? ''} · ${p.date}`,
  })),
])

function money(amount: number, currency: string) {
  return new Intl.NumberFormat(locale.value, {
    style: 'currency',
    currency,
  }).format(amount / 100)
}
function failure(err: unknown) {
  const data = handleApiError(err)
  error.value = Object.values(data.validationErrors).flat()[0] ?? data.message
}
async function loadTransactions() {
  const result = await fioService.transactions(status.value, page.value)
  transactions.value = result.data
  lastPage.value = result.meta.last_page
  total.value = result.meta.total
  if (page.value > lastPage.value) {
    page.value = lastPage.value
    await loadTransactions()
  }
}
async function reload() {
  const [accounts] = await Promise.all([
    fioService.connections(),
    loadTransactions(),
  ])
  connections.value = accounts
}
onMounted(async () => {
  try {
    await Promise.all([
      reload(),
      paymentStore.fetchPaymentModes({ limit: 'all' }),
    ])
  } catch (err) {
    failure(err)
  } finally {
    loading.value = false
  }
})
function edit(connection?: FioConnection) {
  editing.value = connection?.id
  Object.assign(form, {
    name: connection?.name ?? '',
    token: '',
    sync_from:
      connection?.sync_from ??
      new Date().toLocaleDateString('en-CA', { timeZone: 'Europe/Prague' }),
    enabled: connection?.enabled ?? true,
    auto_match: connection?.auto_match ?? true,
    payment_method_id: connection?.payment_method_id
      ? String(connection.payment_method_id)
      : 'default',
  })
  showForm.value = true
  error.value = ''
  success.value = ''
}
function closeForm() {
  showForm.value = false
  form.token = ''
}
async function run(action: () => Promise<void>) {
  if (busy.value || loading.value) return
  busy.value = true
  error.value = ''
  success.value = ''
  try {
    await action()
  } catch (err) {
    failure(err)
  } finally {
    busy.value = false
  }
}
async function save() {
  await run(async () => {
    const input: FioConnectionInput = {
      name: form.name,
      enabled: form.enabled,
      auto_match: form.auto_match,
      payment_method_id:
        form.payment_method_id === 'default'
          ? null
          : Number(form.payment_method_id),
    }
    if (form.token.trim()) input.token = form.token.trim()
    if (!editing.value) input.sync_from = form.sync_from
    await fioService.save(input, editing.value)
    closeForm()
    await reload()
    success.value = t('fio.saved')
  })
}
async function sync(connection: FioConnection) {
  await run(async () => {
    try {
      await fioService.sync(connection.id)
      success.value = t('fio.synced')
    } finally {
      await reload()
    }
  })
}
async function disconnect(connection: FioConnection) {
  await run(async () => {
    await fioService.disconnect(connection.id)
    disconnecting.value = undefined
    await reload()
  })
}
async function filter() {
  page.value = 1
  reviewing.value = null
  await run(loadTransactions)
}
async function changePage(offset: number) {
  page.value += offset
  await run(loadTransactions)
}
async function review(row: FioTransaction) {
  await run(async () => {
    const result = await fioService.candidates(row.id)
    candidates.value = result.invoices
    existing.value = result.payments
    invoiceId.value = ''
    paymentId.value = ''
    exchangeRate.value = ''
    reviewing.value = row
  })
}
async function resolve(row: FioTransaction, action: string) {
  await run(async () => {
    await fioService.review(
      row.id,
      action,
      invoiceId.value ? Number(invoiceId.value) : undefined,
      paymentId.value ? Number(paymentId.value) : undefined,
      exchangeRate.value ? Number(exchangeRate.value) : undefined,
    )
    reviewing.value = null
    await reload()
    success.value = t('fio.resolved')
  })
}
</script>

<template>
  <BaseSettingCard
    :title="$t('fio.title')"
    :description="$t('fio.description')"
  >
    <template #action>
      <BaseButton
        type="button"
        variant="primary-outline"
        :disabled="loading || busy"
        @click="edit()"
        >{{ $t('fio.connect') }}</BaseButton
      >
    </template>
    <p class="mb-5 text-sm text-muted">{{ $t('fio.matching_help') }}</p>
    <p v-if="loading" role="status" class="text-sm text-muted">
      {{ $t('general.loading') }}
    </p>
    <div class="space-y-3">
      <div
        v-for="connection in connections"
        :key="connection.id"
        class="rounded-xl border border-line-light p-4"
      >
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <p class="font-semibold text-heading">
              {{ connection.name }} · {{ connection.currency_code }}
            </p>
            <p class="break-all font-mono text-xs text-muted">
              {{ connection.iban }}
            </p>
            <p class="mt-1 text-xs text-body">
              {{ connection.enabled ? $t('fio.active') : $t('fio.paused') }} ·
              {{
                connection.auto_match ? $t('fio.auto_on') : $t('fio.auto_off')
              }}
            </p>
            <p v-if="connection.last_synced_at" class="mt-1 text-xs text-muted">
              {{ $t('fio.last_sync') }}:
              {{ new Date(connection.last_synced_at).toLocaleString(locale) }} ·
              {{ $t('fio.through') }} {{ connection.imported_through }}
            </p>
            <p
              v-if="connection.last_error"
              class="mt-2 text-sm text-danger"
              role="status"
            >
              {{ $t(`fio.connection_errors.${connection.last_error}`) }}
            </p>
          </div>
          <div class="flex flex-wrap gap-2">
            <BaseButton
              type="button"
              size="sm"
              :disabled="busy || !connection.enabled"
              @click="sync(connection)"
              >{{ $t('fio.sync') }}</BaseButton
            >
            <BaseButton
              type="button"
              size="sm"
              variant="primary-outline"
              :disabled="busy"
              @click="edit(connection)"
              >{{ $t('general.edit') }}</BaseButton
            >
            <BaseButton
              type="button"
              size="sm"
              variant="primary-outline"
              :disabled="busy"
              @click="disconnecting = connection.id"
              >{{ $t('fio.disconnect') }}</BaseButton
            >
          </div>
        </div>
        <div
          v-if="disconnecting === connection.id"
          class="mt-4 space-y-2 text-sm text-body"
        >
          <p>{{ $t('fio.disconnect_help') }}</p>
          <BaseButton
            type="button"
            size="sm"
            :disabled="busy"
            @click="disconnect(connection)"
            >{{ $t('fio.confirm_disconnect') }}</BaseButton
          >
          <BaseButton
            type="button"
            size="sm"
            variant="primary-outline"
            class="ms-2"
            :disabled="busy"
            @click="disconnecting = undefined"
            >{{ $t('general.cancel') }}</BaseButton
          >
        </div>
      </div>
    </div>

    <form
      v-if="showForm"
      class="mt-5 space-y-4 rounded-xl border border-line-light p-4"
      @submit.prevent="save"
    >
      <p class="text-sm text-body">
        {{ $t('fio.token_help') }}
        <a
          href="https://www.fio.cz/bankovni-sluzby/api-bankovnictvi"
          target="_blank"
          rel="noopener noreferrer"
          class="text-primary-600 underline"
          >{{ $t('fio.instructions') }}</a
        >
      </p>
      <BaseInputGrid>
        <BaseInputGroup :label="$t('fio.connection_name')" required
          ><BaseInput
            v-model="form.name"
            maxlength="120"
            :disabled="busy"
            required
        /></BaseInputGroup>
        <BaseInputGroup :label="$t('fio.token')" :required="!editing">
          <BaseInput
            v-model="form.token"
            type="password"
            autocomplete="new-password"
            maxlength="64"
            :disabled="busy"
            :required="!editing"
          />
          <p v-if="editing" class="mt-1 text-xs text-muted">
            {{ $t('fio.keep_token') }}
          </p>
        </BaseInputGroup>
        <BaseInputGroup v-if="!editing" :label="$t('fio.sync_from')" required>
          <BaseInput
            v-model="form.sync_from"
            type="date"
            :disabled="busy"
            required
          />
          <p class="mt-1 text-xs text-muted">{{ $t('fio.history_help') }}</p>
        </BaseInputGroup>
        <BaseInputGroup :label="$t('fio.payment_method')"
          ><BaseSelectInput
            v-model="form.payment_method_id"
            :options="methodOptions"
            value-prop="id"
            :disabled="busy"
        /></BaseInputGroup>
      </BaseInputGrid>
      <BaseCheckbox
        v-model="form.enabled"
        :disabled="busy"
        :label="$t('fio.enabled')"
      />
      <BaseCheckbox
        v-model="form.auto_match"
        :disabled="busy"
        :label="$t('fio.auto_match')"
      />
      <div class="flex gap-2">
        <BaseButton type="submit" :disabled="busy" :loading="busy">{{
          $t('general.save')
        }}</BaseButton>
        <BaseButton
          type="button"
          variant="primary-outline"
          :disabled="busy"
          @click="closeForm"
          >{{ $t('general.cancel') }}</BaseButton
        >
      </div>
    </form>
    <p v-if="error && showForm" role="alert" class="mt-4 text-sm text-danger">
      {{ error }}
    </p>

    <div class="mt-7 border-t border-line-light pt-5">
      <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <h3 class="font-semibold text-heading">
          {{ $t('fio.transactions') }} ({{ total }})
        </h3>
        <BaseSelectInput
          v-model="status"
          :options="statusOptions"
          value-prop="id"
          :disabled="busy || loading"
          :aria-label="$t('fio.status_filter')"
          @update:model-value="filter"
        />
      </div>
      <p v-if="!transactions.length && !loading" class="text-sm text-muted">
        {{ $t('fio.empty') }}
      </p>
      <ul class="divide-y divide-line-light">
        <li v-for="row in transactions" :key="row.id" class="py-4 text-sm">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
              <p class="font-medium text-heading">
                {{
                  row.counterparty_name ||
                  row.counterparty_account ||
                  $t('fio.bank_transaction')
                }}
                · {{ money(row.amount, row.currency_code) }}
              </p>
              <p class="mt-1 text-xs text-muted">
                {{ row.transaction_date }} · VS
                {{ row.variable_symbol || '—' }} ·
                {{ row.counterparty_account }}
              </p>
              <p v-if="row.message" class="mt-1 break-words text-body">
                {{ row.message }}
              </p>
              <p class="mt-1 text-xs text-muted">
                {{ row.account_iban }} · {{ $t('fio.movement_id') }}
                {{ row.external_id }}
              </p>
              <p v-if="row.reason" class="mt-1 text-xs text-muted">
                {{ $t(`fio.reasons.${row.reason}`) }}
              </p>
              <RouterLink
                v-if="row.payment_id && row.payment_number"
                :to="`/admin/payments/${row.payment_id}/view`"
                class="mt-1 inline-block text-primary-600 underline"
                >{{ row.payment_number }}</RouterLink
              >
              <p v-else-if="row.payment_id" class="mt-1 text-xs text-muted">
                {{ $t('fio.payment_deleted') }}
              </p>
            </div>
            <div v-if="row.status === 'pending'" class="flex gap-2">
              <BaseButton
                type="button"
                size="sm"
                :disabled="busy || !row.connection_id"
                @click="review(row)"
                >{{ $t('fio.review') }}</BaseButton
              >
              <BaseButton
                type="button"
                size="sm"
                variant="primary-outline"
                :disabled="busy"
                @click="resolve(row, 'ignore')"
                >{{ $t('fio.ignore') }}</BaseButton
              >
            </div>
            <BaseButton
              v-if="row.status === 'ignored' && row.amount > 0"
              type="button"
              size="sm"
              variant="primary-outline"
              :disabled="busy"
              @click="resolve(row, 'restore')"
              >{{ $t('fio.restore') }}</BaseButton
            >
          </div>
          <div
            v-if="reviewing?.id === row.id"
            class="mt-4 space-y-4 rounded-xl border border-line-light p-4"
          >
            <p class="text-sm text-body">{{ $t('fio.review_help') }}</p>
            <BaseInputGroup :label="$t('fio.invoice')"
              ><BaseSelectInput
                v-model="invoiceId"
                :options="invoiceOptions"
                value-prop="id"
                :placeholder="$t('fio.choose_invoice')"
                :disabled="busy"
            /></BaseInputGroup>
            <BaseInputGroup
              v-if="selectedInvoice?.requires_exchange_rate"
              :label="$t('fio.exchange_rate')"
              required
              ><BaseInput
                v-model="exchangeRate"
                type="number"
                min="0.00000001"
                step="any"
                :disabled="busy"
            /></BaseInputGroup>
            <BaseButton
              type="button"
              size="sm"
              :disabled="
                busy ||
                !selectedInvoice ||
                (selectedInvoice.requires_exchange_rate &&
                  !(Number(exchangeRate) > 0))
              "
              @click="resolve(row, 'match')"
              >{{ $t('fio.record') }}</BaseButton
            >
            <div
              v-if="existing.length"
              class="space-y-2 border-t border-line-light pt-4"
            >
              <BaseInputGroup :label="$t('fio.existing_payment')"
                ><BaseSelectInput
                  v-model="paymentId"
                  :options="paymentOptions"
                  value-prop="id"
                  :disabled="busy"
              /></BaseInputGroup>
              <BaseButton
                type="button"
                size="sm"
                variant="primary-outline"
                :disabled="busy || !paymentId"
                @click="resolve(row, 'link')"
                >{{ $t('fio.link') }}</BaseButton
              >
            </div>
            <BaseButton
              type="button"
              size="sm"
              variant="primary-outline"
              :disabled="busy"
              class="ms-2"
              @click="reviewing = null"
              >{{ $t('general.cancel') }}</BaseButton
            >
          </div>
        </li>
      </ul>
      <div
        v-if="lastPage > 1"
        class="mt-4 flex items-center justify-end gap-3 text-sm text-muted"
      >
        <BaseButton
          type="button"
          size="sm"
          variant="primary-outline"
          :disabled="busy || page <= 1"
          :aria-label="$t('fio.previous_page')"
          @click="changePage(-1)"
          >←</BaseButton
        >
        <span>{{ page }} / {{ lastPage }}</span>
        <BaseButton
          type="button"
          size="sm"
          variant="primary-outline"
          :disabled="busy || page >= lastPage"
          :aria-label="$t('fio.next_page')"
          @click="changePage(1)"
          >→</BaseButton
        >
      </div>
    </div>
    <p v-if="error && !showForm" role="alert" class="mt-4 text-sm text-danger">
      {{ error }}
    </p>
    <p v-if="success" role="status" class="mt-4 text-sm text-status-green">
      {{ success }}
    </p>
  </BaseSettingCard>
</template>
