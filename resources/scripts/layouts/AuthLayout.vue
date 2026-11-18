<template>
  <div class="bg-glass-gradient relative min-h-screen w-full overflow-hidden">
    <NotificationRoot />

    <main
      class="
        relative flex min-h-screen flex-col items-center justify-center
        px-4 py-12 sm:px-6
      "
    >
      <!-- Logo above the card -->
      <div class="mb-8 flex justify-center">
        <MainLogo
          v-if="!loginPageLogo"
          class="w-auto h-11 text-heading"
        />
        <img
          v-else
          :src="loginPageLogo"
          alt="InvoiceShelf"
          class="h-12 w-auto"
        />
      </div>

      <!-- Auth card — same visual language as BaseCard -->
      <article
        :class="showHeading ? 'py-10 sm:py-12' : 'pt-0 pb-10 sm:pb-12'"
        class="
          w-full max-w-md
          bg-surface
          rounded-xl
          border border-line-default
          shadow-sm
          backdrop-blur-sm
          px-8 sm:px-10
        "
      >
        <header v-if="showHeading" class="text-center mb-8">
          <h1 class="text-2xl font-semibold text-heading">
            {{ heading }}
          </h1>
          <p class="mt-2 text-sm text-muted">
            {{ subheading }}
          </p>
        </header>

        <router-view />
      </article>

      <!-- Footer -->
      <footer class="mt-8 text-center text-xs text-subtle">
        <span v-if="copyrightText">{{ copyrightText }}</span>
        <PoweredBy v-else />
      </footer>
    </main>
  </div>
</template>

<script setup lang="ts">
import { computed, shallowRef } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import NotificationRoot from '@/scripts/components/notifications/NotificationRoot.vue'
import MainLogo from '@/scripts/components/icons/MainLogo.vue'
import PoweredBy from '@/scripts/components/layout/PoweredBy.vue'

declare global {
  interface Window {
    login_page_heading?: string
    login_page_description?: string
    copyright_text?: string
    login_page_logo?: string
  }
}

const route = useRoute()
const { t } = useI18n()

interface RouteCopy {
  heading: string
  subheading: string
}

const COPY: Record<string, RouteCopy> = {
  login: {
    heading: 'auth_pages.login.heading',
    subheading: 'auth_pages.login.description',
  },
  'forgot-password': {
    heading: 'auth_pages.forgot_password.heading',
    subheading: 'auth_pages.forgot_password.description',
  },
  'reset-password': {
    heading: 'auth_pages.reset_password.heading',
    subheading: 'auth_pages.reset_password.description',
  },
  'register-with-invitation': {
    heading: 'auth_pages.register.heading',
    subheading: 'auth_pages.register.description',
  },
}

const heading = computed<string>(() => {
  if (window.login_page_heading) return window.login_page_heading
  const name = route.name?.toString() ?? 'login'
  return t(COPY[name]?.heading ?? COPY.login.heading)
})

const subheading = computed<string>(() => {
  if (window.login_page_description) return window.login_page_description
  const name = route.name?.toString() ?? 'login'
  return t(COPY[name]?.subheading ?? COPY.login.subheading)
})

/**
 * Before a client is connected the card belongs to the connect and retry
 * screens, which carry their own headings. The generic "Welcome back" only
 * makes sense once there is a server to sign in to.
 *
 * The boot state is imported lazily so `client/state` stays out of the web
 * bundle, where the branch folds away entirely.
 */
const clientBoot = shallowRef<{ status: string } | null>(null)

if (__INVOICESHELF_CLIENT__) {
  void import('@/scripts/client/state').then((module) => {
    clientBoot.value = module.clientState
  })
}

const showHeading = computed<boolean>(() => {
  if (!__INVOICESHELF_CLIENT__) {
    return true
  }

  return clientBoot.value !== null && clientBoot.value.status === 'ready'
})

const copyrightText = computed<string | null>(() => window.copyright_text ?? null)

const loginPageLogo = computed<string | false>(() => {
  if (window.login_page_logo) return window.login_page_logo
  return false
})
</script>
