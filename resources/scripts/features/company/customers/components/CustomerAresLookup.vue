<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  customerService,
  type AresCompany,
} from '@/scripts/api/services/customer.service'
import { handleApiError } from '@/scripts/utils/error-handling'
import { applyAresCompany } from '@/scripts/utils/ares'
import type { CustomerForm } from '../store'

const props = defineProps<{ customer: CustomerForm; disabled?: boolean }>()
const { t } = useI18n()
const loading = ref(false)
const error = ref('')
const result = ref<AresCompany | null>(null)
const ico = computed({
  get: () => props.customer.registration_number ?? '',
  set: (value: string | number) => {
    props.customer.registration_number = String(value)
  },
})
watch(ico, () => {
  result.value = null
  error.value = ''
})

async function lookup() {
  if (loading.value || props.disabled) return
  const requested = ico.value.replace(/\s/g, '')
  if (!/^\d{8}$/.test(requested)) {
    error.value = t('customers.ares.invalid_ico')
    return
  }
  loading.value = true
  error.value = ''
  result.value = null
  try {
    const company = await customerService.lookupAres(requested)
    if (ico.value.replace(/\s/g, '') === requested) result.value = company
  } catch (err) {
    const response = handleApiError(err)
    error.value = response.validationErrors.ico?.[0] ?? response.message
  } finally {
    loading.value = false
  }
}

function apply() {
  if (!result.value || props.disabled) return
  applyAresCompany(props.customer, result.value)
  result.value = null
}
</script>

<template>
  <section
    class="mb-6 rounded-xl border glass p-4"
    :aria-label="$t('customers.ares.title')"
  >
    <div class="flex flex-wrap items-end gap-3">
      <BaseInputGroup
        :label="$t('customers.ares.registration_number')"
        :error="error"
        class="w-full sm:max-w-xs"
      >
        <BaseInput
          v-model="ico"
          maxlength="32"
          :disabled="disabled || loading"
          :placeholder="$t('customers.ares.placeholder')"
        />
      </BaseInputGroup>
      <BaseButton
        type="button"
        variant="primary-outline"
        :loading="loading"
        :disabled="disabled || loading || !ico.trim()"
        @click="lookup"
      >
        {{ $t('customers.ares.lookup') }}
      </BaseButton>
    </div>
    <p class="mt-2 text-xs text-muted">{{ $t('customers.ares.help') }}</p>
    <div
      v-if="result"
      class="mt-4 space-y-2 text-sm text-body"
      aria-live="polite"
    >
      <p class="font-semibold text-heading">{{ result.name }}</p>
      <p>
        {{ result.billing.address_street_1 }}, {{ result.billing.zip }}
        {{ result.billing.city }}
      </p>
      <p v-if="result.tax_id">
        {{ $t('customers.tax_id') }}: {{ result.tax_id }}
      </p>
      <BaseButton type="button" size="sm" :disabled="disabled" @click="apply">{{
        $t('customers.ares.apply')
      }}</BaseButton>
    </div>
  </section>
</template>
