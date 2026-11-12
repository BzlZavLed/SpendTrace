<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref } from 'vue';
defineProps<{title:string}>();
const emit=defineEmits<{close:[]}>();
const dialog=ref<HTMLDialogElement|null>(null);
let previousFocus:HTMLElement|null=null;
onMounted(()=>{previousFocus=document.activeElement instanceof HTMLElement?document.activeElement:null;dialog.value?.showModal();});
onBeforeUnmount(()=>{dialog.value?.close();previousFocus?.focus();});
</script>
<template>
<Teleport to="body"><dialog ref="dialog" class="wallet-modal" aria-labelledby="wallet-modal-title" @cancel.prevent="emit('close')"><header><h2 id="wallet-modal-title">{{ title }}</h2><button type="button" class="button secondary" aria-label="Close card mapping" @click="emit('close')">Close</button></header><slot/></dialog></Teleport>
</template>
<style scoped>
.wallet-modal{border:0;border-radius:18px;padding:24px;width:min(820px,calc(100vw - 32px));max-height:85dvh;box-sizing:border-box;overflow:auto;color:inherit;background:#fff;box-shadow:0 24px 90px #0004}.wallet-modal::backdrop{background:#15251c88}.wallet-modal header{display:flex;align-items:center;justify-content:space-between;gap:16px}.wallet-modal h2{margin:0;font-size:22px}@media(max-width:580px){.wallet-modal{padding:16px}}
</style>
