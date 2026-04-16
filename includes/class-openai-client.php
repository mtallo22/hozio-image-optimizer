<?php
/**
 * OpenAI API Client for image analysis
 */

if (!defined('ABSPATH')) {
    exit;
}

class Hozio_Image_Optimizer_OpenAI_Client {

    /**
     * API base URL
     */
    private $api_base = 'https://api.openai.com/v1';

    /**
     * API key
     */
    private $api_key;

    /**
     * Model to use
     */
    private $model;

    /**
     * Constructor
     */
    public function __construct($api_key = null) {
        $this->api_key = $api_key ?: get_option('hozio_openai_api_key', '');
        $this->model = get_option('hozio_openai_model', 'gpt-4o');
    }

    /**
     * Test API connection
     */
    public function test_connection() {
        if (empty($this->api_key)) {
            return array(
                'success' => false,
                'error' => __('API key is not configured', 'hozio-image-optimizer'),
            );
        }

        $response = $this->make_request('GET', '/models');

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'error' => $response->get_error_message(),
            );
        }

        return array(
            'success' => true,
            'message' => __('API connection successful', 'hozio-image-optimizer'),
        );
    }

    /**
     * Get available models
     */
    public function get_models() {
        $response = $this->make_request('GET', '/models');

        if (is_wp_error($response)) {
            throw new Exception($response->get_error_message());
        }

        $models = array();
        if (isset($response['data'])) {
            foreach ($response['data'] as $model) {
                // Filter for vision-capable models
                if (strpos($model['id'], 'gpt-4') !== false) {
                    $models[] = array(
                        'id' => $model['id'],
                        'name' => $model['id'],
                    );
                }
            }
        }

        // Sort alphabetically
        usort($models, function($a, $b) {
            return strcmp($a['id'], $b['id']);
        });

        return $models;
    }

    /**
     * Analyze an image using Vision API
     *
     * @param string $image_path Path to image file
     * @param array $options Analysis options
     * @return array Analysis results
     */
    public function analyze_image($image_path, $options = array()) {
        if (empty($this->api_key)) {
            return array(
                'success' => false,
                'error' => __('API key is not configured', 'hozio-image-optimizer'),
            );
        }

        if (!file_exists($image_path)) {
            return array(
                'success' => false,
                'error' => __('Image file not found', 'hozio-image-optimizer'),
            );
        }

        // Default options
        $defaults = array(
            'location' => '',
            'keyword_hint' => '',
            'generate_alt_text' => get_option('hozio_enable_ai_alt_text', true),
            'generate_caption' => get_option('hozio_enable_ai_caption', true),
            'generate_tags' => get_option('hozio_enable_ai_tagging', false),
        );
        $options = wp_parse_args($options, $defaults);

        // Read and encode image
        $image_data = file_get_contents($image_path);
        if (!$image_data) {
            return array(
                'success' => false,
                'error' => __('Failed to read image file', 'hozio-image-optimizer'),
            );
        }

        $base64_image = base64_encode($image_data);
        $mime_type = mime_content_type($image_path);

        // Build the prompt
        $prompt = $this->build_analysis_prompt($options);

        // Build API payload
        $payload = array(
            'model' => $this->model,
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => array(
                        array(
                            'type' => 'text',
                            'text' => $prompt,
                        ),
                        array(
                            'type' => 'image_url',
                            'image_url' => array(
                                'url' => "data:{$mime_type};base64,{$base64_image}",
                            ),
                        ),
                    ),
                ),
            ),
            'max_tokens' => 500,
        );

        // Make API request
        $response = $this->make_request('POST', '/chat/completions', $payload);

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'error' => $response->get_error_message(),
            );
        }

        // Parse response
        if (!isset($response['choices'][0]['message']['content'])) {
            return array(
                'success' => false,
                'error' => __('Invalid API response', 'hozio-image-optimizer'),
            );
        }

        $content = $response['choices'][0]['message']['content'];

        // Track API usage for cost monitoring
        $this->track_api_usage($response);

        // Try to parse as JSON
        $parsed = $this->parse_response($content, $options);

        return array(
            'success' => true,
            'raw_response' => $content,
            'parsed' => $parsed,
        );
    }

    /**
     * Track API usage for cost monitoring
     */
    private function track_api_usage($response) {
        if (!isset($response['usage'])) {
            return;
        }

        $usage = $response['usage'];
        $prompt_tokens = $usage['prompt_tokens'] ?? 0;
        $completion_tokens = $usage['completion_tokens'] ?? 0;
        $total_tokens = $usage['total_tokens'] ?? 0;

        // Get current stats
        $stats = get_option('hozio_api_usage_stats', array(
            'total_requests' => 0,
            'total_prompt_tokens' => 0,
            'total_completion_tokens' => 0,
            'total_tokens' => 0,
            'estimated_cost' => 0,
            'history' => array(),
        ));

        // Update totals
        $stats['total_requests']++;
        $stats['total_prompt_tokens'] += $prompt_tokens;
        $stats['total_completion_tokens'] += $completion_tokens;
        $stats['total_tokens'] += $total_tokens;

        // Calculate cost based on model pricing (per 1M tokens)
        // GPT-4o: $2.50/1M input, $10.00/1M output
        // GPT-4o-mini: $0.15/1M input, $0.60/1M output
        // GPT-4-turbo: $10.00/1M input, $30.00/1M output
        $model_pricing = array(
            'gpt-4o' => array('input' => 2.50, 'output' => 10.00),
            'gpt-4o-mini' => array('input' => 0.15, 'output' => 0.60),
            'gpt-4-turbo' => array('input' => 10.00, 'output' => 30.00),
        );

        $pricing = $model_pricing[$this->model] ?? $model_pricing['gpt-4o'];
        $input_cost = ($prompt_tokens / 1000000) * $pricing['input'];
        $output_cost = ($completion_tokens / 1000000) * $pricing['output'];
        $request_cost = $input_cost + $output_cost;

        $stats['estimated_cost'] += $request_cost;

        // Add to history (keep last 100 entries)
        $stats['history'][] = array(
            'timestamp' => current_time('mysql'),
            'model' => $this->model,
            'prompt_tokens' => $prompt_tokens,
            'completion_tokens' => $completion_tokens,
            'cost' => $request_cost,
        );

        // Keep only last 100 history entries
        if (count($stats['history']) > 100) {
            $stats['history'] = array_slice($stats['history'], -100);
        }

        // Save stats
        update_option('hozio_api_usage_stats', $stats);
    }

    /**
     * Build the analysis prompt based on options
     */
    private function build_analysis_prompt($options) {
        $site_name = get_bloginfo('name');
        $site_description = get_bloginfo('description');
        $max_filename_length = get_option('hozio_max_filename_length', 50);
        $keyword_word_count = get_option('hozio_keyword_word_count', 5);

        // Get custom prompts from settings (if set)
        $custom_filename_prompt = get_option('hozio_prompt_filename', '');
        $custom_title_prompt = get_option('hozio_prompt_title', '');
        $custom_alt_prompt = get_option('hozio_prompt_alt_text', '');
        $custom_caption_prompt = get_option('hozio_prompt_caption', '');
        $custom_description_prompt = get_option('hozio_prompt_description', '');

        $prompt_parts = array();

        $prompt_parts[] = "You are an SEO expert analyzing images. Provide the following in JSON format:";

        // Filename - use custom prompt or default
        if (!empty($custom_filename_prompt)) {
            $prompt_parts[] = "\n\n1. \"filename\": " . $custom_filename_prompt;
        } else {
            $prompt_parts[] = "\n\n1. \"filename\": Create an SEO-optimized filename that:";
            $prompt_parts[] = "\n   - Describes the PRIMARY subject/object in the image clearly and specifically";
            $prompt_parts[] = "\n   - Uses approximately {$keyword_word_count} descriptive words separated by hyphens (this is the keyword portion)";
            $prompt_parts[] = "\n   - Is lowercase with no file extension";
            $prompt_parts[] = "\n   - Includes relevant, searchable keywords a user might search for";
            $prompt_parts[] = "\n   - Should be descriptive enough to stand alone (max {$max_filename_length} characters)";
        }

        // Title - use custom prompt or default
        if (!empty($custom_title_prompt)) {
            $prompt_parts[] = "\n\n2. \"title\": " . $custom_title_prompt;
        } else {
            $prompt_parts[] = "\n\n2. \"title\": A detailed, descriptive title (5-10 words, proper capitalization) that clearly tells users what they're looking at and includes relevant keywords.";
        }

        // Alt text - use custom prompt or default
        if ($options['generate_alt_text']) {
            if (!empty($custom_alt_prompt)) {
                $prompt_parts[] = "\n\n3. \"alt_text\": " . $custom_alt_prompt;
            } else {
                $prompt_parts[] = "\n\n3. \"alt_text\": Write SEO-optimized alt text following these best practices:";
                $prompt_parts[] = "\n   - Describe the image as if explaining to someone who cannot see it";
                $prompt_parts[] = "\n   - Be specific and concise (max 125 characters)";
                $prompt_parts[] = "\n   - Include the main subject and relevant context";
                $prompt_parts[] = "\n   - Naturally incorporate keywords without stuffing";
                $prompt_parts[] = "\n   - Do NOT start with 'Image of', 'Picture of', or 'Photo of'";
                $prompt_parts[] = "\n   - Do NOT include 'alt text' or 'description' in the text";
            }
        }

        // Caption - only include if enabled
        if ($options['generate_caption']) {
            if (!empty($custom_caption_prompt)) {
                $prompt_parts[] = "\n\n4. \"caption\": " . $custom_caption_prompt;
            } else {
                $prompt_parts[] = "\n\n4. \"caption\": Write an engaging caption that:";
                $prompt_parts[] = "\n   - Provides context or additional information about the image";
                $prompt_parts[] = "\n   - Is 1-2 sentences, conversational but professional";
                $prompt_parts[] = "\n   - Could encourage user engagement or provide value";
            }
        }

        // Description - use custom prompt or default
        if (!empty($custom_description_prompt)) {
            $prompt_parts[] = "\n\n5. \"description\": " . $custom_description_prompt;
        } else {
            $prompt_parts[] = "\n\n5. \"description\": Write a detailed description that:";
            $prompt_parts[] = "\n   - Provides comprehensive context about the image";
            $prompt_parts[] = "\n   - Is 2-4 sentences with relevant details";
            $prompt_parts[] = "\n   - Includes relevant keywords naturally";
            $prompt_parts[] = "\n   - Helps with SEO and accessibility";
        }

        // Tags
        if ($options['generate_tags']) {
            $prompt_parts[] = "\n\n6. \"tags\": An array of 3-5 relevant keywords/tags for categorization and SEO.";
        }

        // Add context hints - these are important for accurate analysis
        if (!empty($options['location'])) {
            $prompt_parts[] = "\n\n**IMPORTANT CONTEXT - Location:** This image is related to \"{$options['location']}\". The location will be appended to the filename separately, so DO NOT include location in the filename field. However, DO include location in the title and alt text where relevant.";
        }

        if (!empty($options['keyword_hint'])) {
            $prompt_parts[] = "\n\n**IMPORTANT CONTEXT - Topic/Industry:** This image is about \"{$options['keyword_hint']}\". Use this context to understand what the image represents and incorporate relevant terminology.";
        }

        // Instructions
        $prompt_parts[] = "\n\n**CRITICAL RULES:**";
        $prompt_parts[] = "\n- The filename should be a DESCRIPTIVE keyword phrase (approximately {$keyword_word_count} words, under {$max_filename_length} chars)";
        $prompt_parts[] = "\n- NEVER use generic words like 'image', 'photo', 'picture', 'graphic', 'illustration' in filename";
        $prompt_parts[] = "\n- Identify the SPECIFIC subject (e.g., 'stainless-steel-kitchen-sink' not 'kitchen-item')";
        $prompt_parts[] = "\n- For products/services: describe what it IS, not what it looks like";
        $prompt_parts[] = "\n- For places: include identifying features or location name in title/alt, NOT in filename";
        $prompt_parts[] = "\n- For people: describe the action/context, not physical appearance";
        $prompt_parts[] = "\n- Return ONLY valid JSON, no markdown formatting or extra text";

        return implode('', $prompt_parts);
    }

    /**
     * Parse the AI response
     */
    private function parse_response($content, $options) {
        // Try to extract JSON from response
        $content = trim($content);

        // Remove markdown code blocks if present
        $content = preg_replace('/^```json\s*/', '', $content);
        $content = preg_replace('/\s*```$/', '', $content);
        $content = preg_replace('/^```\s*/', '', $content);

        // Try to parse as JSON
        $parsed = json_decode($content, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
            // Clean up filename
            if (isset($parsed['filename'])) {
                $parsed['filename'] = Hozio_Image_Optimizer_Helpers::sanitize_seo_filename($parsed['filename']);
            }

            return $parsed;
        }

        // Fallback: Try to extract values from text
        $result = array();

        // Extract filename
        if (preg_match('/filename["\s:]+([a-z0-9\-]+)/i', $content, $matches)) {
            $result['filename'] = Hozio_Image_Optimizer_Helpers::sanitize_seo_filename($matches[1]);
        }

        // Extract title
        if (preg_match('/title["\s:]+["\']*([^"\'}\n]+)/i', $content, $matches)) {
            $result['title'] = trim($matches[1], '"\' ');
        }

        // Extract alt text
        if (preg_match('/alt_text["\s:]+["\']*([^"\'}\n]+)/i', $content, $matches)) {
            $result['alt_text'] = trim($matches[1], '"\' ');
        }

        return $result;
    }

    /**
     * Make an API request
     */
    private function make_request($method, $endpoint, $data = null) {
        $url = $this->api_base . $endpoint;

        $args = array(
            'method' => $method,
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json',
            ),
            'timeout' => 60,
        );

        if ($data) {
            $args['body'] = wp_json_encode($data);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            Hozio_Image_Optimizer_Helpers::log('API Error: ' . $response->get_error_message(), 'error');
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);

        if ($code >= 400) {
            $error_message = isset($decoded['error']['message'])
                ? $decoded['error']['message']
                : "API error (HTTP {$code})";

            Hozio_Image_Optimizer_Helpers::log('API Error: ' . $error_message, 'error');
            return new WP_Error('api_error', $error_message);
        }

        return $decoded;
    }

    /**
     * Validate API key format
     */
    public static function validate_api_key($api_key) {
        // OpenAI keys start with "sk-" and are quite long
        return preg_match('/^sk-[a-zA-Z0-9]{20,}$/', $api_key);
    }
}
