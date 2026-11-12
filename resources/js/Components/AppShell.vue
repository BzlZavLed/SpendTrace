<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import InstallApp from './InstallApp.vue';
const page = usePage();
</script>
<template><div class="app-shell">
<header class="topbar"><Link href="/" class="brand"><span class="brand-mark">s<span>t</span></span>SpendTrace</Link><nav aria-label="Main navigation"><Link href="/" :class="{active: page.url === '/'}">Ledger</Link><Link href="/accounts" :class="{active: page.url.startsWith('/accounts')}">Accounts</Link><Link href="/logout" method="post" as="button" class="quiet">Sign out</Link></nav></header>
<main><div v-if="page.props.provider === 'fake'" class="demo-banner"><span class="dot"></span>Fake extraction active <span>· Photos are saved, but no AI reads them.</span></div><div v-if="page.props.notice" role="status" class="notice">{{ page.props.notice }}</div><InstallApp/><slot /></main>
<nav class="mobile-tabs" aria-label="Mobile navigation"><Link href="/" :aria-current="page.url==='/'?'page':undefined">Ledger</Link><Link href="/scan" :aria-current="page.url.startsWith('/scan')?'page':undefined">Scan</Link><Link href="/accounts" :aria-current="page.url.startsWith('/accounts')?'page':undefined">Accounts</Link></nav><footer class="footer"><span>YOUR EVERYDAY SPENDING, WITH A PAPER TRAIL.</span><span>Private receipts · America/New_York</span></footer>
</div></template>
