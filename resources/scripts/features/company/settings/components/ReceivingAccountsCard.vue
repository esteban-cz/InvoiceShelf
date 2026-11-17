<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { invoiceService } from '@/scripts/api/services/invoice.service'
import { useNotificationStore } from '@/scripts/stores/notification.store'
import { handleApiError } from '@/scripts/utils/error-handling'
import type { InvoiceBankAccount } from '@/scripts/types/domain/invoice'
import { czechBankName, suggestedCzechBankName } from '@/scripts/utils/czech-banks'

const notificationStore = useNotificationStore()
const { t } = useI18n()
const accounts = ref<InvoiceBankAccount[]>([])
const iban = ref('')
const bankName = ref('')
const fieldError = ref('')
const saveError = ref('')
const isLoading = ref(true)
const loadFailed = ref(false)
const isSaving = ref(false)

onMounted(async () => {
  try {
    accounts.value = await invoiceService.getBankAccountDetails()
  } catch {
    loadFailed.value = true
  } finally {
    isLoading.value = false
  }
})

async function save(): Promise<void> {
  if (isSaving.value || isLoading.value || !iban.value.trim()) return

  isSaving.value = true
  fieldError.value = ''
  saveError.value = ''

  try {
    await invoiceService.saveBankAccount(iban.value.trim(), bankName.value.trim())
    accounts.value = await invoiceService.getBankAccountDetails()
    iban.value = ''
    bankName.value = ''
    notificationStore.showNotification({
      type: 'success',
      message: 'settings.payment_modes.receiving_accounts.saved',
    })
  } catch (err) {
    const error = handleApiError(err)
    fieldError.value = error.validationErrors.iban ? t('payment_qr_invalid_account') : error.validationErrors.bank_name?.[0] ?? ''
    if (!fieldError.value) saveError.value = error.message
  } finally {
    isSaving.value = false
  }
}

function edit(account: InvoiceBankAccount) {
  iban.value = account.iban
  bankName.value = account.bank_name || czechBankName(account.iban)
  fieldError.value = ''
  saveError.value = ''
}

function updateAccount(value: string | number) {
  const account = String(value)
  bankName.value = suggestedCzechBankName(account, iban.value, bankName.value)
  iban.value = account
  fieldError.value = ''
}
</script>

<template>
  <BaseSettingCard
    :title="$t('settings.payment_modes.receiving_accounts.title')"
    :description="$t('settings.payment_modes.receiving_accounts.description')"
  >
    <template #action>
      <RouterLink
        :to="{ name: 'settings.customization' }"
        class="text-sm font-medium text-primary-600 underline underline-offset-4"
      >
        {{ $t('settings.payment_modes.receiving_accounts.choose_default') }}
      </RouterLink>
    </template>

    <ul
      v-if="accounts.length"
      class="mb-5 divide-y divide-line-light rounded-lg border border-line-light"
    >
      <li
        v-for="account in accounts"
        :key="account.iban"
        class="flex items-start justify-between gap-3 px-3 py-3 text-sm text-body"
      >
        <div class="min-w-0">
          <p v-if="account.bank_name" class="font-medium text-heading">{{ account.bank_name }}</p>
          <p class="font-mono"><bdi>{{ account.account_number }}</bdi></p>
          <p class="mt-1 break-all font-mono text-xs text-muted">IBAN <bdi>{{ account.iban }}</bdi></p>
        </div>
        <button
          type="button"
          class="shrink-0 text-primary-600 hover:underline"
          :aria-label="$t('invoices.payment_qr.edit_account') + ' ' + account.account_number"
          :disabled="isSaving"
          @click="edit(account)"
        >
          {{ $t('invoices.payment_qr.edit_account') }}
        </button>
      </li>
    </ul>
    <p v-if="loadFailed" class="mb-4 text-sm text-muted" role="status">
      {{ $t('settings.customization.invoices.receiving_account.unavailable') }}
    </p>

    <form class="flex flex-col gap-3 sm:flex-row sm:items-start" @submit.prevent="save">
      <BaseInputGroup
        :label="$t('invoices.payment_qr.account')"
        :error="fieldError"
        class="w-full sm:max-w-md"
      >
        <BaseInput
          :model-value="iban"
          dir="ltr"
          maxlength="64"
          :placeholder="$t('invoices.payment_qr.account_placeholder')"
          :invalid="!!fieldError"
          :disabled="isSaving"
          @update:model-value="updateAccount"
        />
      </BaseInputGroup>
      <BaseInputGroup :label="$t('invoices.payment_qr.bank_name')" class="w-full sm:max-w-xs">
        <BaseInput v-model="bankName" maxlength="120" :disabled="isSaving" />
        <p class="mt-1 text-xs text-muted">{{ $t('invoices.payment_qr.bank_name_help') }}</p>
      </BaseInputGroup>
      <BaseButton
        type="submit"
        :loading="isSaving"
        :disabled="isSaving || isLoading || !iban.trim()"
        class="sm:mt-6"
      >
        {{ $t('general.save') }}
      </BaseButton>
    </form>
    <p v-if="saveError" class="mt-3 text-sm text-danger" role="alert">{{ saveError }}</p>
  </BaseSettingCard>
</template>
