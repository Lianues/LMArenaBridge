<?php
/**
 * Token Counter Utility
 * 
 * Provides token counting functionality for accurate usage tracking
 * and billing in the LMArena Bridge API system.
 */

class TokenCounter {
    
    /**
     * Count tokens in messages array
     * 
     * @param array $messages Array of message objects
     * @return int Total token count
     */
    public function countTokens($messages) {
        if (!is_array($messages)) {
            return 0;
        }
        
        $totalTokens = 0;
        
        foreach ($messages as $message) {
            if (isset($message['content'])) {
                if (is_string($message['content'])) {
                    $totalTokens += $this->countTextTokens($message['content']);
                } elseif (is_array($message['content'])) {
                    // Handle multimodal content
                    foreach ($message['content'] as $contentPart) {
                        if (isset($contentPart['type'])) {
                            switch ($contentPart['type']) {
                                case 'text':
                                    $totalTokens += $this->countTextTokens($contentPart['text'] ?? '');
                                    break;
                                case 'image_url':
                                    $totalTokens += $this->countImageTokens($contentPart['image_url'] ?? []);
                                    break;
                                default:
                                    // Unknown content type, estimate based on JSON size
                                    $totalTokens += $this->estimateTokensFromSize(json_encode($contentPart));
                                    break;
                            }
                        }
                    }
                }
            }
            
            // Add tokens for role and other metadata
            if (isset($message['role'])) {
                $totalTokens += $this->countTextTokens($message['role']);
            }
            
            if (isset($message['name'])) {
                $totalTokens += $this->countTextTokens($message['name']);
            }
        }
        
        // Add overhead tokens for message formatting
        $totalTokens += count($messages) * 3; // Approximate overhead per message
        
        return $totalTokens;
    }
    
    /**
     * Count tokens in text content
     * 
     * @param string $text Text content
     * @return int Token count
     */
    public function countTextTokens($text) {
        if (empty($text)) {
            return 0;
        }
        
        // Simple approximation: 1 token ≈ 4 characters for English text
        // This is a rough estimate - for production use, consider integrating
        // with a proper tokenizer library like tiktoken
        
        // Remove extra whitespace
        $text = preg_replace('/\s+/', ' ', trim($text));
        
        // Count words and punctuation separately
        $words = str_word_count($text);
        $punctuation = preg_match_all('/[^\w\s]/', $text);
        
        // Estimate tokens: words + punctuation + some overhead
        $estimatedTokens = $words + $punctuation + ceil(strlen($text) / 4);
        
        // Apply language-specific adjustments
        if ($this->isNonLatinScript($text)) {
            // Non-Latin scripts typically use more tokens per character
            $estimatedTokens = ceil(strlen($text) / 2);
        }
        
        return max(1, $estimatedTokens);
    }
    
    /**
     * Count tokens for image content
     * 
     * @param array $imageData Image data
     * @return int Token count
     */
    public function countImageTokens($imageData) {
        // Base token cost for image processing
        $baseTokens = 85;
        
        if (isset($imageData['detail'])) {
            switch ($imageData['detail']) {
                case 'low':
                    return $baseTokens;
                case 'high':
                    // High detail images cost more tokens
                    // This is a simplified calculation - actual costs depend on image dimensions
                    return $baseTokens + 170;
                default:
                    return $baseTokens;
            }
        }
        
        return $baseTokens;
    }
    
    /**
     * Estimate tokens from content size
     * 
     * @param string $content Content to estimate
     * @return int Estimated token count
     */
    public function estimateTokensFromSize($content) {
        $size = strlen($content);
        
        // Rough estimate: 1 token per 4 characters
        return max(1, ceil($size / 4));
    }
    
