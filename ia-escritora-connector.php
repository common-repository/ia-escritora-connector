<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Plugin Name: IA Escritora Connector
 * Description: Conecta o IA Escritora com o WordPress para criar posts automaticamente.
 * Version: 1.3.1
 * Author: Marcio Nobrega
 * Text Domain: ia-escritora-connector
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Registrar rotas da API
add_action('rest_api_init', function () {
    register_rest_route('ia-escritora/v1', '/create-post', array(
        'methods' => 'POST',
        'callback' => 'ia_escritora_create_post',
        'permission_callback' => 'ia_escritora_validate_api_key',
    ));
});

// Função para criar post
function ia_escritora_create_post(WP_REST_Request $request) {
    $params = $request->get_json_params();

    // Log params
    error_log(print_r($params, true));

    $title = sanitize_text_field($params['title']);
    $content = wp_kses_post(base64_decode($params['content']));
    $thumbnail_url = isset($params['thumbnail']['url']) ? esc_url_raw($params['thumbnail']['url']) : '';
    $description = isset($params['description']) ? sanitize_text_field($params['description']) : '';
    $slug = isset($params['slug']) ? sanitize_title($params['slug']) : '';
    $category_id = get_option('ia_escritora_settings')['ia_escritora_category'];
    $post_status = get_option('ia_escritora_settings')['ia_escritora_post_status'];
    $post_author = get_option('ia_escritora_settings')['ia_escritora_post_author'];

    // Log parameters before creating post
    error_log("Creating post with title: $title, content: $content, status: $post_status, author: $post_author, category: $category_id, slug: $slug");

    // Criar post
    $post_id = wp_insert_post(array(
        'post_title' => $title,
        'post_content' => $content,
        'post_status' => $post_status,
        'post_author' => $post_author,
        'post_excerpt' => $description,
        'post_name' => $slug,
        'post_category' => array($category_id),
    ));

    if (is_wp_error($post_id)) {
        error_log("Error creating post: " . $post_id->get_error_message());
        return new WP_Error('error', 'Erro ao criar post', array('status' => 500));
    }

    // Adicionar imagem destacada (thumbnail) se a URL estiver disponível e válida
    if (!empty($thumbnail_url)) {
        $thumbnail_id = ia_escritora_download_image($thumbnail_url, $post_id);
        if (!is_wp_error($thumbnail_id)) {
            set_post_thumbnail($post_id, $thumbnail_id);
        } else {
            error_log("Error setting post thumbnail: " . $thumbnail_id->get_error_message());
        }
    }

    return new WP_REST_Response(array('post_id' => $post_id), 200);
}

// Função para baixar e definir a imagem destacada
function ia_escritora_download_image($image_url, $post_id) {
    if (!function_exists('media_sideload_image')) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }

    $media = media_sideload_image($image_url, $post_id, null, 'id');
    if (is_wp_error($media)) {
        error_log("Error downloading image: " . $media->get_error_message());
        return $media;
    }
    return $media;
}

// Adicionar página de configurações
add_action('admin_menu', 'ia_escritora_add_admin_menu');
add_action('admin_init', 'ia_escritora_settings_init');

function ia_escritora_add_admin_menu() {
    add_options_page('IA Escritora Connector', 'IA Escritora Connector', 'manage_options', 'ia_escritora_connector', 'ia_escritora_options_page');
}

