<?php

declare(strict_types=1);

namespace App\Application\Actions\User;

use App\Application\Helpers\JsonResponse;
use App\Services\EncryptionService;
use App\Services\GeminiService;
use App\Services\SubscriptionTierService;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class UpdateUserAiSettingsAction
{
    private PDO $db;
    private EncryptionService $encryption;
    private GeminiService $gemini;
    private SubscriptionTierService $tiers;

    public function __construct(
        PDO $db,
        EncryptionService $encryption,
        GeminiService $gemini,
        SubscriptionTierService $tiers
    ) {
        $this->db = $db;
        $this->encryption = $encryption;
        $this->gemini = $gemini;
        $this->tiers = $tiers;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $userId = (int) $request->getAttribute('user_id');

        if (!$this->tiers->canManageByok($userId)) {
            return JsonResponse::error(
                $response,
                'Bring your own Gemini key is available on Ultra only',
                403,
                'FORBIDDEN'
            );
        }

        $data = $request->getParsedBody() ?? [];
        $apiKey = trim((string) ($data['apiKey'] ?? ''));

        if ($apiKey === '' || strlen($apiKey) < 20) {
            return JsonResponse::error($response, 'A valid Gemini API key is required', 422);
        }

        if (!$this->gemini->verifyApiKey($apiKey)) {
            return JsonResponse::error(
                $response,
                'Could not verify this API key with Google. Check the key and try again.',
                422
            );
        }

        $encrypted = $this->encryption->encrypt($apiKey);
        $hint = EncryptionService::keyHint($apiKey);

        $stmt = $this->db->prepare(
            'UPDATE users SET gemini_api_key_encrypted = ?, gemini_api_key_hint = ? WHERE id = ?'
        );
        $stmt->execute([$encrypted, $hint, $userId]);

        return JsonResponse::success($response, [
            'hasCustomKey' => true,
            'keyHint' => $hint,
        ]);
    }
}
