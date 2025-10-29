<?php

declare(strict_types=1);

namespace App\Application\Actions\Ai;

use App\Application\Helpers\JsonResponse;
use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ListModelsAction
{
    private const CACHE_FILE = __DIR__ . '/../../../../../../storage/cache/gemini_models.json';
    private const CACHE_TTL = 2; // seconds

    /**
     * Get list of available Gemini models with caching
     */
    public function __invoke(Request $request, Response $response): Response
    {
        $apiKey = $_ENV['GEMINI_API_KEY'] ?? '';
        $configuredModel = $_ENV['GEMINI_MODEL'] ?? '';

        if (empty($apiKey)) {
            return JsonResponse::error($response, 'GEMINI_API_KEY not configured', 500, 'CONFIG_ERROR');
        }

        // Check cache
        $cachedData = $this->getCachedModels();
        if ($cachedData !== null) {
            return JsonResponse::success($response, [
                'configuredModel' => $configuredModel,
                'availableModels' => $cachedData,
                'cached' => true
            ]);
        }

        // Fetch from API
        try {
            $client = new Client(['timeout' => 5]);
            $apiResponse = $client->get(
                "https://generativelanguage.googleapis.com/v1beta/models?key={$apiKey}"
            );

            $body = json_decode($apiResponse->getBody()->getContents(), true);
            
            // Filter to only text generation models
            $models = [];
            if (isset($body['models']) && is_array($body['models'])) {
                foreach ($body['models'] as $model) {
                    if (isset($model['supportedGenerationMethods']) 
                        && in_array('generateContent', $model['supportedGenerationMethods'], true)
                        && isset($model['name'])
                    ) {
                        $models[] = [
                            'name' => $model['name'],
                            'displayName' => $model['displayName'] ?? $model['name'],
                            'description' => $model['description'] ?? ''
                        ];
                    }
                }
            }

            // Cache the results
            $this->cacheModels($models);

            return JsonResponse::success($response, [
                'configuredModel' => $configuredModel,
                'availableModels' => $models,
                'cached' => false
            ]);

        } catch (\Exception $e) {
            $maskedMessage = str_replace($apiKey, '***REDACTED***', $e->getMessage());
            return JsonResponse::error(
                $response,
                'Failed to fetch models: ' . $maskedMessage,
                500,
                'API_ERROR'
            );
        }
    }

    /**
     * Get cached models if available and not expired
     */
    private function getCachedModels(): ?array
    {
        if (!file_exists(self::CACHE_FILE)) {
            return null;
        }

        $cacheData = json_decode(file_get_contents(self::CACHE_FILE), true);
        if (!$cacheData || !isset($cacheData['timestamp']) || !isset($cacheData['models'])) {
            return null;
        }

        // Check if cache is still valid
        if (time() - $cacheData['timestamp'] > self::CACHE_TTL) {
            return null;
        }

        return $cacheData['models'];
    }

    /**
     * Cache models to file
     */
    private function cacheModels(array $models): void
    {
        $cacheDir = dirname(self::CACHE_FILE);
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        $cacheData = [
            'timestamp' => time(),
            'models' => $models
        ];

        file_put_contents(self::CACHE_FILE, json_encode($cacheData, JSON_PRETTY_PRINT));
    }
}

