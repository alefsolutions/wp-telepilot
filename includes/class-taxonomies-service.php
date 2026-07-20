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
		$icon_key = 'post_tag' === $result['taxonomy'] ? 'tags' : 'categories';

		if ( empty( $result['items'] ) ) {
			return implode(
				"\n",
				array(
					Telepilot_Telegram_Response_Builder::bold( Telepilot_Telegram_Response_Builder::label( $icon_key, $heading ) ),
					__( 'No matching terms were found.', 'wp-telepilot' ),
				)
			);
		}

		$blocks   = array( Telepilot_Telegram_Response_Builder::bold( Telepilot_Telegram_Response_Builder::label( $icon_key, $heading ) ) );
		$blocks[] = Telepilot_Telegram_Response_Builder::italic(
			sprintf(
				__( 'Page %1$d of %2$d | %3$d items', 'wp-telepilot' ),
				$result['page'],
				$result['total_pages'],
				isset( $result['total_items'] ) ? (int) $result['total_items'] : count( $result['items'] )
			)
		);

		foreach ( $result['items'] as $term ) {
			$blocks[] = $this->format_term_summary_block( $term, $result['taxonomy'] );
		}

		$blocks[] = Telepilot_Telegram_Response_Builder::italic( __( 'Tip: use search when your category or tag list grows large.', 'wp-telepilot' ) );

		return Telepilot_Telegram_Response_Builder::join_blocks( $blocks );
	}

	public function render_help_message( $resource ) {
		$resource = $this->normalize_resource( $resource );
		$singular = $this->resource_singular( $resource );
		$lines    = array();

		$lines[] = Telepilot_Telegram_Response_Builder::bold( Telepilot_Telegram_Response_Builder::label( $resource, sprintf( __( '%s Commands', 'wp-telepilot' ), ucfirst( $resource ) ) ) );
		$lines[] = Telepilot_Telegram_Response_Builder::code( '/' . $resource . ' list' ) . ' ' . sprintf( __( 'Show %s', 'wp-telepilot' ), $resource );
		$lines[] = Telepilot_Telegram_Response_Builder::code( '/' . $resource . ' search keyword' ) . ' ' . sprintf( __( 'Search %s', 'wp-telepilot' ), $resource );
		$lines[] = Telepilot_Telegram_Response_Builder::code( '/' . $resource . ' details 12' ) . ' ' . sprintf( __( 'Show %s details', 'wp-telepilot' ), $singular );
		$lines[] = Telepilot_Telegram_Response_Builder::code( '/' . $resource . ' create Name' ) . ' ' . sprintf( __( 'Create a new %s', 'wp-telepilot' ), $singular );
		$lines[] = Telepilot_Telegram_Response_Builder::code( '/' . $resource . ' rename 12 New Name' ) . ' ' . sprintf( __( 'Rename a %s', 'wp-telepilot' ), $singular );
		$lines[] = Telepilot_Telegram_Response_Builder::code( '/' . $resource . ' slug 12 new-slug' ) . ' ' . sprintf( __( 'Update a %s slug', 'wp-telepilot' ), $singular );
		$lines[] = Telepilot_Telegram_Response_Builder::code( '/' . $resource . ' description 12 New description' ) . ' ' . sprintf( __( 'Update a %s description', 'wp-telepilot' ), $singular );

		if ( 'categories' === $resource ) {
			$lines[] = Telepilot_Telegram_Response_Builder::code( '/categories parent 12 3' ) . ' ' . __( 'Assign a parent category, or use `none` to clear it', 'wp-telepilot' );
			$lines[] = Telepilot_Telegram_Response_Builder::code( '/categories post 12' ) . ' ' . __( 'Start a new post in a category', 'wp-telepilot' );
		}

		$lines[] = Telepilot_Telegram_Response_Builder::code( '/' . $resource . ' delete 12' ) . ' ' . sprintf( __( 'Delete a %s after confirmation', 'wp-telepilot' ), $singular );
		$lines[] = Telepilot_Telegram_Response_Builder::italic( __( 'Tip: use the bracketed term ID from list results when updating or deleting.', 'wp-telepilot' ) );

		return Telepilot_Telegram_Response_Builder::join_blocks( $lines );
	}

	public function render_term_details_message( $term, $resource ) {
		if ( ! ( $term instanceof WP_Term ) ) {
			return Telepilot_Telegram_Response_Builder::bold( Telepilot_Telegram_Response_Builder::label( $resource, __( 'Term Details', 'wp-telepilot' ) ) ) . "\n\n" . __( 'Term not found.', 'wp-telepilot' );
		}

		$resource = $this->normalize_resource( $resource );
		$lines    = array(
			Telepilot_Telegram_Response_Builder::bold( Telepilot_Telegram_Response_Builder::label( $resource, sprintf( __( '%s Details', 'wp-telepilot' ), ucfirst( $this->resource_singular( $resource ) ) ) ) ),
			'',
			sprintf( __( 'Name: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( $term->name ) ),
			sprintf( __( 'ID: [%d]', 'wp-telepilot' ), $term->term_id ),
			sprintf( __( 'Slug: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( $term->slug ) ),
			sprintf( __( 'Count: %d', 'wp-telepilot' ), (int) $term->count ),
		);

		if ( '' !== (string) $term->description ) {
			$lines[] = sprintf( __( 'Description: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( $term->description ) );
		}

		if ( 'categories' === $resource ) {
			$parent_label = __( 'None', 'wp-telepilot' );

			if ( ! empty( $term->parent ) ) {
				$parent = get_term( (int) $term->parent, 'category' );
				if ( $parent && ! is_wp_error( $parent ) ) {
					$parent_label = sprintf( '[%1$d] %2$s', $parent->term_id, $parent->name );
				}
			}

			$lines[] = sprintf( __( 'Parent: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( $parent_label ) );
		}

		return implode( "\n", $lines );
	}

	public function get_term_details( $taxonomy, $term_id ) {
		$taxonomy = $this->normalize_taxonomy( $taxonomy );
		$term     = get_term( absint( $term_id ), $taxonomy );

		if ( ! $term || is_wp_error( $term ) ) {
			return new WP_Error( 'telepilot_term_not_found', __( 'Term not found.', 'wp-telepilot' ) );
		}

		return $term;
	}

	public function create_term( $taxonomy, $name ) {
		$taxonomy = $this->normalize_taxonomy( $taxonomy );
		$name     = sanitize_text_field( $name );

		if ( '' === $name ) {
			return new WP_Error( 'telepilot_term_name_required', __( 'A term name is required.', 'wp-telepilot' ) );
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
			return new WP_Error( 'telepilot_term_update_invalid', __( 'A valid term ID and name are required.', 'wp-telepilot' ) );
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
			return new WP_Error( 'telepilot_term_slug_invalid', __( 'A valid slug is required.', 'wp-telepilot' ) );
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
			return new WP_Error( 'telepilot_term_not_found', __( 'Term not found.', 'wp-telepilot' ) );
		}

		$parent_id = absint( $parent_id );
		if ( $parent_id && $parent_id === $term_id ) {
			return new WP_Error( 'telepilot_term_parent_invalid', __( 'A category cannot be its own parent.', 'wp-telepilot' ) );
		}

		if ( $parent_id ) {
			$parent = get_term( $parent_id, $taxonomy );
			if ( ! $parent || is_wp_error( $parent ) ) {
				return new WP_Error( 'telepilot_term_parent_missing', __( 'Parent category not found.', 'wp-telepilot' ) );
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
			return new WP_Error( 'telepilot_term_not_found', __( 'Term not found.', 'wp-telepilot' ) );
		}

		$deleted = wp_delete_term( $term->term_id, $taxonomy );

		if ( is_wp_error( $deleted ) || false === $deleted ) {
			return is_wp_error( $deleted ) ? $deleted : new WP_Error( 'telepilot_term_delete_failed', __( 'WordPress could not delete that term.', 'wp-telepilot' ) );
		}

		$this->bump_cache_version();

		return $term;
	}

	public function build_terms_keyboard( $resource, $result, $allow_post_creation = false ) {
		$rows     = array();
		$resource = $this->normalize_resource( $resource );

		foreach ( $result['items'] as $term ) {
			$row = array(
				array(
					'text'          => Telepilot_Telegram_Response_Builder::label( 'details', sprintf( __( 'Details [%d]', 'wp-telepilot' ), $term->term_id ) ),
					'callback_data' => '/' . $resource . ' details ' . (int) $term->term_id,
				),
			);

			if ( $allow_post_creation && 'categories' === $resource ) {
				$row[] = array(
					'text'          => Telepilot_Telegram_Response_Builder::label( 'posts', sprintf( __( 'New Post [%d]', 'wp-telepilot' ), $term->term_id ) ),
					'callback_data' => '/categories post ' . (int) $term->term_id,
				);
			}

			$row[] = array(
				'text'          => Telepilot_Telegram_Response_Builder::label( 'delete', sprintf( __( 'Delete [%d]', 'wp-telepilot' ), $term->term_id ) ),
				'callback_data' => '/' . $resource . ' delete ' . (int) $term->term_id,
			);

			$rows[] = $row;
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

		$cancel_command = 'post_tag' === $taxonomy ? '/tags list' : '/categories list';

		return Telepilot_Telegram_Response_Builder::append_rows(
			Telepilot_Telegram_Response_Builder::confirmation_keyboard(
				sprintf( __( 'Confirm delete [%d]', 'wp-telepilot' ), $term_id ),
				'tp:term:' . $taxonomy . ':delete:' . (int) $term_id . ':' . $token,
				$cancel_command
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
				'text'          => Telepilot_Telegram_Response_Builder::label( 'prev', __( 'Prev', 'wp-telepilot' ) ),
				'callback_data' => trim( $command . $mode . ' page:' . ( $result['page'] - 1 ) ),
			);
		}

		if ( $result['page'] < $result['total_pages'] ) {
			$buttons[] = array(
				'text'          => Telepilot_Telegram_Response_Builder::label( 'next', __( 'Next', 'wp-telepilot' ) ),
				'callback_data' => trim( $command . $mode . ' page:' . ( $result['page'] + 1 ) ),
			);
		}

		return $buttons;
	}

	private function format_term_summary_block( $term, $taxonomy ) {
		if ( ! $term instanceof WP_Term ) {
			return '';
		}

		$resource = 'post_tag' === $taxonomy ? 'tags' : 'categories';
		$lines    = array(
			Telepilot_Telegram_Response_Builder::label(
				$resource,
				sprintf(
					__( '[%1$d] %2$s', 'wp-telepilot' ),
					$term->term_id,
					Telepilot_Telegram_Response_Builder::escape( $term->name )
				)
			),
			sprintf( __( 'Slug: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( $term->slug ) ),
			sprintf( __( 'Count: %d', 'wp-telepilot' ), (int) $term->count ),
		);

		if ( 'category' === $taxonomy ) {
			$lines[] = sprintf( __( 'Parent: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( $this->get_parent_term_label( $term ) ) );
		}

		if ( '' !== (string) $term->description ) {
			$lines[] = sprintf( __( 'Description: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( wp_html_excerpt( $term->description, 120, '...' ) ) );
		}

		return implode( "\n", $lines );
	}

	private function normalize_resource( $resource ) {
		return 'tags' === $resource ? 'tags' : 'categories';
	}

	private function get_parent_term_label( $term ) {
		if ( ! $term instanceof WP_Term || empty( $term->parent ) ) {
			return __( 'None', 'wp-telepilot' );
		}

		$parent = get_term( (int) $term->parent, 'category' );

		if ( ! $parent || is_wp_error( $parent ) ) {
			return __( 'Unknown', 'wp-telepilot' );
		}

		return sprintf( '[%1$d] %2$s', $parent->term_id, $parent->name );
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
			return new WP_Error( 'telepilot_term_not_found', __( 'Term not found.', 'wp-telepilot' ) );
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
					'text'          => Telepilot_Telegram_Response_Builder::label( 'menu', __( 'Menu', 'wp-telepilot' ) ),
					'callback_data' => '/menu',
				),
				array(
					'text'          => Telepilot_Telegram_Response_Builder::label( 'site', __( 'Site', 'wp-telepilot' ) ),
					'callback_data' => '/site',
				),
			),
		);
	}
}
