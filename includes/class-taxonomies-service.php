<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TelePress_Taxonomies_Service {
	private $confirmation_service;

	public function __construct( TelePress_Confirmation_Service $confirmation_service ) {
		$this->confirmation_service = $confirmation_service;
	}

	public function list_terms( $taxonomy, $page = 1, $limit = 5, $search = '' ) {
		$taxonomy = $this->normalize_taxonomy( $taxonomy );
		$page     = max( 1, absint( $page ) );
		$limit    = max( 1, absint( $limit ) );
		$search   = sanitize_text_field( $search );

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

		return array(
			'items'       => $items,
			'page'        => min( $page, $total_pages ),
			'per_page'    => $limit,
			'total_items' => (int) $total_items,
			'total_pages' => $total_pages,
			'search'      => $search,
			'taxonomy'    => $taxonomy,
		);
	}

	public function render_terms_message( $result, $heading ) {
		if ( empty( $result['items'] ) ) {
			return implode(
				"\n",
				array(
					$heading,
					__( 'No matching terms were found.', 'telepress' ),
				)
			);
		}

		$lines   = array( $heading );
		$lines[] = sprintf(
			__( 'Page %1$d of %2$d', 'telepress' ),
			$result['page'],
			$result['total_pages']
		);

		foreach ( $result['items'] as $term ) {
			$lines[] = sprintf(
				__( '#%1$d %2$s (%3$d)', 'telepress' ),
				$term->term_id,
				$term->name,
				$term->count
			);
		}

		$lines[] = __( 'Tip: use search when your category or tag list grows large.', 'telepress' );

		return implode( "\n", $lines );
	}

	public function create_term( $taxonomy, $name ) {
		$taxonomy = $this->normalize_taxonomy( $taxonomy );
		$name     = sanitize_text_field( $name );

		if ( '' === $name ) {
			return new WP_Error( 'telepress_term_name_required', __( 'A term name is required.', 'telepress' ) );
		}

		$result = wp_insert_term( $name, $taxonomy );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return get_term( $result['term_id'], $taxonomy );
	}

	public function rename_term( $taxonomy, $term_id, $name ) {
		$taxonomy = $this->normalize_taxonomy( $taxonomy );
		$term_id  = absint( $term_id );
		$name     = sanitize_text_field( $name );

		if ( ! $term_id || '' === $name ) {
			return new WP_Error( 'telepress_term_update_invalid', __( 'A valid term ID and name are required.', 'telepress' ) );
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

		return get_term( $term_id, $taxonomy );
	}

	public function delete_term( $taxonomy, $term_id ) {
		$taxonomy = $this->normalize_taxonomy( $taxonomy );
		$term     = get_term( absint( $term_id ), $taxonomy );

		if ( ! $term || is_wp_error( $term ) ) {
			return new WP_Error( 'telepress_term_not_found', __( 'Term not found.', 'telepress' ) );
		}

		$deleted = wp_delete_term( $term->term_id, $taxonomy );

		if ( is_wp_error( $deleted ) || false === $deleted ) {
			return is_wp_error( $deleted ) ? $deleted : new WP_Error( 'telepress_term_delete_failed', __( 'WordPress could not delete that term.', 'telepress' ) );
		}

		return $term;
	}

	public function build_terms_keyboard( $resource, $result ) {
		$rows     = array();
		$resource = $this->normalize_resource( $resource );

		foreach ( $result['items'] as $term ) {
			$rows[] = array(
				array(
					'text'          => sprintf( __( 'Delete #%d', 'telepress' ), $term->term_id ),
					'callback_data' => '/' . $resource . ' delete ' . (int) $term->term_id,
				),
			);
		}

		$pagination = $this->build_pagination_row( $resource, $result );
		if ( ! empty( $pagination ) ) {
			$rows[] = $pagination;
		}

		return TelePress_Telegram_Response_Builder::append_rows(
			TelePress_Telegram_Response_Builder::keyboard( $rows ),
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

		return TelePress_Telegram_Response_Builder::append_rows(
			TelePress_Telegram_Response_Builder::keyboard(
				array(
					array(
						array(
							'text'          => sprintf( __( 'Confirm delete #%d', 'telepress' ), $term_id ),
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
				'text'          => __( 'Prev', 'telepress' ),
				'callback_data' => trim( $command . $mode . ' page:' . ( $result['page'] - 1 ) ),
			);
		}

		if ( $result['page'] < $result['total_pages'] ) {
			$buttons[] = array(
				'text'          => __( 'Next', 'telepress' ),
				'callback_data' => trim( $command . $mode . ' page:' . ( $result['page'] + 1 ) ),
			);
		}

		return $buttons;
	}

	private function normalize_resource( $resource ) {
		return 'tags' === $resource ? 'tags' : 'categories';
	}

	private function normalize_taxonomy( $taxonomy ) {
		return 'post_tag' === $taxonomy || 'tags' === $taxonomy ? 'post_tag' : 'category';
	}

	private function navigation_rows() {
		return array(
			array(
				array(
					'text'          => __( 'Menu', 'telepress' ),
					'callback_data' => '/menu',
				),
				array(
					'text'          => __( 'Site', 'telepress' ),
					'callback_data' => '/site',
				),
			),
		);
	}
}
