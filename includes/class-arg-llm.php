<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ARG LLM wrapper for OpenAI-compatible APIs
 */
class ARG_LLM {
    /**
     * Generate paraphrased review text for a product given a numeric rating.
     * Returns array of generated texts on success, false on failure.
     */
    public static function generate_reviews_for_rating( $product, $rating, $count = 1 ) {
        $opts = get_option( 'arg_options', array() );
        if ( empty( $opts['enable_llm'] ) || empty( $opts['llm_provider'] ) || 'openai' !== $opts['llm_provider'] ) {
            return false;
        }

        $api_key = isset( $opts['llm_api_key'] ) ? $opts['llm_api_key'] : '';
        if ( empty( $api_key ) ) {
            update_option( 'arg_last_llm_error', 'No API key configured' );
            return false;
        }

        $model = isset( $opts['model'] ) ? $opts['model'] : 'gpt-3.5-turbo';
        $temperature = isset( $opts['temperature'] ) ? floatval( $opts['temperature'] ) : 0.7;
        $max_tokens = isset( $opts['max_tokens'] ) ? intval( $opts['max_tokens'] ) : 200;
        $forbidden = isset( $opts['forbidden_content'] ) ? $opts['forbidden_content'] : '';

        $examples_key = 'examples_' . intval( $rating );
        $examples_raw = isset( $opts[ $examples_key ] ) ? $opts[ $examples_key ] : '';
        $examples = self::parse_examples( $examples_raw );

        // Build messages (system + user few-shot)
        $system = "You are a helpful assistant that writes realistic product reviews. Be concise, human, and natural. Do NOT mention AI or that the text was generated. Avoid personal data and profanity. Follow the example reviews' style and tone. Respect forbidden phrases: $forbidden. Do NOT infer or include personal data (names, addresses, phone numbers) from product descriptions.";

        $user_msg = "Here are example reviews (do not repeat them verbatim):\n";
        foreach ( $examples as $i => $ex ) {
            $user_msg .= "Example " . ( $i + 1 ) . ": " . $ex . "\n";
        }

        $title = is_object( $product ) && isset( $product->post_title ) ? $product->post_title : ( is_array( $product ) && isset( $product['post_title'] ) ? $product['post_title'] : '' );

        // Optionally include short product description (sanitized & truncated)
        if ( ! empty( $opts['llm_include_short_description'] ) ) {
            $max_chars = isset( $opts['llm_description_max_chars'] ) ? intval( $opts['llm_description_max_chars'] ) : 300;
            $desc = '';
            if ( is_object( $product ) ) {
                if ( isset( $product->post_excerpt ) && trim( $product->post_excerpt ) !== '' ) {
                    $desc = $product->post_excerpt;
                } elseif ( isset( $product->post_content ) && trim( $product->post_content ) !== '' ) {
                    $desc = wp_trim_words( $product->post_content, 50, '...' );
                } elseif ( method_exists( $product, 'get_short_description' ) ) {
                    $desc = $product->get_short_description();
                } elseif ( method_exists( $product, 'get_description' ) ) {
                    $desc = $product->get_description();
                }
            } elseif ( is_array( $product ) && isset( $product['post_excerpt'] ) ) {
                $desc = $product['post_excerpt'];
            }

            $desc = wp_strip_all_tags( $desc );
            // redact simple emails and phone-like patterns
            $desc = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[redacted]', $desc);
            $desc = preg_replace('/\+?\d[\d\s\-\(\)]{5,}\d/', '[redacted]', $desc);
            if ( mb_strlen( $desc ) > $max_chars ) {
                $desc = mb_substr( $desc, 0, $max_chars ) . '...';
            }
            if ( $desc !== '' ) {
                $user_msg .= "\nProduct short description: " . $desc;
            }
        }

        $tone = self::rating_to_tone( $rating );

        $user_msg .= "\nTask: Write a single " . strtolower( $tone ) . " review for the product '" . $title . "'. Output only the review text, about 80-160 words, similar in style to the examples.";

        $body = array(
            'model' => $model,
            'messages' => array(
                array( 'role' => 'system', 'content' => $system ),
                array( 'role' => 'user', 'content' => $user_msg ),
            ),
            'temperature' => $temperature,
            'max_tokens' => $max_tokens,
            'n' => $count,
        );

        $api_base = isset( $opts['llm_api_base'] ) && ! empty( $opts['llm_api_base'] ) ? rtrim( $opts['llm_api_base'], '\/' ) : 'https://api.openai.com';
        $url = $api_base . '/v1/chat/completions';

        $headers = array(
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        );

        $resp = wp_remote_post( $url, array( 'headers' => $headers, 'body' => wp_json_encode( $body ), 'timeout' => 30 ) );

        if ( is_wp_error( $resp ) ) {
            update_option( 'arg_last_llm_error', $resp->get_error_message() );
            return false;
        }

        $code = intval( $resp['response']['code'] );
        if ( $code >= 400 ) {
            $body_msg = isset( $resp['body'] ) ? $resp['body'] : ''; 
            update_option( 'arg_last_llm_error', 'HTTP ' . $code . ': ' . substr( $body_msg, 0, 200 ) );
            return false;
        }

        $data = json_decode( $resp['body'], true );
        if ( empty( $data ) || empty( $data['choices'] ) ) {
            update_option( 'arg_last_llm_error', 'Invalid response structure' );
            return false;
        }

        $results = array();
        foreach ( $data['choices'] as $choice ) {
            if ( isset( $choice['message']['content'] ) ) {
                $text = trim( $choice['message']['content'] );
            } elseif ( isset( $choice['text'] ) ) {
                $text = trim( $choice['text'] );
            } else {
                continue;
            }

            // Basic forbidden content check
            if ( ! empty( $forbidden ) ) {
                $phrases = array_map( 'trim', explode( ',', strtolower( $forbidden ) ) );
                $lower = strtolower( $text );
                $bad = false;
                foreach ( $phrases as $p ) {
                    if ( empty( $p ) ) { continue; }
                    if ( strpos( $lower, $p ) !== false ) { $bad = true; break; }
                }
                if ( $bad ) {
                    // Skip this result
                    continue;
                }
            }

            // enforce length boundaries (approx words)
            $words = str_word_count( $text );
            if ( $words < 20 || $words > 300 ) {
                // still accept but trim or skip; here we trim if too long
                if ( $words > 300 ) {
                    $text = wp_trim_words( $text, 160, '...' );
                }
            }

            $results[] = $text;
        }

        if ( empty( $results ) ) {
            update_option( 'arg_last_llm_error', 'No valid outputs (filtered or empty) from model' );
            return false;
        }

        return $results;
    }

    private static function parse_examples( $raw ) {
        $parts = preg_split( '/\r?\n\s*\r?\n|\r?\n/', trim( $raw ) );
        $out = array();
        foreach ( $parts as $p ) {
            $s = trim( $p );
            if ( $s !== '' ) { $out[] = $s; }
        }
        return array_slice( $out, 0, 5 );
    }

    private static function rating_to_tone( $rating ) {
        switch ( intval( $rating ) ) {
            case 5: return 'Excellent (five-star)';
            case 4: return 'Very good (four-star)';
            case 3: return 'Average / mixed (three-star)';
            case 2: return 'Poor (two-star)';
            case 1: default: return 'Terrible (one-star)';
        }
    }
}
