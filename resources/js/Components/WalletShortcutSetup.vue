<script setup lang="ts">
import { Link, router, useForm, usePoll } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import type { Account } from '../types';
import Errors from './Errors.vue';
import WalletNameMappings from './WalletNameMappings.vue';
import WalletMappingDialog from './WalletMappingDialog.vue';
const mappingOpen=ref(false);
type Device={created_at:string;id:number;name:string;registration_method:string;revoked_at:string|null;last_test_at:string|null;last_event_at:string|null};
type Alias={id:number;account_id:number;fingerprint:string;name_confirmed_at:string|null;identifier:{label:string;last_four?:string;network?:string}};
type Connection={id:number;name:string;enabled:boolean;devices:Device[];aliases:Alias[];last_test_at:string|null;last_event_at:string|null};
type Observation={id:number;wallet_connection_id:number;currency:string|null;payload:{merchant:string;amount_decimal:string;wallet_alias:{label:string;last_four?:string;network?:string};observed_at:string}};
const props=defineProps<{shortcut:{name:string;version:string;available:boolean;connect_available:boolean;url:string|null;pairing_available:boolean;reason:string|null};connections:Connection[];accounts:Account[];unresolved:{data:Observation[];total:number;prev_page_url:string|null;next_page_url:string|null}}>();
usePoll(5000,{only:['connections','unresolved']});
const selected=ref<number|null>(props.connections.length===1 && props.connections[0].enabled?props.connections[0].id:null);const launchUrl=ref('');const expires=ref('');const error=ref('');const busy=ref(false);
function csrf(){const c=document.cookie.split('; ').find(v=>v.startsWith('XSRF-TOKEN='));return c?decodeURIComponent(c.substring(11)):'';}
async function issue(){
 if (!connection.value?.enabled || !props.shortcut.connect_available || busy.value) return;
 launchUrl.value='';expires.value='';error.value='';busy.value=true;
 try {
  const response=await fetch(`/integrations/wallet/${selected.value}/connect`,{method:'POST',headers:{Accept:'application/json','Content-Type':'application/json','X-XSRF-TOKEN':csrf()},body:'{}'});
  const data=await response.json();
  if(!response.ok)throw new Error(data.message||'Could not connect your shortcut. Please try again.');
  launchUrl.value=data.launch_url;expires.value=data.expires_at;
  window.location.assign(launchUrl.value);
 }catch(e){error.value=e instanceof Error?e.message:'Could not open Shortcuts. Please try again.';}finally{busy.value=false;}
}
const connection = computed(()=>props.connections.find(c=>c.id===selected.value));
const discoveredNames = computed(()=>props.unresolved.data.filter(event=>event.wallet_connection_id===connection.value?.id && !event.payload.wallet_alias.last_four && !event.payload.wallet_alias.network).map(event=>event.payload.wallet_alias.label));
const phones = computed(()=>connection.value?.devices.filter(d=>!d.revoked_at) ?? []);
const phoneTested = computed(()=>phones.value.some(d=>d.last_test_at));

