<?php
/**
 * Tools → Not Translated: every page that is missing text in a language, built
 * from what Lango7 already recorded (Cost estimator scans, Bulk translate and
 * visits). No new scan: a page published or updated is read again in the
 * background so the report stays current.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Admin;

use Lango7\Plugin;
use Lango7\Store\Coverage;
use Lango7\Tools\SitePages;

final class UntranslatedReport {

	public const TAB = 'untranslated';

	private const CACHE = 'lango7_untranslated';

	private const REREAD = 'lango7_reread_page';

	private const SHOW = 200;

	public function __construct( private Plugin $lango7 ) {}

	public function register(): void {
		add_filter( 'lango7_tools_tabs', array( $this, 'tab' ) );
		add_action( 'lango7_tools_tab_' . self::TAB, array( $this, 'render' ) );
		add_action( 'lango7_dashboard_extra', array( $this, 'dashboard' ), 8 );
		add_action( 'transition_post_status', array( $this, 'published' ), 10, 3 );
		add_action( self::REREAD, array( $this, 'reread' ) );
		add_action( 'lango7_translations_saved', array( self::class, 'flush' ) );
	}

	public static function flush(): void {
		delete_transient( self::CACHE );
	}

	/**
	 * @param array<string, array<string, mixed>> $tabs
	 * @return array<string, array<string, mixed>>
	 */
	public function tab( array $tabs ): array {
		$tabs[ self::TAB ] = array(
			'label' => __( 'Not Translated', 'lango7' ),
			'title' => __( 'Pages not fully translated', 'lango7' ),
			'about' => __( 'Every page with text that has no translation yet, per language. Built from what Lango7 has already read; pages you publish or update are read again in the background.', 'lango7' ),
			'pro'   => false,
		);
		return $tabs;
	}

	/**
	 * Per page and language: sentences on the page and sentences translated.
	 *
	 * @return array{langs: string[], pages: array<int, array{path: string, total: int, missing: array<string, int>}>, unscanned: string[], total: int}
	 */
	public function data(): array {
		$cached = get_transient( self::CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$codes  = array_map( static fn( $l ): string => $l->code, $this->lango7->languages()->enabled() );
		$paths  = ( new SitePages( $this->lango7 ) )->paths();
		$counts = Coverage::forPaths( $paths );
		$pages  = array();
		$none   = array();
		foreach ( $paths as $path ) {
			$c = $counts[ sha1( $path ) ] ?? null;
			if ( null === $c || ! $c['total'] ) {
				$none[] = $path;
				continue;
			}
			$missing = array();
			foreach ( $codes as $code ) {
				$gap = $c['total'] - min( $c['total'], (int) ( $c['langs'][ $code ]['done'] ?? 0 ) );
				if ( $gap ) {
					$missing[ $code ] = $gap;
				}
			}
			if ( $missing ) {
				$pages[] = array(
					'path'    => $path,
					'total'   => $c['total'],
					'missing' => $missing,
				);
			}
		}
		usort( $pages, static fn( array $a, array $b ): int => array_sum( $b['missing'] ) <=> array_sum( $a['missing'] ) );
		$data = array(
			'langs'     => $codes,
			'pages'     => $pages,
			'unscanned' => $none,
			'total'     => count( $paths ),
		);
		set_transient( self::CACHE, $data, 10 * MINUTE_IN_SECONDS );
		return $data;
	}

	/**
	 * @param array<string, mixed> $tab
	 */
	public function render( array $tab ): void {
		$data  = $this->data();
		$langs = array();
		foreach ( $this->lango7->languages()->enabled() as $l ) {
			$langs[ $l->code ] = $l;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filter only.
		$only  = isset( $_GET['lang'] ) ? sanitize_text_field( wp_unslash( $_GET['lang'] ) ) : '';
		$only  = isset( $langs[ $only ] ) ? $only : '';
		$pages = '' === $only ? $data['pages'] : array_values( array_filter( $data['pages'], static fn( array $p ): bool => isset( $p['missing'][ $only ] ) ) );
		$shown = '' === $only ? $langs : array( $only => $langs[ $only ] );
		$free  = admin_url( 'admin.php?page=' . ChromePage::SLUG );
		?>
		<div class="lango7-card lango7-untranslated">
			<h2><?php echo esc_html( (string) $tab['title'] ); ?></h2>
			<p class="description"><?php echo esc_html( (string) $tab['about'] ); ?></p>

			<p class="lango7-untranslated-sum">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: pages not fully translated, 2: pages on the site. */
						_n( '%1$s of %2$s page is not fully translated.', '%1$s of %2$s pages are not fully translated.', $data['total'], 'lango7' ),
						number_format_i18n( count( $pages ) ),
						number_format_i18n( $data['total'] )
					)
				);
				?>
			</p>

			<?php if ( count( $langs ) > 1 ) : ?>
				<form method="get" class="lango7-activity-filters">
					<input type="hidden" name="page" value="<?php echo esc_attr( ToolsPage::SLUG ); ?>">
					<input type="hidden" name="tab" value="<?php echo esc_attr( self::TAB ); ?>">
					<label class="screen-reader-text" for="lango7-untranslated-lang"><?php esc_html_e( 'Language', 'lango7' ); ?></label>
					<select id="lango7-untranslated-lang" name="lang">
						<option value=""><?php esc_html_e( 'All languages', 'lango7' ); ?></option>
						<?php foreach ( $langs as $code => $l ) : ?>
							<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $only, $code ); ?>><?php echo esc_html( $l->name ); ?></option>
						<?php endforeach; ?>
					</select>
					<button type="submit" class="button"><?php esc_html_e( 'Filter', 'lango7' ); ?></button>
				</form>
			<?php endif; ?>

			<?php if ( ! $pages ) : ?>
				<p class="lango7-activity-empty"><?php esc_html_e( 'Every page Lango7 has read is fully translated.', 'lango7' ); ?></p>
			<?php else : ?>
				<table class="widefat striped lango7-activity-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Page', 'lango7' ); ?></th>
							<?php foreach ( $shown as $l ) : ?>
								<th scope="col"><?php echo esc_html( $l->name ); ?></th>
							<?php endforeach; ?>
							<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'lango7' ); ?></span></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( array_slice( $pages, 0, self::SHOW ) as $p ) : ?>
							<tr>
								<td><a href="<?php echo esc_url( home_url( $this->relative( $p['path'] ) ) ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $this->title( $p['path'] ) ); ?></a><br><code><?php echo esc_html( $p['path'] ); ?></code></td>
								<?php foreach ( $shown as $code => $l ) : ?>
									<td>
										<?php if ( isset( $p['missing'][ $code ] ) ) : ?>
											<a href="<?php echo esc_url( $this->lango7->router()->urlFor( $l, $p['path'] ) ); ?>" target="_blank" rel="noopener noreferrer">
												<?php
												echo esc_html(
													sprintf(
														/* translators: 1: percent translated, 2: sentences missing. */
														_n( '%1$s%% · %2$s missing', '%1$s%% · %2$s missing', $p['missing'][ $code ], 'lango7' ),
														number_format_i18n( (int) floor( 100 * ( $p['total'] - $p['missing'][ $code ] ) / $p['total'] ) ),
														number_format_i18n( $p['missing'][ $code ] )
													)
												);
												?>
											</a>
										<?php else : ?>
											<span class="lango7-untranslated-done">✓</span>
										<?php endif; ?>
									</td>
								<?php endforeach; ?>
								<td><a class="button button-small" href="<?php echo esc_url( add_query_arg( 'path', rawurlencode( $p['path'] ), $free ) ); ?>"><?php esc_html_e( 'Free Translate', 'lango7' ); ?></a></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php if ( count( $pages ) > self::SHOW ) : ?>
					<p class="description">
						<?php
						/* translators: %s: number of pages. */
						echo esc_html( sprintf( __( 'Showing the %s pages with the most text missing.', 'lango7' ), number_format_i18n( self::SHOW ) ) );
						?>
					</p>
				<?php endif; ?>
			<?php endif; ?>

			<?php if ( $data['unscanned'] ) : ?>
				<h3><?php esc_html_e( 'Not read yet', 'lango7' ); ?></h3>
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: number of pages. */
							_n( 'Lango7 has not read %s page yet, so it cannot tell what is missing there.', 'Lango7 has not read %s pages yet, so it cannot tell what is missing there.', count( $data['unscanned'] ), 'lango7' ),
							number_format_i18n( count( $data['unscanned'] ) )
						)
					);
					?>
					<a href="<?php echo esc_url( ToolsPage::url( 'cost' ) ); ?>"><?php esc_html_e( 'Read them with the Cost Estimator', 'lango7' ); ?></a>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * One line on the Dashboard when pages are missing text.
	 */
	public function dashboard(): void {
		$data = $this->data();
		$n    = count( $data['pages'] );
		if ( ! $n && ! $data['unscanned'] ) {
			return;
		}
		$text = $n
			/* translators: %s: number of pages. */
			? sprintf( _n( '%s page is not fully translated.', '%s pages are not fully translated.', $n, 'lango7' ), number_format_i18n( $n ) )
			/* translators: %s: number of pages. */
			: sprintf( _n( '%s page has not been read yet.', '%s pages have not been read yet.', count( $data['unscanned'] ), 'lango7' ), number_format_i18n( count( $data['unscanned'] ) ) );
		echo '<p class="lango7-untranslated-line">' . esc_html( $text ) . ' <a href="' . esc_url( ToolsPage::url( self::TAB ) ) . '">' . esc_html__( 'See which', 'lango7' ) . ' →</a></p>';
	}

	/**
	 * Read a page again soon after it is published or updated.
	 *
	 * @param string   $new
	 * @param string   $old
	 * @param \WP_Post $post
	 */
	public function published( $new, $old, $post ): void {
		if ( 'publish' !== $new || ! $post instanceof \WP_Post || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) || ! is_post_type_viewable( $post->post_type ) ) {
			return;
		}
		if ( ! wp_next_scheduled( self::REREAD, array( $post->ID ) ) ) {
			wp_schedule_single_event( time() + 30, self::REREAD, array( $post->ID ) );
		}
	}

	/**
	 * The same read as the Cost estimator, for one page. Translates nothing.
	 */
	public function reread( int $post_id ): void {
		$pages = new SitePages( $this->lango7 );
		$path  = $pages->localPath( (string) get_permalink( $post_id ) );
		if ( null === $path || 'publish' !== get_post_status( $post_id ) ) {
			return;
		}
		$html = $pages->fetch( $path );
		if ( ! is_string( $html ) ) {
			return;
		}
		$segments = $this->lango7->pipeline()->segmentsOf( $html );
		$ids      = $this->lango7->strings()->ensure( array_values( $segments ), $this->lango7->languages()->sourceCode() );
		$this->lango7->strings()->touchUrls( array_values( $ids ), sha1( $path ), $path );
		self::flush();
	}

	private function relative( string $path ): string {
		$home = $this->lango7->router()->homePath();
		return '' !== $home && str_starts_with( $path, $home ) ? (string) substr( $path, strlen( $home ) ) : $path;
	}

	private function title( string $path ): string {
		$id = url_to_postid( home_url( $this->relative( $path ) ) );
		if ( $id ) {
			return (string) get_the_title( $id );
		}
		return $this->relative( $path ) === '/' ? __( 'Home page', 'lango7' ) : $path;
	}
}
