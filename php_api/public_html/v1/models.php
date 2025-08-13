<?php
/**
 * OpenAI-Compatible Models Endpoint
 * 
 * Returns available models in OpenAI format for the
 * LMArena Bridge distributed API system.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => ['message' => 'Method not allowed', 'type' => 'invalid_request_error']]);
    exit;
}

require_once __DIR__ . '/../../includes/ApiAuth.php';

try {
    // Initialize authentication
    $auth = new ApiAuth();
    
    // Authenticate request (optional for models endpoint, but good for analytics)
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $user = $auth->authenticate($authHeader);
    
    // Load available models from configuration
    $modelsConfig = loadModelsConfiguration();
    
    // Format models in OpenAI format
    $models = [];
    foreach ($modelsConfig as $modelName => $modelInfo) {
        $models[] = [
            'id' => $modelName,
            'object' => 'model',
            'created' => time(),
            'owned_by' => 'lmarena-bridge',
            'permission' => [
                [
                    'id' => 'modelperm-' . md5($modelName),
                    'object' => 'model_permission',
                    'created' => time(),
                    'allow_create_engine' => false,
                    'allow_sampling' => true,
                    'allow_logprobs' => false,
                    'allow_search_indices' => false,
                    'allow_view' => true,
                    'allow_fine_tuning' => false,
                    'organization' => '*',
                    'group' => null,
                    'is_blocking' => false
                ]
            ],
            'root' => $modelName,
            'parent' => null,
            'capabilities' => $modelInfo['capabilities'] ?? [
                'chat_completion' => true,
                'streaming' => true
            ],
            'context_length' => $modelInfo['context_length'] ?? 4096,
            'description' => $modelInfo['description'] ?? "AI model available through LMArena",
            'pricing' => $modelInfo['pricing'] ?? null
        ];
    }
    
    // Sort models alphabetically
    usort($models, function($a, $b) {
        return strcmp($a['id'], $b['id']);
    });
    
    // Return response
    echo json_encode([
        'object' => 'list',
        'data' => $models
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    error_log("Models endpoint error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => [
            'message' => 'Internal server error',
            'type' => 'internal_server_error'
        ]
    ]);
}

/**
 * Load models configuration from various sources
 */
function loadModelsConfiguration() {
    $models = [];
    
    // Try to load from models.json (from original system)
    $modelsJsonPath = __DIR__ . '/../../../models.json';
    if (file_exists($modelsJsonPath)) {
        $modelsJson = json_decode(file_get_contents($modelsJsonPath), true);
        if ($modelsJson) {
            foreach ($modelsJson as $modelName => $modelId) {
                $models[$modelName] = [
                    'id' => $modelId,
                    'type' => 'text',
                    'capabilities' => ['chat_completion' => true, 'streaming' => true]
                ];
                
                // Detect image models
                if (strpos($modelId, ':image') !== false) {
                    $models[$modelName]['type'] = 'image';
                    $models[$modelName]['capabilities']['image_generation'] = true;
                }
            }
        }
    }
    
    // Load additional model metadata
    $metadataPath = __DIR__ . '/../../config/model_metadata.json';
    if (file_exists($metadataPath)) {
        $metadata = json_decode(file_get_contents($metadataPath), true);
        if ($metadata) {
            foreach ($metadata as $modelName => $modelMeta) {
                if (isset($models[$modelName])) {
                    $models[$modelName] = array_merge($models[$modelName], $modelMeta);
                }
            }
        }
    }
    
    // Add default models if none found
    if (empty($models)) {
        $models = getDefaultModels();
    }
    
    return $models;
}

/**
 * Get default models configuration
 */
function getDefaultModels() {
    return [
        'gpt-3.5-turbo' => [
            'id' => 'default-gpt-3.5-turbo',
            'type' => 'text',
            'capabilities' => ['chat_completion' => true, 'streaming' => true],
            'context_length' => 4096,
            'description' => 'GPT-3.5 Turbo model via LMArena',
            'pricing' => ['input' => 0.001, 'output' => 0.002]
        ],
        'gpt-4' => [
            'id' => 'default-gpt-4',
            'type' => 'text',
            'capabilities' => ['chat_completion' => true, 'streaming' => true],
            'context_length' => 8192,
            'description' => 'GPT-4 model via LMArena',
            'pricing' => ['input' => 0.03, 'output' => 0.06]
        ],
        'claude-3-sonnet' => [
            'id' => 'default-claude-3-sonnet',
            'type' => 'text',
            'capabilities' => ['chat_completion' => true, 'streaming' => true],
            'context_length' => 200000,
            'description' => 'Claude 3 Sonnet model via LMArena',
            'pricing' => ['input' => 0.003, 'output' => 0.015]
        ]
    ];
}
?>
