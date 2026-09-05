<?php
/**
 * Plugin Name: WeeWX PHP Weather
 * Description: Wetterwerte als Sidebar-Widget oder Shortcode.
 * Version: 1.0.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * License: GPL-3.0-or-later
 */

defined('ABSPATH') || exit;

function weewx_weather_options($input) {
    $input = is_array($input) ? $input : [];
    $api = is_string($input['api'] ?? null) ? esc_url_raw(substr($input['api'], 0, 2048), ['http', 'https']) : '';
    $parts = wp_parse_url($api);
    if (!is_array($parts) || empty($parts['host']) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass'])) {
        $api = '';
    }
    $fields = is_string($input['fields'] ?? null) ? explode(',', $input['fields']) : [];
    $fields = array_slice(array_unique(array_filter(array_map('trim', $fields), static function ($name) {
        return preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,47}$/D', $name) === 1;
    })), 0, 32);
    return ['api' => $api, 'fields' => implode(',', $fields),
        'title' => is_string($input['title'] ?? null) ? sanitize_text_field(substr($input['title'], 0, 200)) : 'Wetter'];
}

function weewx_weather_render($input) {
    $options = weewx_weather_options($input);
    if ($options['api'] === '') {
        return '';
    }
    wp_enqueue_script('weewx-weather', plugins_url('assets/weather-widget.js', __FILE__), [], '1.0.0', true);
    return '<weewx-weather api="' . esc_attr($options['api']) . '" fields="' . esc_attr($options['fields'])
        . '" title="' . esc_attr($options['title']) . '"></weewx-weather>';
}

add_filter('script_loader_tag', static function ($tag, $handle, $src) {
    return $handle === 'weewx-weather' ? '<script type="module" src="' . esc_url($src) . '"></script>' : $tag;
}, 10, 3);

add_shortcode('weewx_weather', static function ($attributes) {
    return weewx_weather_render(shortcode_atts(['api' => '', 'fields' => '', 'title' => 'Wetter'], $attributes, 'weewx_weather'));
});

class Weewx_Php_Weather_Widget extends WP_Widget {
    public function __construct() {
        parent::__construct('weewx_php_weather', 'WeeWX Wetter');
    }

    public function widget($args, $instance) {
        $html = weewx_weather_render($instance);
        if ($html !== '') {
            // Wrappers are supplied by the registered WordPress theme sidebar.
            echo $args['before_widget'] . $html . $args['after_widget'];
        }
    }

    public function update($new_instance, $old_instance) {
        return weewx_weather_options($new_instance);
    }

    public function form($instance) {
        $options = weewx_weather_options($instance);
        foreach (['title' => 'Titel', 'api' => 'API-URL', 'fields' => 'Felder (kommagetrennt)'] as $name => $label) {
            echo '<p><label for="' . esc_attr($this->get_field_id($name)) . '">' . esc_html($label) . '</label>';
            echo '<input class="widefat" type="text" id="' . esc_attr($this->get_field_id($name))
                . '" name="' . esc_attr($this->get_field_name($name)) . '" value="' . esc_attr($options[$name]) . '"></p>';
        }
    }
}

add_action('widgets_init', static function () { register_widget(Weewx_Php_Weather_Widget::class); });
