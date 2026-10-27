<?php
/**
 * Settings page markup (styles: admin/css/siteherd-admin.css).
 *
 * @package WP_Siteherd
 *
 * @var array|null $connection Stored connection (url, id, secret) or null.
 * @var int|false  $next_run   Unix time of the next automatic report, or false.
 * @var bool       $overdue    WP-Cron hasn't run the scheduled report in time.
 * @var int|null   $checks_at  Unix time of the last daily checks run.
 * @var array      $sso        One-click login: enabled, user_id, admins, recent.
 * @var array|null $last       Last report result (time, code, error) or null.
 * @var array|null $notice     One-time notice (type, message) or null.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

$siteherd_post_url = admin_url( 'admin-post.php' );

/**
 * Inline Lucide icons (static markup, safe to echo).
 *
 * @param string $name Icon name.
 */
$siteherd_icon = function ( $name ) {
	$paths = array(
		'plug'     => '<path d="M12 22v-5"/><path d="M9 8V2"/><path d="M15 8V2"/><path d="M18 8v5a4 4 0 0 1-4 4h-4a4 4 0 0 1-4-4V8Z"/>',
		'activity' => '<path d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2"/>',
		'key'      => '<path d="M2.586 17.414A2 2 0 0 0 2 18.828V21a1 1 0 0 0 1 1h3a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h1a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h.172a2 2 0 0 0 1.414-.586l.814-.814a6.5 6.5 0 1 0-4-4z"/><circle cx="16.5" cy="7.5" r=".5" fill="currentColor"/>',
		'alert'    => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
		'check'    => '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
		'x'        => '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>',
		'send'     => '<path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/>',
		'refresh'  => '<path d="M21 12a9 9 0 1 1-9-9c2.52 0 4.93 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/>',
	);
	return '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths[ $name ] . '</svg>';
};

