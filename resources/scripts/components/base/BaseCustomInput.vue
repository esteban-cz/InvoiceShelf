<script setup lang="ts">
import { computed, ref, watch, onMounted, useId } from 'vue'
import type { Ref } from 'vue'
import { useI18n } from 'vue-i18n'

const { t, locale } = useI18n()

interface CustomFieldOption {
  label: string
  value: string
}

interface CustomFieldData {
  label: string
  value: string
  slug: string
  model_type: string
}

interface FieldGroup {
  label: string
  fields: CustomFieldOption[]
}

type FieldType =
  | 'shipping'
  | 'billing'
  | 'customer'
  | 'invoice'
  | 'estimate'
  | 'payment'
  | 'company'

interface Props {
  contentLoading?: boolean
  modelValue?: string
  fields?: FieldType[] | null
  customFields?: CustomFieldData[]
}

interface Emits {
  (e: 'update:modelValue', value: string): void
}

const props = withDefaults(defineProps<Props>(), {
  contentLoading: false,
  modelValue: '',
  fields: null,
  customFields: () => [],
})

const emit = defineEmits<Emits>()

const fieldList = ref<FieldGroup[]>([])
const invoiceFields = ref<CustomFieldData[]>([])
const estimateFields = ref<CustomFieldData[]>([])
const paymentFields = ref<CustomFieldData[]>([])
const customerFields = ref<CustomFieldData[]>([])

watch(
  () => props.fields,
  () => {
    if (props.fields && props.fields.length > 0) {
      getFields()
    }
  }
)

watch(
  () => props.customFields,
  (newValue: CustomFieldData[] | undefined) => {
    const data = newValue ?? []
    invoiceFields.value = data.filter((field) => field.model_type === 'Invoice')
    customerFields.value = data.filter(
      (field) => field.model_type === 'Customer'
    )
    paymentFields.value = data.filter(
      (field) => field.model_type === 'Payment'
    )
    estimateFields.value = data.filter(
      (field) => field.model_type === 'Estimate'
    )
    getFields()
  }
)

const value = computed<string>({
  get: () => props.modelValue,
  set: (val: string) => {
    emit('update:modelValue', val)
  },
})

