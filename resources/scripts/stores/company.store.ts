import { defineStore } from 'pinia'
import { ref } from 'vue'
import { bootstrapService } from '@/scripts/api/services/bootstrap.service'
import { companyService } from '@/scripts/api/services/company.service'
import type {
  UpdateCompanyPayload,
  CompanySettingsPayload,
  CreateCompanyPayload,
} from '@/scripts/api/services/company.service'
import { useNotificationStore } from './notification.store'
import { handleApiError } from '../utils/error-handling'
import * as localStore from '../utils/local-storage'
import type { Company } from '@/scripts/types/domain/company'
import type { Currency } from '@/scripts/types/domain/currency'
import type { ApiResponse } from '@/scripts/types/api'
import { emitCompanyChanged, emitCompanyChanging } from '@/scripts/extensions/runtime'

export const useCompanyStore = defineStore('company', () => {
  // State
  const companies = ref<Company[]>([])
  const selectedCompany = ref<Company | null>(null)
  const selectedCompanySettings = ref<Record<string, string>>({})
  const selectedCompanyCurrency = ref<Currency | null>(null)
  const isAdminMode = ref<boolean>(localStore.getBoolean('isAdminMode'))
  const defaultCurrency = ref<Currency | null>(null)

  // Actions
  function setSelectedCompany(data: Company | null): void {
    const previousCompanyId = selectedCompany.value?.id ?? null
    const companyId = data?.id ?? null
    const hasChanged = previousCompanyId !== companyId

    if (hasChanged) {
      emitCompanyChanging({ previousCompanyId, companyId })
    }

    if (data) {
      localStore.set('selectedCompany', data.id)
      localStore.remove('isAdminMode')
      isAdminMode.value = false
    } else {
      localStore.remove('selectedCompany')
    }
    selectedCompany.value = data

    if (hasChanged) {
      emitCompanyChanged({ previousCompanyId, companyId })
    }
  }

  function setAdminMode(enabled: boolean): void {
    const previousCompanyId = selectedCompany.value?.id ?? null
    if (enabled && previousCompanyId !== null) {
      emitCompanyChanging({ previousCompanyId, companyId: null })
    }

    isAdminMode.value = enabled
    if (enabled) {
      localStore.set('isAdminMode', true)
      localStore.remove('selectedCompany')
      selectedCompany.value = null
      if (previousCompanyId !== null) {
        emitCompanyChanged({ previousCompanyId, companyId: null })
      }
    } else {
      localStore.remove('isAdminMode')
    }
  }

  async function fetchBasicMailConfig(): Promise<Record<string, unknown>> {
    try {
      return await companyService.getMailDefaultConfig()
    } catch (err: unknown) {
      handleApiError(err)
      throw err
    }
  }

  async function updateCompany(data: UpdateCompanyPayload): Promise<ApiResponse<Company>> {
    try {
      const response = await companyService.update(data)

      const notificationStore = useNotificationStore()
      notificationStore.showNotification({
        type: 'success',
        message: 'settings.company_info.updated_message',
      })

      selectedCompany.value = response.data
      const companyIndex = companies.value.findIndex(
        (company) => company.unique_hash === response.data.unique_hash
      )
      if (companyIndex !== -1) {
        companies.value[companyIndex] = response.data
      }

      return response
    } catch (err: unknown) {
      handleApiError(err)
      throw err
    }
  }

  async function updateCompanyLogo(data: FormData): Promise<void> {
    try {
      const companyId = selectedCompany.value?.id
      await companyService.uploadLogo(data)
      if (selectedCompany.value?.id !== companyId) return

      const { data: company } = await bootstrapService.getCurrentCompany()
      const logos = { logo: company.logo, logo_path: company.logo_path }
      const membership = companies.value.find(({ id }) => id === company.id)
      if (membership) Object.assign(membership, logos)
      if (selectedCompany.value?.id === company.id) Object.assign(selectedCompany.value, logos)
    } catch (err: unknown) {
      handleApiError(err)
      throw err
    }
  }

  async function addNewCompany(data: CreateCompanyPayload): Promise<ApiResponse<Company>> {
    try {
      const response = await companyService.create(data)

      const notificationStore = useNotificationStore()
      notificationStore.showNotification({
        type: 'success',
        message: 'company_switcher.created_message',
      })

      return response
    } catch (err: unknown) {
      handleApiError(err)
      throw err
    }
  }

  async function fetchCompany(): Promise<Company> {
    try {
      const response = await companyService.listUserCompanies()
      return response.data[0]
    } catch (err: unknown) {
      handleApiError(err)
      throw err
    }
  }

  async function fetchUserCompanies(): Promise<ApiResponse<Company[]>> {
    try {
      return await companyService.listUserCompanies()
    } catch (err: unknown) {
      handleApiError(err)
      throw err
    }
  }

  async function fetchCompanySettings(settings?: string[]): Promise<Record<string, string>> {
    try {
      return await companyService.getSettings(settings)
    } catch (err: unknown) {
      handleApiError(err)
      throw err
    }
  }

  async function updateCompanySettings(params: {
    data: CompanySettingsPayload
    message?: string
  }): Promise<void> {
    try {
      await companyService.updateSettings(params.data)

      Object.assign(
        selectedCompanySettings.value,
        params.data.settings
      )

      if (params.message) {
        const notificationStore = useNotificationStore()
        notificationStore.showNotification({
          type: 'success',
          message: params.message,
        })
      }
    } catch (err: unknown) {
      handleApiError(err)
      throw err
    }
  }

  async function deleteCompany(data: { id: number }): Promise<void> {
    try {
      await companyService.delete(data)
    } catch (err: unknown) {
      handleApiError(err)
      throw err
    }
  }

  function setDefaultCurrency(data: { currency: Currency }): void {
    defaultCurrency.value = data.currency
  }

  return {
    companies,
    selectedCompany,
    selectedCompanySettings,
    selectedCompanyCurrency,
    isAdminMode,
    defaultCurrency,
    setSelectedCompany,
    setAdminMode,
    fetchBasicMailConfig,
    updateCompany,
    updateCompanyLogo,
    addNewCompany,
    fetchCompany,
    fetchUserCompanies,
    fetchCompanySettings,
    updateCompanySettings,
    deleteCompany,
    setDefaultCurrency,
  }
})
