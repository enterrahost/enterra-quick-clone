<?php
/*
 * Plugin Name: Enterra Quick Clone
 * Description: Adds Clone and Clone & Edit options to WordPress Posts, Pages, and WooCommerce products.
 * Version: 1.2.0
 * Author: Enterrahost
 * Author URI: https://enterrahost.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: enterra-quick-clone
 * Domain Path: /languages
 */

defined('ABSPATH') || exit;

final class Quick_Content_Cloner {

    public function __construct() {
        // Add clone links to all post types
        add_filter('post_row_actions', [$this, 'add_clone_links'], 10, 2);
        add_filter('page_row_actions', [$this, 'add_clone_links'], 10, 2);
        
        // Handle clone actions
        add_action('admin_action_qpc_clone_content', [$this, 'handle_clone']);
        add_action('admin_action_qpc_clone_and_edit_content', [$this, 'handle_clone_and_edit']);
        
        // Add admin notices
        add_action('admin_notices', [$this, 'admin_clone_notices']);
    }

    public function add_clone_links($actions, $post) {
        // Get all cloneable post types
        $cloneable_types = $this->get_cloneable_post_types();
        
        if (in_array($post->post_type, $cloneable_types, true) && current_user_can('edit_post', $post->ID)) {
            $nonce_clone = wp_create_nonce('qpc_clone_' . $post->ID);
            $nonce_clone_edit = wp_create_nonce('qpc_clone_edit_' . $post->ID);

            $actions['qpc_clone'] = sprintf(
                '<a href="%s" aria-label="%s">%s</a>',
                esc_url(admin_url('admin.php?action=qpc_clone_content&post=' . $post->ID . '&_wpnonce=' . $nonce_clone)),
                /* translators: %s: Post title */
                esc_attr(sprintf(__('Clone "%s"', 'enterra-quick-clone'), get_the_title($post))),
                esc_html__('Clone', 'enterra-quick-clone')
            );

            $actions['qpc_clone_edit'] = sprintf(
                '<a href="%s" aria-label="%s">%s</a>',
                esc_url(admin_url('admin.php?action=qpc_clone_and_edit_content&post=' . $post->ID . '&_wpnonce=' . $nonce_clone_edit)),
                /* translators: %s: Post title */
                esc_attr(sprintf(__('Clone & Edit "%s"', 'enterra-quick-clone'), get_the_title($post))),
                esc_html__('Clone & Edit', 'enterra-quick-clone')
            );
        }
        return $actions;
    }
    
    public function admin_clone_notices() {
        // Check for cloned success with nonce verification
        if (!empty($_GET['cloned'])) {
            $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
            if (wp_verify_nonce($nonce, 'qpc_clone_notice')) {
                printf(
                    '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                    esc_html__('Content cloned successfully!', 'enterra-quick-clone')
                );
            }
        }
        
        // Check for clone failure with nonce verification
        if (!empty($_GET['clone_failed'])) {
            $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
            if (wp_verify_nonce($nonce, 'qpc_clone_notice')) {
                printf(
                    '<div class="notice notice-error is-dismissible"><p>%s</p></div>',
                    esc_html__('Failed to clone content.', 'enterra-quick-clone')
                );
            }
        }
    }
        
    private function get_cloneable_post_types() {
        $builtin_types = ['post', 'page'];
        $custom_types = get_post_types([
            'public' => true, 
            '_builtin' => false,
            'show_ui' => true
        ]);
        
        return array_merge($builtin_types, array_keys($custom_types));
    }

    private function duplicate_content($post_id) {
        $post = get_post($post_id);
        if (!$post) return false;

        // Setup new post data
        $new_post = [
            'post_title'     => $post->post_title . ' ' . __('(Copy)', 'enterra-quick-clone'),
            'post_content'   => $post->post_content,
            'post_excerpt'   => $post->post_excerpt,
            'post_status'    => 'draft',
            'post_type'      => $post->post_type,
            'post_name'      => $this->generate_unique_slug($post),
            'post_author'    => get_current_user_id(),
            'post_parent'    => $post->post_parent,
            'menu_order'     => $post->menu_order,
            'ping_status'    => $post->ping_status,
            'comment_status' => $post->comment_status,
            'post_password'  => $post->post_password,
            'post_date'      => current_time('mysql'),
            'post_date_gmt'  => current_time('mysql', 1),
        ];

        // Insert new post
        $new_post_id = wp_insert_post($new_post, true);
        if (is_wp_error($new_post_id)) return false;

        // Duplicate all associated data
        $this->duplicate_post_meta($post_id, $new_post_id);
        $this->duplicate_taxonomies($post_id, $new_post_id);
        
        // Handle featured image
        $thumbnail_id = get_post_thumbnail_id($post_id);
        if ($thumbnail_id) {
            set_post_thumbnail($new_post_id, $thumbnail_id);
        }
        
        // Handle product variations only for WooCommerce products
        if ('product' === $post->post_type && function_exists('wc_get_product')) {
            $this->duplicate_product_variations($post_id, $new_post_id);
        }

        return $new_post_id;
    }

