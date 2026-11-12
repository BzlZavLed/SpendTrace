import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import fs from 'node:fs';
const source=fs.readFileSync(new URL('../../public/sw.js',import.meta.url),'utf8');
function worker(fetch){
 const events={};const matches=[];const writes=[];
 vm.runInNewContext(source,{URL,self:{location:{origin:'https://app.example'},addEventListener:(name,callback)=>events[name]=callback,skipWaiting:async()=>{},clients:{claim:async()=>{}}},fetch,caches:{open:async()=>({addAll:async files=>writes.push(...files)}),match:async key=>{matches.push(key);return 'public offline response';},keys:async()=>[],delete:async()=>{}}});
 return {events,matches,writes};
}
test('installation caches only explicit public assets',async()=>{
 const w=worker();let done;w.events.install({waitUntil:p=>done=p});await done;
 assert.deepEqual(w.writes.sort(),['/app-icon-192.png','/app-icon-512.png','/apple-touch-icon.png','/offline.html'].sort());
});
test('successful authenticated navigations are never stored or replaced',async()=>{
 const w=worker(async()=> 'private ledger');let response;
 w.events.fetch({request:{method:'GET',url:'https://app.example/',mode:'navigate'},respondWith:p=>response=p});
 assert.equal(await response,'private ledger');assert.deepEqual(w.matches,[]);assert.deepEqual(w.writes,[]);
});
test('offline navigation gets only the public offline page',async()=>{
 const w=worker(async()=>{throw new Error('offline');});let response;
 w.events.fetch({request:{method:'GET',url:'https://app.example/transactions/1',mode:'navigate'},respondWith:p=>response=p});
 assert.equal(await response,'public offline response');assert.deepEqual(w.matches,['/offline.html']);
});
test('API, Inertia, receipt images, POSTs and other origins bypass the worker',()=>{
 const w=worker(()=>assert.fail('unexpected fetch'));
 for(const [method,url,mode] of [['POST','https://app.example/receipts','navigate'],['GET','https://app.example/','cors'],['GET','https://app.example/receipt-images/1','no-cors'],['GET','https://app.example/api/v1/integrations/wallet/events','cors'],['GET','https://other.example/','navigate']]){
  w.events.fetch({request:{method,url,mode},respondWith:()=>assert.fail('private request intercepted')});
 }
});