function ia_escritora_settings_init() {
    register_setting('ia_escritora_pluginPage', 'ia_escritora_settings');

    add_settings_section(
        'ia_escritora_pluginPage_section',
        __('Configurações da API IA Escritora', 'ia-escritora-connector'),
        'ia_escritora_settings_section_callback',
        'pluginPage'
    );

    add_settings_field(
        'ia_escritora_api_key',
        __('Chave da API', 'ia-escritora-connector'),
        'ia_escritora_api_key_render',
        'pluginPage',
        'ia_escritora_pluginPage_section'
    );

    add_settings_field(
        'ia_escritora_category',
        __('Categoria Padrão dos Posts', 'ia-escritora-connector'),
        'ia_escritora_category_render',
        'pluginPage',
        'ia_escritora_pluginPage_section'
    );

    add_settings_field(
        'ia_escritora_post_status',
        __('Status Padrão dos Posts', 'ia-escritora-connector'),
        'ia_escritora_post_status_render',
        'pluginPage',
        'ia_escritora_pluginPage_section'
    );

    add_settings_field(
        'ia_escritora_post_author',
        __('Autor Padrão dos Posts', 'ia-escritora-connector'),
        'ia_escritora_post_author_render',
        'pluginPage',
        'ia_escritora_pluginPage_section'
    );
}

function ia_escritora_api_key_render() {
    $options = get_option('ia_escritora_settings');
    ?>
    <input type='text' name='ia_escritora_settings[ia_escritora_api_key]' value='<?php
if ( ! defined( 'ABSPATH' ) ) exit; echo esc_html($options['ia_escritora_api_key']); ?>'>
    <?php
if ( ! defined( 'ABSPATH' ) ) exit;
}

function ia_escritora_category_render() {
    $options = get_option('ia_escritora_settings');
    $selected_category = isset($options['ia_escritora_category']) ? $options['ia_escritora_category'] : '';
    wp_dropdown_categories(array(
        'name' => 'ia_escritora_settings[ia_escritora_category]',
        'selected' => $selected_category,
        'show_option_all' => __('Todas as Categorias', 'ia-escritora-connector'),
        'hide_empty' => 0,
    ));
}

function ia_escritora_post_status_render() {
    $options = get_option('ia_escritora_settings');
    $selected_status = isset($options['ia_escritora_post_status']) ? $options['ia_escritora_post_status'] : 'publish';
    ?>
    <select name='ia_escritora_settings[ia_escritora_post_status]'>
        <option value='publish' <?php
if ( ! defined( 'ABSPATH' ) ) exit; selected($selected_status, 'publish'); ?>><?php
if ( ! defined( 'ABSPATH' ) ) exit; esc_html_e('Publicado', 'ia-escritora-connector'); ?></option>
        <option value='draft' <?php
if ( ! defined( 'ABSPATH' ) ) exit; selected($selected_status, 'draft'); ?>><?php
if ( ! defined( 'ABSPATH' ) ) exit; esc_html_e('Rascunho', 'ia-escritora-connector'); ?></option>
    </select>
    <?php
if ( ! defined( 'ABSPATH' ) ) exit;
}

function ia_escritora_post_author_render() {
    $options = get_option('ia_escritora_settings');
    $selected_author = isset($options['ia_escritora_post_author']) ? $options['ia_escritora_post_author'] : 1;
    wp_dropdown_users(array(
        'name' => 'ia_escritora_settings[ia_escritora_post_author]',
        'selected' => $selected_author,
    ));
}

function ia_escritora_settings_section_callback() {
    echo esc_html__('Insira sua chave de API para permitir conexões seguras e escolha a categoria, autor e status padrão dos posts.', 'ia-escritora-connector');
}

function ia_escritora_options_page() {
    ?>
    <form action='options.php' method='post'>
        <h2>IA Escritora Connector</h2>
        <?php
if ( ! defined( 'ABSPATH' ) ) exit;
        settings_fields('pluginPage');
        do_settings_sections('pluginPage');
        submit_button();
        ?>
    </form>
    <?php
if ( ! defined( 'ABSPATH' ) ) exit;
}

// Função para validar a chave de API
function ia_escritora_validate_api_key($request) {
    $options = get_option('ia_escritora_settings');
    $api_key = isset($options['ia_escritora_api_key']) ? $options['ia_escritora_api_key'] : '';
    $header_api_key = $request->get_header('x-api-key');

    return $api_key && $header_api_key && $api_key === $header_api_key;
}
