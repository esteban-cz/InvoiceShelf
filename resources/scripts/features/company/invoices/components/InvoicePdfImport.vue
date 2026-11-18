<script setup lang="ts">
import { computed, nextTick, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { client } from '@/scripts/api/client'
import { useUserStore } from '@/scripts/stores/user.store'
import { useCompanyStore } from '@/scripts/stores/company.store'
import { useCustomerStore } from '../../customers/store'
import { useModalStore } from '@/scripts/stores/modal.store'
import { generateClientId } from '@/scripts/utils'
import { useInvoiceStore } from '../store'
import { prefillImportedInvoice, type InvoiceImportPreview } from '../import'
import type { AxiosError } from 'axios'

defineProps<{ disabled: boolean }>()
const { t } = useI18n()
const invoices = useInvoiceStore()
const customers = useCustomerStore()
const company = useCompanyStore()
const user = useUserStore()
const modal = useModalStore()
const file = ref<File | null>(null)
const fileInput = ref<HTMLInputElement | null>(null)
const preview = ref<InvoiceImportPreview | null>(null)
const loading = ref(false)
const applying = ref(false)
const applied = ref(false)
const error = ref('')
const importPanel = ref<HTMLElement | null>(null)
const totalMismatch = computed(
  () =>
    applied.value &&
    preview.value?.total !== null &&
    preview.value?.total !== undefined &&
    Math.abs(invoices.getTotal - preview.value.total) > 1,
)
const currencyMismatch = computed(
  () =>
    applied.value &&
    preview.value?.currency_code &&
    (invoices.newInvoice.selectedCurrency as { code?: string })?.code !==
      preview.value.currency_code,
)

function chooseFile(event: Event) {
  file.value = (event.target as HTMLInputElement).files?.[0] ?? null
  preview.value = null
  applied.value = false
  error.value = ''
}

async function extract() {
  if (!file.value || loading.value) return
  if (file.value.size > 10 * 1024 * 1024) {
    error.value = t('invoices.import_pdf.file_size')
    return
  }
  loading.value = true
  applied.value = false
  preview.value = null
  error.value = ''
  const payload = new FormData()
  payload.append('file', file.value)
  try {
    const response = await client.post<{ data: InvoiceImportPreview }>(
      '/api/v1/invoices/import/preview',
      payload,
      { timeout: 90000 },
    )
    preview.value = response.data.data
  } catch (exception) {
    const response = (
      exception as AxiosError<{
        message?: string
        errors?: { file?: string[] }
      }>
    ).response
    error.value =
      response?.data.errors?.file?.[0] ??
      response?.data.message ??
      t('invoices.import_pdf.failed')
  } finally {
    loading.value = false
  }
}

async function apply() {
  if (!preview.value || applying.value) return
  applying.value = true
  error.value = ''
  try {
    // Do not regenerate the historical number when a customer is chosen later.
    invoices.preserveImportedNumber = true
    if (preview.value.matched_customer_id) {
      await invoices.selectCustomer(preview.value.matched_customer_id)
    } else {
      invoices.resetSelectedCustomer()
    }
    prefillImportedInvoice(invoices.newInvoice, preview.value, generateClientId)
    applied.value = true
    await nextTick()
    importPanel.value?.focus({ preventScroll: true })
    importPanel.value?.scrollIntoView({ block: 'start' })
  } catch {
    error.value = t('invoices.import_pdf.apply_failed')
  } finally {
    applying.value = false
  }
}

function createCustomer() {
  if (!preview.value) return
  const party = preview.value.customer
  customers.resetCurrentCustomer()
  Object.assign(customers.currentCustomer, {
    name: party.name ?? '',
    company_name: party.name,
    registration_number: party.registration_number,
    tax_id: party.tax_id,
    email: party.email ?? '',
    phone: party.phone,
    currency_id:
      preview.value.currency?.id ?? company.selectedCompanyCurrency?.id ?? null,
  })
  Object.assign(customers.currentCustomer.billing, {
    name: party.name,
    phone: party.phone,
    address_street_1: party.address_street_1,
    address_street_2: party.address_street_2,
    city: party.city,
    zip: party.zip,
    country_id: party.country_id,
  })
  modal.openModal({
    componentName: 'CustomerModal',
    title: t('customers.add_customer'),
  })
}
</script>

<template>
  <section
    id="invoice-pdf-import-panel"
    ref="importPanel"
    aria-labelledby="invoice-pdf-import-title"
    tabindex="-1"
    class="mt-5 scroll-mt-24 rounded-xl border glass p-4 md:p-5"
  >
    <h2 id="invoice-pdf-import-title" class="font-semibold text-heading">
      {{ $t('invoices.import_pdf.button') }}
    </h2>
    <p class="mt-3 text-sm text-muted">{{ $t('invoices.import_pdf.help') }}</p>
    <div class="mt-4 flex flex-wrap items-end gap-3">
      <div class="min-w-0 flex-1">
        <label
          for="invoice-import-file"
          class="mb-2 block text-sm font-medium text-heading"
          >{{ $t('invoices.import_pdf.file') }}</label
        >
        <input
          id="invoice-import-file"
          ref="fileInput"
          type="file"
          accept="application/pdf,.pdf"
          class="sr-only"
          tabindex="-1"
          :disabled="disabled || loading || applying"
          @change="chooseFile"
        />
        <div class="flex flex-wrap items-center gap-3">
          <BaseButton
            type="button"
            variant="primary-outline"
            :disabled="disabled || loading || applying"
            @click="fileInput?.click()"
          >
            {{ $t('general.choose_file') }}
          </BaseButton>
          <span v-if="file" class="break-all text-sm text-body">{{
            file.name
          }}</span>
        </div>
      </div>
      <BaseButton
        type="button"
        variant="primary-outline"
        :loading="loading"
        :disabled="disabled || !file || loading || applying"
        @click="extract"
      >
        {{
          $t(
            loading
              ? 'invoices.import_pdf.extracting'
              : 'invoices.import_pdf.extract',
          )
        }}
      </BaseButton>
    </div>
    <p v-if="error" role="alert" class="mt-3 text-sm text-danger">
      {{ error }}
    </p>
    <div v-if="preview" class="mt-4 space-y-3" aria-live="polite">
      <dl class="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
        <div>
          <dt class="text-muted">{{ $t('invoices.invoice_number') }}</dt>
          <dd class="font-medium text-heading">
            {{ preview.invoice_number || '—' }}
          </dd>
        </div>
        <div>
          <dt class="text-muted">
            {{ $t('invoices.invoice_date') }} / {{ $t('invoices.due_date') }}
          </dt>
          <dd class="text-heading">
            {{ preview.invoice_date || '—' }} / {{ preview.due_date || '—' }}
          </dd>
        </div>
        <div>
          <dt class="text-muted">{{ $t('invoices.customer') }}</dt>
          <dd class="text-heading">
            {{ preview.customer.name || '—'
            }}<span v-if="preview.customer.registration_number">
              · {{ preview.customer.registration_number }}</span
            >
          </dd>
        </div>
        <div>
          <dt class="text-muted">
            {{ $t('invoices.import_pdf.source_total') }}
          </dt>
          <dd class="font-medium text-heading">
            <BaseFormatMoney
              v-if="preview.total !== null && preview.currency"
              :amount="preview.total"
              :currency="preview.currency"
            /><span v-else>—</span>
          </dd>
        </div>
      </dl>
      <ul
        v-if="preview.warnings.length"
        class="list-disc space-y-1 ps-5 text-sm text-body"
      >
        <li v-for="warning in preview.warnings" :key="warning">
          {{ $t(`invoices.import_pdf.warnings.${warning}`) }}
        </li>
      </ul>
      <BaseButton
        v-if="!applied"
        type="button"
        :loading="applying"
        :disabled="disabled || applying"
        @click="apply"
        >{{ $t('invoices.import_pdf.apply') }}</BaseButton
      >
      <template v-else>
        <p class="text-sm text-body">{{ $t('invoices.import_pdf.applied') }}</p>
        <p v-if="!invoices.newInvoice.customer_id" class="text-sm text-body">
          {{ $t('invoices.import_pdf.choose_customer') }}
        </p>
        <BaseButton
          v-if="
            !invoices.newInvoice.customer_id &&
            preview.customer.name &&
            user.hasAbilities('create-customer')
          "
          type="button"
          variant="primary-outline"
          size="sm"
          @click="createCustomer"
          >{{ $t('invoices.import_pdf.create_customer') }}</BaseButton
        >
        <p
          v-if="totalMismatch || currencyMismatch"
          role="alert"
          class="text-sm font-medium text-danger"
        >
          {{ $t('invoices.import_pdf.total_mismatch') }}
        </p>
      </template>
      <details class="text-sm">
        <summary class="cursor-pointer text-primary-500">
          {{ $t('invoices.import_pdf.source_text') }}
        </summary>
        <pre
          class="mt-2 max-h-64 overflow-auto whitespace-pre-wrap break-words rounded-lg bg-surface-secondary p-3 text-xs text-body"
          >{{ preview.source_text }}</pre
        >
      </details>
    </div>
  </section>
</template>
