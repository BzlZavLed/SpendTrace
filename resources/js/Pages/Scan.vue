<script setup lang="ts">
import { Head, Link, useForm } from "@inertiajs/vue3";
import { ref, onBeforeUnmount } from "vue";
import AppShell from "../Components/AppShell.vue";
import Errors from "../Components/Errors.vue";
import ReceiptCamera from "../Components/ReceiptCamera.vue";
const form = useForm<{ images: File[] }>({ images: [] });
const previews = ref<{ name: string; url: string }[]>([]);
const localError = ref("");
function addFiles(files: File[]) {
  localError.value = "";
  if (form.images.length + files.length > 6) {
    localError.value = "Choose up to six photos of one receipt.";
    return;
  }
  if (files.some((f) => /\.hei[cf]$/i.test(f.name) || f.type.includes("hei"))) {
    localError.value =
      "HEIC/HEIF needs conversion. Export as JPEG from Photos or Files, or choose Settings → Camera → Formats → Most Compatible for new photos.";
    return;
  }
  if (files.some((f) => f.size > 10 * 1024 * 1024)) {
    localError.value = "Each photo must be 10 MB or smaller.";
    return;
  }
  form.images.push(...files);
  previews.value.push(
    ...files.map((f) => ({ name: f.name, url: URL.createObjectURL(f) }))
  );
}
function select(event: Event) {
  const input = event.target as HTMLInputElement;
  addFiles([...(input.files ?? [])]);
  input.value = "";
}
function remove(i: number) {
  URL.revokeObjectURL(previews.value[i].url);
  previews.value.splice(i, 1);
  form.images.splice(i, 1);
}
onBeforeUnmount(() => previews.value.forEach((p) => URL.revokeObjectURL(p.url)));
function submit() {
  form.post("/receipts", { forceFormData: true });
}
</script>
<template>
  <AppShell
    ><Head title="Scan receipt" /><Link href="/" class="back-link">← Back to ledger</Link>
    <section class="narrow">
      <p class="eyebrow">CAPTURE THE EVERYDAY</p>
      <h1>One receipt. All its details.</h1>
      <p class="muted">
        Add up to six photos of the same receipt. Include the merchant, date, total, and
        any item details.
      </p>
      <form @submit.prevent="submit" class="scan-panel">
        <Errors :errors="form.errors" />
        <p v-if="localError" role="alert" class="errors">{{ localError }}</p>
        <div class="upload-zone">
          <span class="camera-symbol" aria-hidden="true">⊕</span>
          <h2>Let’s get it on record.</h2>
          <p>Good light. A flat receipt. Every edge in frame.</p>
          <ReceiptCamera
            :disabled="form.processing || form.images.length >= 6"
            @capture="(file) => addFiles([file])"
          />
          <div class="capture-actions">
            <label class="button secondary file-button"
              >Use device camera app<input
                type="file"
                accept="image/jpeg,image/png,image/webp"
                capture="environment"
                @change="select"
                :disabled="form.processing" /></label
            ><label class="button secondary file-button"
              >Choose photos<input
                type="file"
                accept="image/jpeg,image/png,image/webp,.heic,.heif"
                multiple
                @change="select"
                :disabled="form.processing"
            /></label>
          </div>
          <small>JPEG, PNG, WebP · Up to 10 MB each</small>
        </div>
        <div v-if="previews.length" class="preview-grid">
          <div v-for="(preview, i) in previews" :key="preview.url">
            <img :src="preview.url" :alt="`Receipt photo ${i + 1}`" /><button
              type="button"
              class="quiet"
              @click="remove(i)"
              :disabled="form.processing"
            >
              Remove photo {{ i + 1 }}
            </button>
          </div>
        </div>
        <p class="muted small">
          HEIC/HEIF: convert to JPEG before uploading. For new iPhone photos, select
          Camera → Formats → Most Compatible.
        </p>
        <div class="privacy-note">
          <strong>Originals first. Details next.</strong>
          <p>
            We save your photos privately, then extract in the background. You can leave
            once the upload finishes. With real extraction enabled, images are sent to
            OpenAI.
          </p>
        </div>
        <progress
          v-if="form.progress"
          :value="form.progress.percentage"
          max="100"
        ></progress
        ><button
          class="button primary full"
          :disabled="!form.images.length || form.processing"
        >
          {{
            form.processing
              ? `Saving originals… ${form.progress?.percentage ?? 0}%`
              : `Save & extract${
                  form.images.length
                    ? ` · ${form.images.length} photo${
                        form.images.length === 1 ? "" : "s"
                      }`
                    : ""
                }`
          }}
          <span>→</span>
        </button>
      </form>
    </section></AppShell
  >
</template>
