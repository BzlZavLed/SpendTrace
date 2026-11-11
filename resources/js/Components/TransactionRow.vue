<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { type Transaction, money, dateLabel } from '../types';
defineProps<{transaction: Transaction}>();
</script>
<template><Link :href="`/transactions/${transaction.id}`" class="transaction-row"><span class="receipt-icon" aria-hidden="true">▤</span><span class="row-description"><strong>{{ transaction.merchant || (transaction.receipt.status === 'queued' ? 'Reading your receipt…' : 'Receipt to review') }}</strong><small>{{ dateLabel(transaction.purchase_date) }} <span v-if="transaction.category">· {{ transaction.category }}</span></small></span><span class="row-amount"><strong>{{ money(transaction) }}</strong><small :class="{'amber-text':transaction.review_status === 'needs_review'}">{{ transaction.excluded ? 'Excluded from totals' : transaction.receipt.status === 'failed' ? 'Extraction failed' : transaction.review_status === 'needs_review' ? 'Needs review' : 'Reviewed · Unreconciled' }}</small></span><span class="row-arrow" aria-hidden="true">↗</span></Link></template>
