<template>
  <section class="mt-6 rounded-xl border glass p-4 md:p-5">
    <BaseCheckbox
      :model-value="payment !== null"
      :label="$t('invoices.payment_qr.enable')"
      :description="$t('invoices.payment_qr.help')"
      @update:model-value="toggle"
    />

    <div v-if="payment" class="mt-4 space-y-3">
      <BaseInputGrid>
        <BaseSelectInput
          v-if="accountOptions.length"
          :label="$t('invoices.payment_qr.saved_accounts')"
          :options="accountOptions"
          :model-value="selectedAccount"
          value-prop="id"
          @update:model-value="selectAccount"
        />
        <BaseInputGroup
          :label="$t('invoices.payment_qr.account')"
          :error="errors['payment_qr.iban']?.[0]"
          required
        >
          <BaseInput
            v-model="payment.iban"
            :placeholder="$t('invoices.payment_qr.account_placeholder')"
            dir="ltr"
            maxlength="64"
          />
        </BaseInputGroup>
        <BaseInputGroup
          :label="$t('invoices.payment_qr.variable_symbol')"
          :error="errors['payment_qr.variable_symbol']?.[0]"
        >
          <BaseInput
            v-model="variableSymbol"
            inputmode="numeric"
            maxlength="10"
            dir="ltr"
          />
          <button
            v-if="useDefaultSymbol && payment.variable_symbol === undefined && !defaultSymbol"
            type="button"
            class="mt-1 text-sm text-primary-600 hover:underline"
            @click="variableSymbol = ''"
          >
            {{ $t('invoices.payment_qr.leave_symbol_blank') }}
          </button>
        </BaseInputGroup>
      </BaseInputGrid>
      <BaseCheckbox
        v-if="payment.iban && !selectedAccount"
        v-model="payment.save_account"
        :label="$t('invoices.payment_qr.save_account')"
      />
      <p v-if="accountsUnavailable" class="text-sm text-muted" role="status">
        {{ $t('invoices.payment_qr.accounts_unavailable') }}
      </p>
    </div>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { invoiceService } from '@/scripts/api/services/invoice.service'
import type { InvoicePaymentQrInput } from '@/scripts/types/domain/invoice'

const props = defineProps<{
  errors: Record<string, string[]>
  useDefaultSymbol: boolean
  defaultSymbol: string
  defaultAccount: string
}>()

const payment = defineModel<InvoicePaymentQrInput | null>({ required: true })
const accounts = ref<string[]>([])
const accountsUnavailable = ref(false)
const accountOptions = computed(() => accounts.value.map((iban) => ({ id: iban, label: iban })))
const selectedAccount = computed(() =>
  payment.value && accounts.value.includes(payment.value.iban) ? payment.value.iban : '',
)
const variableSymbol = computed({
  get: () => props.useDefaultSymbol && payment.value?.variable_symbol === undefined
    ? props.defaultSymbol
    : payment.value?.variable_symbol ?? '',
  set: (value: string | number) => {
    if (payment.value) payment.value.variable_symbol = String(value) || null
  },
})

onMounted(async () => {
  try {
    accounts.value = await invoiceService.getBankAccounts()
  } catch {
    accountsUnavailable.value = true
  }
})

function toggle(enabled: boolean | unknown[]) {
  payment.value = enabled === true ? {
    iban: props.defaultAccount,
    save_account: false,
    ...(props.useDefaultSymbol ? {} : { variable_symbol: null }),
  } : null
}

function selectAccount(iban: unknown) {
  if (payment.value && typeof iban === 'string' && accounts.value.includes(iban)) {
    payment.value.iban = iban
    payment.value.save_account = false
  }
}
</script>
