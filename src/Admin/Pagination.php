<?php
/**
 * Page navigation of the admin lists (konzept.md 10: "Admin-Listen paginiert"), with the markup and the `paged`
 * parameter of WP_List_Table so it looks and behaves like the rest of wp-admin. Filters stay in the links.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Admin;

defined( 'ABSPATH' ) || exit;

final class Pagination {

	/**
	 * Page numbers for a list.
	 *
	 * @return array{total:int,per_page:int,pages:int,paged:int,offset:int} $paged clamped to 1..pages.
	 */
	public static function state( int $total, int $per_page, int $paged ): array {
		$per_page = max( 1, $per_page );
		$pages    = max( 1, (int) ceil( max( 0, $total ) / $per_page ) );
		$paged    = min( max( 1, $paged ), $pages );
		return array( 'total' => max( 0, $total ), 'per_page' => $per_page, 'pages' => $pages, 'paged' => $paged, 'offset' => ( $paged - 1 ) * $per_page );
	}

	/** The requested page from the query string (`paged`, as in WP_List_Table). */
	public static function requested(): int {
		return isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
	}

	/**
	 * Navigation below a table. $args are the query arguments of the list (page and active filters); empty values
	 * are dropped so links stay short.
	 *
	 * @param array{total:int,per_page:int,pages:int,paged:int,offset:int} $state From state().
	 * @param array<string,scalar>                                         $args
	 */
	public static function render( array $state, array $args, string $label ): string {
		$args  = array_filter( $args, static fn( $v ) => '' !== (string) $v && 0 !== $v );
		$url   = static fn( int $page ): string => esc_url( add_query_arg( $args + ( $page > 1 ? array( 'paged' => $page ) : array() ), admin_url( 'admin.php' ) ) );
		$pages = $state['pages'];
		$paged = $state['paged'];
		$link  = static function ( string $class, int $page, string $sr, string $sign, bool $active ) use ( $url ): string {
			if ( ! $active ) {
				return '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">' . $sign . '</span>';
			}
			return '<a class="' . $class . ' button" href="' . $url( $page ) . '"><span class="screen-reader-text">' . esc_html( $sr ) . '</span><span aria-hidden="true">' . $sign . '</span></a>';
		};
		$out  = '<nav class="tablenav bottom" aria-label="' . esc_attr( $label ) . '"><div class="tablenav-pages' . ( $pages <= 1 ? ' one-page' : '' ) . '">';
		/* translators: %s: number of entries */
		$out .= '<span class="displaying-num">' . esc_html( sprintf( _n( '%s entry', '%s entries', $state['total'], 'workflow-captain' ), number_format_i18n( $state['total'] ) ) ) . '</span>';
		$out .= '<span class="pagination-links">';
		$out .= $link( 'first-page', 1, __( 'First page', 'workflow-captain' ), '&laquo;', $paged > 2 ) . ' ';
		$out .= $link( 'prev-page', $paged - 1, __( 'Previous page', 'workflow-captain' ), '&lsaquo;', $paged > 1 ) . ' ';
		$out .= '<span class="screen-reader-text">' . esc_html__( 'Current page', 'workflow-captain' ) . '</span>';
		/* translators: 1: current page, 2: number of pages */
		$out .= '<span class="paging-input"><span class="tablenav-paging-text" aria-current="page">' . esc_html( sprintf( __( '%1$s of %2$s', 'workflow-captain' ), number_format_i18n( $paged ), number_format_i18n( $pages ) ) ) . '</span></span> ';
		$out .= $link( 'next-page', $paged + 1, __( 'Next page', 'workflow-captain' ), '&rsaquo;', $paged < $pages ) . ' ';
		$out .= $link( 'last-page', $pages, __( 'Last page', 'workflow-captain' ), '&raquo;', $paged < $pages - 1 );
		$out .= '</span></div><br class="clear"></nav>';
		return $out;
	}
}
