<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppShell from '../Components/AppShell.vue';
import Errors from '../Components/Errors.vue';
import WalletShortcutSetup from '../Components/WalletShortcutSetup.vue';
import WalletCardMappings, { type CardMapping } from '../Components/WalletCardMappings.vue';
import type { Account } from '../types';
type Connection = {devices:{created_at:string;id:number;name:string;registration_method:string;revoked_at:string|null;last_test_at:string|null;last_event_at:string|null}[];aliases:{id:number;account_id:number;fingerprint:string;name_confirmed_at:string|null;identifier:{label:string;last_four?:string;network?:string}}[];id:number;name:string;account_id:number|null;card_mappings:CardMapping[]|null;enabled:boolean;default_currency:string|null;has_token:boolean;last_event_at:string|null;last_test_at:string|null};
const props = defineProps<{connections:Connection[];accounts:Account[];endpoint:string;shortcut:{name:string;version:string;available:boolean;url:string|null;pairing_available:boolean;reason:string|null};unresolved:{data:{id:number;wallet_connection_id:number;currency:string|null;payload:{merchant:string;amount_decimal:string;wallet_alias:{label:string;last_four?:string;network?:string};observed_at:string}}[];total:number;prev_page_url:string|null;next_page_url:string|null}}>();
const page = usePage();
const currencies = Object.keys(page.props.currencies as Record<string,number>);
const form = useForm({name:'',card_mappings:[] as CardMapping[],default_currency:null as string|null,enabled:false});
const token = ref('');
const tokenFor = ref<number|null>(null);
const busy = ref(false);
const error = ref('');
const result = ref('');
const history = ref<{event_id:string;status:string;is_test:boolean;created_at:string}[]>([]);
const editing = ref<number|null>(null);
const edit = useForm({name:'',card_mappings:[] as CardMapping[],default_currency:null as string|null,enabled:false});
const example = JSON.stringify({card_key:'everyday-visa',event_id:'GENERATE-ONE-UUID-PER-EVENT',observed_at:'2026-11-11T14:36:00-05:00',merchant:'Example merchant',amount_decimal:'86.42',currency:null,test:true},null,2);
function csrf() { const cookie=document.cookie.split('; ').find(v=>v.startsWith('XSRF-TOKEN=')); return cookie ? decodeURIComponent(cookie.substring(11)) : ''; }
async function generate(c:Connection) {
 busy.value=true;error.value='';token.value='';tokenFor.value=null;
 try {
  const response=await fetch(`/integrations/wallet/${c.id}/token`,{method:'POST',headers:{Accept:'application/json','X-XSRF-TOKEN':csrf()},credentials:'same-origin'});
  if(!response.ok) throw new Error('Could not create the credential. Reload and try again.');
  token.value=(await response.json()).token;tokenFor.value=c.id;
  router.reload({only:['connections']});
 } catch(e) { error.value=e instanceof Error?e.message:'Request failed.'; } finally { busy.value=false; }
}
async function status(c:Connection) {
 error.value='';history.value=[];
 try {const response=await fetch(`/integrations/wallet/${c.id}/status`,{headers:{Accept:'application/json'}});if(!response.ok) throw new Error('Could not load status.'); history.value=(await response.json()).events; result.value=`Recent accepted events for ${c.name}`;} catch(e){error.value=e instanceof Error?e.message:'Request failed.';}
}
async function test() {
 busy.value=true;error.value='';result.value='';
 try {
  const response=await fetch('/api/v1/integrations/wallet/events',{method:'POST',credentials:'omit',headers:{'Content-Type':'application/json',Accept:'application/json',Authorization:`Bearer ${token.value}`},body:JSON.stringify({event_id:crypto.randomUUID(),observed_at:new Date().toISOString(),merchant:'Connectivity test',amount_decimal:'0',currency:null,test:true})});
  if(!response.ok) throw new Error(`Test was not accepted (HTTP ${response.status}). Check that the connection is enabled.`);
  const data=await response.json();
  if(!data.accepted) throw new Error('The server did not confirm receipt.');
  result.value='Test durably received. No spending was recorded. This browser test does not prove phone connectivity or payment coverage.';
  router.reload({only:['connections']});
 } catch(e){error.value=e instanceof Error?e.message:'Connection failed. No automatic retry was scheduled.';} finally{busy.value=false;}
}
function configure(c:Connection) {editing.value=c.id;edit.name=c.name;edit.card_mappings=(c.card_mappings ?? []).map(mapping=>({...mapping}));edit.default_currency=c.default_currency;edit.enabled=c.enabled;}
function revoke(c:Connection) { token.value='';tokenFor.value=null;router.delete(`/integrations/wallet/${c.id}/token`); }
function remove(c:Connection) {token.value='';tokenFor.value=null;router.delete(`/integrations/wallet/${c.id}`);}
</script>
<template><AppShell><Head title="Apple Wallet via Shortcuts"/>
<section class="page-heading"><div><p class="eyebrow">INTEGRATIONS</p><h1>Apple Wallet via Shortcuts</h1><p class="muted">Capture future purchases, then attach their receipts. Wallet observations are not bank reconciliation.</p></div><Link href="/wallet-matches" class="button secondary">Review matches ↗</Link></section>
<WalletShortcutSetup :shortcut="shortcut" :connections="connections" :accounts="accounts" :unresolved="unresolved"/><details class="wallet-card" :open="!connections.length"><summary>Connection management and existing manual shortcuts</summary><div v-if="error" class="review-notice" role="alert">{{ error }}</div><p v-if="result" class="notice" role="status">{{ result }}</p>
<div class="accounts-grid"><section><h2>Your connections</h2><p v-if="!connections.length" class="muted">Create one connection for all your Wallet cards. Add your SpendTrace accounts, then map the cards below.</p>
<article v-for="c in connections" :key="c.id" class="edit-panel wallet-card"><h3>{{ c.name }}</h3><p>{{ c.card_mappings?.length || 0 }} mapped cards · {{ c.default_currency || 'No default currency' }}</p><p><span class="pill">{{ c.enabled ? 'Accepting submissions' : 'Disabled' }}</span> · {{ c.has_token ? 'Credential created' : 'No credential' }}</p><p class="muted small">Last real event: {{ c.last_event_at || 'None received' }}<br/>Last test: {{ c.last_test_at || 'None received' }}<br/>Enabling does not prove your phone automation is configured or running.</p>
<p v-if="c.account_id" class="small muted">Existing shortcuts without card_key still use {{ accounts.find(a=>a.id===c.account_id)?.nickname }}.</p><ul v-if="c.card_mappings?.length" class="small"><li v-for="mapping in c.card_mappings" :key="mapping.card_key"><code>{{ mapping.card_key }}</code> → {{ accounts.find(a=>a.id===mapping.account_id)?.nickname }}</li></ul><div class="wallet-actions"><button class="button secondary" @click="configure(c)">Configure</button><button class="button secondary" :disabled="busy" @click="generate(c)">{{ c.has_token ? 'Replace token' : 'Create token' }}</button><button class="text-button" @click="status(c)">Status / event history</button><button class="text-button" @click="revoke(c)">Revoke token</button><button class="text-button" @click="remove(c)">Delete connection</button></div>
<form v-if="editing===c.id" @submit.prevent="edit.patch(`/integrations/wallet/${c.id}`,{onSuccess:()=>editing=null})" class="wallet-card"><Errors :errors="edit.errors"/><label>Connection name<input v-model="edit.name" maxlength="100" required/></label><label>Default currency for all cards (optional)<select v-model="edit.default_currency"><option :value="null">Unknown — do not assume</option><option v-for="code in currencies" :key="code">{{ code }}</option></select></label><label class="check"><input type="checkbox" v-model="edit.enabled"/>Enable submissions</label><WalletCardMappings v-model="edit.card_mappings" :accounts="accounts"/><p class="small muted">Mapping changes apply only to future events. Existing events and queued matches keep their saved accounts.</p><button class="button primary" :disabled="edit.processing">Save</button></form></article>
<form @submit.prevent="form.post('/integrations/wallet',{onSuccess:()=>form.reset()})" class="edit-panel"><h2>One connection, all your cards</h2><Errors :errors="form.errors"/><label>Name<input v-model="form.name" placeholder="My Apple Wallet" maxlength="100" required/></label><WalletCardMappings v-model="form.card_mappings" :accounts="accounts"/><label>Default currency for all cards (optional)<select v-model="form.default_currency"><option :value="null">Unknown — do not assume</option><option v-for="code in currencies" :key="code">{{ code }}</option></select></label><label class="check"><input v-model="form.enabled" type="checkbox"/>Enable submissions</label><button class="button primary" :disabled="form.processing || !accounts.length">Create connection</button> <Link href="/accounts">Manage accounts</Link></form>
</section><section class="edit-panel"><h2>Connect your iPhone</h2><p>Endpoint: <code class="wallet-code">{{ endpoint }}</code></p><p v-if="!endpoint.startsWith('https://')" class="review-notice">A phone needs a reachable HTTPS address. This local URL is for development; localhost on your phone is not your Mac. Set WALLET_PUBLIC_URL to your deployed HTTPS origin or tunnel origin.</p>
<div v-if="token" class="review-notice"><strong>Copy this new token now · connection #{{ tokenFor }}</strong><p>It is shown only in this response and kept in this page’s memory. Replacing it immediately revokes the previous token.</p><code class="wallet-code">{{ token }}</code><div class="wallet-actions"><button class="button secondary" :disabled="busy" @click="test">Send harmless browser test</button><button class="text-button" @click="token='';tokenFor=null">Hide token</button></div></div>
<ol class="wallet-guide"><li>Create one connection, add card keys mapped to your SpendTrace accounts, and create its shared token.</li><li>In iPhone Shortcuts, create a personal automation using the Transaction trigger. Select the Wallet cards supported by your automation’s “When I tap” options. UI labels and multi-card support vary by iOS version.</li><li>Choose automatic execution where supported. Check action permissions and locked-device behavior on your phone.</li><li>Inspect the trigger’s available input properties. Map only merchant, amount, and currency fields actually provided. Include card_key matching a configured mapping. If the trigger exposes a stable card name, map it to that key; otherwise use a card-specific automation with a fixed key. These automations can all use the same connection and token. Card-field availability requires physical-device verification; do not guess missing values.</li><li>Generate an event UUID once. Keep that same UUID and payload for retries. Set observed_at to when the shortcut executes, with an explicit timezone. Send transaction_at separately only if an actual transaction timestamp is available.</li><li>Use Get Contents of URL, method POST, JSON body, Content-Type application/json, and header Authorization: Bearer YOUR_INGESTION_TOKEN. Never put the token in the URL.</li><li>Send the example with test: true from your phone and verify “test_received” in event history. Remove test or set it to false for real events.</li><li>Make a real in-store Apple Pay purchase, verify its event and amount, then upload the receipt and review the match.</li></ol>
<p class="small muted">This is SpendTrace’s contract, not Apple’s native schema. Amounts must be decimal strings without currency symbols or grouping. Currency may be null; no USD assumption is made. Real events must identify a configured card_key on new multi-card connections. Unknown keys are rejected, never routed to a fallback account. Optional transaction_at uses the same timestamp format as observed_at.</p><pre class="wallet-code">{{ example }}</pre>
<p class="small">No native app or FinanceKit entitlement is required for this HTTP approach. Capture depends on supported triggers and fields. Wallet history, physical-card purchases, online payments, and Apple Watch purchases are not guaranteed. Your Mac and services must remain running for local development. Tests prove connectivity only. Failed phone requests are not queued or retried automatically; this implementation promises no offline delivery.</p>
<p class="small"><a href="https://support.apple.com/en-euro/guide/shortcuts/apd65c67538a/ios" target="_blank" rel="noopener">Apple: Transaction triggers ↗</a> · <a href="https://support.apple.com/en-mide/guide/shortcuts/apdfbdbd7123/ios" target="_blank" rel="noopener">Apple: Personal automations ↗</a></p>
</section></div><section v-if="history.length" class="edit-panel wallet-card"><h2>Recent accepted ingestion outcomes</h2><p v-for="h in history" :key="h.event_id" class="small wallet-code">{{ h.created_at }} · {{ h.is_test ? 'Test' : 'Purchase' }} · {{ h.status }} · {{ h.event_id }}</p></section>
</details></AppShell></template>
