<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ARG Admin: registers admin menu and settings page
 */
class ARG_Admin {
    const OPTION_NAME = 'arg_options';

    public static function init() {
        if ( is_admin() ) {
            add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
            add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
        }
    }

    public static function add_menu() {
        add_menu_page(
            __( 'Automated Review Generator', 'automated-review-generator' ),
            __( 'Automated Reviews', 'automated-review-generator' ),
            'manage_options',
            'arg-admin',
            array( __CLASS__, 'render_admin_page' ),
            'dashicons-welcome-write-blog',
            60
        );
    }

    public static function register_settings() {
        register_setting( 'arg_settings_group', self::OPTION_NAME, array( __CLASS__, 'sanitize_options' ) );

        add_settings_section(
            'arg_main_section',
            __( 'Automated Review Settings', 'automated-review-generator' ),
            array( __CLASS__, 'section_cb' ),
            'arg-admin'
        );

        add_settings_field(
            'avg_frequency_days',
            __( 'Average frequency (days)', 'automated-review-generator' ),
            array( __CLASS__, 'field_avg_frequency' ),
            'arg-admin',
            'arg_main_section'
        );

        add_settings_field(
            'avg_score',
            __( 'Average score (1.0 - 5.0)', 'automated-review-generator' ),
            array( __CLASS__, 'field_avg_score' ),
            'arg-admin',
            'arg_main_section'
        );

        add_settings_field(
            'negative_percentage',
            __( 'Negative review percentage', 'automated-review-generator' ),
            array( __CLASS__, 'field_negative_percentage' ),
            'arg-admin',
            'arg_main_section'
        );

        add_settings_field(
            'review_prompt',
            __( 'Review prompt / guidance', 'automated-review-generator' ),
            array( __CLASS__, 'field_review_prompt' ),
            'arg-admin',
            'arg_main_section'
        );

        add_settings_field(
            'enable_live_posts',
            __( 'Enable live posting', 'automated-review-generator' ),
            array( __CLASS__, 'field_enable_live_posts' ),
            'arg-admin',
            'arg_main_section'
        );

        add_settings_field(
            'use_minutes',
            __( 'Use minutes for testing', 'automated-review-generator' ),
            array( __CLASS__, 'field_use_minutes' ),
            'arg-admin',
            'arg_main_section'
        );

        // LLM settings
        add_settings_field(
            'enable_llm',
            __( 'Enable LLM generation', 'automated-review-generator' ),
            array( __CLASS__, 'field_enable_llm' ),
            'arg-admin',
            'arg_main_section'
        );

        add_settings_field(
            'llm_provider',
            __( 'LLM provider', 'automated-review-generator' ),
            array( __CLASS__, 'field_llm_provider' ),
            'arg-admin',
            'arg_main_section'
        );

        add_settings_field(
            'llm_api_key',
            __( 'LLM API key', 'automated-review-generator' ),
            array( __CLASS__, 'field_llm_api_key' ),
            'arg-admin',
            'arg_main_section'
        );

        add_settings_field(
            'llm_api_base',
            __( 'LLM API base URL', 'automated-review-generator' ),
            array( __CLASS__, 'field_llm_api_base' ),
            'arg-admin',
            'arg_main_section'
        );

        add_settings_field(
            'paraphrase_count',
            __( 'Paraphrase count per generation', 'automated-review-generator' ),
            array( __CLASS__, 'field_paraphrase_count' ),
            'arg-admin',
            'arg_main_section'
        );

        // Example reviews per star rating
        add_settings_field(
            'examples_5',
            __( 'Three 5-star examples', 'automated-review-generator' ),
            array( __CLASS__, 'field_examples_5' ),
            'arg-admin',
            'arg_main_section'
        );
        add_settings_field(
            'examples_4',
            __( 'Three 4-star examples', 'automated-review-generator' ),
            array( __CLASS__, 'field_examples_4' ),
            'arg-admin',
            'arg_main_section'
        );
        add_settings_field(
            'examples_3',
            __( 'Three 3-star examples', 'automated-review-generator' ),
            array( __CLASS__, 'field_examples_3' ),
            'arg-admin',
            'arg_main_section'
        );
        add_settings_field(
            'examples_2',
            __( 'Three 2-star examples', 'automated-review-generator' ),
            array( __CLASS__, 'field_examples_2' ),
            'arg-admin',
            'arg_main_section'
        );
        add_settings_field(
            'examples_1',
            __( 'Three 1-star examples', 'automated-review-generator' ),
            array( __CLASS__, 'field_examples_1' ),
            'arg-admin',
            'arg_main_section'
        );

        // Model & generation settings
        add_settings_field(
            'model',
            __( 'Model name', 'automated-review-generator' ),
            array( __CLASS__, 'field_model' ),
            'arg-admin',
            'arg_main_section'
        );

        add_settings_field(
            'temperature',
            __( 'Temperature', 'automated-review-generator' ),
            array( __CLASS__, 'field_temperature' ),
            'arg-admin',
            'arg_main_section'
        );

        add_settings_field(
            'max_tokens',
            __( 'Max tokens', 'automated-review-generator' ),
            array( __CLASS__, 'field_max_tokens' ),
            'arg-admin',
            'arg_main_section'
        );

        add_settings_field(
            'forbidden_content',
            __( 'Forbidden phrases', 'automated-review-generator' ),
            array( __CLASS__, 'field_forbidden_content' ),
            'arg-admin',
            'arg_main_section'
        );

        add_settings_field(
            'llm_include_short_description',
            __( 'Include product short description', 'automated-review-generator' ),
            array( __CLASS__, 'field_llm_include_short_description' ),
            'arg-admin',
            'arg_main_section'
        );

        add_settings_field(
            'llm_description_max_chars',
            __( 'Max description chars', 'automated-review-generator' ),
            array( __CLASS__, 'field_llm_description_max_chars' ),
            'arg-admin',
            'arg_main_section'
        );

        add_settings_field(
            'username_examples',
            __( 'Example usernames', 'automated-review-generator' ),
            array( __CLASS__, 'field_username_examples' ),
            'arg-admin',
            'arg_main_section'
        );
    }

