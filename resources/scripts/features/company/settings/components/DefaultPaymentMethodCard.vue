<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useCompanyStore } from '@/scripts/stores/company.store'
import { usePaymentStore } from '@/scripts/features/company/payments/store'
import { handleApiError } from '@/scripts/utils/error-handling'

const { t } = useI18n()
const companyStore = useCompanyStore()
const paymentStore = usePaymentStore()
const selected = ref(String(companyStore.selectedCompanySettings.default_payment_method_id || 'none'))
const loading = ref(true)
const saving = ref(false)
const loadFailed = ref(false)
const error = ref('')
const options = computed(() => [
  { id: 'none', label: t('settings.payment_modes.no_default') },
  ...paymentStore.paymentModes.map(({ id, name }) => ({ id: String(id), label: name })),
])

onMounted(async () => {
  try {
    await paymentStore.fetchPaymentModes({ limit: 'all' })
    if (!options.value.some(({ id }) => id === selected.value)) selected.value = 'none'
  } catch (err) {
    loadFailed.value = true
    error.value = handleApiError(err).message
  } finally {
    loading.value = false
  }
})

async function save() {
  if (loading.value || saving.value || loadFailed.value) return
  saving.value = true
  error.value = ''
  try {
    await companyStore.updateCompanySettings({
      data: { settings: { default_payment_method_id: selected.value } },
      message: 'settings.payment_modes.default_saved',
    })
  } catch (err) {
    error.value = handleApiError(err).message
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <form @submit.prevent="save">
    <BaseSettingCard :title="$t('settings.payment_modes.default_method')" :description="$t('settings.payment_modes.default_method_help')">
      <BaseInputGroup :label="$t('settings.payment_modes.default_method')" class="max-w-md">
        <BaseSelectInput v-model="selected" :options="options" value-prop="id" :disabled="loading || saving || loadFailed" />
      </BaseInputGroup>
      <p v-if="error" class="mt-3 text-sm text-danger" role="alert">{{ error }}</p>
      <BaseButton type="submit" class="mt-4" :loading="saving" :disabled="loading || saving || loadFailed">
        {{ $t('general.save') }}
      </BaseButton>
    </BaseSettingCard>
  </form>
</template>
