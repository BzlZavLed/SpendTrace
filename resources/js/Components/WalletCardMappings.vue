<script setup lang="ts">
import type { Account } from '../types';
export type CardMapping = {card_key:string;account_id:number|null};
const mappings = defineModel<CardMapping[]>({required:true});
defineProps<{accounts:Account[]}>();
</script>
<template><fieldset class="wallet-mappings"><legend>Cards on this connection</legend><p class="small muted">Each card key routes to one of your accounts. All cards share the same endpoint and token. Keys are case-sensitive and must match the shortcut’s card_key exactly.</p><div v-for="(mapping,index) in mappings" :key="index" class="item-editor"><label>Card key<input v-model="mapping.card_key" placeholder="everyday-visa" maxlength="100" required/></label><label>SpendTrace account<select v-model="mapping.account_id" required><option :value="null" disabled>Select an account</option><option v-for="account in accounts" :key="account.id" :value="account.id">{{ account.nickname }}</option></select></label><button type="button" class="text-button" @click="mappings.splice(index,1)">Remove card mapping</button></div><button type="button" class="button secondary" :disabled="!accounts.length || mappings.length>=100" @click="mappings.push({card_key:'',account_id:null})">+ Add card</button></fieldset></template>