function getFields(): void {
  fieldList.value = []
  if (!props.fields || props.fields.length === 0) return

  if (props.fields.includes('shipping')) {
    fieldList.value.push({
      label: t('insert_fields.shipping_address'),
      fields: [
        { label: t('insert_fields.address_name'), value: 'SHIPPING_ADDRESS_NAME' },
        { label: t('insert_fields.country'), value: 'SHIPPING_COUNTRY' },
        { label: t('insert_fields.state'), value: 'SHIPPING_STATE' },
        { label: t('insert_fields.city'), value: 'SHIPPING_CITY' },
        { label: t('insert_fields.address_street_1'), value: 'SHIPPING_ADDRESS_STREET_1' },
        { label: t('insert_fields.address_street_2'), value: 'SHIPPING_ADDRESS_STREET_2' },
        { label: t('insert_fields.phone'), value: 'SHIPPING_PHONE' },
        { label: t('insert_fields.zip_code'), value: 'SHIPPING_ZIP_CODE' },
      ],
    })
  }

  if (props.fields.includes('billing')) {
    fieldList.value.push({
      label: t('insert_fields.billing_address'),
      fields: [
        { label: t('insert_fields.address_name'), value: 'BILLING_ADDRESS_NAME' },
        { label: t('insert_fields.country'), value: 'BILLING_COUNTRY' },
        { label: t('insert_fields.state'), value: 'BILLING_STATE' },
        { label: t('insert_fields.city'), value: 'BILLING_CITY' },
        { label: t('insert_fields.address_street_1'), value: 'BILLING_ADDRESS_STREET_1' },
        { label: t('insert_fields.address_street_2'), value: 'BILLING_ADDRESS_STREET_2' },
        { label: t('insert_fields.phone'), value: 'BILLING_PHONE' },
        { label: t('insert_fields.zip_code'), value: 'BILLING_ZIP_CODE' },
      ],
    })
  }

  if (props.fields.includes('customer')) {
    fieldList.value.push({
      label: t('insert_fields.customer'),
      fields: [
        { label: t('insert_fields.display_name'), value: 'CONTACT_DISPLAY_NAME' },
        { label: t('insert_fields.contact_name'), value: 'PRIMARY_CONTACT_NAME' },
        { label: t('insert_fields.email'), value: 'CONTACT_EMAIL' },
        { label: t('insert_fields.phone'), value: 'CONTACT_PHONE' },
        { label: t('insert_fields.website'), value: 'CONTACT_WEBSITE' },
        { label: t('insert_fields.tax_id'), value: 'CONTACT_TAX_ID' },
        ...customerFields.value.map((i) => ({
          label: i.label,
          value: i.slug,
        })),
      ],
    })
  }

  if (props.fields.includes('invoice')) {
    fieldList.value.push({
      label: t('insert_fields.invoice'),
      fields: [
        { label: t('insert_fields.date'), value: 'INVOICE_DATE' },
        { label: t('insert_fields.due_date'), value: 'INVOICE_DUE_DATE' },
        { label: t('insert_fields.number'), value: 'INVOICE_NUMBER' },
        { label: t('insert_fields.ref_number'), value: 'INVOICE_REF_NUMBER' },
        { label: t('insert_fields.total'), value: 'INVOICE_TOTAL' },
        { label: t('insert_fields.amount_due'), value: 'INVOICE_DUE_AMOUNT' },
        { label: t('insert_fields.amount_paid'), value: 'INVOICE_PAID_AMOUNT' },
        { label: t('insert_fields.days_overdue'), value: 'INVOICE_DAYS_OVERDUE' },
        ...invoiceFields.value.map((i) => ({
          label: i.label,
          value: i.slug,
        })),
      ],
    })
  }

  if (props.fields.includes('estimate')) {
    fieldList.value.push({
      label: t('insert_fields.estimate'),
      fields: [
        { label: t('insert_fields.date'), value: 'ESTIMATE_DATE' },
        { label: t('insert_fields.expiry_date'), value: 'ESTIMATE_EXPIRY_DATE' },
        { label: t('insert_fields.number'), value: 'ESTIMATE_NUMBER' },
        { label: t('insert_fields.ref_number'), value: 'ESTIMATE_REF_NUMBER' },
        ...estimateFields.value.map((i) => ({
          label: i.label,
          value: i.slug,
        })),
      ],
    })
  }

  if (props.fields.includes('payment')) {
    fieldList.value.push({
      label: t('insert_fields.payment'),
      fields: [
        { label: t('insert_fields.date'), value: 'PAYMENT_DATE' },
        { label: t('insert_fields.number'), value: 'PAYMENT_NUMBER' },
        { label: t('insert_fields.mode'), value: 'PAYMENT_MODE' },
        { label: t('insert_fields.amount'), value: 'PAYMENT_AMOUNT' },
        ...paymentFields.value.map((i) => ({
          label: i.label,
          value: i.slug,
        })),
      ],
    })
  }

  if (props.fields.includes('company')) {
    fieldList.value.push({
      label: t('insert_fields.company'),
      fields: [
        { label: t('insert_fields.company_name'), value: 'COMPANY_NAME' },
        { label: t('insert_fields.country'), value: 'COMPANY_COUNTRY' },
        { label: t('insert_fields.state'), value: 'COMPANY_STATE' },
        { label: t('insert_fields.city'), value: 'COMPANY_CITY' },
        { label: t('insert_fields.address_street_1'), value: 'COMPANY_ADDRESS_STREET_1' },
        { label: t('insert_fields.address_street_2'), value: 'COMPANY_ADDRESS_STREET_2' },
        { label: t('insert_fields.phone'), value: 'COMPANY_PHONE' },
        { label: t('insert_fields.zip_code'), value: 'COMPANY_ZIP_CODE' },
        { label: t('insert_fields.vat_id'), value: 'COMPANY_VAT' },
        { label: t('insert_fields.tax_id'), value: 'COMPANY_TAX' },
      ],
    })
  }
}

watch(locale, getFields)
getFields()

const groupId = `insert-fields-${useId()}`
</script>

<template>
  <BaseContentPlaceholders v-if="contentLoading">
    <BaseContentPlaceholdersBox
      :rounded="true"
      class="w-full"
      style="height: 200px"
    />
  </BaseContentPlaceholders>

  <div v-else class="relative">
    <div class="absolute bottom-0 end-0 z-10">
      <BaseDropdown
        :close-on-select="true"
        max-height="220"
        position="top-end"
        width-class="w-auto min-w-[40rem]"
        class="mb-2"
      >
        <template #activator>
          <BaseButton tag="span" variant="primary-outline" class="me-4">
            {{ $t('settings.customization.insert_fields') }}
            <template #left="slotProps">
              <BaseIcon name="PlusSmIcon" :class="slotProps.class" />
            </template>
          </BaseButton>
        </template>

        <!-- Each field is a menu item, so the arrow keys reach it -->
        <div class="flex p-2">
          <div
            v-for="(type, index) in fieldList"
            :key="index"
            role="group"
            :aria-labelledby="`${groupId}-${index}`"
          >
            <p :id="`${groupId}-${index}`" class="mb-1 ms-2 text-xs font-semibold uppercase text-muted">
              {{ type.label }}
            </p>

            <BaseDropdownItem
              v-for="(field, fieldIndex) in type.fields"
              :key="fieldIndex"
              class="w-48 !py-1"
              @click="value += `{${field.value}}`"
            >
              <BaseIcon
                name="ChevronDoubleRightIcon"
                class="h-3 me-2 shrink-0 text-subtle"
              />
              {{ field.label }}
            </BaseDropdownItem>
          </div>
        </div>
      </BaseDropdown>
    </div>
    <BaseEditor v-model="value" />
  </div>
</template>
