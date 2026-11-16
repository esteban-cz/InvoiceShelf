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
            v-model="payment.variable_symbol"
            inputmode="numeric"
            maxlength="10"
            dir="ltr"
          />
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

defineProps<{ errors: Record<string, string[]> }>()

const payment = defineModel<InvoicePaymentQrInput | null>({ required: true })
const accounts = ref<string[]>([])
const accountsUnavailable = ref(false)
const accountOptions = computed(() => accounts.value.map((iban) => ({ id: iban, label: iban })))
const selectedAccount = computed(() =>
  payment.value && accounts.value.includes(payment.value.iban) ? payment.value.iban : '',
)

onMounted(async () => {
  try {
    accounts.value = await invoiceService.getBankAccounts()
  } catch {
    accountsUnavailable.value = true
  }
})

function toggle(enabled: boolean | unknown[]) {
  payment.value = enabled === true ? { iban: '', variable_symbol: null, save_account: false } : null
}

function selectAccount(iban: unknown) {
  if (payment.value && typeof iban === 'string' && accounts.value.includes(iban)) {
    payment.value.iban = iban
    payment.value.save_account = false
  }
}
</script>
