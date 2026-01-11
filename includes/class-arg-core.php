<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ARG_Core {
    /**
     * Singleton instance
     */
    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
            self::$instance->init();
        }
        return self::$instance;
    }

    private function __construct() {}

    private function init() {
        // Hooks, shortcodes, admin init, etc.
        add_action( 'init', array( $this, 'load_textdomain' ) );

        // Add minute interval schedule (makes sure it exists when needed)
        add_filter( 'cron_schedules', array( $this, 'add_cron_schedules' ) );

        // Daily cron handler for generating reviews
        add_action( 'arg_daily_event', array( $this, 'handle_daily_event' ) );

        // React to options saved to reschedule if needed
        add_action( 'arg_options_saved', array( $this, 'maybe_reschedule_cron' ) );
    }

    public function load_textdomain() {
        load_plugin_textdomain( 'automated-review-generator', false, dirname( plugin_basename( __FILE__ ) ) . '/../languages' );
    }

    public function add_cron_schedules( $schedules ) {
        if ( ! isset( $schedules['every_minute'] ) ) {
            $schedules['every_minute'] = array(
                'interval' => 60,
                'display'  => __( 'Every Minute', 'automated-review-generator' ),
            );
        }
        return $schedules;
    }

    /**
     * Reschedule the cron job if the time-scaling option changed
     */
    public function maybe_reschedule_cron( $opts ) {
        $desired = ! empty( $opts['use_minutes'] ) ? 'every_minute' : 'daily';
        $current = wp_get_schedule( 'arg_daily_event' );

        if ( $current !== $desired ) {
            // Clear and schedule the new interval
            wp_clear_scheduled_hook( 'arg_daily_event' );
            wp_schedule_event( time(), $desired, 'arg_daily_event' );
        }
    }

    /**
     * Daily cron: iterate published products and probabilistically post reviews
     */
    public function handle_daily_event() {
        if ( ! post_type_exists( 'product' ) ) {
            // No WooCommerce products present
            return;
        }

        $opts = get_option( 'arg_options', array( 'avg_frequency_days' => 5, 'avg_score' => '4.8', 'negative_percentage' => 5 ) );
        $avg_days = max( 1.0, floatval( $opts['avg_frequency_days'] ) );
        $probability = 1.0 / $avg_days; // per-day probability per product

        $args = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'numberposts' => -1,
        );

        $products = get_posts( $args );
        $count = 0;

        foreach ( $products as $p ) {
            // Decide whether to post today for this product
            if ( mt_rand() / mt_getrandmax() < $probability ) {
                $res = $this->post_review_for_product( $p->ID, $opts );
                if ( $res ) {
                    $count++;
                }
            }
        }

        // Record run metadata for admin UI
        update_option( 'arg_last_run', current_time( 'mysql' ) );
        update_option( 'arg_last_run_count', intval( $count ) );
    }

    /**
     * Create a placeholder review for a product.
     * Returns comment ID on success, false on failure.
     */
    protected function post_review_for_product( $product_id, $opts ) {
        // Prepare rating
        $neg_percent = isset( $opts['negative_percentage'] ) ? intval( $opts['negative_percentage'] ) : 5;
        $is_negative = ( mt_rand( 1, 100 ) <= $neg_percent );

        if ( $is_negative ) {
            $rating = mt_rand( 1, 3 );
        } else {
            $s = floatval( $opts['avg_score'] );
            $floor = floor( $s );
            $ceil  = ceil( $s );
            if ( $floor === $ceil ) {
                $rating = (int) $floor;
            } else {
                $p_ceil = $s - $floor; // probability of rounding up
                $rating = ( mt_rand() / mt_getrandmax() < $p_ceil ) ? (int) $ceil : (int) $floor;
            }
        }

        // Determine live/test mode (default: test-only)
        $enable_live = ! empty( $opts['enable_live_posts'] );
        $comment_author = $enable_live ? 'Automated Review' : 'Automated Review (test)';
        $comment_approved = $enable_live ? 1 : 0;

        // Determine comment content (LLM if enabled and available)
        $comment_content = isset( $opts['review_prompt'] ) && ! empty( $opts['review_prompt'] ) ? sanitize_text_field( $opts['review_prompt'] ) : 'This is just a test review.';
        $llm_used = false;
        if ( ! empty( $opts['enable_llm'] ) ) {
            $product = get_post( $product_id );
            $paraphrase_count = isset( $opts['paraphrase_count'] ) ? max(1, intval( $opts['paraphrase_count'] ) ) : 1;
            $generated = ARG_LLM::generate_reviews_for_rating( $product, $rating, $paraphrase_count );
            if ( $generated && is_array( $generated ) && ! empty( $generated ) ) {
                // pick one at random
                $comment_content = $generated[ array_rand( $generated ) ];
                $llm_used = true;
            }
        }

        // Build comment
        $comment = array(
            'comment_post_ID'      => $product_id,
            'comment_author'       => $comment_author,
            'comment_author_email' => '',
            'comment_content'      => $comment_content,
            'comment_type'         => 'review',
            'comment_approved'     => $comment_approved,
            'user_id'              => 0,
        );

        $comment_id = wp_insert_comment( $comment );

        if ( $comment_id ) {
            add_comment_meta( $comment_id, 'rating', (string) $rating, true );
            add_comment_meta( $comment_id, 'arg_generated', '1', true );
            add_comment_meta( $comment_id, 'arg_test', $enable_live ? '0' : '1', true );
            if ( $llm_used ) {
                add_comment_meta( $comment_id, 'arg_llm', '1', true );
                if ( ! empty( $opts['model'] ) ) {
                    add_comment_meta( $comment_id, 'arg_llm_model', sanitize_text_field( $opts['model'] ), true );
                }
            }
            update_post_meta( $product_id, 'arg_last_post_date', current_time( 'mysql' ) );
            return $comment_id;
        }

        return false;
    }
}
