<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref } from 'vue';
type InstallPrompt = Event & {prompt:()=>Promise<void>;userChoice:Promise<{outcome:string}>};
const prompt=ref<InstallPrompt|null>(null);
const installed=ref(false);
const help=ref(false);
const ios=ref(false);
const error=ref('');
const capture=(event:Event)=>{event.preventDefault();prompt.value=event as InstallPrompt;};
const complete=()=>{installed.value=true;prompt.value=null;};
let display:MediaQueryList|null=null;
const update=()=>{installed.value=Boolean(display?.matches||(navigator as Navigator & {standalone?:boolean}).standalone);};
onMounted(()=>{ios.value=/iPad|iPhone|iPod/.test(navigator.userAgent)||(navigator.platform==='MacIntel'&&navigator.maxTouchPoints>1);display=matchMedia('(display-mode: standalone)');update();display.addEventListener('change',update);window.addEventListener('beforeinstallprompt',capture);window.addEventListener('appinstalled',complete);});
onBeforeUnmount(()=>{display?.removeEventListener('change',update);window.removeEventListener('beforeinstallprompt',capture);window.removeEventListener('appinstalled',complete);});
async function install(){
 error.value='';
 if(!prompt.value){help.value=!help.value;return;}
 try{await prompt.value.prompt();await prompt.value.userChoice;prompt.value=null;}catch{error.value='Use your browser menu to add SpendTrace to your home screen.';}
}
</script>
<template><div v-if="!installed" class="install-app"><button type="button" class="text-button" @click="install">Install web dashboard</button><p v-if="help" role="status" class="small">{{ ios ? 'Open SpendTrace in Safari, tap Share, then Add to Home Screen. Enable Open as Web App if shown, then tap Add.' : 'Open your browser menu and choose Install app or Add to Home screen. If that option is unavailable, open this site in Chrome or Edge.' }}</p><p v-if="error" role="alert" class="small">{{ error }}</p></div></template>
<style scoped>.install-app{margin:12px 0}.install-app p{max-width:55ch;line-height:1.6}</style>