    public static function section_cb() {
        echo '<p>' . esc_html__( 'Configure how automated product reviews should be generated. These settings control the average timing, scoring distribution and the prompt used to create review text. You can update the prompt later to refine tone and content.', 'automated-review-generator' ) . '</p>';
    }

    public static function field_avg_frequency() {
        $opts = get_option( self::OPTION_NAME, self::get_defaults() );
        $val  = isset( $opts['avg_frequency_days'] ) ? $opts['avg_frequency_days'] : self::get_defaults()['avg_frequency_days'];
        printf(
            '<input name="%1$s[avg_frequency_days]" type="number" min="1" step="0.1" value="%2$s" class="small-text" />'
            . '<p class="description">%3$s</p>',
            esc_attr( self::OPTION_NAME ),
            esc_attr( $val ),
            esc_html__( 'Average number of days between reviews (e.g., 5 for ~every 5 days). The plugin will use randomness around this average to vary intervals.', 'automated-review-generator' )
        );
    }

    public static function field_avg_score() {
        $opts = get_option( self::OPTION_NAME, self::get_defaults() );
        $val  = isset( $opts['avg_score'] ) ? $opts['avg_score'] : self::get_defaults()['avg_score'];
        printf(
            '<input name="%1$s[avg_score]" type="number" min="1.0" max="5.0" step="0.1" value="%2$s" class="small-text" />'
            . '<p class="description">%3$s</p>',
            esc_attr( self::OPTION_NAME ),
            esc_attr( number_format( (float) $val, 1, '.', '' ) ),
            esc_html__( 'Average star rating to give products (format #.# between 1.0 and 5.0). Suggested: 4.7 or 4.8 to look realistic.', 'automated-review-generator' )
        );
    }

