<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps<{ disabled: boolean }>();
const emit = defineEmits<{ capture: [file: File] }>();
const video = ref<HTMLVideoElement | null>(null);
const opened = ref(false);
const starting = ref(false);
const capturing = ref(false);
const ready = ref(false);
const error = ref('');
const notice = ref('');
const cameras = ref<MediaDeviceInfo[]>([]);
const selectedCamera = ref('');
const isMac = /Macintosh|MacIntel/.test(navigator.userAgent + navigator.platform) && navigator.maxTouchPoints < 2;
let stream: MediaStream | null = null;
let generation = 0;

function releaseStream() {
    stream?.getTracks().forEach(track => track.stop());
    stream = null;
    ready.value = false;
    if (video.value) video.value.srcObject = null;
}

function closeCamera() {
    generation++;
    releaseStream();
    opened.value = false;
    starting.value = false;
    capturing.value = false;
}

async function refreshCameras() {
    if (!navigator.mediaDevices?.enumerateDevices) return;
    try {
        cameras.value = (await navigator.mediaDevices.enumerateDevices()).filter(device => device.kind === 'videoinput');
    } catch {
        // Capture can still work when a browser restricts device enumeration.
        notice.value = 'The browser could not list cameras. You can still use the current camera or choose photos.';
    }
}

function cameraError(cause: unknown): string {
    const name = cause instanceof DOMException ? cause.name : '';
    if (name === 'NotAllowedError' || name === 'SecurityError') return 'Camera access was blocked. Allow this site in your browser’s camera settings and allow your browser in macOS System Settings → Privacy & Security → Camera, then try again.';
    if (name === 'NotFoundError') return 'No camera is available. Connect a camera or enable iPhone Continuity Camera, then try again. You can also choose photos.';
    if (name === 'NotReadableError' || name === 'AbortError') return 'The camera could not start. Close other apps using it, check its connection, then try again.';
    if (name === 'OverconstrainedError') return 'That camera is no longer available. Refresh cameras and choose another one.';
    return 'Could not open the camera. Try again or use Choose photos.';
}

async function startCamera(deviceId?: string) {
    if (props.disabled) return;
    error.value = '';
    notice.value = '';
    if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) {
        error.value = 'Live camera access requires HTTPS or localhost and a supported browser. Use the photo picker below if camera access is unavailable.';
        return;
    }
    const request = ++generation;
    opened.value = true;
    starting.value = true;
    releaseStream();
    try {
        const nextStream = await navigator.mediaDevices.getUserMedia({
            audio: false,
            video: {
                ...(deviceId ? { deviceId: { exact: deviceId } } : { facingMode: { ideal: 'environment' } }),
                width: { ideal: 2560 },
                height: { ideal: 1920 },
            },
        });
        // A permission prompt may resolve after the panel has closed or navigation occurred.
        if (request !== generation) {
            nextStream.getTracks().forEach(track => track.stop());
            return;
        }
        stream = nextStream;
        const track = nextStream.getVideoTracks()[0];
        selectedCamera.value = track?.getSettings().deviceId ?? deviceId ?? '';
        track?.addEventListener('ended', () => {
            if (request !== generation) return;
            closeCamera();
            error.value = 'Camera disconnected. Reconnect it and open the camera again. Captured photos are still selected.';
        });
        await nextTick();
        if (request !== generation || !video.value) return;
        video.value.srcObject = nextStream;
        await video.value.play();
        await refreshCameras();
    } catch (cause) {
        if (request !== generation) return;
        releaseStream();
        error.value = cameraError(cause);
    } finally {
        if (request === generation) starting.value = false;
    }
}

async function capturePhoto() {
    const element = video.value;
    if (!element || !ready.value || props.disabled || capturing.value || !element.videoWidth || !element.videoHeight) return;
    const request = generation;
    capturing.value = true;
    error.value = '';
    try {
        const canvas = document.createElement('canvas');
        const scale = Math.min(1, 4096 / Math.max(element.videoWidth, element.videoHeight));
        canvas.width = Math.round(element.videoWidth * scale);
        canvas.height = Math.round(element.videoHeight * scale);
        const context = canvas.getContext('2d');
        if (!context) throw new Error('Canvas unavailable');
        context.drawImage(element, 0, 0, canvas.width, canvas.height);
        const blob = await new Promise<Blob | null>(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.94));
        if (request !== generation || props.disabled) return;
        if (!blob) throw new Error('Image unavailable');
        emit('capture', new File([blob], `receipt-camera-${Date.now()}.jpg`, { type: 'image/jpeg' }));
        notice.value = 'Photo added below. Check that the text is readable before saving.';
    } catch {
        error.value = 'Could not capture this frame. Wait for the preview and try again.';
    } finally {
        if (request === generation) capturing.value = false;
    }
}

