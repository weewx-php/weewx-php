<?php
// Standalone hook/escaping contract test; no WordPress installation required.
declare(strict_types=1);
define('ABSPATH', __DIR__);
$hooks = [];
$scripts = [];
function add_filter($name, $callback, ...$rest) { $GLOBALS['hooks'][$name] = $callback; }
function add_action($name, $callback) { add_filter($name, $callback); }
function add_shortcode($name, $callback) { add_filter($name, $callback); }
function esc_attr($value) { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function esc_html($value) { return esc_attr($value); }
function esc_url($value) { return esc_attr($value); }
function esc_url_raw($value, $protocols) { return preg_match('~^https?://~', $value) === 1 ? $value : ''; }
function wp_parse_url($value) { return parse_url($value); }
function sanitize_text_field($value) { return strip_tags($value); }
function plugins_url($path, $file) { return 'https://blog.example/plugins/weewx-weather/' . $path; }
function wp_enqueue_script($handle, ...$args) { $GLOBALS['scripts'][$handle] = $args; }
function shortcode_atts($defaults, $attributes, $name) { return array_intersect_key($attributes + $defaults, $defaults); }
function register_widget($name) { $GLOBALS['registered'] = $name; }
class WP_Widget {
    public function __construct(...$args) {}
    public function get_field_id($name) { return 'id-' . $name; }
    public function get_field_name($name) { return 'field-' . $name; }
}
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
require dirname(__DIR__) . '/examples/wordpress/weewx-weather/weewx-weather.php';
$html = $hooks['weewx_weather'](['api' => 'https://station.example/api/v1.php?feed=live&fields=temperature', 'title' => '" onmouseover="bad', 'fields' => 'temperature,../private,humidity']);
check(str_contains($html, '&amp;fields=temperature'), 'URL escaping');
check(!str_contains($html, 'title="" onmouseover'), 'Attribute escaping');
check(str_contains($html, 'fields="temperature,humidity"'), 'Field validation');
check(isset($scripts['weewx-weather']), 'Enqueued module');
check(str_contains($hooks['script_loader_tag']('', 'weewx-weather', 'https://blog.example/widget.js'), 'type="module"'), 'Module tag');
check($hooks['script_loader_tag']('original', 'other', '') === 'original', 'Other scripts unchanged');
check(weewx_weather_render(['api' => 'javascript:alert(1)']) === '', 'Scheme validation');
check(weewx_weather_render(['api' => 'https://user:password@station.example/']) === '', 'No embedded credentials');
$hooks['widgets_init']();
check($GLOBALS['registered'] === Weewx_Php_Weather_Widget::class, 'Classic widget registration');
$widget = new Weewx_Php_Weather_Widget();
check($widget->update(['api' => ['bad']], [])['api'] === '', 'Malformed option');
ob_start(); $widget->form(['title' => '"><img src=x>']); $form = ob_get_clean();
check(!str_contains($form, '<img'), 'Form escaping');
echo "WordPress hook, module, shortcode, widget and escaping checks passed\n";
