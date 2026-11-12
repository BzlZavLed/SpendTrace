<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import type { Account } from '../types';
import Errors from './Errors.vue';
type Alias = { id:number; account_id:number; fingerprint:string; name_confirmed_at:string|null; identifier:{label:string;last_four?:string;network?:string} };
const props = defineProps<{connectionId:number; aliases:Alias[]; accounts:Account[]}>();
const form = useForm({label:'',account_id:null as number|null,name_unique:false});
const removal = useForm({});
function conflicting(alias:Alias) {
 return new Set(props.aliases.filter(a=>a.fingerprint===alias.fingerprint).map(a=>a.account_id)).size>1;
}
</script>
<template>
<section class="wallet-card">
 <h3>Wallet cards and accounts</h3>
 <p class="small muted">Map the exact card name supplied by your iPhone. Names appear as purchases arrive; you can also enter a name now. SpendTrace cannot import your full Wallet card list.</p>
 <Errors :errors="removal.errors"/>
 <p v-for="alias in aliases" :key="alias.id" class="small">
  <strong>{{ alias.identifier.label }}</strong> {{ alias.identifier.last_four ? `· ${alias.identifier.last_four}` : '' }} → {{ accounts.find(a=>a.id===alias.account_id)?.nickname }}
  <span v-if="conflicting(alias)" class="pill">Conflicting accounts — manual review</span>
  <span v-else-if="!alias.identifier.last_four && !alias.name_confirmed_at" class="pill">Name not confirmed — manual review</span>
  <button type="button" class="text-button" :disabled="removal.processing" @click="removal.delete(`/integrations/wallet/${connectionId}/aliases/${alias.id}`)">Remove mapping</button>
 </p>
 <form @submit.prevent="form.post(`/integrations/wallet/${connectionId}/aliases`,{preserveScroll:true,onSuccess:()=>form.reset()})">
  <Errors :errors="form.errors"/>
  <label>Wallet card name<input v-model="form.label" maxlength="100" required placeholder="Exact name shown by the Wallet transaction"/></label>
  <label>SpendTrace account<select v-model="form.account_id" required><option :value="null" disabled>Select your account</option><option v-for="account in accounts" :key="account.id" :value="account.id">{{ account.nickname }}</option></select></label>
  <label class="check"><input v-model="form.name_unique" type="checkbox" required/>This name identifies only one card on this connection. Use this account for future transactions with this name.</label>
  <p class="small muted">If two cards share a name, do not enable name-based routing. Use manual account assignment or separate card automations with distinct card keys. New or changed names need a new mapping. Removing mappings affects future events only.</p>
  <button class="button secondary" :disabled="form.processing || !accounts.length">Save card name mapping</button>
 </form>
</section>
</template>
