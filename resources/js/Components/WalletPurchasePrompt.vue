<script setup lang="ts">
import { usePage, usePoll } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import type { Account } from '../types';
import WalletMappingDialog from './WalletMappingDialog.vue';
import WalletNameMappings, { type PendingWalletPurchase } from './WalletNameMappings.vue';
const page=usePage();
const attention=computed(()=>page.props.walletAttention as {events:PendingWalletPurchase[];accounts:Account[]}|null);
const userId=computed(()=>(page.props.auth as {user:{id:number}|null})?.user?.id);
const activeId=ref<number|null>(null);
const dismissed=ref<number[]>([]);
watch(userId,()=>{
 activeId.value=null;
 try{dismissed.value=JSON.parse(sessionStorage.getItem(`wallet-dismissed-${userId.value}`)||'[]');}catch{dismissed.value=[];}
},{immediate:true});
const current=computed(()=>attention.value?.events.find(event=>event.id===activeId.value));
usePoll(8000,{only:['walletAttention']});
watch(()=>attention.value?.events,events=>{
 if(current.value)return;
 activeId.value=events?.find(event=>!dismissed.value.includes(event.id))?.id??null;
},{immediate:true});
function later(){
 dismissed.value=[...new Set([...dismissed.value,...(attention.value?.events.map(event=>event.id)??[])])].slice(-500);
 try{sessionStorage.setItem(`wallet-dismissed-${userId.value}`,JSON.stringify(dismissed.value));}catch{/* Dismissal still works in memory when browser storage is unavailable. */}
 activeId.value=null;
}
function resolved(){activeId.value=null;}
</script>
<template>
<div>
 <button v-if="attention?.events.length && !current" type="button" class="button secondary" @click="activeId=attention.events[0].id">Wallet purchases need an account · {{ attention.events.length }}</button>
 <WalletMappingDialog v-if="current && attention" title="A Wallet card needs an account" @close="later">
  <WalletNameMappings :key="current.id" :connection-id="current.wallet_connection_id" :aliases="[]" :accounts="attention.accounts" :purchase="current" @resolved="resolved"/>
  <button type="button" class="text-button" @click="later">I'll do this later</button>
 </WalletMappingDialog>
</div>
</template>