    public static function field_negative_percentage() {
        $opts = get_option( self::OPTION_NAME, self::get_defaults() );
        $val  = isset( $opts['negative_percentage'] ) ? $opts['negative_percentage'] : self::get_defaults()['negative_percentage'];
        printf(
            '<input name="%1$s[negative_percentage]" type="number" min="0" max="100" step="1" value="%2$s" class="small-text" /> %%'
            . '<p class="description">%3$s</p>',
            esc_attr( self::OPTION_NAME ),
            esc_attr( (int) $val ),
            esc_html__( 'Percentage of reviews that should be negative to keep the distribution realistic (e.g., 5 means 5% of reviews will be low-rated).', 'automated-review-generator' )
        );
    }

    public static function field_review_prompt() {
        $opts = get_option( self::OPTION_NAME, self::get_defaults() );
        $val  = isset( $opts['review_prompt'] ) ? $opts['review_prompt'] : self::get_defaults()['review_prompt'];
        printf(
            '<textarea name="%1$s[review_prompt]" rows="6" cols="60" class="large-text code">%2$s</textarea>'
            . '<p class="description">%3$s</p>',
            esc_attr( self::OPTION_NAME ),
            esc_textarea( $val ),
            esc_html__( 'Provide guidance for how reviews should be written (tone, length, points to mention). Keep it short and actionable.', 'automated-review-generator' )
        );
    }

    public static function field_enable_live_posts() {
        $opts = get_option( self::OPTION_NAME, self::get_defaults() );
        $val  = ! empty( $opts['enable_live_posts'] );
        printf(
            '<label><input name="%1$s[enable_live_posts]" type="checkbox" value="1" %2$s /> %3$s</label>'
            . '<p class="description">%4$s</p>',
            esc_attr( self::OPTION_NAME ),
            checked( 1, $val, false ),
            esc_html__( 'Generate publicly visible reviews (live). Requires explicit opt-in and is OFF by default.', 'automated-review-generator' ),
            esc_html__( 'When unchecked, generated reviews are saved as pending and marked as test data. Use this on development/staging sites only.', 'automated-review-generator' )
        );
    }

    public static function field_use_minutes() {
        $opts = get_option( self::OPTION_NAME, self::get_defaults() );
        $val  = ! empty( $opts['use_minutes'] );
        printf(
            '<label><input name="%1$s[use_minutes]" type="checkbox" value="1" %2$s /> %3$s</label>'
            . '<p class="description">%4$s</p>',
            esc_attr( self::OPTION_NAME ),
            checked( 1, $val, false ),
            esc_html__( 'Treat the average frequency value as minutes instead of days. This enables fast testing and posts every few minutes.', 'automated-review-generator' ),
            esc_html__( 'When enabled, the plugin schedules a per-minute cron and posts according to minutes. Disable this for production.', 'automated-review-generator' )
        );
    }

    public static function field_enable_llm() {
        $opts = get_option( self::OPTION_NAME, self::get_defaults() );
        $val  = ! empty( $opts['enable_llm'] );
        printf(
            '<label><input name="%1$s[enable_llm]" type="checkbox" value="1" %2$s /> %3$s</label>'
            . '<p class="description">%4$s</p>',
            esc_attr( self::OPTION_NAME ),
            checked( 1, $val, false ),
            esc_html__( 'Enable LLM paraphrase generation for reviews (default: enabled for dev testing).', 'automated-review-generator' ),
            esc_html__( 'If enabled, the plugin will attempt to call the configured LLM endpoint to generate review text. Disabling will fall back to deterministic text.', 'automated-review-generator' )
        );
    }