    private function generate_unique_slug($post) {
        $slug = sanitize_title($post->post_name . '-copy');
        $original_slug = $slug;
        $suffix = 2;
    
        while (get_page_by_path($slug, OBJECT, $post->post_type)) {
            $slug = $original_slug . '-' . $suffix;
            $suffix++;
        }
    
        return $slug;
    }

    private function duplicate_post_meta($original_id, $new_id) {
        $meta_data = get_post_meta($original_id);
        
        if (empty($meta_data)) return;

        $skippable_keys = [
            '_edit_lock', 
            '_edit_last', 
            '_wp_old_slug',
            '_wp_old_date',
            '_wp_attached_file',
            '_wp_attachment_metadata'
        ];

        foreach ($meta_data as $key => $values) {
            if (in_array($key, $skippable_keys, true)) continue;
            
            foreach ($values as $value) {
                $value = maybe_unserialize($value);
                
                // Handle SKU modification for products
                if ('_sku' === $key && !empty($value)) {
                    $value .= '-Copy';
                }
                
                add_post_meta($new_id, $key, $value);
            }
        }
    }

    private function duplicate_taxonomies($original_id, $new_id) {
        $taxonomies = get_object_taxonomies(get_post_type($original_id));
        if (empty($taxonomies)) return;
        
        wp_defer_term_counting(true);
        
        foreach ($taxonomies as $taxonomy) {
            $terms = wp_get_object_terms($original_id, $taxonomy, ['fields' => 'ids']);
            if (!empty($terms) && !is_wp_error($terms)) {
                wp_set_object_terms($new_id, $terms, $taxonomy);
            }
        }
        
        wp_defer_term_counting(false);
    }

    private function duplicate_product_variations($original_id, $new_id) {
        $children = get_posts([
            'post_parent'    => $original_id,
            'post_type'      => 'product_variation',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ]);

        if (empty($children)) return;

        foreach ($children as $child_id) {
            $child = get_post($child_id);
            if (!$child) continue;

            $new_child_id = wp_insert_post([
                'post_title'   => $child->post_title,
                'post_status'  => $child->post_status,
                'post_type'    => 'product_variation',
                'post_parent'  => $new_id,
                'post_name'    => $this->generate_unique_slug($child),
                'menu_order'   => $child->menu_order,
            ]);

            if (!is_wp_error($new_child_id)) {
                $this->duplicate_post_meta($child_id, $new_child_id);
            }
        }
    }

    public function handle_clone() {
        // Validate request
        if (!isset($_GET['post'], $_REQUEST['_wpnonce'])) {
            wp_die(esc_html__('Invalid request', 'enterra-quick-clone'));
        }
        
        // Sanitize inputs
        $post_id = absint($_GET['post']);
        $nonce = sanitize_text_field(wp_unslash($_REQUEST['_wpnonce']));
        
        // Verify capabilities
        if (!current_user_can('edit_post', $post_id)) {
            wp_die(esc_html__('Permission denied', 'enterra-quick-clone'));
        }
        
        // Verify nonce
        if (!wp_verify_nonce($nonce, 'qpc_clone_' . $post_id)) {
            wp_die(esc_html__('Security check failed', 'enterra-quick-clone'));
        }
    
        // Clone content
        if ($this->duplicate_content($post_id)) {
            $post_type = get_post_type($post_id);
            $redirect_url = add_query_arg([
                'post_type' => $post_type,
                'cloned' => 1,
                '_wpnonce' => wp_create_nonce('qpc_clone_notice')
            ], admin_url('edit.php'));
            wp_safe_redirect($redirect_url);
            exit;
        }
        
        $post_type = get_post_type($post_id);
        $redirect_url = add_query_arg([
            'post_type' => $post_type,
            'clone_failed' => 1,
            '_wpnonce' => wp_create_nonce('qpc_clone_notice')
        ], admin_url('edit.php'));
        wp_safe_redirect($redirect_url);
        exit;
    }

    public function handle_clone_and_edit() {
        // Validate request
        if (!isset($_GET['post'], $_REQUEST['_wpnonce'])) {
            wp_die(esc_html__('Invalid request', 'enterra-quick-clone'));
        }
        
        // Sanitize inputs
        $post_id = absint($_GET['post']);
        $nonce = sanitize_text_field(wp_unslash($_REQUEST['_wpnonce']));
        
        // Verify capabilities
        if (!current_user_can('edit_post', $post_id)) {
            wp_die(esc_html__('Permission denied', 'enterra-quick-clone'));
        }
        
        // Verify nonce
        if (!wp_verify_nonce($nonce, 'qpc_clone_edit_' . $post_id)) {
            wp_die(esc_html__('Security check failed', 'enterra-quick-clone'));
        }

        // Clone content
        $new_post_id = $this->duplicate_content($post_id);

        if ($new_post_id) {
            wp_safe_redirect(admin_url('post.php?action=edit&post=' . $new_post_id));
            exit;
        }
        
        wp_die(esc_html__('Failed to clone content', 'enterra-quick-clone'));
    }
}

// Initialize plugin
new Quick_Content_Cloner();