<script setup lang="ts">
import { ref } from 'vue'
import { useUserStore } from '../../../../stores/user.store'
import { useNotificationStore } from '../../../../stores/notification.store'
import { useI18n } from 'vue-i18n'
import { handleApiError } from '@/scripts/utils/error-handling'

interface AvatarFile {
  image?: string
  name: string
  type: string
}

const userStore = useUserStore()
const notificationStore = useNotificationStore()
const { t } = useI18n()

const isSaving = ref<boolean>(false)
const isRemoving = ref(false)
const error = ref('')
const imgFiles = ref<AvatarFile[]>(savedAvatar())
const avatarFileBlob = ref<File | null>(null)

function savedAvatar(): AvatarFile[] {
  const avatar = userStore.currentUser?.avatar
  return typeof avatar === 'string' && avatar ? [{ image: avatar, name: '', type: 'image' }] : []
}

function onFileInputChange(_fileName: string, file: FileList | File | string): void {
  if (file instanceof File) {
    avatarFileBlob.value = file
    error.value = ''
  }
}

function onFileInputRemove(): void {
  if (avatarFileBlob.value) {
    avatarFileBlob.value = null
    imgFiles.value = isRemoving.value ? [] : savedAvatar()
  } else {
    isRemoving.value = savedAvatar().length > 0
  }
  error.value = ''
}

async function updateAvatar(): Promise<void> {
  if (isSaving.value || (!avatarFileBlob.value && !isRemoving.value)) return

  isSaving.value = true
  error.value = ''
  try {
    const formData = new FormData()
    if (avatarFileBlob.value) {
      formData.append('admin_avatar', avatarFileBlob.value)
    } else {
      formData.append('is_admin_avatar_removed', '1')
    }

    await userStore.uploadAvatar(formData)

    notificationStore.showNotification({
      type: 'success',
      message: 'settings.account_settings.updated_message',
    })

    avatarFileBlob.value = null
    isRemoving.value = false
    imgFiles.value = savedAvatar()
  } catch (err) {
    error.value = handleApiError(err).message
  } finally {
    isSaving.value = false
  }
}
</script>

<template>
  <form @submit.prevent="updateAvatar">
    <BaseSettingCard
      :title="$t('settings.account_settings.profile_picture')"
      :description="$t('settings.account_settings.profile_picture_description')"
    >
      <BaseInputGrid class="mt-5">
        <BaseInputGroup
          :label="$t('settings.account_settings.profile_picture')"
          :error="error"
        >
          <BaseFileUploader
            v-model="imgFiles"
            :avatar="true"
            :inert="isSaving"
            accept="image/*"
            @change="onFileInputChange"
            @remove="onFileInputRemove"
          />
        </BaseInputGroup>
      </BaseInputGrid>

      <BaseButton
        :loading="isSaving"
        :disabled="isSaving || (!avatarFileBlob && !isRemoving)"
        type="submit"
        class="mt-6"
      >
        <template #left="slotProps">
          <BaseIcon v-if="!isSaving" name="ArrowDownOnSquareIcon" :class="slotProps.class" />
        </template>
        {{ $t('settings.company_info.save') }}
      </BaseButton>
    </BaseSettingCard>
  </form>
</template>
