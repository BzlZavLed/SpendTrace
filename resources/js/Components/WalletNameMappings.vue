<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import type { Account } from '../types';
import Errors from './Errors.vue';
type Alias = { id:number; account_id:number; fingerprint:string; name_confirmed_at:string|null; identifier:{label:string;last_four?:string;network?:string} };
export type PendingWalletPurchase = {id:number;wallet_connection_id:number;currency:string|null;merchant:string;amount_decimal:string;identifier:{label:string;last_four?:string;network?:string}};
type Card = {key:string;label:string;suffix?:string;network?:string;aliases:Alias[]};
const props = withDefaults(defineProps<{connectionId:number; aliases:Alias[]; accounts:Account[]; discoveredNames?:string[];purchase?:PendingWalletPurchase}>(),{discoveredNames:()=>[]});
const form = useForm({label:'',account_id:null as number|null,name_unique:false,remember:true,confirmed:false});
const removal = useForm({});
const addedNames = ref<string[]>([]);
const newName = ref('');
const selectedKey = ref<string|null>(null);
const normalize = (value:string)=>value.trim().replace(/\s+/gu,' ').toLowerCase();
const cards = computed<Card[]>(()=>{
 const result:Card[]=[];
 if(props.purchase) return [{key:`event:${props.purchase.id}`,label:props.purchase.identifier.label,suffix:props.purchase.identifier.last_four,network:props.purchase.identifier.network,aliases:[]}];
 for(const alias of props.aliases){
  let card=result.find(c=>c.key===alias.fingerprint);
  if(!card){card={key:alias.fingerprint,label:alias.identifier.label,suffix:alias.identifier.last_four,network:alias.identifier.network,aliases:[]};result.push(card);}
  card.aliases.push(alias);
 }
 for(const label of [...props.discoveredNames,...addedNames.value]){
  if(!result.some(c=>!c.suffix&&!c.network&&normalize(c.label)===normalize(label))) result.push({key:`name:${normalize(label)}`,label,aliases:[]});
 }
 return result;
});
const selected = computed(()=>cards.value.find(c=>c.key===selectedKey.value));
const chosenAccount = computed(()=>props.accounts.find(a=>a.id===form.account_id));
const height = computed(()=>Math.max(cards.value.length,props.accounts.length,1)*104);
function conflict(card:Card){return new Set(card.aliases.map(a=>a.account_id)).size>1;}
function active(card:Card,alias:Alias){return !conflict(card)&&Boolean(card.suffix||alias.name_confirmed_at);}
const lines = computed(()=>cards.value.flatMap((card,index)=>card.aliases.flatMap(alias=>{
 const target=props.accounts.findIndex(a=>a.id===alias.account_id);
 return target<0?[]:[{id:alias.id,path:`M 40 ${index*104+44} C 50 ${index*104+44}, 50 ${target*104+44}, 60 ${target*104+44}`,active:active(card,alias),highlight:card.key===selectedKey.value}];
})));
const preview = computed(()=>{
 const source=cards.value.findIndex(c=>c.key===selectedKey.value);
 const target=props.accounts.findIndex(a=>a.id===form.account_id);
 return source<0||target<0?null:`M 40 ${source*104+44} C 50 ${source*104+44}, 50 ${target*104+44}, 60 ${target*104+44}`;
});
function select(card:Card){selectedKey.value=card.key;form.reset();form.clearErrors();form.label=card.label;}
function add(){const label=newName.value.trim();if(!label)return;addedNames.value.push(label);newName.value='';const card=cards.value.find(c=>!c.suffix&&!c.network&&normalize(c.label)===normalize(label));if(card)select(card);}
function save(){
 if(!selected.value||selected.value.aliases.length||!form.account_id)return;
 if(props.purchase){
  form.post(`/wallet-aliases/${props.purchase.id}/resolve`,{preserveScroll:true,onSuccess:()=>emit('resolved')});
  return;
 }
 if(!form.name_unique)return;
 form.post(`/integrations/wallet/${props.connectionId}/aliases`,{preserveScroll:true,onSuccess:()=>{selectedKey.value=null;form.reset();}});
}
const emit = defineEmits<{resolved:[]}>();
watch(()=>props.purchase?.id,()=>{if(props.purchase && cards.value[0])select(cards.value[0]);},{immediate:true});
watch(()=>props.connectionId,()=>{selectedKey.value=null;addedNames.value=[];newName.value='';form.reset();form.clearErrors();});
</script>
<template>
<section class="wallet-card card-linker">
 <h3>{{ purchase ? 'Which account paid?' : 'Link your cards to accounts' }}</h3>
 <p v-if="purchase"><strong>{{ purchase.merchant }}</strong> · {{ purchase.currency || 'Currency unknown' }} {{ purchase.amount_decimal }}</p>
 <p class="muted">Select a Wallet card, then the SpendTrace account it pays from.</p>
 <p v-if="!purchase" class="small muted">Names appear after purchases arrive, or you can add one below. SpendTrace cannot read your full Wallet card list.</p>
 <Errors :errors="removal.errors"/>
 <div class="column-titles"><h4>Wallet cards</h4><h4>SpendTrace accounts</h4></div>
 <div class="link-board" :style="{minHeight:`${height}px`}">
  <svg class="connections" :viewBox="`0 0 100 ${height}`" preserveAspectRatio="none" aria-hidden="true">
   <path v-for="line in lines" :key="line.id" :d="line.path" :class="{confirmed:line.active,highlight:line.highlight,pending:!line.active}"/>
   <path v-if="preview && !selected?.aliases.length" :d="preview" class="preview"/>
  </svg>
  <div class="card-column">
   <button v-for="card in cards" :key="card.key" type="button" class="link-node wallet-node" :class="{selected:card.key===selectedKey}" :aria-pressed="card.key===selectedKey" @click="select(card)">
    <span class="node-type">WALLET CARD <span aria-hidden="true">▣</span></span><strong>{{ card.label }}</strong><span class="node-detail">{{ card.suffix ? `•••• ${card.suffix} · ` : '' }}{{ conflict(card)?'Needs review':card.aliases.length ? (card.aliases.every(a=>active(card,a))?'Linked':'Needs confirmation') : 'Choose an account' }}</span>
   </button>
   <p v-if="!cards.length" class="empty-column">No cards yet. Add a name below or make your first purchase.</p>
  </div>
  <div class="account-column">
   <button v-for="account in accounts" :key="account.id" type="button" class="link-node account-node" :class="{selected:form.account_id===account.id,linked:selected?.aliases.some(a=>a.account_id===account.id)}" :disabled="!selected || !!selected.aliases.length || form.processing" :aria-pressed="form.account_id===account.id" @click="form.account_id=account.id">
    <span class="node-type">{{ account.institution }}</span><strong>{{ account.nickname }}</strong><span class="node-detail">{{ account.card_network || 'SpendTrace account' }}</span>
   </button>
   <p v-if="!accounts.length" class="empty-column">Add an account to link your cards.<br/><Link href="/accounts">Manage accounts →</Link></p>
  </div>
 </div>
 <div v-if="selected" class="link-confirmation" aria-live="polite">
  <template v-if="selected.aliases.length">
   <strong>{{ selected.label }}</strong>
   <p v-if="conflict(selected)" class="review-notice">This card points to different accounts. Remove the incorrect links before automatic recording can resume.</p>
   <p v-else-if="!selected.aliases.every(a=>active(selected!,a))" class="review-notice">This name has not been confirmed. Remove the link and link it again only if one card uses this name.</p>
   <p v-else class="small">Future purchases use the linked account. To change it, remove the link first.</p>
   <div v-for="alias in selected.aliases" :key="alias.id" class="wallet-actions"><span>{{ selected.label }} → {{ accounts.find(a=>a.id===alias.account_id)?.nickname || 'Account unavailable' }}</span><button class="text-button" :disabled="removal.processing" @click="removal.delete(`/integrations/wallet/${connectionId}/aliases/${alias.id}`,{preserveScroll:true,onSuccess:()=>{selectedKey=null;}})">Remove link</button></div>
  </template>
  <form v-else @submit.prevent="save">
   <Errors :errors="form.errors"/>
   <p><strong>{{ selected.label }}</strong> → <strong>{{ chosenAccount?.nickname || 'Select an account on the right' }}</strong></p>
   <label v-if="purchase" class="check"><input v-model="form.remember" type="checkbox"/>Remember this card for future purchases</label>
   <label v-if="(!purchase || form.remember) && !selected.suffix" class="check"><input v-model="form.name_unique" type="checkbox" required/>Only one of my Wallet cards uses this name.</label>
   <label v-if="purchase" class="check"><input v-model="form.confirmed" type="checkbox" required/>I checked this purchase and its account</label>
   <p class="small muted">If cards share a name, choose the account for each purchase instead. Linking applies to future purchases; review any waiting purchases below.</p>
   <button class="button primary" :disabled="form.processing || !form.account_id || (purchase ? (!form.confirmed || (form.remember && !selected.suffix && !form.name_unique)) : !form.name_unique)">{{ form.processing?'Saving…':purchase ? (form.remember?'Link card and record purchase':'Record this purchase') : 'Save link' }}</button>
  </form>
 </div>
 <form v-if="!purchase" class="add-card" @submit.prevent="add"><label>Card name from Wallet<input v-model="newName" maxlength="100" placeholder="For example, Personal Visa" required/></label><button class="button secondary" :disabled="!newName.trim()">Add card name</button></form>
 <p class="small muted">Solid lines are active links. Dashed lines need confirmation or review. Removing a link does not change past purchases.</p>
