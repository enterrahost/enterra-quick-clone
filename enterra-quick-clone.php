<?php
/*
 * Plugin Name: Enterra Quick Clone
 * Description: Adds a Clone and Clone & Edit option to WooCommerce products.
 * Version: 1.0.1
 * Author: Enterrahost
 * Author URI: https://enterrahost.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: enterra-quick-clone
 * Domain Path: /languages
 */

defined('ABSPATH') || exit;

final class Quick_Product_Cloner {

    public function __construct() {
        add_filter('post_row_actions', [$this, 'add_clone_links'], 10, 2);
        add_action('admin_action_qpc_clone_product', [$this, 'handle_clone']);
        add_action('admin_action_qpc_clone_and_edit_product', [$this, 'handle_clone_and_edit']);
    }

    public function add_clone_links($actions, $post) {
        if ('product' === $post->post_type && current_user_can('edit_products')) {
            $nonce_clone = wp_create_nonce('qpc_clone_' . $post->ID);
            $nonce_clone_edit = wp_create_nonce('qpc_clone_edit_' . $post->ID);

            $actions['qpc_clone'] = sprintf(
                '<a href="%s">%s</a>',
                esc_url(admin_url('admin.php?action=qpc_clone_product&post=' . $post->ID . '&_wpnonce=' . $nonce_clone)),
                esc_html__('Clone', 'enterra-quick-clone')
            );

            $actions['qpc_clone_edit'] = sprintf(
                '<a href="%s">%s</a>',
                esc_url(admin_url('admin.php?action=qpc_clone_and_edit_product&post=' . $post->ID . '&_wpnonce=' . $nonce_clone_edit)),
                esc_html__('Clone & Edit', 'enterra-quick-clone')
            );
        }
        return $actions;
    }

    private function duplicate_product($post_id) {
        $post = get_post($post_id);
        if (!$post || 'product' !== $post->post_type) return false;

        $new_post = [
            'post_title'    => $post->post_title . ' ' . __('(Copy)', 'enterra-quick-clone'),
            'post_content'  => $post->post_content,
            'post_excerpt'  => $post->post_excerpt,
            'post_status'   => 'draft',
            'post_type'     => 'product',
            'post_name'     => sanitize_title($post->post_name . '-copy'),
        ];

        $new_post_id = wp_insert_post($new_post, true);
        if (is_wp_error($new_post_id)) return false;

        $this->duplicate_post_meta($post_id, $new_post_id);
        $this->duplicate_taxonomies($post_id, $new_post_id);
        $this->duplicate_product_variations($post_id, $new_post_id);

        return $new_post_id;
    }

    private function duplicate_post_meta($original_id, $new_id) {
        // Use cached metadata instead of direct DB queries
        $meta_data = get_post_meta($original_id);
        
        if (empty($meta_data)) return;

        $skippable_keys = ['_edit_lock', '_edit_last'];
        $skus = [];

        foreach ($meta_data as $key => $values) {
            if (in_array($key, $skippable_keys, true)) continue;
            
            foreach ($values as $value) {
                $value = maybe_unserialize($value);
                
                // Handle SKU modification
                if ('_sku' === $key && !empty($value)) {
                    $skus[$new_id] = $value . '-Copy';
                    continue;
                }
                
                add_post_meta($new_id, $key, $value);
            }
        }

        // Update SKUs
        if (!empty($skus)) {
            foreach ($skus as $id => $sku) {
                update_post_meta($id, '_sku', $sku);
            }
        }
    }

    private function duplicate_taxonomies($original_id, $new_id) {
        $taxonomies = get_object_taxonomies('product');
        wp_defer_term_counting(true);
        
        foreach ($taxonomies as $taxonomy) {
            $terms = wp_get_object_terms($original_id, $taxonomy, ['fields' => 'slugs']);
            if (!empty($terms) && !is_wp_error($terms)) {
                wp_set_object_terms($new_id, $terms, $taxonomy, false);
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
                'post_name'    => sanitize_title($child->post_name . '-copy'),
                'menu_order'   => $child->menu_order,
            ]);

            if (!is_wp_error($new_child_id)) {
                $this->duplicate_post_meta($child_id, $new_child_id);
                
                // Update variation SKU separately
                $child_sku = get_post_meta($child_id, '_sku', true);
                if (!empty($child_sku)) {
                    update_post_meta($new_child_id, '_sku', $child_sku . '-copy');
                }
            }
        }
    }

    public function handle_clone() {
        // Validate request with proper sanitization
        if (!isset($_GET['post']) || !current_user_can('edit_products')) {
            wp_die(esc_html__('Permission denied', 'enterra-quick-clone'));
        }
        
        $post_id = absint($_GET['post']);
        $nonce = isset($_REQUEST['_wpnonce']) ? sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'])) : '';
        
        // Verify nonce with proper sanitization
        if (!wp_verify_nonce($nonce, 'qpc_clone_' . $post_id)) {
            wp_die(esc_html__('Security check failed', 'enterra-quick-clone'));
        }

        if ($this->duplicate_product($post_id)) {
            wp_safe_redirect(admin_url('edit.php?post_type=product&cloned=1'));
        } else {
            wp_safe_redirect(admin_url('edit.php?post_type=product&clone_failed=1'));
        }
        exit;
    }

    public function handle_clone_and_edit() {
        // Validate request with proper sanitization
        if (!isset($_GET['post']) || !current_user_can('edit_products')) {
            wp_die(esc_html__('Permission denied', 'enterra-quick-clone'));
        }
        
        $post_id = absint($_GET['post']);
        $nonce = isset($_REQUEST['_wpnonce']) ? sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'])) : '';
        
        // Verify nonce with proper sanitization
        if (!wp_verify_nonce($nonce, 'qpc_clone_edit_' . $post_id)) {
            wp_die(esc_html__('Security check failed', 'enterra-quick-clone'));
        }

        $new_post_id = $this->duplicate_product($post_id);

        if ($new_post_id) {
            wp_safe_redirect(admin_url('post.php?action=edit&post=' . $new_post_id));
        } else {
            wp_die(esc_html__('Failed to clone product', 'enterra-quick-clone'));
        }
        exit;
    }
}

new Quick_Product_Cloner();