<template>
  <img
    v-if="logo && logo !== failedLogo"
    :key="logo"
    :src="logo"
    alt=""
    class="w-full h-full object-contain bg-surface"
    @error="failedLogo = logo"
  />
  <span v-else aria-hidden="true">{{ name?.trim().charAt(0).toUpperCase() }}</span>
</template>

<script setup lang="ts">
import { ref } from 'vue'

defineProps<{
  logo?: string | null
  name?: string
}>()

const failedLogo = ref<string | null>(null)
</script>
