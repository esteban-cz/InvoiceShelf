<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { invoiceService } from '@/scripts/api/services/invoice.service'
import { useNotificationStore } from '@/scripts/stores/notification.store'
import { handleApiError } from '@/scripts/utils/error-handling'

const notificationStore = useNotificationStore()
const { t } = useI18n()
const accounts = ref<string[]>([])
const iban = ref('')
const fieldError = ref('')
const saveError = ref('')
const isLoading = ref(true)
const loadFailed = ref(false)
const isSaving = ref(false)

onMounted(async () => {
  try {
    accounts.value = await invoiceService.getBankAccounts()
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
    const account = await invoiceService.saveBankAccount(iban.value.trim())
    if (!accounts.value.includes(account)) {
      accounts.value = [...accounts.value, account].sort()
    }
    iban.value = ''
    notificationStore.showNotification({
      type: 'success',
      message: 'settings.payment_modes.receiving_accounts.saved',
    })
  } catch (err) {
    const error = handleApiError(err)
    fieldError.value = error.validationErrors.iban ? t('payment_qr_invalid_account') : ''
    if (!fieldError.value) saveError.value = error.message
  } finally {
    isSaving.value = false
  }
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
        :key="account"
        class="px-3 py-2 font-mono text-sm text-body"
      >
        <bdi>{{ account }}</bdi>
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
          v-model="iban"
          dir="ltr"
          maxlength="64"
          :placeholder="$t('invoices.payment_qr.account_placeholder')"
          :invalid="!!fieldError"
          :disabled="isSaving"
          @update:model-value="fieldError = ''"
        />
      </BaseInputGroup>
      <BaseButton
        type="submit"
        :loading="isSaving"
        :disabled="isSaving || isLoading || !iban.trim()"
        class="sm:mt-6"
      >
        {{ $t('settings.payment_modes.receiving_accounts.add') }}
      </BaseButton>
    </form>
    <p v-if="saveError" class="mt-3 text-sm text-danger" role="alert">{{ saveError }}</p>
  </BaseSettingCard>
</template>