function handleVisibility() {
    if (document.hidden) closeCamera();
}
watch(() => props.disabled, disabled => { if (disabled) closeCamera(); });
onMounted(() => {
    navigator.mediaDevices?.addEventListener?.('devicechange', refreshCameras);
    document.addEventListener('visibilitychange', handleVisibility);
    window.addEventListener('pagehide', closeCamera);
});
onBeforeUnmount(() => {
    closeCamera();
    navigator.mediaDevices?.removeEventListener?.('devicechange', refreshCameras);
    document.removeEventListener('visibilitychange', handleVisibility);
    window.removeEventListener('pagehide', closeCamera);
});
</script>

<template>
    <div class="receipt-camera">
        <button v-if="!opened" type="button" class="button primary" :disabled="disabled" @click="startCamera()">Open camera</button>
        <div v-else class="live-camera-panel">
            <div class="camera-toolbar">
                <label>Camera
                    <select v-model="selectedCamera" :disabled="starting || capturing || disabled" @change="startCamera(selectedCamera)">
                        <option v-if="!cameras.length || !cameras.some(c => c.deviceId === selectedCamera)" :value="selectedCamera">Current / default camera</option>
                        <option v-for="(camera, index) in cameras" :key="camera.deviceId" :value="camera.deviceId">{{ camera.label || `Camera ${index + 1}` }}</option>
                    </select>
                </label>
                <button type="button" class="text-button" :disabled="starting" @click="refreshCameras">Refresh cameras</button>
                <button type="button" class="text-button" @click="closeCamera">Close camera</button>
            </div>
            <video ref="video" autoplay playsinline muted aria-label="Live receipt camera preview" @loadeddata="ready = true" @emptied="ready = false"></video>
            <p v-if="starting" role="status">Opening camera… Allow access when your browser asks.</p>
            <div class="camera-capture-actions">
                <button type="button" class="button primary" :disabled="!ready || starting || capturing || disabled" @click="capturePhoto">{{ capturing ? 'Capturing…' : 'Capture photo' }}</button>
                <button v-if="!starting && !ready" type="button" class="button secondary" @click="startCamera(selectedCamera || undefined)">Try camera again</button>
            </div>
            <small>Preview stays on your device. Only captured photos are uploaded when you save. Microphone is off.</small>
        </div>
        <p v-if="error" role="alert" class="errors camera-message">{{ error }}</p>
        <p v-if="notice" role="status" class="camera-message">{{ notice }}</p>
        <details v-if="isMac" class="continuity-help">
            <summary>Use your iPhone camera on this Mac</summary>
            <p>Enable Continuity Camera on your iPhone under Settings → General → AirPlay &amp; Continuity (or AirPlay &amp; Handoff). Keep it nearby, locked, and stable, with its rear camera aimed at the receipt.</p>
            <p>Both devices need the same Apple Account with two-factor authentication and Wi-Fi/Bluetooth on. Open the camera, allow access, then select your iPhone in the Camera menu. You can also connect it by USB and trust this Mac.</p>
            <p>If it is missing, use Refresh cameras or check your browser’s camera settings. SpendTrace can only select cameras that macOS and your browser make available; it cannot discover or pair a nearby phone itself.</p>
            <a href="https://support.apple.com/en-us/102546" target="_blank" rel="noopener noreferrer">Apple’s Continuity Camera requirements and setup ↗</a>
        </details>
    </div>
</template>

<style scoped>
.receipt-camera { margin-top: 22px; }
.live-camera-panel { margin-top: 16px; text-align: left; }
.camera-toolbar { display: flex; flex-wrap: wrap; gap: 12px; align-items: end; margin-bottom: 15px; }
.camera-toolbar label { flex: 1 1 220px; min-width: 0; }
video { width: 100%; max-height: 65vh; display: block; object-fit: contain; background: #172c25; border-radius: 8px; }
.camera-capture-actions { display: flex; flex-wrap: wrap; gap: 12px; justify-content: center; margin: 18px 0 12px; }
.camera-message { margin-top: 16px; }
.continuity-help { text-align: left; margin-top: 18px; font-size: 12px; line-height: 1.6; }
.continuity-help summary { color: var(--green); font-weight: 600; }
.continuity-help a { text-decoration: underline; }
</style>