    /**
     * Check if text contains non-Latin scripts
     * 
     * @param string $text Text to check
     * @return bool True if contains non-Latin scripts
     */
    private function isNonLatinScript($text) {
        // Check for common non-Latin Unicode ranges
        $nonLatinPatterns = [
            '/[\x{4e00}-\x{9fff}]/u', // Chinese
            '/[\x{3040}-\x{309f}]/u', // Hiragana
            '/[\x{30a0}-\x{30ff}]/u', // Katakana
            '/[\x{0400}-\x{04ff}]/u', // Cyrillic
            '/[\x{0590}-\x{05ff}]/u', // Hebrew
            '/[\x{0600}-\x{06ff}]/u', // Arabic
            '/[\x{0900}-\x{097f}]/u', // Devanagari
        ];
        
        foreach ($nonLatinPatterns as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Count tokens in streaming response
     * 
     * @param string $responseChunk Response chunk
     * @return int Token count
     */
    public function countResponseTokens($responseChunk) {
        // Parse streaming response format
        if (strpos($responseChunk, 'data: ') === 0) {
            $jsonData = substr($responseChunk, 6);
            $data = json_decode($jsonData, true);
            
            if (isset($data['choices'][0]['delta']['content'])) {
                return $this->countTextTokens($data['choices'][0]['delta']['content']);
            }
        }
        
        // Fallback to direct text counting
        return $this->countTextTokens($responseChunk);
    }
    
    /**
     * Get token usage summary for a conversation
     * 
     * @param array $messages Input messages
     * @param string $response Generated response
     * @return array Token usage breakdown
     */
    public function getUsageSummary($messages, $response = '') {
        $inputTokens = $this->countTokens($messages);
        $outputTokens = $this->countTextTokens($response);
        $totalTokens = $inputTokens + $outputTokens;
        
        return [
            'prompt_tokens' => $inputTokens,
            'completion_tokens' => $outputTokens,
            'total_tokens' => $totalTokens
        ];
    }
    
    /**
     * Validate token limits for model
     * 
     * @param string $model Model name
     * @param int $tokenCount Token count to validate
     * @return array Validation result
     */
    public function validateTokenLimit($model, $tokenCount) {
        $limits = $this->getModelTokenLimits();
        $modelLimit = $limits[$model] ?? $limits['default'];
        
        $isValid = $tokenCount <= $modelLimit;
        $remaining = max(0, $modelLimit - $tokenCount);
        
        return [
            'valid' => $isValid,
            'limit' => $modelLimit,
            'used' => $tokenCount,
            'remaining' => $remaining,
            'percentage' => ($tokenCount / $modelLimit) * 100
        ];
    }
    
    /**
     * Get token limits for different models
     * 
     * @return array Model token limits
     */
    private function getModelTokenLimits() {
        return [
            'gpt-4' => 8192,
            'gpt-4-32k' => 32768,
            'gpt-3.5-turbo' => 4096,
            'gpt-3.5-turbo-16k' => 16384,
            'claude-3-opus' => 200000,
            'claude-3-sonnet' => 200000,
            'claude-3-haiku' => 200000,
            'gemini-pro' => 32768,
            'gemini-pro-vision' => 16384,
            'default' => 4096
        ];
    }
    
    /**
     * Estimate cost based on token usage
     * 
     * @param string $model Model name
     * @param int $inputTokens Input token count
     * @param int $outputTokens Output token count
     * @return array Cost breakdown
     */
    public function estimateCost($model, $inputTokens, $outputTokens) {
        $pricing = $this->getModelPricing();
        $modelPricing = $pricing[$model] ?? $pricing['default'];
        
        $inputCost = ($inputTokens / 1000) * $modelPricing['input'];
        $outputCost = ($outputTokens / 1000) * $modelPricing['output'];
        $totalCost = $inputCost + $outputCost;
        
        return [
            'input_cost' => $inputCost,
            'output_cost' => $outputCost,
            'total_cost' => $totalCost,
            'currency' => 'USD'
        ];
    }
    
    /**
     * Get pricing information for models
     * 
     * @return array Model pricing (per 1K tokens)
     */
    private function getModelPricing() {
        return [
            'gpt-4' => ['input' => 0.03, 'output' => 0.06],
            'gpt-4-32k' => ['input' => 0.06, 'output' => 0.12],
            'gpt-3.5-turbo' => ['input' => 0.001, 'output' => 0.002],
            'gpt-3.5-turbo-16k' => ['input' => 0.003, 'output' => 0.004],
            'claude-3-opus' => ['input' => 0.015, 'output' => 0.075],
            'claude-3-sonnet' => ['input' => 0.003, 'output' => 0.015],
            'claude-3-haiku' => ['input' => 0.00025, 'output' => 0.00125],
            'default' => ['input' => 0.01, 'output' => 0.02]
        ];
    }
}
