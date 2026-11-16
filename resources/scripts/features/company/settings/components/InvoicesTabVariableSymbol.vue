<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { invoiceService } from '@/scripts/api/services/invoice.service'
import { useCompanyStore } from '@/scripts/stores/company.store'
import type { NextNumberResponse } from '@/scripts/types/api'
import { handleApiError } from '@/scripts/utils/error-handling'
import { invoiceVariableSymbolFormats, previewInvoiceVariableSymbol } from '@/scripts/utils/invoice-variable-symbol'

const { t } = useI18n()
const companyStore = useCompanyStore()
const format = ref(companyStore.selectedCompanySettings.invoice_variable_symbol_format || 'manual')
const isSaving = ref(false)
const nextInvoice = ref<NextNumberResponse | null>(null)
const error = ref('')
const loadingPreview = ref(true)
const options = computed(() => invoiceVariableSymbolFormats.map((id) => ({
  id,
  label: t('settings.customization.invoices.variable_symbol_format.' + id),
})))
const preview = computed(() => previewInvoiceVariableSymbol(
  format.value,
  nextInvoice.value?.nextNumber ?? '',
  String(new Date().getFullYear()) + '-01-01',
  nextInvoice.value?.nextSequenceNumber,
))

onMounted(async () => {
  try {
    nextInvoice.value = await invoiceService.getNextNumber({ include_sequence: true })
  } catch {
    nextInvoice.value = null
  } finally {
    loadingPreview.value = false
  }
})

async function save() {
  isSaving.value = true
  error.value = ''

  try {
    await companyStore.updateCompanySettings({
      data: { settings: { invoice_variable_symbol_format: format.value } },
      message: 'settings.customization.invoices.invoice_settings_updated',
    })
  } catch (err: unknown) {
    error.value = handleApiError(err).message
  } finally {
    isSaving.value = false
  }
}
</script>

<template>
  <form @submit.prevent="save">
    <BaseSettingCard
      :title="$t('settings.customization.invoices.variable_symbol_format.title')"
      :description="$t('settings.customization.invoices.variable_symbol_format.description')"
    >
      <BaseInputGrid class="mt-4">
        <BaseInputGroup
          :label="$t('settings.customization.invoices.variable_symbol_format.label')"
          :error="error"
        >
          <BaseSelectInput v-model="format" :options="options" value-prop="id" />
        </BaseInputGroup>
        <BaseInputGroup
          v-if="format !== 'manual'"
          :label="$t('settings.customization.invoices.variable_symbol_format.preview')"
        >
          <BaseInput :model-value="preview" :loading="loadingPreview" disabled />
          <p v-if="!loadingPreview && !preview" class="mt-1 text-xs text-muted">
            {{ $t('settings.customization.invoices.variable_symbol_format.no_preview') }}
          </p>
        </BaseInputGroup>
      </BaseInputGrid>
      <BaseButton :loading="isSaving" :disabled="isSaving" type="submit" class="mt-4">
        {{ $t('settings.customization.save') }}
      </BaseButton>
    </BaseSettingCard>
  </form>
</template>