const setup = useForm({name:'My Apple Wallet',enabled:true,default_currency:null,card_mappings:[]});
watch(()=>props.connections, connections=>{
 if(!connections.some(c=>c.id===selected.value)) selected.value=connections.length===1?connections[0].id:null;
},{immediate:true});
watch(selected,()=>{launchUrl.value='';expires.value='';error.value='';});
function begin(){setup.post('/integrations/wallet',{preserveScroll:true});}
const resolving=ref<number|null>(null);
const assignment=useForm({account_id:null as number|null,remember:false,confirmed:false,name_unique:false});
function choose(event:Observation){resolving.value=event.id;assignment.reset();assignment.clearErrors();}
</script>
<template>
<section class="edit-panel wallet-card">
 <div class="section-heading"><div><p class="eyebrow">GET STARTED</p><h2>One shortcut for your Wallet cards</h2><p class="muted">Set up your iPhone once. Add receipts whenever you need them.</p></div><span class="pill">{{ phoneTested ? 'iPhone test received' : 'Setup in progress' }}</span></div>
 <p v-if="!shortcut.available" class="review-notice" role="status">The installable shortcut is still being prepared. You can review the steps below; setup will be available when it is ready.</p>
 <ol class="setup-steps">
  <li><h3>Install the shortcut</h3><p>On your iPhone, add <strong>{{ shortcut.name }}</strong> to the Shortcuts app.</p><a v-if="shortcut.available && shortcut.url" :href="shortcut.url" class="button primary" target="_blank" rel="noopener noreferrer">Install shortcut ↗</a><button v-else class="button secondary" disabled>Shortcut coming soon</button></li>
  <li><h3>Connect your iPhone</h3><p>On this iPhone, tap Connect Shortcut after installing it. Shortcuts will save the connection for you—no setup text to copy.</p>
   <Errors :errors="setup.errors"/>
   <button v-if="!connections.length" class="button primary" :disabled="setup.processing || !shortcut.available" @click="begin">Set up my Wallet</button>
   <label v-if="connections.length>1">Choose your existing Wallet<select v-model="selected"><option :value="null" disabled>Choose a Wallet</option><option v-for="c in connections" :key="c.id" :value="c.id">{{ c.name }}</option></select></label>
   <p v-if="connection && !connection.enabled" class="review-notice">This Wallet is paused. Enable it in Advanced settings to continue.</p>
   <p v-if="phones.length" class="notice">A shortcut is connected to your account. Continue with the card setup below.</p>
   <template v-if="connection && !phones.length"><button class="button primary" :disabled="busy || !connection.enabled || !shortcut.connect_available" @click="issue()">Connect Shortcut</button></template>
   <p v-if="!shortcut.pairing_available" class="small muted">Phone setup is temporarily unavailable. Please try again later.</p>
   <p v-if="error" role="alert" class="review-notice">{{ error }}</p>
   <div v-if="launchUrl" class="notice"><p>Finish connecting in Shortcuts, then return here. If Shortcuts did not open, tap below.</p><a :href="launchUrl" class="button primary" referrerpolicy="no-referrer">Open Shortcuts</a><p class="small">This connection link expires at {{ new Date(expires).toLocaleTimeString() }}. If it expires, tap Connect Shortcut again.</p></div>
   <p v-if="shortcut.available && !shortcut.connect_available" class="small muted">One-tap connection is awaiting validation for this shortcut release.</p>
  </li>
  <li><h3>Choose your cards</h3><p>In iPhone Shortcuts, open <strong>Automation → + → Transaction</strong>. Select the cards you want to track and choose <strong>Run Immediately</strong>, if available.</p><p>Add <strong>Run Shortcut → {{ shortcut.name }}</strong> and pass <strong>Shortcut Input</strong> to it. Allow permissions when prompted.</p><p class="small muted">You choose cards on your iPhone. SpendTrace cannot read your Wallet card list.</p></li>
  <li><h3>Check that it works</h3><p>Open the shortcut and choose <strong>Test connection</strong>. This sends a test without recording an expense.</p><p role="status" :class="phoneTested ? 'notice' : 'muted'">{{ phoneTested ? 'Test received from your iPhone.' : 'Waiting for your iPhone test…' }}</p><p>Then make a purchase with a selected card. If we ask which account paid, choose it below. Add a receipt later to match it to the purchase.</p><p v-if="connection?.last_event_at" class="small">Latest purchase received: {{ new Date(connection.last_event_at).toLocaleString() }}</p><p class="small muted">A successful test confirms connectivity. Check a real purchase to confirm automatic capture. Your iPhone needs internet access; failed sends are not retried automatically.</p></li>
 </ol>
 <details v-if="connection" class="wallet-card"><summary>Manage connected iPhones</summary><p class="small muted">Disconnect a lost or replaced phone here. Saved purchases stay in SpendTrace.</p><p v-if="!phones.length" class="muted">No iPhone connected yet.</p><article v-for="phone in phones" :key="phone.id" class="wallet-actions"><strong>{{ phone.name }}</strong><button class="text-button" @click="router.delete(`/integrations/wallet/${connection.id}/devices/${phone.id}`,{preserveScroll:true})">Disconnect</button></article><button class="button secondary" :disabled="busy || !connection.enabled || !shortcut.connect_available" @click="issue()">Reconnect or connect another iPhone</button></details>
 <button v-if="connection" class="button secondary" @click="mappingOpen=true">Manage cards and accounts</button>
 <WalletMappingDialog v-if="mappingOpen && connection" title="Your cards and accounts" @close="mappingOpen=false"><WalletNameMappings :key="connection.id" :connection-id="connection.id" :aliases="connection.aliases" :accounts="accounts" :discovered-names="discoveredNames"/></WalletMappingDialog>
<section v-if="unresolved.total"><h2>Choose an account · {{ unresolved.total }} purchases</h2><p class="small muted">Choose which account paid for each purchase. Remember a card to do this automatically next time. These purchases are saved but are not yet included in your expenses.</p><article v-for="event in unresolved.data" :key="event.id" class="review-notice"><strong>{{ event.payload.merchant }} · {{ event.currency || 'Currency unknown' }} {{ event.payload.amount_decimal }}</strong><p>{{ event.payload.wallet_alias.label }}{{ event.payload.wallet_alias.last_four ? ' · '+event.payload.wallet_alias.last_four : '' }}<br/>{{ new Date(event.payload.observed_at).toLocaleString() }}</p><button v-if="resolving!==event.id" class="button secondary" @click="choose(event)">Choose account</button><form v-else @submit.prevent="assignment.post(`/wallet-aliases/${event.id}/resolve`,{onSuccess:()=>resolving=null})"><Errors :errors="assignment.errors"/><label>Account<select v-model="assignment.account_id" required><option :value="null" disabled>Select your account</option><option v-for="account in accounts" :key="account.id" :value="account.id">{{ account.nickname }}</option></select></label><label class="check"><input type="checkbox" v-model="assignment.remember"/>Remember this card for future purchases</label><label v-if="assignment.remember && !event.payload.wallet_alias.last_four" class="check"><input v-model="assignment.name_unique" type="checkbox" required/>Only one of my cards uses this name</label><label class="check"><input type="checkbox" v-model="assignment.confirmed" required/>I checked this purchase and its payment account</label><button class="button primary" :disabled="assignment.processing || !assignment.confirmed">Assign and record purchase</button></form></article><div class="wallet-actions"><Link v-if="unresolved.prev_page_url" :href="unresolved.prev_page_url">← Previous unresolved</Link><Link v-if="unresolved.next_page_url" :href="unresolved.next_page_url">Next unresolved →</Link></div></section>
</section></template>

<style scoped>
.setup-steps{list-style:none;counter-reset:setup;padding:0;margin:28px 0;display:grid;gap:24px}
.setup-steps>li{counter-increment:setup;position:relative;padding:0 0 24px 52px;border-bottom:1px solid #ddd}
.setup-steps>li::before{content:counter(setup);position:absolute;left:0;top:0;width:32px;height:32px;border-radius:50%;background:#eef2ef;color:#244b37;display:grid;place-items:center;font-weight:700}
.setup-steps h3{margin-top:0}.setup-steps p{max-width:70ch;line-height:1.6}
summary{cursor:pointer}details>summary{padding:12px 0} .setup-steps details{margin-top:12px}
</style>