    public static function field_llm_provider() {
        $opts = get_option( self::OPTION_NAME, self::get_defaults() );
        $val  = isset( $opts['llm_provider'] ) ? $opts['llm_provider'] : self::get_defaults()['llm_provider'];
        $providers = array( 'none' => __( 'None', 'automated-review-generator' ), 'openai' => __( 'OpenAI-compatible', 'automated-review-generator' ), 'custom' => __( 'Custom API base', 'automated-review-generator' ) );
        echo '<select name="' . esc_attr( self::OPTION_NAME ) . '[llm_provider]">';
        foreach ( $providers as $key => $label ) {
            echo '<option value="' . esc_attr( $key ) . '" ' . selected( $val, $key, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__( 'Choose how to generate paraphrases: an OpenAI-compatible API or a custom API base URL.', 'automated-review-generator' ) . '</p>';
    }

    public static function field_llm_api_key() {
        $opts = get_option( self::OPTION_NAME, self::get_defaults() );
        $val  = isset( $opts['llm_api_key'] ) ? $opts['llm_api_key'] : '';
        printf(
            '<input name="%1$s[llm_api_key]" type="password" value="%2$s" class="regular-text" />'
            . '<p class="description">%3$s</p>',
            esc_attr( self::OPTION_NAME ),
            esc_attr( $val ),
            esc_html__( 'API key for the chosen provider (if applicable). Stored in plugin options; shown as password for safety.', 'automated-review-generator' )
        );
    }

    public static function field_llm_api_base() {
        $opts = get_option( self::OPTION_NAME, self::get_defaults() );
        $val  = isset( $opts['llm_api_base'] ) ? $opts['llm_api_base'] : self::get_defaults()['llm_api_base'];
        printf(
            '<input name="%1$s[llm_api_base]" type="url" value="%2$s" class="regular-text" />'
            . '<p class="description">%3$s</p>',
            esc_attr( self::OPTION_NAME ),
            esc_attr( $val ),
            esc_html__( 'Base URL for custom OpenAI-compatible API endpoints (leave empty to use OpenAI API).', 'automated-review-generator' )
        );
    }

    public static function field_paraphrase_count() {
        $opts = get_option( self::OPTION_NAME, self::get_defaults() );
        $val  = isset( $opts['paraphrase_count'] ) ? intval( $opts['paraphrase_count'] ) : self::get_defaults()['paraphrase_count'];
        printf(
            '<input name="%1$s[paraphrase_count]" type="number" min="1" max="5" value="%2$s" class="small-text" />'
            . '<p class="description">%3$s</p>',
            esc_attr( self::OPTION_NAME ),
            esc_attr( $val ),
            esc_html__( 'Number of paraphrases to request for each generation (default 1). Higher values produce more variants but cost/latency increases.', 'automated-review-generator' )
        );
    }

    // Example reviews per rating (admin supplies three examples per star rating)
    public static function field_examples_for_rating( $rating ) {
        $opts = get_option( self::OPTION_NAME, self::get_defaults() );
        $key = 'examples_' . intval( $rating );
        $val = isset( $opts[ $key ] ) ? $opts[ $key ] : '';
        printf(
            '<textarea name="%1$s[%4$s]" rows="6" cols="60" class="large-text code">%2$s</textarea>'
            . '<p class="description">%3$s</p>',
            esc_attr( self::OPTION_NAME ),
            esc_textarea( $val ),
            esc_html__( 'Provide three example reviews of this star rating (one per paragraph). Use them as style examples for the model.', 'automated-review-generator' ),
            esc_attr( $key )
        );
    }

    public static function field_examples_5() { self::field_examples_for_rating(5); }
    public static function field_examples_4() { self::field_examples_for_rating(4); }
    public static function field_examples_3() { self::field_examples_for_rating(3); }
    public static function field_examples_2() { self::field_examples_for_rating(2); }
    public static function field_examples_1() { self::field_examples_for_rating(1); }

    public static function field_model() {
        $opts = get_option( self::OPTION_NAME, self::get_defaults() );
        $val  = isset( $opts['model'] ) ? $opts['model'] : self::get_defaults()['model'];
        printf(
            '<input name="%1$s[model]" type="text" value="%2$s" class="regular-text" />'
            . '<p class="description">%3$s</p>',
            esc_attr( self::OPTION_NAME ),
            esc_attr( $val ),
            esc_html__( 'Model name for OpenAI-compatible APIs (e.g., gpt-3.5-turbo).', 'automated-review-generator' )
        );
    }

    public static function field_temperature() {
        $opts = get_option( self::OPTION_NAME, self::get_defaults() );
        $val  = isset( $opts['temperature'] ) ? floatval( $opts['temperature'] ) : self::get_defaults()['temperature'];
        printf(
            '<input name="%1$s[temperature]" type="number" min="0" max="1" step="0.05" value="%2$s" class="small-text" />'
            . '<p class="description">%3$s</p>',
            esc_attr( self::OPTION_NAME ),
            esc_attr( $val ),
            esc_html__( 'Sampling temperature for the model (0.0–1.0). Higher = more creative.', 'automated-review-generator' )
        );
    }

    public static function field_max_tokens() {
        $opts = get_option( self::OPTION_NAME, self::get_defaults() );
        $val  = isset( $opts['max_tokens'] ) ? intval( $opts['max_tokens'] ) : self::get_defaults()['max_tokens'];
        printf(
            '<input name="%1$s[max_tokens]" type="number" min="16" max="2048" step="1" value="%2$s" class="small-text" />'
            . '<p class="description">%3$s</p>',
            esc_attr( self::OPTION_NAME ),
            esc_attr( $val ),
            esc_html__( 'Maximum tokens to request from the model for each generation (controls length).', 'automated-review-generator' )
        );
    }

    public static function field_forbidden_content() {
        $opts = get_option( self::OPTION_NAME, self::get_defaults() );
        $val  = isset( $opts['forbidden_content'] ) ? $opts['forbidden_content'] : '';
        printf(
            '<textarea name="%1$s[forbidden_content]" rows="3" cols="60" class="large-text code">%2$s</textarea>'
            . '<p class="description">%3$s</p>',
            esc_attr( self::OPTION_NAME ),
            esc_textarea( $val ),
            esc_html__( 'Comma-separated phrases that must NOT appear in generated reviews (e.g., "AI-generated", "do not buy"). The system will try to reject outputs containing these.', 'automated-review-generator' )
        );
    }

    public static function field_llm_include_short_description() {
        $opts = get_option( self::OPTION_NAME, self::get_defaults() );
        $val  = ! empty( $opts['llm_include_short_description'] );
        printf(
            '<label><input name="%1$s[llm_include_short_description]" type="checkbox" value="1" %2$s /> %3$s</label>'
            . '<p class="description">%4$s</p>',
            esc_attr( self::OPTION_NAME ),
            checked( 1, $val, false ),
            esc_html__( 'Include the short product description (excerpt) in the LLM prompt to make reviews product-specific.', 'automated-review-generator' ),
            esc_html__( 'This will be sanitized and truncated to avoid token bloat and remove PII (emails/phone numbers will be redacted). Enabling may increase API costs.', 'automated-review-generator' )
        );
    }

    public static function field_llm_description_max_chars() {
        $opts = get_option( self::OPTION_NAME, self::get_defaults() );
        $val  = isset( $opts['llm_description_max_chars'] ) ? intval( $opts['llm_description_max_chars'] ) : self::get_defaults()['llm_description_max_chars'];
        printf(
            '<input name="%1$s[llm_description_max_chars]" type="number" min="50" max="2000" step="10" value="%2$s" class="small-text" />'
            . '<p class="description">%3$s</p>',
            esc_attr( self::OPTION_NAME ),
            esc_attr( $val ),
            esc_html__( 'Maximum characters of the product description to include in prompts (sanitized). Lower numbers reduce token use and cost.', 'automated-review-generator' )
        );
    }

    public static function field_username_examples() {
        $opts = get_option( self::OPTION_NAME, self::get_defaults() );
        $val  = isset( $opts['username_examples'] ) ? $opts['username_examples'] : self::get_defaults()['username_examples'];
        printf(
            '<textarea name="%1$s[username_examples]" rows="10" cols="60" class="large-text code">%2$s</textarea>'
            . '<p class="description">%3$s</p>',
            esc_attr( self::OPTION_NAME ),
            esc_textarea( $val ),
            esc_html__( 'Provide about 10 example usernames, one per line. These guide the LLM to create new usernames; avoid real personal data.', 'automated-review-generator' )
        );
    }

    public static function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions', 'automated-review-generator' ) );
        }

        echo '<div class="wrap"><h1>' . esc_html__( 'Automated Review Generator', 'automated-review-generator' ) . '</h1>';

        // Last run info
        $last_run = get_option( 'arg_last_run' );
        $last_count = get_option( 'arg_last_run_count', 0 );
        if ( $last_run ) {
            echo '<p><strong>' . esc_html__( 'Last run:', 'automated-review-generator' ) . '</strong> ' . esc_html( $last_run ) . ' &middot; <strong>' . esc_html__( 'Reviews generated:', 'automated-review-generator' ) . '</strong> ' . intval( $last_count ) . '</p>';
        }

        // Show test/live mode notice
        $opts = get_option( self::OPTION_NAME, self::get_defaults() );
        if ( empty( $opts['enable_live_posts'] ) ) {
            echo '<div class="notice notice-info"><p>' . esc_html__( 'Test mode is enabled — generated reviews will not be publicly visible by default.', 'automated-review-generator' ) . '</p></div>';
        } else {
            echo '<div class="notice notice-warning"><p>' . esc_html__( 'Live posting is ENABLED — generated reviews may appear on your site. Use only on development/staging sites unless you understand the impact.', 'automated-review-generator' ) . '</p></div>';
        }

        // LLM test form (separate)
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:1em;">';
        echo '<input type="hidden" name="action" value="arg_test_llm" />';
        wp_nonce_field( 'arg_test_llm_action', 'arg_test_llm_nonce' );
        echo '<button type="submit" class="button button-secondary">' . esc_html__( 'Test LLM connection', 'automated-review-generator' ) . '</button>';
        echo '</form>';

        // Show LLM test result
        if ( isset( $_GET['arg_test_llm'] ) ) {
            $ok = '1' === $_GET['arg_test_llm'];
            $msg = isset( $_GET['arg_test_msg'] ) ? urldecode( $_GET['arg_test_msg'] ) : '';
            if ( $ok ) {
                echo '<div class="updated notice"><p>' . esc_html__( 'LLM connection succeeded:', 'automated-review-generator' ) . ' ' . esc_html( $msg ) . '</p></div>';
            } else {
                echo '<div class="error notice"><p>' . esc_html__( 'LLM connection failed:', 'automated-review-generator' ) . ' ' . esc_html( $msg ) . '</p></div>';
            }
        }

        // Manual run form (separate to avoid nonce collision with settings form)
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:1em;">';
        echo '<input type="hidden" name="action" value="arg_run_now" />';
        wp_nonce_field( 'arg_run_now_action', 'arg_run_now_nonce' );
        echo '<button type="submit" class="button button-secondary">' . esc_html__( 'Run now', 'automated-review-generator' ) . '</button>';
        echo '</form>';

        // Show notice after run
        if ( isset( $_GET['arg_run'] ) && '1' === $_GET['arg_run'] ) {
            echo '<div class="updated notice"><p>' . esc_html__( 'Manual run completed. Check the last run info below.', 'automated-review-generator' ) . '</p></div>';
        }

        echo '<form method="post" action="options.php">';
        settings_fields( 'arg_settings_group' );
        do_settings_sections( 'arg-admin' );

        submit_button();
        echo '</form>';
        echo '</div>';
    }