</section>
</template>
<style scoped>
.column-titles,.link-board{display:grid;grid-template-columns:40% 20% 40%}.column-titles h4:last-child,.account-column{grid-column:3}.column-titles h4{margin:16px 0}.link-board{position:relative}.card-column,.account-column{z-index:1;min-width:0}.connections{position:absolute;inset:0;width:100%;height:100%;pointer-events:none;overflow:visible}.connections path{fill:none;stroke:#a68b58;stroke-width:2;vector-effect:non-scaling-stroke}.connections .confirmed{stroke:#6a9b83}.connections .pending{stroke-dasharray:5 5}.connections .highlight,.connections .preview{stroke:#246448;stroke-width:3}.connections .preview{stroke-dasharray:5 4}.link-node{box-sizing:border-box;width:100%;height:88px;margin:0 0 16px;padding:12px 16px;border:1px solid #cbd5ce;border-radius:12px;text-align:left;display:flex;flex-direction:column;justify-content:space-between;background:#fff;color:#253b30;cursor:pointer;font:inherit}.wallet-node{background:linear-gradient(115deg,#eff5f0,#e3eee8)}.link-node strong{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.link-node.selected,.link-node.linked{border:2px solid #246448;box-shadow:0 0 0 3px #24644815}.link-node:focus-visible{outline:3px solid #317855;outline-offset:3px}.link-node:disabled{cursor:default;opacity:1}.node-type{display:flex;justify-content:space-between;font-size:10px;letter-spacing:.06em;overflow:hidden;white-space:nowrap}.node-detail{font-size:11px;color:#526b5d}.empty-column{padding:16px;border:1px dashed #cbd5ce;border-radius:12px;font-size:13px;line-height:1.6}.link-confirmation{padding:18px;background:#f4f7f4;border:1px solid #d5dfd7;border-radius:12px}.add-card{display:flex;align-items:end;gap:12px;margin-top:20px}.add-card label{flex:1;margin:0}.add-card button{margin-bottom:0} @media(max-width:580px){.link-node{padding:10px 8px}.link-node strong{font-size:13px}.node-detail{font-size:10px}.column-titles h4{font-size:12px}.add-card{align-items:stretch;flex-direction:column}}
</style>
