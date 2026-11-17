<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { companyService } from '@/scripts/api/services/company.service'
import type { CompanySignature } from '@/scripts/api/services/company.service'
import { useNotificationStore } from '@/scripts/stores/notification.store'
import { handleApiError } from '@/scripts/utils/error-handling'

const { t } = useI18n()
const notifications = useNotificationStore()
const issuerName = ref('')
const saved = ref<CompanySignature | null>(null)
const files = ref<Array<{ image: string; name: string; type: string }>>([])
const signatureFile = ref<File | null>(null)
const removeSignature = ref(false)
const isLoading = ref(true)
const isSaving = ref(false)
const loadFailed = ref(false)
const error = ref('')

function showSavedImage() {
  files.value = saved.value?.image ? [{ image: saved.value.image, name: '', type: 'image' }] : []
}

onMounted(async () => {
  try {
    saved.value = await companyService.getSignature()
    issuerName.value = saved.value.issuer_name
    showSavedImage()
  } catch {
    loadFailed.value = true
    error.value = t('settings.customization.invoices.signature.load_failed')
  } finally {
    isLoading.value = false
  }
})

function onChange(_name: string, file: FileList | File | string) {
  if (file instanceof File) {
    signatureFile.value = file
    removeSignature.value = false
    error.value = ''
  }
}

function onRemove() {
  if (signatureFile.value) {
    signatureFile.value = null
    showSavedImage()
  } else {
    removeSignature.value = true
    files.value = []
  }
}

async function save() {
  if (isSaving.value || isLoading.value || loadFailed.value || !issuerName.value.trim()) return
  isSaving.value = true
  error.value = ''
  try {
    const data = new FormData()
    data.append('issuer_name', issuerName.value.trim())
    data.append('remove_signature', removeSignature.value ? '1' : '0')
    if (signatureFile.value) data.append('signature', signatureFile.value)
    saved.value = await companyService.saveSignature(data)
    signatureFile.value = null
    removeSignature.value = false
    showSavedImage()
    notifications.showNotification({ type: 'success', message: 'settings.customization.invoices.signature.saved' })
  } catch (err) {
    const response = handleApiError(err)
    error.value = response.validationErrors.signature?.[0] ?? response.validationErrors.issuer_name?.[0] ?? response.message
  } finally {
    isSaving.value = false
  }
}
</script>

<template>
  <form @submit.prevent="save">
    <BaseSettingCard
      :title="$t('settings.customization.invoices.signature.title')"
      :description="$t('settings.customization.invoices.signature.description')"
    >
      <BaseInputGrid>
        <BaseInputGroup :label="$t('settings.customization.invoices.signature.issuer_name')" required>
          <BaseInput v-model="issuerName" maxlength="255" :disabled="isLoading || isSaving || loadFailed" />
        </BaseInputGroup>
        <BaseInputGroup :label="$t('settings.customization.invoices.signature.image')">
          <BaseFileUploader
            v-model="files"
            accept="image/png,image/jpeg"
            :inert="isLoading || isSaving || loadFailed"
            @change="onChange"
            @remove="onRemove"
          />
          <p class="mt-2 text-xs text-muted">{{ $t('settings.customization.invoices.signature.upload_help') }}</p>
        </BaseInputGroup>
      </BaseInputGrid>
      <p v-if="error" class="mt-3 text-sm text-danger" role="alert">{{ error }}</p>
      <BaseButton type="submit" class="mt-4" :loading="isSaving" :disabled="isLoading || isSaving || loadFailed || !issuerName.trim()">
        {{ $t('general.save') }}
      </BaseButton>
    </BaseSettingCard>
  </form>
</template>
