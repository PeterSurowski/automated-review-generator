<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ARG LLM wrapper for OpenAI-compatible APIs (simplified)
 */
class ARG_LLM {
    /**
     * Generate paraphrased review text for a product given a numeric rating.
     * Returns array of generated texts on success, false on failure.
     */
    public static function generate_reviews_for_rating( $product, $rating, $count = 1, $params = array() ) {
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

        $title = is_object( $product ) && isset( $product->post_title ) ? $product->post_title : ( is_array( $product ) && isset( $product['post_title'] ) ? $product['post_title'] : '' );

        // Allow user/admin to fully control output via a prompt in $params or options
        $user_prompt = '';
        if ( isset( $params['prompt'] ) && is_string( $params['prompt'] ) && trim( $params['prompt'] ) !== '' ) {
            $user_prompt = trim( $params['prompt'] );
        } elseif ( isset( $opts['review_prompt'] ) && is_string( $opts['review_prompt'] ) && trim( $opts['review_prompt'] ) !== '' ) {
            $user_prompt = trim( $opts['review_prompt'] );
        }

        $system = "You are an assistant that follows the user's instructions to write product reviews. Follow the user's prompt exactly and output only what they ask for.";

        if ( $user_prompt !== '' ) {
            $user_msg = "Product: " . $title . "\nInstructions: " . $user_prompt;
        } else {
            $tone = self::rating_to_tone( $rating );
            $user_msg = "Task: Write a single " . strtolower( $tone ) . " review for the product '" . $title . "'. Output only the review text.";
        }

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

        $api_base = isset( $opts['llm_api_base'] ) && ! empty( $opts['llm_api_base'] ) ? rtrim( $opts['llm_api_base'], '\\/' ) : 'https://api.openai.com';
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

            // Minimal sanitization only
            $text = preg_replace('/\s+/u', ' ', $text);
            if ( $text === '' ) { continue; }
            $results[] = $text;
        }

        if ( empty( $results ) ) {
            update_option( 'arg_last_llm_error', 'No outputs from model' );
            return false;
        }

