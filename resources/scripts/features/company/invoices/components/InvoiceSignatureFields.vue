<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { invoiceService } from '@/scripts/api/services/invoice.service'
import type { InvoiceSignature } from '@/scripts/types/domain/invoice'

defineProps<{ errors: Record<string, string[]> }>()
const signature = defineModel<InvoiceSignature | null>({ required: true })
const enabled = defineModel<boolean>('enabled', { required: true })
const emit = defineEmits<{ busy: [value: boolean] }>()
const { t } = useI18n()
const isLoading = ref(false)
const error = ref('')
const files = ref<Array<{ image: string; name: string; type: string }>>([])
const issuerName = computed({
  get: () => signature.value?.issuer_name ?? '',
  set: (value: string | number) => {
    signature.value = { issuer_name: String(value), image: signature.value?.image ?? null }
  },
})

watch(() => signature.value?.image, (image) => {
  files.value = image ? [{ image, name: '', type: 'image' }] : []
}, { immediate: true })

async function loadDefaults() {
  isLoading.value = true
  emit('busy', true)
  error.value = ''
  try {
    signature.value = await invoiceService.signatureDefaults()
  } catch {
    error.value = t('settings.customization.invoices.signature.load_failed')
  } finally {
    isLoading.value = false
    emit('busy', false)
  }
}

onMounted(() => {
  if (signature.value === null) void loadDefaults()
})

function changeImage(_name: string, file: FileList | File | string) {
  if (!(file instanceof File)) return
  if (!['image/png', 'image/jpeg'].includes(file.type) || file.size > 256 * 1024) {
    error.value = t('signature_image_invalid')
    files.value = signature.value?.image ? [{ image: signature.value.image, name: '', type: 'image' }] : []
    return
  }
  error.value = ''
  isLoading.value = true
  emit('busy', true)
  const reader = new FileReader()
  reader.onload = () => {
    signature.value = { issuer_name: issuerName.value, image: String(reader.result) }
  }
  reader.onerror = () => { error.value = t('signature_image_invalid') }
  reader.onloadend = () => {
    isLoading.value = false
    emit('busy', false)
  }
  reader.readAsDataURL(file)
}

function removeImage() {
  if (signature.value) signature.value.image = null
}
</script>

<template>
  <section class="mt-6 rounded-xl border glass p-4 md:p-5">
    <BaseCheckbox
      v-model="enabled"
      :label="$t('invoices.signature.enable')"
      :description="$t('invoices.signature.help')"
    />
    <div v-if="enabled" class="mt-4 space-y-3">
      <BaseInputGrid>
        <BaseInputGroup :label="$t('settings.customization.invoices.signature.issuer_name')" :error="errors['signature.issuer_name']?.[0]" required>
          <BaseInput v-model="issuerName" maxlength="255" :disabled="isLoading" />
        </BaseInputGroup>
        <BaseInputGroup :label="$t('settings.customization.invoices.signature.image')" :error="errors['signature.image']?.[0]">
          <BaseFileUploader v-model="files" accept="image/png,image/jpeg" :inert="isLoading" @change="changeImage" @remove="removeImage" />
          <p class="mt-2 text-xs text-muted">{{ $t('settings.customization.invoices.signature.upload_help') }}</p>
        </BaseInputGroup>
      </BaseInputGrid>
      <BaseButton size="sm" variant="primary-outline" type="button" :disabled="isLoading" @click="loadDefaults">
        {{ $t('invoices.signature.use_company_default') }}
      </BaseButton>
      <p v-if="error" class="text-sm text-danger" role="alert">{{ error }}</p>
    </div>
  </section>
</template>
