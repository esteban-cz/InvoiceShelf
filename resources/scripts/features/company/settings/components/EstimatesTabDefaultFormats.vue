<script setup lang="ts">
import { ref, reactive } from 'vue'
import { useCompanyStore } from '@/scripts/stores/company.store'

const companyStore = useCompanyStore()

const estimateMailFields = ref(['customer', 'company', 'estimate'])

const isSaving = ref(false)

const formatSettings = reactive({
  estimate_mail_body:
    companyStore.selectedCompanySettings.estimate_mail_body ?? '',
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
    message: 'settings.customization.estimates.estimate_settings_updated',
  })

  isSaving.value = false

  return true
}
</script>

<template>
  <form @submit.prevent="submitForm">
    <h3 class="text-heading text-lg font-medium">
      {{ $t('settings.customization.estimates.default_formats') }}
    </h3>
    <p class="mt-1 text-sm text-muted mb-2">
      {{ $t('settings.customization.estimates.default_formats_description') }}
    </p>

    <BaseInputGroup
      :label="
        $t('settings.customization.estimates.default_estimate_email_body')
      "
      class="mt-6 mb-4"
    >
      <BaseCustomInput
        v-model="formatSettings.estimate_mail_body"
        :fields="estimateMailFields"
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
