<?php
/**
 * Translate → Activity: what Lango7 did, newest first, with filters and Clear.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Admin;

use Lango7\Engine\EngineRegistry;
use Lango7\Languages;
use Lango7\Router\LanguageRouter;
use Lango7\Store\Activity;

final class ActivityPage {

	public const SLUG = 'lango7-activity';

	private const ACTION = 'lango7_activity_clear';

	public function __construct(
		private Languages $languages,
		private EngineRegistry $engines,
		private LanguageRouter $router
	) {}

	public function register( string $parent ): void {
		$hook = add_submenu_page( $parent, __( 'Activity', 'lango7' ), __( 'Activity', 'lango7' ), 'manage_options', self::SLUG, array( $this, 'render' ) );
		add_action( 'load-' . $hook, static fn() => add_action( 'admin_enqueue_scripts', array( PageShell::class, 'styles' ) ) );
	}

	public static function registerActions(): void {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'clear' ) );
	}

	public static function url( array $args = array() ): string {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::SLUG ) );
	}

	/**
	 * @return array<string, string>
	 */
	public static function types(): array {
		return array(
			Activity::TRANSLATED => __( 'Translated', 'lango7' ),
			Activity::EDITED     => __( 'Edited', 'lango7' ),
			Activity::APPROVED   => __( 'Approved', 'lango7' ),
			Activity::FAILED     => __( 'Failed', 'lango7' ),
			Activity::ERROR      => __( 'Error', 'lango7' ),
			Activity::BULK       => __( 'Bulk Translate', 'lango7' ),
			Activity::AUTOMATION => __( 'Automation', 'lango7' ),
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- filters and paging only.
		$type    = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
		$lang    = isset( $_GET['lang'] ) ? sanitize_text_field( wp_unslash( $_GET['lang'] ) ) : '';
		$paged   = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 );
		$cleared = isset( $_GET['cleared'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$types = self::types();
		$type  = isset( $types[ $type ] ) ? $type : '';
		$langs = array();
		foreach ( $this->languages->enabled() as $l ) {
			$langs[ $l->code ] = $l;
		}
		$lang   = isset( $langs[ $lang ] ) ? $lang : '';
		$result = Activity::query(
			array(
				'type' => $type,
				'lang' => $lang,
			),
			$paged
		);
		$pages  = max( 1, (int) ceil( $result['total'] / Activity::PER_PAGE ) );
		?>
		<div class="wrap lango7-wrap lango7-activity">
			<?php PageShell::header( __( 'Activity', 'lango7' ) ); ?>
			<p class="lango7-lead">
				<?php
				/* translators: %d: number of days. */
				echo esc_html( sprintf( _n( 'What Lango7 translated, who edited or approved what, and anything that went wrong. Newest first; kept for %d day.', 'What Lango7 translated, who edited or approved what, and anything that went wrong. Newest first; kept for %d days.', Activity::days(), 'lango7' ), Activity::days() ) );
				?>
			</p>
			<?php PageShell::nav( self::SLUG ); ?>
			<?php if ( $cleared ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Activity cleared.', 'lango7' ); ?></p></div>
			<?php endif; ?>

			<div class="lango7-card lango7-activity-card">
				<div class="lango7-activity-bar">
					<form method="get" class="lango7-activity-filters">
						<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
						<label class="screen-reader-text" for="lango7-activity-type"><?php esc_html_e( 'Type', 'lango7' ); ?></label>
						<select id="lango7-activity-type" name="type">
							<option value=""><?php esc_html_e( 'All activity', 'lango7' ); ?></option>
							<?php foreach ( $types as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $type, $key ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<?php if ( count( $langs ) > 1 ) : ?>
							<label class="screen-reader-text" for="lango7-activity-lang"><?php esc_html_e( 'Language', 'lango7' ); ?></label>
							<select id="lango7-activity-lang" name="lang">
								<option value=""><?php esc_html_e( 'All languages', 'lango7' ); ?></option>
								<?php foreach ( $langs as $code => $l ) : ?>
									<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $lang, $code ); ?>><?php echo esc_html( $l->name ); ?></option>
								<?php endforeach; ?>
							</select>
						<?php endif; ?>
						<button type="submit" class="button"><?php esc_html_e( 'Filter', 'lango7' ); ?></button>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return window.confirm(<?php echo esc_attr( (string) wp_json_encode( __( 'Clear the whole activity log? This cannot be undone. Translations are not affected.', 'lango7' ) ) ); ?>);">
						<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
						<?php wp_nonce_field( self::ACTION ); ?>
						<button type="submit" class="button button-link-delete"<?php disabled( 0 === $result['total'] && '' === $type && '' === $lang ); ?>><?php esc_html_e( 'Clear log', 'lango7' ); ?></button>
					</form>
				</div>

				<?php if ( ! $result['rows'] ) : ?>
					<p class="lango7-activity-empty">
						<?php
						echo esc_html(
							'' !== $type || '' !== $lang
								? __( 'Nothing matches these filters.', 'lango7' )
								: __( 'Nothing yet. Each translation, edit, approval and error shows here as it happens.', 'lango7' )
						);
						?>
					</p>
				<?php else : ?>
					<table class="widefat striped lango7-activity-table">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'When', 'lango7' ); ?></th>
								<th scope="col"><?php esc_html_e( 'What happened', 'lango7' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Language', 'lango7' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Page', 'lango7' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Service', 'lango7' ); ?></th>
								<th scope="col"><?php esc_html_e( 'By', 'lango7' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $result['rows'] as $row ) : ?>
								<?php $this->row( $row, $langs, $types ); ?>
							<?php endforeach; ?>
						</tbody>
					</table>
					<?php if ( $pages > 1 ) : ?>
						<div class="tablenav bottom"><div class="tablenav-pages">
							<?php
							echo wp_kses_post(
								(string) paginate_links(
									array(
										'base'    => add_query_arg( 'paged', '%#%', self::url( array_filter( array( 'type' => $type, 'lang' => $lang ) ) ) ),
										'format'  => '',
										'current' => $paged,
										'total'   => $pages,
									)
								)
							);
							?>
						</div></div>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * @param array<string, mixed>                $row
	 * @param array<string, \Lango7\Language>     $langs
	 * @param array<string, string>               $types
	 */
	private function row( array $row, array $langs, array $types ): void {
		$type  = (string) $row['type'];
		$time  = strtotime( (string) $row['updated_at'] . ' UTC' );
		$lang  = $langs[ (string) $row['lang'] ] ?? null;
		$path  = (string) $row['url'];
		$user  = (int) $row['user_id'] ? get_userdata( (int) $row['user_id'] ) : false;
		$label = $types[ $type ] ?? $type;
		?>
		<tr>
			<td class="lango7-activity-time" title="<?php echo esc_attr( (string) wp_date( 'Y-m-d H:i:s', (int) $time ) ); ?>">
				<?php
				/* translators: %s: time, e.g. 5 mins. */
				echo esc_html( sprintf( __( '%s ago', 'lango7' ), human_time_diff( (int) $time ) ) );
				?>
			</td>
			<td>
				<span class="lango7-activity-type is-<?php echo esc_attr( $type ); ?>"><?php echo esc_html( $label ); ?></span>
				<?php echo esc_html( $this->summary( $row ) ); ?>
				<?php if ( Activity::FAILED === $type && $lang ) : ?>
					<?php /* translators: shown after "N sentences" that a service could not translate; they stay in the original language. */ ?>
					<span class="description"><?php esc_html_e( '(left in the original language)', 'lango7' ); ?></span>
					<a href="<?php echo esc_url( add_query_arg( array( 'page' => StringsPage::SLUG, 'lang' => $lang->code, 'status' => 'failed' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Review', 'lango7' ); ?></a>
				<?php endif; ?>
			</td>
			<td><?php echo esc_html( $lang ? $lang->name : (string) $row['lang'] ); ?></td>
			<td class="lango7-activity-page">
				<?php if ( '' !== $path && $lang ) : ?>
					<?php [ $shown, $href ] = $this->link( $path, $lang ); ?>
					<a href="<?php echo esc_url( $href ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $shown ); ?></a>
				<?php elseif ( '' !== $path ) : ?>
					<?php echo esc_html( $path ); ?>
				<?php else : ?>
					<span class="description">&mdash;</span>
				<?php endif; ?>
			</td>
			<td><?php echo esc_html( '' !== (string) $row['engine'] ? $this->engines->serviceLabel( (string) $row['engine'] ) : '—' ); ?></td>
			<td><?php echo esc_html( $user ? $user->display_name : ( 'chrome' === $row['engine'] || in_array( $type, array( Activity::EDITED, Activity::APPROVED ), true ) ? '—' : __( 'Lango7', 'lango7' ) ) ); ?></td>
		</tr>
		<?php
	}

	/**
	 * Rows hold either the translated path (/cn/pricing/) or the source path
	 * (/pricing/). Show it without the language folder; link to the translation.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function link( string $path, \Lango7\Language $lang ): array {
		$folder = $this->router->homePath() . '/' . $lang->prefix;
		if ( $path === $folder || str_starts_with( $path, $folder . '/' ) ) {
			$shown = $this->router->homePath() . ( substr( $path, strlen( $folder ) ) ?: '/' );
			return array( $shown, home_url( substr( $path, strlen( $this->router->homePath() ) ) ) );
		}
		return array( $path, $this->router->urlFor( $lang, $path ) );
	}

	/**
	 * "42 sentences · 3,120 characters", or the error message.
	 *
	 * @param array<string, mixed> $row
	 */
	private function summary( array $row ): string {
		if ( '' !== (string) $row['message'] ) {
			return (string) $row['message'];
		}
		$items = (int) $row['items'];
		$parts = array();
		if ( $items ) {
			/* translators: %s: number of sentences. */
			$parts[] = sprintf( _n( '%s sentence', '%s sentences', $items, 'lango7' ), number_format_i18n( $items ) );
		}
		if ( (int) $row['chars'] ) {
			/* translators: %s: number of characters. */
			$parts[] = sprintf( _n( '%s character', '%s characters', (int) $row['chars'], 'lango7' ), number_format_i18n( (int) $row['chars'] ) );
		}
		return implode( ' · ', $parts );
	}

	public static function clear(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'lango7' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );
		Activity::clear();
		wp_safe_redirect( self::url( array( 'cleared' => 1 ) ) );
		exit;
	}
}
