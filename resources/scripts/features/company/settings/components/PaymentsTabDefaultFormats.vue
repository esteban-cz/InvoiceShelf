<script setup lang="ts">
import { ref, reactive } from 'vue'
import { useCompanyStore } from '@/scripts/stores/company.store'

const companyStore = useCompanyStore()

const mailFields = ref(['customer', 'company', 'payment'])

const isSaving = ref(false)

const formatSettings = reactive({
  payment_mail_body:
    companyStore.selectedCompanySettings.payment_mail_body ?? '',
})

async function submitForm() {
  isSaving.value = true

  const data = {
    settings: {
      ...formatSettings,
    },
  }

  await companyStore.updateCompanySettings({
    data,
    message: 'settings.customization.payments.payment_settings_updated',
  })

  isSaving.value = false

  return true
}
</script>

<template>
  <form @submit.prevent="submitForm">
    <h3 class="text-heading text-lg font-medium">
      {{ $t('settings.customization.payments.default_formats') }}
    </h3>
    <p class="mt-1 text-sm text-muted mb-2">
      {{ $t('settings.customization.payments.default_formats_description') }}
    </p>

    <BaseInputGroup
      :label="$t('settings.customization.payments.default_payment_email_body')"
      class="mt-6 mb-4"
    >
      <BaseCustomInput
        v-model="formatSettings.payment_mail_body"
        :fields="mailFields"
      />
    </BaseInputGroup>

    <p class="my-4 text-sm text-muted">
      {{ $t('settings.customization.shared_address_formats') }}
    </p>

    <BaseButton
      :loading="isSaving"
      :disabled="isSaving"
      variant="primary"
      type="submit"
      class="mt-4"
    >
      <template #left="slotProps">
        <BaseIcon
          v-if="!isSaving"
          :class="slotProps.class"
          name="ArrowDownOnSquareIcon"
        />
      </template>
      {{ $t('settings.customization.save') }}
    </BaseButton>
  </form>
</template>
