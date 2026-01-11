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

    /**
     * Generate usernames based on admin-provided examples. Returns array of usernames or false.
     */
    public static function generate_usernames( $count = 1, $params = array() ) {
        return self::generate_usernames_advanced( $count, $params );
        /* LEFTOVER-BLOCK-START - removed by refactor (commented out) */
    }
        /* LEFTOVER BLOCK START
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
        $max_tokens = 50;
        $forbidden = isset( $opts['forbidden_content'] ) ? $opts['forbidden_content'] : ''; 

        $examples_raw = isset( $opts['username_examples'] ) ? $opts['username_examples'] : '';
        $examples = self::parse_examples( $examples_raw );

        $system = "You are a helpful assistant that invents short, realistic usernames for product reviews. Avoid profanity and personal data. Output only the usernames, one per line, with no extra text.";

        $user_msg = "Here are example usernames (do not repeat them verbatim). Do NOT include list numbers or bullets in your output. Make each username varied and distinct — do not reuse the same leading token across multiple usernames. Output only the usernames, one per line, with no extra commentary:\n";
        foreach ( $examples as $i => $ex ) {
            // show examples as bullets (no numeric prefixes) to avoid seeding numbering
            $user_msg .= "- " . $ex . "\n";
        }
        $user_msg .= "\nTask: Generate " . intval( $count ) . " unique usernames similar in style to the examples. Return them as a newline-separated list with no numbering or bullets, and no extra commentary. Use letters, numbers, underscores or hyphens only, 3-30 characters long. Avoid starting multiple usernames with the same exact prefix.";

        $body = array(
            'model' => $model,
            'messages' => array(
                array( 'role' => 'system', 'content' => $system ),
                array( 'role' => 'user', 'content' => $user_msg ),
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


    }

        /* LEFTOVER-BLOCK-END (comment closed) */

    private static function parse_examples( $raw ) {
        $parts = preg_split( '/\r?\n\s*\r?\n|\r?\n/', trim( $raw ) );
        $out = array();
        foreach ( $parts as $p ) {
            $s = trim( $p );
            // Strip common list markers and numbering (e.g., "1. ", "1)", "- ", "* ", "•")
            $s = preg_replace('/^\s*[-*\x{2022}]+\s*/u', '', $s);
            $s = preg_replace('/^\s*\d+[\.\)\-]*\s*/', '', $s);
            $s = trim( $s );
            if ( $s !== '' ) { $out[] = $s; }
        }
        return array_slice( $out, 0, 5 );
    }

    /**
     * Choose a diverse set of usernames from candidates.
     * Ensures different styles and enforces prefix and distance diversity.
     */
    private static function select_diverse_usernames( $candidates, $count ) {
        if ( empty( $candidates ) ) { return array(); }

        // Categorize candidates into style buckets
        $buckets = array( 'underscore' => array(), 'hyphen' => array(), 'lower' => array(), 'pascal' => array(), 'alnum' => array(), 'initials' => array(), 'other' => array() );
        foreach ( $candidates as $c ) {
            $s = $c;
            if ( preg_match('/^[a-z]+[0-9_]+$/i', $s ) && strpos( $s, '_' ) !== false ) {
                $buckets['underscore'][] = $s;
            } elseif ( strpos( $s, '-' ) !== false ) {
                $buckets['hyphen'][] = $s;
            } elseif ( preg_match('/^[a-z0-9]{3,}$/', $s ) && strtolower($s) === $s && preg_match('/[a-z]/', $s) ) {
                $buckets['lower'][] = $s;
            } elseif ( preg_match('/^[A-Z][a-z]+[A-Z][a-z0-9]+$/', $s) ) {
                $buckets['pascal'][] = $s;
            } elseif ( preg_match('/^[a-z0-9]{6,}$/i', $s) && preg_match('/[0-9]/', $s) ) {
                $buckets['alnum'][] = $s;
            } elseif ( preg_match('/^[A-Z]{1,3}[\.\s]?[A-Z]{1,3}\.?$/', $s) || preg_match('/[A-Z]\./', $s) ) {
                $buckets['initials'][] = $s;
            } else {
                $buckets['other'][] = $s;
            }
        }

        // Pick candidates from different buckets round-robin to maximize style diversity
        $picked = array();
        $used_prefixes = array();

        // bucket order: try to cover many styles first
        $order = array( 'underscore', 'hyphen', 'lower', 'pascal', 'alnum', 'initials', 'other' );
        $i = 0;
        while ( count( $picked ) < $count ) {
            $made_progress = false;
            foreach ( $order as $bucket ) {
                if ( empty( $buckets[ $bucket ] ) ) { continue; }
                $candidate = array_shift( $buckets[ $bucket ] );
                if ( ! $candidate ) { continue; }
                // enforce prefix uniqueness (first 4 chars)
                $pref = strtolower( substr( $candidate, 0, 4 ) );
                if ( isset( $used_prefixes[ $pref ] ) ) {
                    // try a different candidate from same bucket
                    $found = false;
                    foreach ( $buckets[ $bucket ] as $k => $alt ) {
                        $apref = strtolower( substr( $alt, 0, 4 ) );
                        if ( ! isset( $used_prefixes[ $apref ] ) ) {
                            $candidate = $alt;
                            unset( $buckets[ $bucket ][ $k ] );
                            $found = true;
                            break;
                        }
                    }
                    if ( ! $found ) { continue; }
                }

                // ensure Levenshtein distance from existing picks is >= 3
                $ok = true;
                foreach ( $picked as $p ) {
                    if ( levenshtein( strtolower( $p ), strtolower( $candidate ) ) < 3 ) { $ok = false; break; }
                }
                if ( ! $ok ) { continue; }

                $picked[] = $candidate;
                $used_prefixes[ $pref ] = true;
                $made_progress = true;
                if ( count( $picked ) >= $count ) { break 2; }
            }

            if ( ! $made_progress ) { break; }
            $i++;
            if ( $i > 10 ) { break; }
        }

        // If not enough picked, fill with remaining unique candidates preserving uniqueness
        if ( count( $picked ) < $count ) {
            $remaining = array();
            foreach ( $buckets as $bucket ) { foreach ( $bucket as $c ) { $remaining[] = $c; } }
            foreach ( $remaining as $r ) {
                if ( count( $picked ) >= $count ) { break; }
                $pref = strtolower( substr( $r, 0, 4 ) );
                if ( isset( $used_prefixes[ $pref ] ) ) { continue; }
                $picked[] = $r;
                $used_prefixes[ $pref ] = true;
            }
        }

        return array_slice( $picked, 0, $count );
    }

    /**
     * Advanced username generation implementation (used by public wrapper).
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
        $temperature = isset( $opts['temperature'] ) ? floatval( $opts['temperature'] ) : 0.9;
        $top_p = 0.9;
        // Estimate required tokens per candidate (we'll compute max_tokens after candidate_count is known)
        $est_tokens_per_candidate = 30; // rough estimate (includes quotes/commas/spacing)
        $forbidden = isset( $opts['forbidden_content'] ) ? $opts['forbidden_content'] : '';  

        $examples_raw = isset( $opts['username_examples'] ) ? $opts['username_examples'] : '';
        $examples = self::parse_examples( $examples_raw );

        $desired = max( 1, intval( $count ) );
        $candidate_target = isset( $params['candidate_multiplier'] ) ? intval( $params['candidate_multiplier'] ) : 3;
        $candidate_count = $desired * $candidate_target; // request more candidates to select from
        $candidate_count = min( max( $candidate_count, $desired ), 50 );
        // Compute max_tokens based on candidate count (cap to a safe max)
        $max_tokens = max( 200, min( 1500, intval( $candidate_count * $est_tokens_per_candidate ) ) );

        $system = "You are a helpful assistant that invents short, realistic usernames for product reviews. Avoid profanity and personal data. Return exactly one valid JSON array of strings and nothing else, e.g. [\"tex_teen_99\", \"J-red\", \"SeanR\"]. Do NOT include extra commentary or explanation.";

        $user_msg = "Here are example usernames (do not repeat them verbatim). Examples are shown as bullets below:\n";
        foreach ( $examples as $i => $ex ) {
            $user_msg .= "- " . $ex . "\n";
        }

        $user_msg .= "\nTask: Generate a single JSON array with exactly " . intval( $candidate_count ) . " unique candidate usernames. Requirements: each username must match regex ^[A-Za-z0-9_.-]{3,30}$ (letters, numbers, underscore, hyphen or dot only), must not contain consecutive punctuation (e.g., .. or __), and must not repeat the supplied examples verbatim. Use varied styles (underscore_with_numbers, hyphenated, initials with dots, alphanumeric tokens, PascalCase). Do NOT output nested arrays or trailing commas; if you cannot meet constraints, return an empty array []. Output only the JSON array.";

        $body = array(
            'model' => $model,
            'messages' => array(
                array( 'role' => 'system', 'content' => $system ),
                array( 'role' => 'user', 'content' => $user_msg ),
            ),
            'temperature' => $temperature,
            'top_p' => $top_p,
            'max_tokens' => $max_tokens,
            'n' => 1,
        );

        $api_base = isset( $opts['llm_api_base'] ) && ! empty( $opts['llm_api_base'] ) ? rtrim( $opts['llm_api_base'], '\\/' ) : 'https://api.openai.com';
        $url = $api_base . '/v1/chat/completions';

        $headers = array(
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        );

        $attempt = 0;
        $max_attempts = 3;
        $final_candidates = array();
        $raw_collected = array();
        $last_error = '';
        // fallback indicators
        $fallback_used = false;
        $raw_fallback_used = false;
        // If the model returned a JSON array inside its text, collect it here and prefer it
        $json_arrays_found = array();
        $used_model_array = false;
        $http_transport_missing = false;
        // track rejections for diagnostics
        $rejected_counts = array(
            'invalid_length' => 0,
            'forbidden' => 0,
            'bad_format' => 0,
            'consecutive_punct' => 0,
            'duplicate' => 0,
        );
        $rejected_samples = array();

        while ( $attempt < $max_attempts && count( $final_candidates ) < $desired ) {
            $attempt++;
            // For retries, slightly increase randomness
            if ( $attempt > 1 ) {
                $body['temperature'] = min( 1.0, $temperature + 0.1 * $attempt );
                $body['top_p'] = min( 1.0, $top_p + 0.05 * $attempt );
                // increase max_tokens on retry to avoid truncated outputs
                $body['max_tokens'] = min( 1500, intval( $body['max_tokens'] * 1.75 ) );
                $user_msg .= "\nNote: please make the usernames more varied on retry #" . $attempt . ".";
                $body['messages'][1]['content'] = $user_msg;
            }

            // Allow tests to inject a mock response body via params (useful in CLI/dev environments)
            if ( isset( $params['mock_remote_body'] ) ) {
                $resp = array( 'body' => $params['mock_remote_body'], 'response' => array( 'code' => 200 ) );
            } else {
                $resp = wp_remote_post( $url, array( 'headers' => $headers, 'body' => wp_json_encode( $body ), 'timeout' => 30 ) );
                if ( is_wp_error( $resp ) ) {
                    $last_error = $resp->get_error_message();
                    if ( stripos( $last_error, 'No working transports found' ) !== false ) {
                        $last_error .= ' — PHP has no available HTTP transports. Ensure curl (php_curl) and openssl (php_openssl) are enabled, and that allow_url_fopen is on; then restart your web/PHP service (MAMP).';
                        $http_transport_missing = true;
                    }
                    continue;
                }
            }
            $code = intval( $resp['response']['code'] );
            if ( $code >= 400 ) {
                $body_msg = isset( $resp['body'] ) ? $resp['body'] : ''; 
                $last_error = 'HTTP ' . $code . ': ' . substr( $body_msg, 0, 200 );
                continue;
            }

            $data = json_decode( $resp['body'], true );
            if ( empty( $data ) || empty( $data['choices'] ) ) {
                $last_error = 'Invalid response structure';
                continue;
            }

            // Parse text - prefer JSON array if the model returned it
            $candidates = array();
            // collect raw model texts for diagnostics
            $raw_texts = isset( $raw_texts ) && is_array( $raw_texts ) ? $raw_texts : array();
            $truncated = false;
            foreach ( $data['choices'] as $choice ) {
                $choice_finish = isset( $choice['finish_reason'] ) ? $choice['finish_reason'] : '';
                if ( 'length' === $choice_finish ) { $truncated = true; }

                $text = '';
                if ( isset( $choice['message']['content'] ) ) {
                    $text = trim( $choice['message']['content'] );
                } elseif ( isset( $choice['text'] ) ) {
                    $text = trim( $choice['text'] );
                }
                if ( $text === '' ) { continue; }

                $raw_texts[] = $text;

                // Try to extract one or more JSON arrays inside the text and repair common issues
                $json = null;
                if ( preg_match_all('/\[[^\]]*\]/s', $text, $arrs) ) {
                    foreach ( $arrs[0] as $maybe ) {
                        $maybe = trim( $maybe, "\n\r \t'\"`" );
                        // quick repairs for repeated/trailing commas
                        $repair = preg_replace('/,\s*,+/', ',', $maybe);
                        $repair = preg_replace('/,\s*\]/', ']', $repair);
                        $repair = preg_replace('/\[\s*,/', '[', $repair);
                        // quick repair for unbalanced brackets/quotes from truncated outputs
                        $open = substr_count( $repair, '[' );
                        $close = substr_count( $repair, ']' );
                        if ( $open > $close ) { $repair .= str_repeat( ']', $open - $close ); }
                        if ( substr_count( $repair, '"' ) % 2 != 0 ) { $repair .= '"'; }
                        $json = json_decode( $repair, true );
                        if ( is_array( $json ) ) {
                            // remember raw JSON arrays found for preference later
                            $json_arrays_found[] = $json;
                            foreach ( $json as $it ) {
                                $it = trim( (string) $it );
                                // strip common bullets (hyphen, asterisk, bullet)
                                $norm = preg_replace('/^\s*[-*\x{2022}]+\s*/u', '', $it);
                                $norm = preg_replace('/^\s*\d+[\.\)\-]*\s*/', '', $norm);
                                $norm = preg_replace('/^[\.\-_]+/', '', $norm);
                                $norm = preg_replace('/[^A-Za-z0-9_\-\.]/', '', $norm);
                                $norm = trim( $norm );
                                if ( strlen( $norm ) >= 3 && strlen( $norm ) <= 30 ) { $candidates[] = $norm; }
                            }
                            continue;
                        }

                        // If JSON decode fails, extract quoted strings inside the bracket
                        if ( preg_match_all('/"([^\"]+)"/s', $repair, $qm) ) {
                            foreach ( $qm[1] as $it ) {
                                $it = trim( (string) $it );
                                // early normalize to avoid empty/short tokens
                                $norm = preg_replace('/^\s*[-*\x{2022}]+\s*/u', '', $it);
                                $norm = preg_replace('/^\s*\d+[\.\)\-]*\s*/', '', $norm);
                                $norm = preg_replace('/^[\.\-_]+/', '', $norm);
                                $norm = preg_replace('/[^A-Za-z0-9_\-\.]/', '', $norm);
                                $norm = trim( $norm );
                                if ( strlen( $norm ) >= 3 && strlen( $norm ) <= 30 ) { $candidates[] = $norm; }
                            }
                            continue;
                        }
                    }

                    // Also inspect text outside arrays (in case model added stray tokens between arrays)
                    $text_outside = preg_replace('/\[[^\]]*\]/s', '', $text);
                    if ( trim( $text_outside ) !== '' ) {
                        $lines = preg_split('/\r?\n|,/', $text_outside);
                        foreach ( $lines as $line ) {
                            $t = trim( (string) $line );
                            if ( $t !== '' && preg_match('/[A-Za-z0-9_\.\-]{3,30}/', $t) ) {
                                $norm = preg_replace('/^\s*[-*\x{2022}]+\s*/u', '', $t);
                                $norm = preg_replace('/^\s*\d+[\.\)\-]*\s*/', '', $norm);
                                $norm = preg_replace('/^[\.\-_]+/', '', $norm);
                                $norm = preg_replace('/[^A-Za-z0-9_\-\.]/', '', $norm);
                                $norm = trim( $norm );
                                if ( strlen( $norm ) >= 3 && strlen( $norm ) <= 30 ) { $candidates[] = $norm; }
                            }
                        }
                    }
                } else {
                    // fallback to splitting lines or commas
                    $lines = preg_split('/\r?\n|,/', $text);
                    foreach ( $lines as $line ) {
                        $t = trim( (string) $line );
                        if ( $t !== '' && preg_match('/[A-Za-z0-9_\.\-]{3,30}/', $t) ) {
                            $norm = preg_replace('/^\s*[-*\x{2022}]+\s*/u', '', $t);
                            $norm = preg_replace('/^\s*\d+[\.\)\-]*\s*/', '', $norm);
                            $norm = preg_replace('/^[\.\-_]+/', '', $norm);
                            $norm = preg_replace('/[^A-Za-z0-9_\-\.]/', '', $norm);
                            $norm = trim( $norm );
                            if ( strlen( $norm ) >= 3 && strlen( $norm ) <= 30 ) { $candidates[] = $norm; }
                        }
                    }

                    // As a last resort, extract token-like substrings
                    if ( empty( $candidates ) ) {
                        if ( preg_match_all('/[A-Za-z0-9_\.\-]{3,30}/', $text, $matches) ) {
                            foreach ( $matches[0] as $m ) {
                                $candidates[] = trim( $m );
                            }
                        }
                    }
                }
            }
            // persist raw_texts into the collected pool for diagnostics across attempts
            if ( ! empty( $raw_texts ) ) {
                $raw_collected = array_merge( $raw_collected, $raw_texts );
            }

            // If the response was truncated and we can retry, increase tokens and try again
            if ( ! empty( $truncated ) && $attempt < $max_attempts ) {
                $body['max_tokens'] = min( 1500, intval( $body['max_tokens'] * 2 ) );
                $last_error = 'Truncated response; retrying with more tokens';
                continue;
            }

            // If the model provided a JSON array, prefer those strings directly (they match the requested format)
            if ( ! empty( $json_arrays_found ) ) {
                $preferred = array();
                foreach ( $json_arrays_found as $ja ) {
                    foreach ( (array) $ja as $v ) {
                        $v = trim( (string) $v );
                        if ( $v === '' ) { continue; }
                        $preferred[] = $v;
                    }
                }
                $preferred = array_values( array_unique( $preferred ) );
                if ( ! empty( $preferred ) ) {
                    $candidates = $preferred;
                    $used_model_array = true;
                }
            }

            // Sanitize and filter raw candidates
            $clean = array();
            foreach ( $candidates as $c ) {
                $u = trim( $c );
                // strip bullets/numbering
                $u = preg_replace('/^\s*[-*\x{2022}]+\s*/u', '', $u);
                $u = preg_replace('/^\s*\d+[\.\)\-]*\s*/', '', $u);
                $u = preg_replace('/^[\.\-_]+/', '', $u);
                // remove disallowed chars and spaces
                $u = preg_replace('/[^A-Za-z0-9_\-\.]/', '', $u);

                if ( strlen( $u ) < 3 || strlen( $u ) > 30 ) {
                    $rejected_counts['invalid_length']++;
                    if ( $u !== '' ) { $rejected_samples[] = $u; }
                    continue;
                }

                // require strict final format
                if ( ! preg_match('/^[A-Za-z0-9_.-]{3,30}$/', $u) ) {
                    $rejected_counts['bad_format']++;
                    $rejected_samples[] = $u;
                    continue;
                }

                // disallow consecutive punctuation like '..' '__' '--'
                if ( preg_match('/[._\-]{2,}/', $u) ) {
                    $rejected_counts['consecutive_punct']++;
                    $rejected_samples[] = $u;
                    continue;
                }

                $lower = strtolower( $u );
                if ( ! empty( $forbidden ) ) {
                    $phrases = array_map( 'trim', explode( ',', strtolower( $forbidden ) ) );
                    $bad = false;
                    foreach ( $phrases as $p ) {
                        if ( $p !== '' && strpos( $lower, $p ) !== false ) { $bad = true; break; }
                    }
                    if ( $bad ) {
                        $rejected_counts['forbidden']++;
                        $rejected_samples[] = $u;
                        continue;
                    }
                }

                // skip duplicates within this clean list
                if ( in_array( $u, $clean, true ) ) {
                    $rejected_counts['duplicate']++;
                    continue;
                }

                $clean[] = $u;
            }

            $clean = array_values( array_unique( $clean ) );
            $raw_collected = array_merge( $raw_collected, $clean );

            // Select diverse final candidates from sanitized 'clean' candidates (prefer cleaned inputs)
            $buckets_info = self::get_username_buckets( $clean );
            $final_candidates = self::select_diverse_usernames( $clean, $desired );

            // If selection produced nothing but we have cleaned candidates, fall back to top cleaned candidates
            if ( empty( $final_candidates ) && ! empty( $clean ) ) {
                $final_candidates = array_slice( $clean, 0, $desired );
                // mark that fallback was needed so diagnostics can highlight it
                $fallback_used = true;
            } else {
                $fallback_used = false;
            }

            // If we have enough, break; otherwise allow retry loop to continue
        }

        if ( empty( $final_candidates ) ) {
            // If selection failed, try a cross-attempt fallback: sanitize collected raw tokens and pick top cleaned candidates
            $cross_possible = array();
            foreach ( array_values( array_unique( $raw_collected ) ) as $rc ) {
                $u = trim( (string) $rc );
                $u = preg_replace('/^\s*[-*\x{2022}]+\s*/u', '', $u);
                $u = preg_replace('/^\s*\d+[\.\)\-]*\s*/', '', $u);
                $u = preg_replace('/^[\.\-_]+/', '', $u);
                $u = preg_replace('/[^A-Za-z0-9_\-\.]/', '', $u);
                if ( strlen( $u ) >= 3 && strlen( $u ) <= 30 && preg_match('/^[A-Za-z0-9_.-]{3,30}$/', $u) && ! preg_match('/[._\-]{2,}/', $u) ) {
                    $cross_possible[] = $u;
                }
            }
            $cross_possible = array_values( array_unique( $cross_possible ) );
            if ( ! empty( $cross_possible ) ) {
                $final_candidates = array_slice( $cross_possible, 0, $desired );
                $fallback_used = true;
            }

            // If we still have none, attempt a permissive raw-array fallback: keep token text with light validation
            if ( empty( $final_candidates ) ) {
                $raw_fallback_candidates = array();
                foreach ( array_values( array_unique( $raw_collected ) ) as $rc_text ) {
                    $txt = (string) $rc_text;
                    // extract any bracketed arrays inside the raw text
                    if ( preg_match_all('/\[[^\]]*\]/s', $txt, $arrs) ) {
                        foreach ( $arrs[0] as $arr_text ) {
                            $arr_text = trim( $arr_text, "\n\r \t'\"`" );
                            // balance brackets if truncated
                            $open = substr_count( $arr_text, '[' );
                            $close = substr_count( $arr_text, ']' );
                            if ( $open > $close ) { $arr_text .= str_repeat( ']', $open - $close ); }

                            // extract quoted tokens if present
                            if ( preg_match_all('/"([^\"]+)"|\'([^\']+)\'/s', $arr_text, $m) ) {
                                foreach ( $m[1] as $k => $v ) {
                                    $val = $v !== '' ? $v : ( isset( $m[2][ $k ] ) ? $m[2][ $k ] : '' );
                                    $val = trim( (string) $val );
                                    if ( $val === '' ) { continue; }

                                    // light validation: reject obvious emails or urls
                                    if ( preg_match('/@|https?:\/\//i', $val ) ) { continue; }

                                    // strip leading/trailing punctuation/spaces, but keep inner punctuation
                                    $val2 = preg_replace('/^[\p{P}\s]+|[\p{P}\s]+$/u', '', $val);
                                    $val2 = preg_replace('/\s+/', '_', $val2);
                                    $val2 = trim( $val2 );
                                    if ( mb_strlen( $val2 ) >= 3 && mb_strlen( $val2 ) <= 30 ) { $raw_fallback_candidates[] = $val2; }
                                }
                            } else {
                                // split by commas as a fallback
                                $parts = preg_split('/\s*,\s*/', trim( $arr_text, '[]' ) );
                                foreach ( $parts as $p ) {
                                    $p = trim( (string) $p );
                                    $p = trim( $p, '"\'' );
                                    if ( $p === '' ) { continue; }
                                    if ( preg_match('/@|https?:\/\//i', $p ) ) { continue; }
                                    $p2 = preg_replace('/^[\p{P}\s]+|[\p{P}\s]+$/u', '', $p);
                                    $p2 = preg_replace('/\s+/', '_', $p2);
                                    $p2 = trim( $p2 );
                                    if ( mb_strlen( $p2 ) >= 3 && mb_strlen( $p2 ) <= 30 ) { $raw_fallback_candidates[] = $p2; }
                                }
                            }
                        }
                    }
                }

                $raw_fallback_candidates = array_values( array_unique( array_filter( $raw_fallback_candidates, function( $v ) { return $v !== ''; } ) ) );
                if ( ! empty( $raw_fallback_candidates ) ) {
                    $final_candidates = array_slice( $raw_fallback_candidates, 0, $desired );
                    $raw_fallback_used = true;
                }
            }

            $err = $last_error ?: 'No valid usernames generated';
            update_option( 'arg_last_llm_error', $err );

            // If diagnostics requested, store an error diagnostic so the admin diagnostic box shows context
            if ( isset( $params['diagnostics'] ) && $params['diagnostics'] ) {
                $diagn = array(
                    'error' => $err,
                    'raw' => array_values( array_unique( $raw_collected ) ),
                    'model_texts' => isset( $raw_texts ) ? array_values( array_unique( $raw_texts ) ) : array(),
                    'http_response' => ( is_array( $resp ) && isset( $resp['body'] ) ) ? substr( $resp['body'], 0, 2000 ) : ( is_wp_error( $resp ) ? $resp->get_error_message() : '' ),
                    'rejected' => $rejected_counts,
                    'rejected_samples' => array_slice( $rejected_samples, 0, 50 ),
                    'fallback_used' => ! empty( $fallback_used ),
                    'raw_fallback_used' => ! empty( $raw_fallback_used ),
                    'raw_fallback_candidates' => isset( $raw_fallback_candidates ) ? array_slice( $raw_fallback_candidates, 0, 50 ) : array(),
                    'truncated' => ! empty( $truncated ),
                    'http_transport_missing' => ! empty( $http_transport_missing ),
                    'used_model_array' => ! empty( $used_model_array ),
                );
                update_option( 'arg_last_username_sample', $diagn );
                return $diagn;
            }

            return false;
        }

        // Store diagnostics if requested
        if ( isset( $params['diagnostics'] ) && $params['diagnostics'] ) {
            $diagn = array(
                'raw' => array_values( array_unique( $raw_collected ) ),
                'model_texts' => isset( $raw_texts ) ? array_values( array_unique( $raw_texts ) ) : array(),
                'http_response' => ( is_array( $resp ) && isset( $resp['body'] ) ) ? substr( $resp['body'], 0, 2000 ) : ( is_wp_error( $resp ) ? $resp->get_error_message() : '' ),
                'final' => $final_candidates,
                'attempts' => $attempt,
                'clean' => isset( $clean ) ? $clean : array(),
                'buckets' => isset( $buckets_info ) ? array_map( 'count', $buckets_info ) : array(),
                'buckets_samples' => isset( $buckets_info ) ? array_map( function( $b ) { return array_slice( $b, 0, 6 ); }, $buckets_info ) : array(),
                'rejected' => $rejected_counts,
                'rejected_samples' => array_slice( $rejected_samples, 0, 50 ),
                'fallback_used' => ! empty( $fallback_used ),
                'raw_fallback_used' => ! empty( $raw_fallback_used ),
                'raw_fallback_candidates' => isset( $raw_fallback_candidates ) ? array_slice( $raw_fallback_candidates, 0, 50 ) : array(),
                'http_transport_missing' => ! empty( $http_transport_missing ),
                'used_model_array' => ! empty( $used_model_array ),
            );
            update_option( 'arg_last_username_sample', $diagn );
            return $diagn;
        }
        return $final_candidates;
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

    /**
     * Bucketize username candidates for diagnostics and selection insight.
     * Returns an array of buckets => list of candidates.
     */
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
