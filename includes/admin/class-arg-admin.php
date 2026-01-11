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

    public static function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions', 'automated-review-generator' ) );
        }

        echo '<div class="wrap"><h1>' . esc_html__( 'Automated Review Generator', 'automated-review-generator' ) . '</h1>';
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
            'review_prompt' => __( "Write a concise, helpful review (80-160 words). Mention product features, ease of use, and value for money. Keep tone friendly and realistic.", 'automated-review-generator' ),
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

        return $out;
    }
}

// Initialize
ARG_Admin::init();
