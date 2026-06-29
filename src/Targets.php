<?php
/**
 * Resolves publish targets: a simple product is its own target, a variable
 * product resolves to the variations matching a chosen attribute value.
 *
 * @package WCGP
 */

namespace WCGP;

defined( 'ABSPATH' ) || exit;

/**
 * Helpers for mapping a publish action to the WooCommerce object(s) that should
 * receive the downloadable file.
 */
class Targets {

	const ALL = '__all__';

	/**
	 * Whether a product is a variable (or variable-subscription) product.
	 *
	 * @param \WC_Product $product Product object.
	 * @return bool
	 */
	public static function is_variable( $product ) {
		return $product && $product->is_type( array( 'variable', 'variable-subscription' ) );
	}

	/**
	 * Get the product's variation attributes for the target selector.
	 *
	 * @param \WC_Product $product Variable product.
	 * @return array List of { name, label, values: [{ slug, label }] }.
	 */
	public static function get_variation_attributes( $product ) {
		$out = array();
		if ( ! self::is_variable( $product ) ) {
			return $out;
		}
		// attribute_name => array of value slugs/names used by variations.
		$attributes = $product->get_variation_attributes();
		foreach ( $attributes as $name => $values ) {
			$values = array_filter( (array) $values, 'strlen' );
			$opts   = array();
			foreach ( $values as $value ) {
				$opts[] = array(
					'slug'  => $value,
					'label' => self::value_label( $name, $value ),
				);
			}
			$out[] = array(
				'name'   => $name,
				'label'  => wc_attribute_label( $name, $product ),
				'values' => $opts,
			);
		}
		return $out;
	}

	/**
	 * Resolve the target ids for a publish action.
	 *
	 * @param \WC_Product $product   Product object.
	 * @param string      $attribute Variation attribute name (e.g. "pa_platform"). Empty for "all".
	 * @param string      $value     Attribute value slug, or {@see ALL}.
	 * @return int[] Target ids (the product id for simple products, variation ids otherwise).
	 */
	public static function resolve( $product, $attribute, $value ) {
		if ( ! self::is_variable( $product ) ) {
			return array( $product->get_id() );
		}

		$children = $product->get_children();
		if ( self::ALL === $value || '' === $attribute ) {
			return array_map( 'intval', $children );
		}

		$matched = array();
		foreach ( $children as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( $variation && self::variation_matches( $variation, $attribute, $value ) ) {
				$matched[] = (int) $variation_id;
			}
		}
		return $matched;
	}

	/**
	 * Whether a variation matches an attribute=value selection. A variation set to
	 * "Any" (empty) for that attribute matches every value.
	 *
	 * @param \WC_Product_Variation $variation Variation.
	 * @param string                $attribute Attribute name.
	 * @param string                $value     Attribute value slug.
	 * @return bool
	 */
	public static function variation_matches( $variation, $attribute, $value ) {
		$attributes = $variation->get_attributes(); // [ attribute_name => value ].
		$key        = self::normalize_key( $attribute );
		foreach ( $attributes as $name => $set_value ) {
			if ( self::normalize_key( $name ) === $key ) {
				return '' === $set_value || 0 === strcasecmp( (string) $set_value, (string) $value );
			}
		}
		// Variation does not use this attribute at all — treat as non-match.
		return false;
	}

	/**
	 * Build a human label for an attribute value (resolves taxonomy term names).
	 *
	 * @param string $name  Attribute name.
	 * @param string $value Value slug.
	 * @return string
	 */
	public static function value_label( $name, $value ) {
		if ( taxonomy_exists( $name ) ) {
			$term = get_term_by( 'slug', $value, $name );
			if ( $term && ! is_wp_error( $term ) ) {
				return $term->name;
			}
		}
		return $value;
	}

	/**
	 * Normalize an attribute key for comparison (variation arrays use the
	 * "attribute_" prefix internally in some contexts).
	 *
	 * @param string $key Attribute key.
	 * @return string
	 */
	private static function normalize_key( $key ) {
		$key = strtolower( (string) $key );
		if ( 0 === strpos( $key, 'attribute_' ) ) {
			$key = substr( $key, strlen( 'attribute_' ) );
		}
		return $key;
	}
}