    public static function get_defaults() {
        return array(
            'avg_frequency_days' => 5,
            'avg_score' => '4.8',
            'negative_percentage' => 5,
            'review_prompt' => __( "Write a very short review (6-60 words, mostly under 15 words). Be brief and realistic.", 'automated-review-generator' ),
            'enable_live_posts' => 0,
            'use_minutes' => 1,
            'enable_llm' => 1,
            'llm_provider' => 'openai',
            'llm_api_key' => '',
            'llm_api_base' => '',
            'paraphrase_count' => 1,
            'examples_5' => "Amazing! Perfect quality.\nLove it! Exceeded expectations.\nBest purchase ever. Highly recommend.\nFantastic! Works great.\nExcellent product, very happy!",
            'examples_4' => "Very good. Minor issues but satisfied.\nGreat overall, would buy again.\nGood quality, happy with it.\nNice product. Works well.",
            'examples_3' => "It's okay. Does the job.\nDecent. Has some limitations.\nAverage product, nothing special.\nWorks but could be better.",
            'examples_2' => "Not great. Several issues.\nDisappointing quality.\nBelow expectations. Wouldn't recommend.\nHard to use. Not happy.",
            'examples_1' => "Terrible! Broke immediately.\nAwful quality. Don't buy.\nWaste of money.\nComplete disappointment.\nVery poor product.",
            'model' => 'gpt-3.5-turbo',
            'temperature' => 0.7,
            'max_tokens' => 200,
            'forbidden_content' => "AI-generated,do not buy",
            'llm_include_short_description' => 1,
            'llm_description_max_chars' => 300,
            'username_examples' => "Stan Spedalski\nN. Tolen\nJeremy H.\nSusan H. Collins\nBarb\nCorey\nDanelsky J.\nMack T. LaPeer\nTechie_Guru\nInnovator_22\nMoon_Walker\nL.Ross\nJane_Smith\nR3view3r\nHappy_Hiker\nA.B.C.\nBob Johnson\nAmy K.\nMichael T.\nSarah",
        );
    }

