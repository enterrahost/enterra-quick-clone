<?php
/*
 * Plugin Name: Enterra Quick Clone
 * Description: Adds a Clone and Clone & Edit option to WooCommerce products.
 * Version: 1.0.0
 * Author: Enterrahost
 * Author URI: https://enterrahost.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: enterra-quick-clone
 * Domain Path: /languages
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class Quick_Product_Cloner {

    public function __construct() {
        add_filter('post_row_actions', array($this, 'add_clone_links'), 10, 2);
        add_action('admin_action_qpc_clone_product', array($this, 'handle_clone'));
        add_action('admin_action_qpc_clone_and_edit_product', array($this, 'handle_clone_and_edit'));
    }

    public function add_clone_links($actions, $post) {
        if ($post->post_type === 'product' && current_user_can('edit_post', $post->ID)) {
            $clone_url = wp_nonce_url(admin_url('admin.php?action=qpc_clone_product&post=' . $post->ID), 'qpc_clone_' . $post->ID);
            $clone_edit_url = wp_nonce_url(admin_url('admin.php?action=qpc_clone_and_edit_product&post=' . $post->ID), 'qpc_clone_edit_' . $post->ID);

            $actions['qpc_clone'] = '<a href="' . esc_url($clone_url) . '">' . esc_html__('Clone', 'enterra-quick-clone') . '</a>';
            $actions['qpc_clone_edit'] = '<a href="' . esc_url($clone_edit_url) . '">' . esc_html__('Clone & Edit', 'enterra-quick-clone') . '</a>';
        }
        return $actions;
    }

    private function duplicate_product($post_id) {
        $post = get_post($post_id);

        if (!$post || $post->post_type !== 'product') {
            return false;
        }

        $new_post = array(
            'post_title'    => $post->post_title . ' (Copy)',
            'post_content'  => $post->post_content,
            'post_excerpt'  => $post->post_excerpt,
            'post_status'   => 'draft',
            'post_type'     => 'product',
        );

        $new_post_id = wp_insert_post($new_post);

        if (is_wp_error($new_post_id)) {
            return false;
        }

        // Copy post meta
        $meta = get_post_meta($post_id);
        foreach ($meta as $key => $values) {
            foreach ($values as $value) {
                update_post_meta($new_post_id, $key, maybe_unserialize($value));
            }
        }

        // Duplicate and Update SKU add *SKUCODE*-Copy
        $original_sku = get_post_meta($post_id, '_sku', true);
            if (!empty($original_sku)) {
                update_post_meta($new_post_id, '_sku', $original_sku . '-Copy');
            }

        // Copy taxonomy terms
        $taxonomies = get_object_taxonomies('product');
        foreach ($taxonomies as $taxonomy) {
            $terms = wp_get_object_terms($post_id, $taxonomy, array('fields' => 'slugs'));
            wp_set_object_terms($new_post_id, $terms, $taxonomy);
        }
        
        $children = get_children(array(
            'post_parent' => $post_id,
            'post_type'   => 'product_variation',
            'post_status' => 'any',
            'numberposts' => -1
        ));

        foreach ($children as $child) {
            $new_child = array(
                'post_title'   => $child->post_title,
                'post_name'    => $child->post_name,
                'post_status'  => 'publish',
                'post_type'    => 'product_variation',
                'post_parent'  => $new_post_id,
                'menu_order'   => $child->menu_order,
                'guid'         => $child->guid,
            );

            $new_child_id = wp_insert_post($new_child);

            if (!is_wp_error($new_child_id)) {
                $child_meta = get_post_meta($child->ID);
                foreach ($child_meta as $key => $values) {
                    foreach ($values as $value) {
                        $val = maybe_unserialize($value);

                        // Clone SKU with "-copy"
                        if ($key === '_sku' && !empty($val)) {
                            $val .= '-copy';
                        }

                        update_post_meta($new_child_id, $key, $val);
                    }
                }
            }
        }

        return $new_post_id;
    }

    public function handle_clone() {
        if (!isset($_GET['post']) || !current_user_can('edit_products')) {
            wp_die(esc_html__('Permission denied', 'enterra-quick-clone'));
        }

        $post_id = absint($_GET['post']);
        check_admin_referer('qpc_clone_' . $post_id);

        $new_post_id = $this->duplicate_product($post_id);

        wp_redirect(admin_url('edit.php?post_type=product&cloned=1'));
        exit;
    }

    public function handle_clone_and_edit() {
        if (!isset($_GET['post']) || !current_user_can('edit_products')) {
            wp_die(esc_html__('Permission denied', 'enterra-quick-clone'));
        }

        $post_id = absint($_GET['post']);
        check_admin_referer('qpc_clone_edit_' . $post_id);

        $new_post_id = $this->duplicate_product($post_id);

        wp_redirect(admin_url('post.php?action=edit&post=' . $new_post_id));
        exit;
    }
}

new Quick_Product_Cloner();