$siteherd_notice_icon = array(
	'success' => 'check',
	'warning' => 'alert',
	'error'   => 'x',
);
?>
<div class="wrap sh-wrap">
	<div class="sh-header">
		<div>
			<h1 class="sh-title"><span class="sh-logo">S</span><?php esc_html_e( 'WP Siteherd', 'wp-siteherd' ); ?></h1>
			<p class="sh-subtitle"><?php esc_html_e( 'Reports this site’s versions, updates, health and security to your WP Siteherd dashboard.', 'wp-siteherd' ); ?></p>
		</div>
		<?php if ( $connection ) : ?>
			<span class="sh-badge sh-badge--success"><?php esc_html_e( 'Connected', 'wp-siteherd' ); ?></span>
		<?php else : ?>
			<span class="sh-badge sh-badge--muted"><?php esc_html_e( 'Not connected', 'wp-siteherd' ); ?></span>
		<?php endif; ?>
	</div>
	<hr class="wp-header-end">

	<?php if ( $notice ) : ?>
		<div class="sh-alert sh-alert--<?php echo esc_attr( $notice['type'] ); ?>" role="status">
			<?php echo $siteherd_icon( $siteherd_notice_icon[ $notice['type'] ] ?? 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput -- static SVG. ?>
			<p><?php echo esc_html( $notice['message'] ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( ! $connection ) : ?>

		<div class="sh-card sh-card--narrow">
			<div class="sh-card__header">
				<h2 class="sh-card__title"><?php echo $siteherd_icon( 'plug' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e( 'Connect to your dashboard', 'wp-siteherd' ); ?></h2>
				<p class="sh-card__desc"><?php esc_html_e( 'Add this site in your WP Siteherd dashboard, copy the connection key from its page, and paste it here.', 'wp-siteherd' ); ?></p>
			</div>
			<form method="post" action="<?php echo esc_url( $siteherd_post_url ); ?>">
				<input type="hidden" name="action" value="siteherd_save_key">
				<?php wp_nonce_field( 'siteherd_save_key' ); ?>
				<div class="sh-card__content">
					<label class="sh-label" for="siteherd_key"><?php esc_html_e( 'Connection key', 'wp-siteherd' ); ?></label>
					<input type="password" id="siteherd_key" name="siteherd_key" class="sh-input sh-mono"
						autocomplete="off" spellcheck="false" placeholder="siteherd1_…" required>
					<p class="sh-help"><?php esc_html_e( 'Treat this like a password.', 'wp-siteherd' ); ?></p>
				</div>
				<div class="sh-card__footer">
					<button type="submit" class="sh-btn"><?php echo $siteherd_icon( 'plug' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e( 'Connect', 'wp-siteherd' ); ?></button>
				</div>
			</form>
		</div>

	<?php else : ?>

		<?php if ( $overdue ) : ?>
			<div class="sh-alert sh-alert--warning">
				<?php echo $siteherd_icon( 'alert' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<div>
					<p><strong><?php esc_html_e( 'Automatic reports are running late.', 'wp-siteherd' ); ?></strong></p>
					<p>
						<?php if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) : ?>
							<?php esc_html_e( 'WordPress’s built-in scheduler is turned off on this site (DISABLE_WP_CRON), and no server cron job seems to be running it instead.', 'wp-siteherd' ); ?>
						<?php else : ?>
							<?php esc_html_e( 'WordPress only runs scheduled tasks when someone visits the site, so on a site with few visitors reports can be delayed.', 'wp-siteherd' ); ?>
						<?php endif; ?>
						<?php esc_html_e( 'To keep reports on time, ask your host to set up a server cron job that requests this address every 5 minutes:', 'wp-siteherd' ); ?>
						<code><?php echo esc_html( site_url( 'wp-cron.php' ) ); ?></code>
					</p>
				</div>
			</div>
		<?php endif; ?>

		<div class="sh-grid">

			<!-- Connection -->
			<div class="sh-card">
				<div class="sh-card__header">
					<h2 class="sh-card__title"><?php echo $siteherd_icon( 'plug' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e( 'Connection', 'wp-siteherd' ); ?></h2>
					<p class="sh-card__desc"><?php esc_html_e( 'Where this site sends its reports.', 'wp-siteherd' ); ?></p>
				</div>
				<div class="sh-card__content">
					<dl class="sh-rows">
						<div class="sh-row">
							<dt><?php esc_html_e( 'Dashboard', 'wp-siteherd' ); ?></dt>
							<dd class="sh-mono"><?php echo esc_html( $connection['url'] ); ?></dd>
						</div>
						<div class="sh-row">
							<dt><?php esc_html_e( 'Site ID', 'wp-siteherd' ); ?></dt>
							<dd class="sh-mono"><?php echo esc_html( $connection['id'] ); ?></dd>
						</div>
						<div class="sh-row">
							<dt><?php esc_html_e( 'Last report', 'wp-siteherd' ); ?></dt>
							<dd>
								<?php if ( ! $last ) : ?>
									<span class="sh-badge sh-badge--muted"><?php esc_html_e( 'None yet', 'wp-siteherd' ); ?></span>
								<?php elseif ( $last['error'] ) : ?>
									<span class="sh-badge sh-badge--error"><?php esc_html_e( 'Failed', 'wp-siteherd' ); ?></span>
									<small><?php echo esc_html( $last['error'] ); ?></small>
								<?php else : ?>
									<span class="sh-badge sh-badge--success"><?php esc_html_e( 'Delivered', 'wp-siteherd' ); ?></span>
								<?php endif; ?>
								<?php if ( $last ) : ?>
									<small>
										<?php
										/* translators: %s: human-readable time difference, e.g. "5 mins" */
										echo esc_html( sprintf( __( '%s ago', 'wp-siteherd' ), human_time_diff( $last['time'] ) ) );
										?>
									</small>
								<?php endif; ?>
							</dd>
						</div>
					</dl>

					<details class="sh-details">
						<summary><?php esc_html_e( 'Replace connection key', 'wp-siteherd' ); ?></summary>
						<form method="post" action="<?php echo esc_url( $siteherd_post_url ); ?>" class="sh-inline-form">
							<input type="hidden" name="action" value="siteherd_save_key">
							<?php wp_nonce_field( 'siteherd_save_key' ); ?>
							<input type="password" name="siteherd_key" class="sh-input sh-mono" aria-label="<?php esc_attr_e( 'New connection key', 'wp-siteherd' ); ?>"
								autocomplete="off" spellcheck="false" placeholder="siteherd1_…" required>
							<button type="submit" class="sh-btn sh-btn--outline"><?php esc_html_e( 'Save', 'wp-siteherd' ); ?></button>
						</form>
					</details>
				</div>
				<div class="sh-card__footer">
					<form method="post" action="<?php echo esc_url( $siteherd_post_url ); ?>">
						<input type="hidden" name="action" value="siteherd_send_now">
						<?php wp_nonce_field( 'siteherd_send_now' ); ?>
						<button type="submit" class="sh-btn sh-btn--outline"><?php echo $siteherd_icon( 'send' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e( 'Send report now', 'wp-siteherd' ); ?></button>
					</form>
					<form method="post" action="<?php echo esc_url( $siteherd_post_url ); ?>"
						onsubmit="return confirm('<?php echo esc_js( __( 'Disconnect this site from the dashboard?', 'wp-siteherd' ) ); ?>');">
						<input type="hidden" name="action" value="siteherd_disconnect">
						<?php wp_nonce_field( 'siteherd_disconnect' ); ?>
						<button type="submit" class="sh-btn sh-btn--danger"><?php esc_html_e( 'Disconnect', 'wp-siteherd' ); ?></button>
					</form>
				</div>
			</div>

			<!-- Monitoring -->
			<div class="sh-card">
				<div class="sh-card__header">
					<h2 class="sh-card__title"><?php echo $siteherd_icon( 'activity' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e( 'Monitoring', 'wp-siteherd' ); ?></h2>
					<p class="sh-card__desc"><?php esc_html_e( 'Runs automatically. The buttons are optional, for updating the dashboard right away.', 'wp-siteherd' ); ?></p>
				</div>
				<div class="sh-card__content">
					<dl class="sh-rows">
						<div class="sh-row">
							<dt><?php esc_html_e( 'Reports', 'wp-siteherd' ); ?></dt>
							<dd>
								<?php esc_html_e( 'Every 10 minutes', 'wp-siteherd' ); ?>
								<small><?php esc_html_e( '+ 30 s after any update', 'wp-siteherd' ); ?></small>
							</dd>
						</div>
						<div class="sh-row">
							<dt><?php esc_html_e( 'Next report', 'wp-siteherd' ); ?></dt>
							<dd>
								<?php if ( ! $next_run ) : ?>
									<span class="sh-badge sh-badge--muted"><?php esc_html_e( 'Not scheduled', 'wp-siteherd' ); ?></span>
								<?php elseif ( $overdue ) : ?>
									<span class="sh-badge sh-badge--warning"><?php esc_html_e( 'Late', 'wp-siteherd' ); ?></span>
									<small>
										<?php
										/* translators: %s: human-readable time difference, e.g. "2 hours" */
										echo esc_html( sprintf( __( 'was due %s ago', 'wp-siteherd' ), human_time_diff( $next_run ) ) );
										?>
									</small>
								<?php elseif ( $next_run > time() ) : ?>
									<?php
									/* translators: %s: human-readable time difference, e.g. "4 mins" */
									echo esc_html( sprintf( __( 'in %s', 'wp-siteherd' ), human_time_diff( $next_run ) ) );
									?>
								<?php else : ?>
									<?php esc_html_e( 'Due now', 'wp-siteherd' ); ?>
								<?php endif; ?>
							</dd>
						</div>
						<div class="sh-row">
							<dt><?php esc_html_e( 'Daily checks', 'wp-siteherd' ); ?></dt>
							<dd>
								<?php esc_html_e( 'Site Health + file integrity', 'wp-siteherd' ); ?>
								<small>
									<?php if ( $checks_at ) : ?>
										<?php
										/* translators: %s: human-readable time difference, e.g. "3 hours" */
										echo esc_html( sprintf( __( 'last run %s ago', 'wp-siteherd' ), human_time_diff( $checks_at ) ) );
										?>
									<?php else : ?>
										<?php esc_html_e( 'not run yet', 'wp-siteherd' ); ?>
									<?php endif; ?>
								</small>
							</dd>
						</div>
					</dl>
				</div>
				<div class="sh-card__footer">
					<form method="post" action="<?php echo esc_url( $siteherd_post_url ); ?>">
						<input type="hidden" name="action" value="siteherd_run_checks">
						<?php wp_nonce_field( 'siteherd_run_checks' ); ?>
						<button type="submit" class="sh-btn sh-btn--outline" title="<?php esc_attr_e( 'Can take up to a minute', 'wp-siteherd' ); ?>"><?php echo $siteherd_icon( 'refresh' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e( 'Run checks now', 'wp-siteherd' ); ?></button>
					</form>
				</div>
			</div>

			<!-- One-click login -->
			<div class="sh-card">
				<div class="sh-card__header">
					<h2 class="sh-card__title"><?php echo $siteherd_icon( 'key' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e( 'One-click login', 'wp-siteherd' ); ?></h2>
					<p class="sh-card__desc"><?php esc_html_e( 'Open wp-admin from the dashboard without a password, via a single-use link that expires after 2 minutes.', 'wp-siteherd' ); ?></p>
				</div>
				<form method="post" action="<?php echo esc_url( $siteherd_post_url ); ?>">
					<input type="hidden" name="action" value="siteherd_save_sso">
					<?php wp_nonce_field( 'siteherd_save_sso' ); ?>
					<div class="sh-card__content">
						<label class="sh-switch-row">
							<span>
								<strong><?php esc_html_e( 'Allow one-click login', 'wp-siteherd' ); ?></strong>
								<span class="sh-help"><?php esc_html_e( 'From your WP Siteherd dashboard', 'wp-siteherd' ); ?></span>
							</span>
							<span class="sh-toggle">
								<input type="checkbox" name="siteherd_sso_enabled" value="1" <?php checked( $sso['enabled'] ); ?>>
								<span class="sh-toggle__track"><span class="sh-toggle__thumb"></span></span>
							</span>
						</label>

						<div class="sh-field">
							<label class="sh-label" for="siteherd_sso_user"><?php esc_html_e( 'Log in as', 'wp-siteherd' ); ?></label>
							<select id="siteherd_sso_user" name="siteherd_sso_user" class="sh-select">
								<option value="0" <?php selected( null, $sso['user_id'] ); ?>><?php esc_html_e( 'Ask every time (choose in the dashboard)', 'wp-siteherd' ); ?></option>
								<?php foreach ( $sso['admins'] as $siteherd_admin ) : ?>
									<option value="<?php echo esc_attr( $siteherd_admin['id'] ); ?>" <?php selected( $siteherd_admin['id'], $sso['user_id'] ); ?>>
										<?php echo esc_html( $siteherd_admin['name'] . ' (' . $siteherd_admin['login'] . ')' ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="sh-help"><?php esc_html_e( 'Only administrators can be used.', 'wp-siteherd' ); ?></p>
						</div>

						<?php if ( $sso['recent'] ) : ?>
							<p class="sh-subheading"><?php esc_html_e( 'Recent logins', 'wp-siteherd' ); ?></p>
							<ul class="sh-list">
								<?php foreach ( $sso['recent'] as $siteherd_login ) : ?>
									<?php $siteherd_login_user = get_userdata( $siteherd_login['user'] ); ?>
									<li>
										<span><?php echo esc_html( $siteherd_login_user ? $siteherd_login_user->display_name : '#' . $siteherd_login['user'] ); ?></span>
										<span>
											<?php
											/* translators: %s: human-readable time difference, e.g. "5 mins" */
											echo esc_html( sprintf( __( '%s ago', 'wp-siteherd' ), human_time_diff( $siteherd_login['time'] ) ) );
											?>
										</span>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					</div>
					<div class="sh-card__footer">
						<button type="submit" class="sh-btn"><?php esc_html_e( 'Save login settings', 'wp-siteherd' ); ?></button>
					</div>
				</form>
			</div>

		</div>

	<?php endif; ?>
</div>
