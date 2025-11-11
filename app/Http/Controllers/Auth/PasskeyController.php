<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Passkey\PasskeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class PasskeyController extends Controller
{
    public function __construct(private readonly PasskeyService $service)
    {
    }

    public function createRegistrationChallenge(Request $request): JsonResponse
    {
        $data = $request->validate([
            'userHandle' => 'required|string',
            'displayName' => 'nullable|string',
        ]);

        $result = $this->service->createRegistrationChallenge($data['userHandle'], $data['displayName'] ?? null);

        return response()->json($result);
    }

    public function finishRegistration(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challengeId' => 'required|string',
            'credential' => 'required|array',
            'name' => 'nullable|string',
        ]);

        try {
            $passkey = $this->service->completeRegistration($data['credential'], $data['challengeId'], $data['name'] ?? null);
        } catch (RuntimeException $exception) {
            Log::error('Passkey registration failed.', ['error' => $exception->getMessage()]);
            return response()->json([
                'message' => 'Registration failed.',
                'error' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => 'Registration successful.',
            'credentialId' => $passkey->credential_id,
        ]);
    }

    public function createLoginChallenge(Request $request): JsonResponse
    {
        $data = $request->validate([
            'userHandle' => 'required|string',
        ]);

        try {
            $challenge = $this->service->createLoginChallenge($data['userHandle']);
        } catch (RuntimeException $exception) {
            Log::error('Passkey login challenge failed.', ['error' => $exception->getMessage()]);
            return response()->json([
                'message' => 'Unable to create challenge.',
                'error' => $exception->getMessage(),
            ], 422);
        }

        return response()->json($challenge);
    }

    public function finishLogin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challengeId' => 'required|string',
            'credential' => 'required|array',
        ]);

        try {
            $passkey = $this->service->verifyLogin($data['credential'], $data['challengeId']);
        } catch (RuntimeException $exception) {
            Log::error('Passkey login failed.', ['error' => $exception->getMessage()]);
            return response()->json([
                'message' => 'Login failed.',
                'error' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => 'Login successful.',
            'credentialId' => $passkey->credential_id,
            'userHandle' => $passkey->user_handle,
        ]);
    }
}
