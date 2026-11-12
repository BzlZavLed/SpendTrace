<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AiAccess;
use App\Services\ExtractionValidator;
use App\Services\OpenAiReceiptExtractor;
use App\Services\ReceiptExtractionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class MobileReceiptController extends Controller
{
    public function session(Request $request, AiAccess $access): JsonResponse
    {
        $data = $request->validate(['email' => 'required|email|max:255', 'password' => 'required|string|max:1000']);
        $user = User::where('email', $data['email'])->first();
        /** Keep nonexistent users on the password-check path too. */
        $valid = Hash::check($data['password'], $user?->password ?? '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
        abort_unless($user && $valid, 401, 'The email or password is incorrect.');
        abort_unless($access->allowed($user), 403, 'AI access is not enabled for this account.');
        $token = Crypt::encryptString(json_encode([
            'scope' => 'mobile-receipt-extraction', 'user' => $user->id,
            'password_version' => hash('sha256', $user->password), 'expires' => now()->addDays(30)->timestamp,
        ], JSON_THROW_ON_ERROR));

        return response()->json(['token' => $token, 'expires_at' => now()->addDays(30)->toIso8601String(), 'preview' => (bool) config('spendtrace.ai_preview_enabled')])->header('Cache-Control', 'no-store');
    }

    public function extract(Request $request, OpenAiReceiptExtractor $extractor, ExtractionValidator $validator, AiAccess $access): JsonResponse
    {
        try {
            $claims = json_decode(Crypt::decryptString($request->bearerToken() ?? ''), true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            abort(401, 'Sign in again to use OpenAI processing.');
        }
        $user = User::find($claims['user'] ?? null);
        abort_unless($user && ($claims['scope'] ?? '') === 'mobile-receipt-extraction'
            && ($claims['expires'] ?? 0) > now()->timestamp
            && hash_equals(hash('sha256', $user->password), $claims['password_version'] ?? ''), 401, 'Sign in again to use OpenAI processing.');
        abort_unless($access->allowed($user), 403, 'AI access is not enabled for this account.');
        $request->validate([
            'consent' => 'required|accepted',
            'image' => 'required|file|mimes:jpeg,png|max:10240|dimensions:max_width=6000,max_height=6000',
            'user_id' => 'missing', 'account_id' => 'missing', 'connection_id' => 'missing', 'receipt_id' => 'missing',
        ]);
        try {
            $image = $request->file('image');
            $result = $validator->validate($extractor->extractImages([['bytes' => $image->getContent(), 'mime' => $image->getMimeType()]]));
        } catch (\Throwable $exception) {
            Log::warning('Mobile receipt extraction failed', $exception instanceof ReceiptExtractionException ? $exception->diagnostics : ['reason' => 'extraction_failed']);

            return response()->json(['message' => 'OpenAI processing failed. Retry explicitly, or process on this iPhone.'])->setStatusCode(502)->header('Cache-Control', 'no-store');
        }

        return response()->json($result)->header('Cache-Control', 'no-store');
    }
}
