<?php

namespace App\Http\Controllers;

use App\Jobs\ExtractReceipt;
use App\Models\Receipt;
use App\Models\ReceiptImage;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReceiptController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Scan');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['images' => 'required|array|min:1|max:6', 'images.*' => 'required|file|max:10240']);
        $files = $request->file('images');
        foreach ($files as $file) {
            if (in_array(strtolower($file->getClientOriginalExtension()), ['heic', 'heif']) || str_contains($file->getMimeType(), 'hei')) {
                throw ValidationException::withMessages(['images' => 'HEIC/HEIF needs conversion: export as JPEG from Photos or Files, or use iPhone Settings → Camera → Formats → Most Compatible for new photos.']);
            }
            if (! in_array($file->getMimeType(), ['image/jpeg', 'image/png', 'image/webp']) || ! ($dimensions = @getimagesize($file->getPathname())) || $dimensions[0] * $dimensions[1] > 50000000) {
                throw ValidationException::withMessages(['images' => 'Use a valid JPEG, PNG, or WebP image (up to 10 MB and 50 megapixels each).']);
            }
        }
        $paths = [];
        try {
            $result = DB::transaction(function () use ($request, $files, &$paths): array {
                User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
                $hashes = array_map(fn ($file) => hash_file('sha256', $file->getPathname()), $files);
                if (count($hashes) !== count(array_unique($hashes))) {
                    throw ValidationException::withMessages(['images' => 'The same image was selected twice. Remove the extra copy.']);
                }
                $existing = ReceiptImage::where('user_id', $request->user()->id)->whereIn('sha256', $hashes)->get();
                if ($existing->isNotEmpty()) {
                    if ($existing->count() === count($hashes) && $existing->pluck('receipt_id')->unique()->count() === 1) {
                        return ['transaction' => Transaction::where('receipt_id', $existing->first()->receipt_id)->firstOrFail(), 'duplicate' => true];
                    }
                    throw ValidationException::withMessages(['images' => 'One or more photos already belong to a saved receipt. Open the existing receipt, or select only new photos.']);
                }
                $receipt = Receipt::create(['user_id' => $request->user()->id, 'provider' => config('spendtrace.provider')]);
                foreach ($files as $i => $file) {
                    $path = $file->store('receipts/'.$request->user()->id.'/'.$receipt->id, 'local');
                    if (! $path) {
                        throw new \RuntimeException('Could not save image.');
                    }
                    $paths[] = $path;
                    $receipt->images()->create(['user_id' => $request->user()->id, 'path' => $path, 'sha256' => $hashes[$i], 'mime' => $file->getMimeType(), 'bytes' => $file->getSize()]);
                }
                $transaction = $receipt->transaction()->create(['user_id' => $request->user()->id, 'review_reasons' => ['Extraction queued.'], 'corrected_fields' => []]);

                return compact('transaction') + ['duplicate' => false];
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($paths);
            throw $e;
        }
        if (! $result['duplicate']) {
            $this->dispatch($result['transaction']->receipt);
        }

        return to_route('transactions.show', $result['transaction'])->with('notice', $result['duplicate'] ? 'These exact images are already saved. Opened the existing receipt.' : 'Originals saved privately. You can close this page while extraction runs.');
    }

    public function retry(Request $request, Receipt $receipt): RedirectResponse
    {
        abort_unless($receipt->user_id === $request->user()->id, 404);
        DB::transaction(function () use ($receipt): void {
            $locked = Receipt::whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            $locked->update(['generation' => $locked->generation + 1, 'status' => 'queued', 'error' => null, 'provider' => config('spendtrace.provider')]);
        });
        $this->dispatch($receipt->fresh());

        return back()->with('notice', 'Extraction queued again. Your corrected fields will be preserved.');
    }

    private function dispatch(Receipt $receipt): void
    {
        try {
            ExtractReceipt::dispatch($receipt->id, $receipt->generation);
        } catch (\Throwable $e) {
            $receipt->update(['status' => 'failed', 'error' => 'Queue unavailable. Originals are safe; retry after starting the queue.']);
        }
    }

    public function image(Request $request, ReceiptImage $image): StreamedResponse
    {
        abort_unless($image->user_id === $request->user()->id, 404);
        abort_unless(Storage::disk('local')->exists($image->path), 404);

        return Storage::disk('local')->response($image->path, 'receipt-'.$image->id, ['Content-Type' => $image->mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