        return $results;
    }

    /**
     * Generate usernames (public wrapper)
     */
    public static function generate_usernames( $count = 1, $params = array() ) {
        return self::generate_usernames_advanced( $count, $params );
    }

    private static function parse_examples( $raw ) {
        $parts = preg_split( '/\r?\n\s*\r?\n|\r?\n/', trim( (string) $raw ) );
        $out = array();
        foreach ( $parts as $p ) {
            $s = trim( $p );
            $s = preg_replace('/^\s*[-*\x{2022}]+\s*/u', '', $s);
            $s = preg_replace('/^\s*\d+[\.\)\-]*\s*/', '', $s);
            if ( $s !== '' ) { $out[] = $s; }
        }
        return array_slice( $out, 0, 5 );
    }

    private static function select_diverse_usernames( $candidates, $count ) {
        $cand = array_values( array_unique( (array) $candidates ) );
        return array_slice( $cand, 0, max(1, intval( $count ) ) );
    }

    /**
     * Advanced username generation (simplified): accepts a natural-language prompt
     */
    private static function generate_usernames_advanced( $count = 1, $params = array() ) {
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
        $temperature = isset( $opts['temperature'] ) ? floatval( $opts['temperature'] ) : 0.8;
        $max_tokens = 200;

        $user_prompt = '';
        if ( isset( $params['prompt'] ) && is_string( $params['prompt'] ) && trim( $params['prompt'] ) !== '' ) {
            $user_prompt = trim( $params['prompt'] );
        } elseif ( isset( $opts['username_examples'] ) && is_string( $opts['username_examples'] ) && trim( $opts['username_examples'] ) !== '' ) {
            // Use the raw contents of the admin textarea `arg_options[username_examples]` as the prompt.
            $user_prompt = trim( $opts['username_examples'] );
        } elseif ( isset( $opts['username_prompt'] ) && is_string( $opts['username_prompt'] ) && trim( $opts['username_prompt'] ) !== '' ) {
            $user_prompt = trim( $opts['username_prompt'] );
        }

        if ( $user_prompt === '' ) {
            $user_prompt = "Generate {$count} short realistic usernames suitable for product review attribution. Output either a JSON array of strings or a newline-separated list, with no extra commentary.";
        }

        $system = "You are an assistant that follows the user's instructions exactly. Output only what the user requests (JSON array or newline-separated usernames).";

        $body = array(
            'model' => $model,
            'messages' => array(
                array( 'role' => 'system', 'content' => $system ),
                array( 'role' => 'user', 'content' => $user_prompt ),
            ),
            'temperature' => $temperature,
            'max_tokens' => $max_tokens,
            'n' => 1,
        );

        $api_base = isset( $opts['llm_api_base'] ) && ! empty( $opts['llm_api_base'] ) ? rtrim( $opts['llm_api_base'], '\\/' ) : 'https://api.openai.com';
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

        $text = '';
        foreach ( $data['choices'] as $choice ) {
            if ( isset( $choice['message']['content'] ) ) { $text .= (string) $choice['message']['content'] . "\n"; }
            elseif ( isset( $choice['text'] ) ) { $text .= (string) $choice['text'] . "\n"; }
        }
        $text = trim( $text );

        $usernames = array();
        $maybe_json = json_decode( $text, true );
        if ( is_array( $maybe_json ) ) {
            foreach ( $maybe_json as $v ) {
                $v = trim( (string) $v );
                if ( $v !== '' ) { $usernames[] = $v; }
            }
        } else {
            $lines = preg_split('/\r?\n|,/', $text);
            foreach ( $lines as $line ) {
                $u = trim( (string) $line );
                $u = preg_replace('/^\s*[-*\x{2022}]+\s*/u', '', $u);
                $u = preg_replace('/^\s*\d+[\.\)\-]*\s*/', '', $u);
                if ( preg_match('/@|https?:\/\//i', $u ) ) { continue; }
                $u = preg_replace('/[^A-Za-z0-9_\-\. \s]/u', '', $u);
                $u = trim( $u );
                if ( $u !== '' ) { $usernames[] = $u; }
            }
        }

        $clean = array();
        foreach ( array_values( array_unique( $usernames ) ) as $u ) {
            $u = trim( $u );
            if ( mb_strlen( $u ) < 3 || mb_strlen( $u ) > 30 ) { continue; }
            $clean[] = $u;
            if ( count( $clean ) >= $count ) { break; }
        }

        if ( empty( $clean ) ) {
            update_option( 'arg_last_llm_error', 'No usernames generated' );
            return false;
        }

        return $clean;
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

    private static function get_username_buckets( $candidates ) {
        $buckets = array( 'underscore' => array(), 'hyphen' => array(), 'lower' => array(), 'pascal' => array(), 'alnum' => array(), 'initials' => array(), 'other' => array() );
        foreach ( (array) $candidates as $s ) {
            if ( preg_match('/^[a-z]+[0-9_]+$/i', $s ) && strpos( $s, '_' ) !== false ) {
                $buckets['underscore'][] = $s;
            } elseif ( strpos( $s, '-' ) !== false ) {
                $buckets['hyphen'][] = $s;
            } elseif ( preg_match('/^[a-z0-9]{3,}$/', $s ) && strtolower( $s ) === $s && preg_match('/[a-z]/', $s ) ) {
                $buckets['lower'][] = $s;
            } elseif ( preg_match('/^[A-Z][a-z]+[A-Z][a-z0-9]+$/', $s ) ) {
                $buckets['pascal'][] = $s;
            } elseif ( preg_match('/^[a-z0-9]{6,}$/i', $s ) && preg_match('/[0-9]/', $s ) ) {
                $buckets['alnum'][] = $s;
            } elseif ( preg_match('/^[A-Z]{1,3}[\.\s]?[A-Z]{1,3}\.?$/', $s ) || preg_match('/[A-Z]\./', $s ) ) {
                $buckets['initials'][] = $s;
            } else {
                $buckets['other'][] = $s;
            }
        }
        return $buckets;
    }
}
