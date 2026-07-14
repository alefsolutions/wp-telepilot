<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Telepilot_Taxonomies_Service {
	const PER_PAGE = 5;

	private $confirmation_service;

	public function __construct( Telepilot_Confirmation_Service $confirmation_service ) {
		$this->confirmation_service = $confirmation_service;
	}

	public function list_terms( $taxonomy, $page = 1, $limit = self::PER_PAGE, $search = '' ) {
		$taxonomy = $this->normalize_taxonomy( $taxonomy );
		$page     = max( 1, absint( $page ) );
		$limit    = max( 1, absint( $limit ) );
		$search   = sanitize_text_field( $search );
		$cache_key = 'telepilot_terms_' . $this->get_cache_version() . '_' . md5( wp_json_encode( array( $taxonomy, $page, $limit, $search ) ) );
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$query_args = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'number'     => $limit,
			'offset'     => ( $page - 1 ) * $limit,
			'orderby'    => 'name',
			'order'      => 'ASC',
		);

		if ( '' !== $search ) {
			$query_args['search'] = $search;
		}

		$items = get_terms( $query_args );

		if ( is_wp_error( $items ) ) {
			return $items;
		}

		$total_items = wp_count_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'search'     => $search,
			)
		);

		if ( is_wp_error( $total_items ) ) {
			return $total_items;
		}

		$total_pages = max( 1, (int) ceil( (int) $total_items / $limit ) );

		$result = array(
			'items'       => $items,
			'page'        => min( $page, $total_pages ),
			'per_page'    => $limit,
			'total_items' => (int) $total_items,
			'total_pages' => $total_pages,
			'search'      => $search,
			'taxonomy'    => $taxonomy,
		);

		set_transient( $cache_key, $result, 30 );

		return $result;
	}

	public function render_terms_message( $result, $heading ) {
		if ( empty( $result['items'] ) ) {
			return implode(
				"\n",
				array(
					Telepilot_Telegram_Response_Builder::bold( $heading ),
					__( 'No matching terms were found.', 'telepilot' ),
				)
			);
		}

		$lines   = array( Telepilot_Telegram_Response_Builder::bold( $heading ) );
		$lines[] = Telepilot_Telegram_Response_Builder::italic(
			sprintf(
				__( 'Page %1$d of %2$d', 'telepilot' ),
				$result['page'],
				$result['total_pages']
			)
		);
		$lines[] = '';

		foreach ( $result['items'] as $term ) {
			$lines[] = sprintf(
				__( '[%1$d] %2$s (%3$d)', 'telepilot' ),
				$term->term_id,
				Telepilot_Telegram_Response_Builder::escape( $term->name ),
				$term->count
			);
		}

		$lines[] = '';
		$lines[] = Telepilot_Telegram_Response_Builder::italic( __( 'Tip: use search when your category or tag list grows large.', 'telepilot' ) );

		return implode( "\n", $lines );
	}

	public function render_help_message( $resource ) {
		$resource = $this->normalize_resource( $resource );
		$singular = $this->resource_singular( $resource );
		$lines    = array();

		$lines[] = Telepilot_Telegram_Response_Builder::bold( sprintf( __( '%s Commands', 'telepilot' ), ucfirst( $resource ) ) );
		$lines[] = '';
		$lines[] = Telepilot_Telegram_Response_Builder::code( '/' . $resource . ' list' ) . ' ' . sprintf( __( 'Show %s', 'telepilot' ), $resource );
		$lines[] = Telepilot_Telegram_Response_Builder::code( '/' . $resource . ' search keyword' ) . ' ' . sprintf( __( 'Search %s', 'telepilot' ), $resource );
		$lines[] = Telepilot_Telegram_Response_Builder::code( '/' . $resource . ' details 12' ) . ' ' . sprintf( __( 'Show %s details', 'telepilot' ), $singular );
		$lines[] = Telepilot_Telegram_Response_Builder::code( '/' . $resource . ' create Name' ) . ' ' . sprintf( __( 'Create a new %s', 'telepilot' ), $singular );
		$lines[] = Telepilot_Telegram_Response_Builder::code( '/' . $resource . ' rename 12 New Name' ) . ' ' . sprintf( __( 'Rename a %s', 'telepilot' ), $singular );
		$lines[] = Telepilot_Telegram_Response_Builder::code( '/' . $resource . ' slug 12 new-slug' ) . ' ' . sprintf( __( 'Update a %s slug', 'telepilot' ), $singular );
		$lines[] = Telepilot_Telegram_Response_Builder::code( '/' . $resource . ' description 12 New description' ) . ' ' . sprintf( __( 'Update a %s description', 'telepilot' ), $singular );

		if ( 'categories' === $resource ) {
			$lines[] = Telepilot_Telegram_Response_Builder::code( '/categories parent 12 3' ) . ' ' . __( 'Assign a parent category, or use `none` to clear it', 'telepilot' );
		}

		$lines[] = Telepilot_Telegram_Response_Builder::code( '/' . $resource . ' delete 12' ) . ' ' . sprintf( __( 'Delete a %s after confirmation', 'telepilot' ), $singular );
		$lines[] = '';
		$lines[] = Telepilot_Telegram_Response_Builder::italic( __( 'Tip: use the bracketed term ID from list results when updating or deleting.', 'telepilot' ) );

		return implode( "\n", $lines );
	}

	public function render_term_details_message( $term, $resource ) {
		if ( ! ( $term instanceof WP_Term ) ) {
			return Telepilot_Telegram_Response_Builder::bold( __( 'Term Details', 'telepilot' ) ) . "\n\n" . __( 'Term not found.', 'telepilot' );
		}

		$resource = $this->normalize_resource( $resource );
		$lines    = array(
			Telepilot_Telegram_Response_Builder::bold( sprintf( __( '%s Details', 'telepilot' ), ucfirst( $this->resource_singular( $resource ) ) ) ),
			'',
			sprintf( __( 'Name: %s', 'telepilot' ), Telepilot_Telegram_Response_Builder::escape( $term->name ) ),
			sprintf( __( 'ID: [%d]', 'telepilot' ), $term->term_id ),
			sprintf( __( 'Slug: %s', 'telepilot' ), Telepilot_Telegram_Response_Builder::escape( $term->slug ) ),
			sprintf( __( 'Count: %d', 'telepilot' ), (int) $term->count ),
		);

		if ( '' !== (string) $term->description ) {
			$lines[] = sprintf( __( 'Description: %s', 'telepilot' ), Telepilot_Telegram_Response_Builder::escape( $term->description ) );
		}

		if ( 'categories' === $resource ) {
			$parent_label = __( 'None', 'telepilot' );

			if ( ! empty( $term->parent ) ) {
				$parent = get_term( (int) $term->parent, 'category' );
				if ( $parent && ! is_wp_error( $parent ) ) {
					$parent_label = sprintf( '[%1$d] %2$s', $parent->term_id, $parent->name );
				}
			}

			$lines[] = sprintf( __( 'Parent: %s', 'telepilot' ), Telepilot_Telegram_Response_Builder::escape( $parent_label ) );
		}

		return implode( "\n", $lines );
	}

	public function get_term_details( $taxonomy, $term_id ) {
		$taxonomy = $this->normalize_taxonomy( $taxonomy );
		$term     = get_term( absint( $term_id ), $taxonomy );

		if ( ! $term || is_wp_error( $term ) ) {
			return new WP_Error( 'telepilot_term_not_found', __( 'Term not found.', 'telepilot' ) );
		}

		return $term;
	}

	public function create_term( $taxonomy, $name ) {
		$taxonomy = $this->normalize_taxonomy( $taxonomy );
		$name     = sanitize_text_field( $name );

		if ( '' === $name ) {
			return new WP_Error( 'telepilot_term_name_required', __( 'A term name is required.', 'telepilot' ) );
		}

		$result = wp_insert_term( $name, $taxonomy );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->bump_cache_version();

		return get_term( $result['term_id'], $taxonomy );
	}

	public function rename_term( $taxonomy, $term_id, $name ) {
		$taxonomy = $this->normalize_taxonomy( $taxonomy );
		$term_id  = absint( $term_id );
		$name     = sanitize_text_field( $name );

		if ( ! $term_id || '' === $name ) {
			return new WP_Error( 'telepilot_term_update_invalid', __( 'A valid term ID and name are required.', 'telepilot' ) );
		}

		$result = wp_update_term(
			$term_id,
			$taxonomy,
			array(
				'name' => $name,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->bump_cache_version();

		return get_term( $term_id, $taxonomy );
	}

	public function update_slug( $taxonomy, $term_id, $slug ) {
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return new WP_Error( 'telepilot_term_slug_invalid', __( 'A valid slug is required.', 'telepilot' ) );
		}

		return $this->update_term(
			$taxonomy,
			$term_id,
			array(
				'slug' => $slug,
			)
		);
	}

	public function update_description( $taxonomy, $term_id, $description ) {
		return $this->update_term(
			$taxonomy,
			$term_id,
			array(
				'description' => sanitize_textarea_field( $description ),
			)
		);
	}

	public function update_parent( $term_id, $parent_id ) {
		$taxonomy = 'category';
		$term_id  = absint( $term_id );

		if ( ! $term_id ) {
			return new WP_Error( 'telepilot_term_not_found', __( 'Term not found.', 'telepilot' ) );
		}

		$parent_id = absint( $parent_id );
		if ( $parent_id && $parent_id === $term_id ) {
			return new WP_Error( 'telepilot_term_parent_invalid', __( 'A category cannot be its own parent.', 'telepilot' ) );
		}

		if ( $parent_id ) {
			$parent = get_term( $parent_id, $taxonomy );
			if ( ! $parent || is_wp_error( $parent ) ) {
				return new WP_Error( 'telepilot_term_parent_missing', __( 'Parent category not found.', 'telepilot' ) );
			}
		}

		return $this->update_term(
			$taxonomy,
			$term_id,
			array(
				'parent' => $parent_id,
			)
		);
	}

	public function delete_term( $taxonomy, $term_id ) {
		$taxonomy = $this->normalize_taxonomy( $taxonomy );
		$term     = get_term( absint( $term_id ), $taxonomy );

		if ( ! $term || is_wp_error( $term ) ) {
			return new WP_Error( 'telepilot_term_not_found', __( 'Term not found.', 'telepilot' ) );
		}

		$deleted = wp_delete_term( $term->term_id, $taxonomy );

		if ( is_wp_error( $deleted ) || false === $deleted ) {
			return is_wp_error( $deleted ) ? $deleted : new WP_Error( 'telepilot_term_delete_failed', __( 'WordPress could not delete that term.', 'telepilot' ) );
		}

		$this->bump_cache_version();

		return $term;
	}

	public function build_terms_keyboard( $resource, $result ) {
		$rows     = array();
		$resource = $this->normalize_resource( $resource );

		foreach ( $result['items'] as $term ) {
			$rows[] = array(
				array(
					'text'          => sprintf( __( 'Details [%d]', 'telepilot' ), $term->term_id ),
					'callback_data' => '/' . $resource . ' details ' . (int) $term->term_id,
				),
				array(
					'text'          => sprintf( __( 'Delete [%d]', 'telepilot' ), $term->term_id ),
					'callback_data' => '/' . $resource . ' delete ' . (int) $term->term_id,
				),
			);
		}

		$pagination = $this->build_pagination_row( $resource, $result );
		if ( ! empty( $pagination ) ) {
			$rows[] = $pagination;
		}

		return Telepilot_Telegram_Response_Builder::append_rows(
			Telepilot_Telegram_Response_Builder::keyboard( $rows ),
			$this->navigation_rows()
		);
	}

	public function build_delete_confirmation_keyboard( $taxonomy, $term_id, $telegram_user_id ) {
		$taxonomy = $this->normalize_taxonomy( $taxonomy );
		$token    = $this->confirmation_service->create_token(
			array(
				'action'           => 'delete',
				'taxonomy'         => $taxonomy,
				'term_id'          => (int) $term_id,
				'telegram_user_id' => (string) $telegram_user_id,
			)
		);

		return Telepilot_Telegram_Response_Builder::append_rows(
			Telepilot_Telegram_Response_Builder::keyboard(
				array(
					array(
						array(
							'text'          => sprintf( __( 'Confirm delete [%d]', 'telepilot' ), $term_id ),
							'callback_data' => 'tp:term:' . $taxonomy . ':delete:' . (int) $term_id . ':' . $token,
						),
					),
				)
			),
			$this->navigation_rows()
		);
	}

	private function build_pagination_row( $resource, $result ) {
		if ( $result['total_pages'] <= 1 ) {
			return array();
		}

		$buttons = array();
		$command = '/' . $resource . ' ';
		$mode    = '' !== $result['search'] ? 'search ' . $result['search'] : 'list';

		if ( $result['page'] > 1 ) {
			$buttons[] = array(
				'text'          => __( 'Prev', 'telepilot' ),
				'callback_data' => trim( $command . $mode . ' page:' . ( $result['page'] - 1 ) ),
			);
		}

		if ( $result['page'] < $result['total_pages'] ) {
			$buttons[] = array(
				'text'          => __( 'Next', 'telepilot' ),
				'callback_data' => trim( $command . $mode . ' page:' . ( $result['page'] + 1 ) ),
			);
		}

		return $buttons;
	}

	private function normalize_resource( $resource ) {
		return 'tags' === $resource ? 'tags' : 'categories';
	}

	private function resource_singular( $resource ) {
		return 'tags' === $resource ? 'tag' : 'category';
	}

	private function normalize_taxonomy( $taxonomy ) {
		return 'post_tag' === $taxonomy || 'tags' === $taxonomy ? 'post_tag' : 'category';
	}

	private function update_term( $taxonomy, $term_id, $args ) {
		$taxonomy = $this->normalize_taxonomy( $taxonomy );
		$term_id  = absint( $term_id );
		$term     = get_term( $term_id, $taxonomy );

		if ( ! $term || is_wp_error( $term ) ) {
			return new WP_Error( 'telepilot_term_not_found', __( 'Term not found.', 'telepilot' ) );
		}

		$result = wp_update_term( $term_id, $taxonomy, $args );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->bump_cache_version();

		return get_term( $term_id, $taxonomy );
	}

	private function bump_cache_version() {
		update_option( 'telepilot_terms_cache_version', $this->get_cache_version() + 1, false );
	}

	private function get_cache_version() {
		return max( 1, (int) get_option( 'telepilot_terms_cache_version', 1 ) );
	}

	private function navigation_rows() {
		return array(
			array(
				array(
					'text'          => __( 'Menu', 'telepilot' ),
					'callback_data' => '/menu',
				),
				array(
					'text'          => __( 'Site', 'telepilot' ),
					'callback_data' => '/site',
				),
			),
		);
	}
}
