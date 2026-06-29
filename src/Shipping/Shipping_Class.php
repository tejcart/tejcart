<?php
/**
 * Shipping Class manager.
 *
 * @package TejCart\Shipping
 */

declare( strict_types=1 );

namespace TejCart\Shipping;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Manages shipping classes that allow different shipping rates per product type
 * (e.g., "bulky", "fragile").
 *
 * Shipping classes are stored in the `tejcart_shipping_classes` option as a JSON
 * array of objects: [{id, name, slug, description}].
 *
 * Products are assigned a shipping class via product meta `_shipping_class`.
 */
class Shipping_Class {
    /**
     * Loaded shipping classes.
     *
     * @var array
     */
    private $classes = array();

    /**
     * Constructor - loads classes from the database.
     */
    public function __construct() {
        $stored       = get_option( 'tejcart_shipping_classes', '[]' );
        $decoded      = json_decode( $stored, true );
        $this->classes = is_array( $decoded ) ? $decoded : array();
    }

    /**
     * Persist the current classes array back to the database.
     *
     * @return bool
     */
    private function save_classes() {
        // Audit 08 #19 — small option used only during shipping
        // calculation; the surrounding code reads via wp_cache. No
        // benefit to autoloading.
        return update_option( 'tejcart_shipping_classes', wp_json_encode( $this->classes ), false );
    }

    /**
     * Get all shipping classes.
     *
     * @return array Array of shipping class entries.
     */
    public function get_classes() {
        return $this->classes;
    }

    /**
     * Add a new shipping class.
     *
     * @param array $data {name, slug, description}.
     * @return int The new class ID.
     */
    public function add_class( $data ) {
        $id = 1;
        foreach ( $this->classes as $class ) {
            if ( isset( $class['id'] ) && (int) $class['id'] >= $id ) {
                $id = (int) $class['id'] + 1;
            }
        }

        $name = isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '';
        $slug = isset( $data['slug'] ) && '' !== $data['slug']
            ? sanitize_title( $data['slug'] )
            : sanitize_title( $name );

        $new_class = array(
            'id'          => $id,
            'name'        => $name,
            'slug'        => $slug,
            'description' => isset( $data['description'] ) ? sanitize_text_field( $data['description'] ) : '',
        );

        $this->classes[] = $new_class;
        $this->save_classes();

        return $id;
    }

    /**
     * Update an existing shipping class.
     *
     * @param int   $id   Class ID.
     * @param array $data Fields to update (name, slug, description).
     * @return bool True on success.
     */
    public function update_class( $id, $data ) {
        foreach ( $this->classes as $index => $class ) {
            if ( isset( $class['id'] ) && (int) $class['id'] === (int) $id ) {
                $allowed = array( 'name', 'slug', 'description' );
                foreach ( $allowed as $key ) {
                    if ( isset( $data[ $key ] ) ) {
                        if ( 'slug' === $key ) {
                            $this->classes[ $index ][ $key ] = sanitize_title( $data[ $key ] );
                        } else {
                            $this->classes[ $index ][ $key ] = sanitize_text_field( $data[ $key ] );
                        }
                    }
                }
                $this->save_classes();
                return true;
            }
        }

        return false;
    }

    /**
     * Delete a shipping class by ID.
     *
     * @param int $id Class ID.
     * @return bool True on success.
     */
    public function delete_class( $id ) {
        foreach ( $this->classes as $index => $class ) {
            if ( isset( $class['id'] ) && (int) $class['id'] === (int) $id ) {
                array_splice( $this->classes, $index, 1 );
                $this->save_classes();
                return true;
            }
        }

        return false;
    }

    /**
     * Get a shipping class by ID.
     *
     * @param int $id Class ID.
     * @return array|null Shipping class data or null if not found.
     */
    public function get_class( $id ) {
        foreach ( $this->classes as $class ) {
            if ( isset( $class['id'] ) && (int) $class['id'] === (int) $id ) {
                return $class;
            }
        }

        return null;
    }

    /**
     * Get a shipping class by slug.
     *
     * @param string $slug Class slug.
     * @return array|null Shipping class data or null if not found.
     */
    public function get_class_by_slug( $slug ) {
        $slug = sanitize_title( $slug );

        foreach ( $this->classes as $class ) {
            if ( isset( $class['slug'] ) && $class['slug'] === $slug ) {
                return $class;
            }
        }

        return null;
    }
}
