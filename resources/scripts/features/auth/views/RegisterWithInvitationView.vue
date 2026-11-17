<template>
  <!-- Loading -->
  <div v-if="isLoading" class="mt-12 text-center">
    <BaseSpinner class="w-8 h-8 text-primary-400 mx-auto" />
    <p class="text-muted mt-4 text-sm">{{ $t('invitation.loading') }}</p>
  </div>

  <!-- Invalid/Expired -->
  <div v-else-if="error" class="mt-12 text-center">
    <BaseIcon
      name="ExclamationCircleIcon"
      class="w-12 h-12 mx-auto text-red-400 mb-4"
    />
    <h2 class="text-lg font-semibold text-heading mb-2">
      {{ $t('invitation.invalid_title') }}
    </h2>
    <p class="text-sm text-muted mb-4">{{ $t(error) }}</p>
    <router-link
      to="/login"
      class="text-sm text-primary-600 hover:text-primary-700"
    >
      {{ $t('general.back_to_login') }}
    </router-link>
  </div>

  <!-- Registration Form -->
  <div v-else class="mt-12">
    <div class="mb-8">
      <h1 class="text-2xl font-semibold text-heading">
        {{ $t('invitation.create_account') }}
      </h1>
      <i18n-t keypath="invitation.invited_as" tag="p" class="text-sm text-muted mt-2">
        <template #company><strong class="text-heading">{{ invitationDetails.company_name }}</strong></template>
        <template #role><strong class="text-heading">{{ invitationDetails.role_name }}</strong></template>
      </i18n-t>
    </div>

    <form @submit.prevent="submitRegistration">
      <BaseInputGroup
        :label="$t('settings.user_profile.name')"
        :error="v$.name.$error && v$.name.$errors[0].$message"
        class="mb-4"
        required
      >
        <BaseInput
          v-model="form.name"
          autocomplete="name"
          :invalid="v$.name.$error"
          focus
          @input="v$.name.$touch()"
        />
      </BaseInputGroup>

      <BaseInputGroup :label="$t('settings.user_profile.email')" class="mb-4">
        <BaseInput
          v-model="form.email"
          autocomplete="email"
          type="email"
          disabled
        />
      </BaseInputGroup>

      <BaseInputGroup
        :label="$t('settings.user_profile.password')"
        :error="v$.password.$error && v$.password.$errors[0].$message"
        class="mb-4"
        required
      >
        <BaseInput
          v-model="form.password"
          autocomplete="new-password"
          type="password"
          revealable
          :invalid="v$.password.$error"
          @input="v$.password.$touch()"
        />
      </BaseInputGroup>

      <BaseInputGroup
        :label="$t('settings.user_profile.confirm_password')"
        :error="
          v$.password_confirmation.$error &&
          v$.password_confirmation.$errors[0].$message
        "
        class="mb-4"
        required
      >
        <BaseInput
          v-model="form.password_confirmation"
          autocomplete="new-password"
          type="password"
          revealable
          :invalid="v$.password_confirmation.$error"
          @input="v$.password_confirmation.$touch()"
        />
      </BaseInputGroup>

      <div class="mt-5 mb-8">
        <router-link
          to="/login"
          class="text-sm text-primary-600 hover:text-body"
        >
          {{ $t('invitation.already_registered') }}
        </router-link>
      </div>

      <BaseButton
        :loading="isSubmitting"
        :disabled="isSubmitting"
        type="submit"
        class="w-full justify-center"
      >
        {{ $t('invitation.create_and_join') }}
      </BaseButton>
    </form>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { helpers, required, minLength, sameAs } from '@vuelidate/validators'
import { useVuelidate } from '@vuelidate/core'
import { useI18n } from 'vue-i18n'
import { authService } from '../../../api/services/auth.service'

interface InvitationDetailsData {
  email: string
  company_name: string
  role_name: string
}

interface RegistrationForm {
  name: string
  email: string
  password: string
  password_confirmation: string
}

const route = useRoute()
const router = useRouter()
const { t } = useI18n()

const isLoading = ref<boolean>(true)
const isSubmitting = ref<boolean>(false)
const error = ref<string | null>(null)
const invitationDetails = ref<InvitationDetailsData>({
  email: '',
  company_name: '',
  role_name: '',
})

const form = reactive<RegistrationForm>({
  name: '',
  email: '',
  password: '',
  password_confirmation: '',
})

const rules = computed(() => ({
  name: {
    required: helpers.withMessage(t('validation.required'), required),
  },
  password: {
    required: helpers.withMessage(t('validation.required'), required),
    minLength: helpers.withMessage(t('validation.password_min_length', { count: 8 }), minLength(8)),
  },
  password_confirmation: {
    required: helpers.withMessage(t('validation.required'), required),
    sameAs: helpers.withMessage(t('validation.password_incorrect'), sameAs(form.password)),
  },
}))

const v$ = useVuelidate(
  rules,
  computed(() => form),
)

const token = computed<string>(() => route.query.invitation as string)

onMounted(async () => {
  if (!token.value) {
    error.value = 'invitation.missing_token'
    isLoading.value = false
    return
  }

  try {
    const details = await authService.getInvitationDetails(token.value) as unknown as InvitationDetailsData
    invitationDetails.value = {
      email: details.email,
      company_name: details.company_name,
      role_name: details.role_name,
    }
    form.email = details.email
  } catch {
    error.value = 'invitation.invalid_or_expired'
  } finally {
    isLoading.value = false
  }
})

async function submitRegistration(): Promise<void> {
  v$.value.$touch()
  if (v$.value.$invalid) return

  isSubmitting.value = true

  try {
    const response = await authService.registerWithInvitation({
      name: form.name,
      email: form.email,
      password: form.password,
      password_confirmation: form.password_confirmation,
      invitation_token: token.value,
    })

    // Save the auth token before navigating (matching old version's pattern)
    localStorage.setItem('auth.token', `Bearer ${response.token}`)

    router.push('/admin/dashboard')
  } catch (err: unknown) {
    const { handleApiError } = await import('../../../utils/error-handling')
    const { useNotificationStore } = await import('../../../stores/notification.store')
    const normalized = handleApiError(err)
    const notificationStore = useNotificationStore()
    notificationStore.showNotification({
      type: 'error',
      message: normalized.message,
    })
  } finally {
    isSubmitting.value = false
  }
}
</script>