    public static function sanitize_options( $input ) {
        $defaults = self::get_defaults();
        $out = array();

        // avg_frequency_days: float >= 1
        if ( isset( $input['avg_frequency_days'] ) ) {
            $freq = floatval( $input['avg_frequency_days'] );
            if ( $freq < 1 ) {
                $freq = $defaults['avg_frequency_days'];
            }
            $out['avg_frequency_days'] = round( $freq, 2 );
        } else {
            $out['avg_frequency_days'] = $defaults['avg_frequency_days'];
        }

        // avg_score: one decimal between 1.0 and 5.0
        if ( isset( $input['avg_score'] ) ) {
            $score = round( floatval( $input['avg_score'] ), 1 );
            if ( $score < 1.0 ) {
                $score = 1.0;
            }
            if ( $score > 5.0 ) {
                $score = 5.0;
            }
            $out['avg_score'] = number_format( $score, 1, '.', '' );
        } else {
            $out['avg_score'] = $defaults['avg_score'];
        }

        // negative_percentage: integer 0-100
        if ( isset( $input['negative_percentage'] ) ) {
            $neg = intval( $input['negative_percentage'] );
            if ( $neg < 0 ) {
                $neg = 0;
            }
            if ( $neg > 100 ) {
                $neg = 100;
            }
            $out['negative_percentage'] = $neg;
        } else {
            $out['negative_percentage'] = $defaults['negative_percentage'];
        }

        // review_prompt: sanitize textarea
        if ( isset( $input['review_prompt'] ) ) {
            $out['review_prompt'] = sanitize_textarea_field( $input['review_prompt'] );
        } else {
            $out['review_prompt'] = $defaults['review_prompt'];
        }

        // enable_live_posts: boolean checkbox
        $out['enable_live_posts'] = ( isset( $input['enable_live_posts'] ) && $input['enable_live_posts'] ) ? 1 : 0;

        // use_minutes: boolean checkbox for dev time-scaling
        $out['use_minutes'] = ( isset( $input['use_minutes'] ) && $input['use_minutes'] ) ? 1 : 0;

        // enable_llm
        $out['enable_llm'] = ( isset( $input['enable_llm'] ) && $input['enable_llm'] ) ? 1 : 0;

        // llm_provider
        $allowed = array( 'none', 'openai', 'custom' );
        $out['llm_provider'] = ( isset( $input['llm_provider'] ) && in_array( $input['llm_provider'], $allowed, true ) ) ? $input['llm_provider'] : $defaults['llm_provider'];

        // llm_api_key
        $out['llm_api_key'] = isset( $input['llm_api_key'] ) ? sanitize_text_field( $input['llm_api_key'] ) : $defaults['llm_api_key'];

        // llm_api_base (custom OpenAI-compatible base)
        $out['llm_api_base'] = isset( $input['llm_api_base'] ) ? esc_url_raw( trim( $input['llm_api_base'] ) ) : $defaults['llm_api_base'];

        // examples per rating
        for ( $r = 1; $r <= 5; $r++ ) {
            $k = 'examples_' . $r;
            $out[ $k ] = isset( $input[ $k ] ) ? sanitize_textarea_field( $input[ $k ] ) : $defaults[ $k ];
        }

        // model, temperature, max_tokens, forbidden_content
        $out['model'] = isset( $input['model'] ) ? sanitize_text_field( $input['model'] ) : $defaults['model'];
        $out['temperature'] = isset( $input['temperature'] ) ? floatval( $input['temperature'] ) : $defaults['temperature'];
        if ( $out['temperature'] < 0 ) { $out['temperature'] = 0; }
        if ( $out['temperature'] > 1 ) { $out['temperature'] = 1; }
        $out['max_tokens'] = isset( $input['max_tokens'] ) ? intval( $input['max_tokens'] ) : $defaults['max_tokens'];
        if ( $out['max_tokens'] < 16 ) { $out['max_tokens'] = 16; }
        if ( $out['max_tokens'] > 2048 ) { $out['max_tokens'] = 2048; }
        $out['forbidden_content'] = isset( $input['forbidden_content'] ) ? sanitize_textarea_field( $input['forbidden_content'] ) : $defaults['forbidden_content'];


        // paraphrase_count
        $pc = isset( $input['paraphrase_count'] ) ? intval( $input['paraphrase_count'] ) : $defaults['paraphrase_count'];
        if ( $pc < 1 ) { $pc = 1; }
        if ( $pc > 5 ) { $pc = 5; }
        $out['paraphrase_count'] = $pc;

        // llm_include_short_description
        $out['llm_include_short_description'] = ( isset( $input['llm_include_short_description'] ) && $input['llm_include_short_description'] ) ? 1 : 0;

        // llm_description_max_chars (50-2000)
        $desc_max = isset( $input['llm_description_max_chars'] ) ? intval( $input['llm_description_max_chars'] ) : $defaults['llm_description_max_chars'];
        if ( $desc_max < 50 ) { $desc_max = 50; }
        if ( $desc_max > 2000 ) { $desc_max = 2000; }
        $out['llm_description_max_chars'] = $desc_max;

        // username_examples: textarea, one per line
        $out['username_examples'] = isset( $input['username_examples'] ) ? sanitize_textarea_field( $input['username_examples'] ) : $defaults['username_examples'];

        // Trigger an action so other components can react to option changes (reschedule cron, etc.)
        do_action( 'arg_options_saved', $out );

        return $out;
    }
}

// Initialize
ARG_Admin::init();
