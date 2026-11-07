<?php
/**
 * Plugin Name:       Siaran Langsung Masjid
 * Plugin URI:        https://github.com/mohdneotech/siaran-langsung-masjid
 * Description:       Siaran langsung & rakaman khutbah, ceramah dan kuliah masjid / surau dari YouTube atau Facebook. AJK tampal pautan di Papan Pemuka dan klik "Mula Siaran"; widget laman utama, halaman siaran dengan jadual & rakaman, dan butang LANGSUNG terapung di halaman lain.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Mohd Nordin Hussain
 * Author URI:        https://mohdnordin.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       siaran-langsung-masjid
 */

defined( 'ABSPATH' ) || exit;

// Another copy (e.g. the older site-specific "Perepat Siaran Langsung" build) is already loaded — stand down instead of fataling.
if ( defined( 'PS_VER' ) ) {
	register_activation_hook( __FILE__, function () {
		wp_die( 'Sila nyahaktifkan plugin lama (Perepat Siaran Langsung) dahulu, kemudian aktifkan plugin ini. Semua siaran dan rakaman akan dikekalkan.', 'Plugin lama masih aktif', [ 'back_link' => true ] );
	} );
	return;
}

define( 'PS_VER', '1.0.0' );
define( 'PS_FILE', __FILE__ );
define( 'PS_URL', plugin_dir_url( __FILE__ ) );
define( 'PS_CPT', 'mp_siaran' );
define( 'PS_REPO', 'mohdneotech/siaran-langsung-masjid' );
const PS_FA_URL = 'https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.2/css/all.min.css';
const PS_FA_SRI = 'sha384-PPIZEGYM1v8zp5Py7UjFb79S58UeqCL9pYVnVPURKEqvioPROaVAJKKLzvH2rDnI';

require_once __DIR__ . '/includes/class-github-updater.php';
if ( is_admin() || wp_doing_cron() ) { new PS_GitHub_Updater( __FILE__, PS_REPO, PS_VER ); }

register_activation_hook( __FILE__, function () { set_transient( 'ps_welcome', 1, HOUR_IN_SECONDS ); } );

/* ------------------------------------------------------------------ *
 * Settings
 * ------------------------------------------------------------------ */
function ps_settings() {
	$d = [
		'hari_akan_datang' => 7,     // show upcoming on front page if within N days
		'rakaman_utama'    => 0,     // show latest recording on front page when nothing live/upcoming
		'halaman'          => 0,     // page ID that holds [siaran_langsung]
		'jenis'            => "Khutbah Jumaat\nCeramah\nKuliah Maghrib\nKuliah Subuh\nTazkirah\nMajlis Ilmu\nLain-lain",
		'auto_utama'       => 0,     // insert the front-page widget at the top of the front page content
		'warna'            => '#527c3a',
		'warna_gelap'      => '#1d3314',
		'aksen'            => '#8dc642',
		'fontawesome'      => 'auto',  // auto | cdn | off
		'padam_data'       => 0,
	];
	return wp_parse_args( (array) get_option( 'ps_settings', [] ), $d );
}
function ps_jenis_list() {
	return array_values( array_filter( array_map( 'trim', explode( "\n", ps_settings()['jenis'] ) ) ) );
}
function ps_page_url() {
	$id = (int) ps_settings()['halaman'];
	return $id && get_post_status( $id ) === 'publish' ? get_permalink( $id ) : home_url( '/' );
}

/* ------------------------------------------------------------------ *
 * Post type
 * ------------------------------------------------------------------ */
add_action( 'init', function () {
	register_post_type( PS_CPT, [
		'labels'          => [
			'name'               => 'Siaran Langsung',
			'singular_name'      => 'Siaran',
			'menu_name'          => 'Siaran Langsung',
			'add_new'            => 'Tambah Siaran',
			'add_new_item'       => 'Tambah Siaran / Rakaman',
			'edit_item'          => 'Sunting Siaran',
			'all_items'          => 'Semua Siaran',
			'search_items'       => 'Cari Siaran',
			'not_found'          => 'Tiada siaran.',
			'not_found_in_trash' => 'Tiada siaran dalam tong sampah.',
		],
		'public'          => false,
		'show_ui'         => true,
		'show_in_menu'    => true,
		'menu_position'   => 26,
		'menu_icon'       => 'dashicons-video-alt3',
		'supports'        => [ 'title' ],
		'capability_type' => 'post',
		'map_meta_cap'    => true,
	] );
} );

/* ------------------------------------------------------------------ *
 * URL → embed
 * ------------------------------------------------------------------ */
function ps_parse_url( $url ) {
	$url = trim( (string) $url );
	if ( $url === '' ) return null;
	$p    = wp_parse_url( $url );
	$host = strtolower( preg_replace( '/^(www\.|m\.|web\.)/', '', $p['host'] ?? '' ) );
	$path = $p['path'] ?? '';
	parse_str( $p['query'] ?? '', $q );

	// YouTube
	if ( in_array( $host, [ 'youtube.com', 'youtu.be', 'youtube-nocookie.com', 'music.youtube.com' ], true ) ) {
		$id = '';
		if ( $host === 'youtu.be' ) {
			$id = trim( $path, '/' );
		} elseif ( ! empty( $q['v'] ) ) {
			$id = $q['v'];
		} elseif ( preg_match( '#^/(?:live|embed|shorts|v)/([A-Za-z0-9_-]{6,})#', $path, $m ) ) {
			$id = $m[1];
		} elseif ( preg_match( '#^/channel/(UC[A-Za-z0-9_-]{20,})#', $path, $m ) ) {
			// Channel's current live stream
			return [ 'platform' => 'youtube', 'id' => '', 'embed' => 'https://www.youtube.com/embed/live_stream?channel=' . $m[1] . '&rel=0', 'thumb' => '' ];
		}
		if ( $id === 'live_stream' && ! empty( $q['channel'] ) && preg_match( '/^UC[A-Za-z0-9_-]{20,}$/', $q['channel'] ) ) {
			return [ 'platform' => 'youtube', 'id' => '', 'embed' => 'https://www.youtube.com/embed/live_stream?channel=' . $q['channel'] . '&rel=0', 'thumb' => '' ];
		}
		if ( preg_match( '/^[A-Za-z0-9_-]{6,20}$/', $id ) ) {
			return [
				'platform' => 'youtube',
				'id'       => $id,
				'embed'    => 'https://www.youtube-nocookie.com/embed/' . $id . '?rel=0&modestbranding=1&playsinline=1',
				'thumb'    => 'https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg',
			];
		}
		return [ 'platform' => 'youtube', 'id' => '', 'embed' => '', 'thumb' => '', 'error' => 'Pautan YouTube tidak dikenali. Guna pautan video (watch?v=… / youtu.be/… / youtube.com/live/…) atau youtube.com/channel/UC…/live.' ];
	}

	// Facebook
	if ( in_array( $host, [ 'facebook.com', 'fb.watch', 'fb.com' ], true ) ) {
		$clean = 'https://www.facebook.com' . $path . ( ! empty( $q['v'] ) ? '?v=' . rawurlencode( $q['v'] ) : '' );
		if ( $host === 'fb.watch' ) $clean = 'https://fb.watch' . $path;
		return [
			'platform' => 'facebook',
			'id'       => '',
			'embed'    => 'https://www.facebook.com/plugins/video.php?href=' . rawurlencode( $clean ) . '&show_text=false&width=1280',
			'thumb'    => '',
		];
	}
	return [ 'platform' => '', 'embed' => '', 'thumb' => '', 'error' => 'Hanya pautan YouTube atau Facebook diterima.' ];
}

/* ------------------------------------------------------------------ *
 * Data helpers
 * ------------------------------------------------------------------ */
function ps_get( $id ) {
	$mula_s = get_post_meta( $id, '_ps_mula', true );
	$tz     = wp_timezone();
	$mula   = $mula_s ? date_create_immutable( $mula_s, $tz ) : null;
	$tempoh = max( 5, (int) get_post_meta( $id, '_ps_tempoh', true ) ?: 90 );
	$mod    = get_post_meta( $id, '_ps_mod', true ) ?: 'auto';
	$url    = get_post_meta( $id, '_ps_url', true );
	$e      = ps_parse_url( $url ) ?: [ 'platform' => '', 'embed' => '', 'thumb' => '' ];

	$now = current_datetime();
	if ( $mod === 'langsung' )      $status = 'live';
	elseif ( $mod === 'tamat' )     $status = 'rakaman';
	elseif ( ! $mula )              $status = 'rakaman';
	elseif ( $now < $mula )         $status = 'akan';
	elseif ( $now < $mula->modify( "+{$tempoh} minutes" ) ) $status = 'live';
	else                            $status = 'rakaman';

	return [
		'id'         => $id,
		'tajuk'      => get_the_title( $id ),
		'url'        => $url,
		'jenis'      => get_post_meta( $id, '_ps_jenis', true ),
		'penceramah' => get_post_meta( $id, '_ps_penceramah', true ),
		'mula'       => $mula,
		'tempoh'     => $tempoh,
		'mod'        => $mod,
		'status'     => $status,
	] + $e;
}

/** All published items, newest first (by start time, falling back to publish date). */
function ps_all( $limit = 200 ) {
	static $cache = null;
	if ( $cache !== null ) return $cache;
	$ids = get_posts( [
		'post_type'      => PS_CPT,
		'post_status'    => 'publish',
		'posts_per_page' => $limit,
		'fields'         => 'ids',
		'orderby'        => 'date',
		'order'          => 'DESC',
		'no_found_rows'  => true,
	] );
	$items = array_map( 'ps_get', $ids );
	usort( $items, function ( $a, $b ) {
		$ta = $a['mula'] ? $a['mula']->getTimestamp() : get_post_time( 'U', true, $a['id'] );
		$tb = $b['mula'] ? $b['mula']->getTimestamp() : get_post_time( 'U', true, $b['id'] );
		return $tb <=> $ta;
	} );
	return $cache = $items;
}
function ps_live() {
	foreach ( ps_all() as $s ) if ( $s['status'] === 'live' && $s['embed'] ) return $s;
	return null;
}
function ps_upcoming( $days = 0 ) {
	$out = [];
	$lim = $days ? current_datetime()->modify( "+{$days} days" ) : null;
	foreach ( ps_all() as $s ) {
		if ( $s['status'] !== 'akan' ) continue;
		if ( $lim && $s['mula'] > $lim ) continue;
		$out[] = $s;
	}
	usort( $out, fn( $a, $b ) => $a['mula'] <=> $b['mula'] );
	return $out;
}
function ps_recordings( $jenis = '' ) {
	return array_values( array_filter( ps_all(), fn( $s ) => $s['status'] === 'rakaman' && $s['embed'] && ( $jenis === '' || $s['jenis'] === $jenis ) ) );
}

function ps_fmt_date( $dt, $with_time = true ) {
	if ( ! $dt ) return '';
	return wp_date( $with_time ? 'l, j F Y · g:i A' : 'j F Y', $dt->getTimestamp() );
}
function ps_platform_icon( $p ) {
	return $p === 'facebook' ? 'fa-brands fa-facebook' : ( $p === 'youtube' ? 'fa-brands fa-youtube' : 'fa-solid fa-video' );
}

/* ------------------------------------------------------------------ *
 * Front-end assets
 * ------------------------------------------------------------------ */
add_action( 'wp_enqueue_scripts', function () {
	wp_enqueue_style( 'ps-siaran', PS_URL . 'assets/siaran.css', [], PS_VER );
	$s = ps_settings();
	$c = fn( $k, $d ) => sanitize_hex_color( $s[ $k ] ) ?: $d;
	wp_add_inline_style( 'ps-siaran', ':root{--ps-c:' . $c( 'warna', '#527c3a' ) . ';--ps-deep:' . $c( 'warna_gelap', '#1d3314' ) . ';--ps-leaf:' . $c( 'aksen', '#8dc642' ) . ';}' );
	wp_enqueue_script( 'ps-siaran', PS_URL . 'assets/siaran.js', [], PS_VER, true );
	wp_register_style( 'ps-fontawesome', PS_FA_URL, [], null );
} );
add_action( 'wp_enqueue_scripts', function () {
	$fa = ps_settings()['fontawesome'];
	if ( 'off' === $fa ) { return; }
	if ( 'auto' === $fa ) {
		$st = wp_styles();
		foreach ( array_merge( $st->queue, $st->done ) as $h ) {
			if ( 'ps-fontawesome' !== $h && ! empty( $st->registered[ $h ] ) && preg_match( '/font-?awesome/i', $h . ' ' . (string) $st->registered[ $h ]->src ) ) { return; }
		}
	}
	wp_enqueue_style( 'ps-fontawesome' );
}, 100 );
add_filter( 'style_loader_tag', function ( $tag, $handle ) {
	return 'ps-fontawesome' === $handle ? str_replace( ' href=', ' integrity="' . PS_FA_SRI . '" crossorigin="anonymous" href=', $tag ) : $tag;
}, 10, 2 );

function ps_iframe( $s, $autoplay = false ) {
	$src = $s['embed'];
	if ( $autoplay ) $src .= ( strpos( $src, '?' ) === false ? '?' : '&' ) . ( $s['platform'] === 'facebook' ? 'autoplay=true' : 'autoplay=1' );
	return sprintf(
		'<div class="ps-player ratio ratio-16x9"><iframe src="%s" title="%s" loading="lazy" allow="autoplay; encrypted-media; picture-in-picture; web-share; fullscreen" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe></div>',
		esc_url( $src ), esc_attr( $s['tajuk'] )
	);
}

function ps_meta_line( $s ) {
	$bits = [];
	if ( $s['jenis'] )      $bits[] = '<span><i class="fa-solid fa-tag"></i> ' . esc_html( $s['jenis'] ) . '</span>';
	if ( $s['penceramah'] ) $bits[] = '<span><i class="fa-solid fa-user"></i> ' . esc_html( $s['penceramah'] ) . '</span>';
	if ( $s['mula'] )       $bits[] = '<span><i class="fa-regular fa-clock"></i> ' . esc_html( ps_fmt_date( $s['mula'] ) ) . '</span>';
	if ( $s['platform'] )   $bits[] = '<span><i class="' . ps_platform_icon( $s['platform'] ) . '"></i> ' . ( $s['platform'] === 'facebook' ? 'Facebook' : 'YouTube' ) . '</span>';
	return '<div class="ps-meta">' . implode( '', $bits ) . '</div>';
}

function ps_live_block( $s, $show_page_link = true ) {
	$wide = $show_page_link; // front page = side-by-side; inner page (with sidebar) = stacked
	ob_start(); ?>
	<div class="ps-live ps-ui">
		<div class="row g-4 align-items-center">
			<div class="<?php echo $wide ? 'col-lg-8' : 'col-12'; ?>"><?php echo ps_iframe( $s ); ?></div>
			<div class="<?php echo $wide ? 'col-lg-4' : 'col-12'; ?>">
				<span class="ps-badge-live"><span class="ps-dot"></span> SIARAN LANGSUNG</span>
				<h2 class="ps-live-title"><?php echo esc_html( $s['tajuk'] ); ?></h2>
				<?php echo ps_meta_line( $s ); ?>
				<div class="d-flex flex-wrap gap-2 mt-3">
					<?php if ( $show_page_link ) : ?><a class="btn btn-leaf" href="<?php echo esc_url( ps_page_url() ); ?>"><i class="fa-solid fa-tower-broadcast"></i> Halaman Siaran</a><?php endif; ?>
					<?php if ( $s['url'] ) : ?><a class="btn btn-outline-light" href="<?php echo esc_url( $s['url'] ); ?>" target="_blank" rel="noopener"><i class="<?php echo esc_attr( ps_platform_icon( $s['platform'] ) ); ?>"></i> Buka di <?php echo $s['platform'] === 'facebook' ? 'Facebook' : 'YouTube'; ?></a><?php endif; ?>
				</div>
			</div>
		</div>
	</div>
	<?php return ob_get_clean();
}

function ps_upcoming_card( $s, $compact = false ) {
	$ts = $s['mula'] ? $s['mula']->getTimestamp() : 0;
	ob_start(); ?>
	<div class="ps-ui ps-upcoming<?php echo $compact ? ' ps-upcoming--compact' : ''; ?>">
		<div class="ps-up-icon"><i class="fa-solid fa-tower-broadcast"></i></div>
		<div class="ps-up-body">
			<div class="ps-up-eyebrow">Siaran langsung akan datang<?php echo $s['jenis'] ? ' · ' . esc_html( $s['jenis'] ) : ''; ?></div>
			<div class="ps-up-title"><?php echo esc_html( $s['tajuk'] ); ?></div>
			<div class="ps-up-when"><i class="fa-regular fa-calendar"></i> <?php echo esc_html( ps_fmt_date( $s['mula'] ) ); ?><?php echo $s['penceramah'] ? ' · ' . esc_html( $s['penceramah'] ) : ''; ?></div>
		</div>
		<div class="ps-up-count" data-ps-countdown="<?php echo (int) $ts; ?>"></div>
	</div>
	<?php return ob_get_clean();
}

function ps_recording_card( $s ) {
	ob_start(); ?>
	<div class="ps-rec ps-card">
		<button type="button" class="ps-rec-thumb" data-ps-embed="<?php echo esc_url( $s['embed'] ); ?>" data-ps-title="<?php echo esc_attr( $s['tajuk'] ); ?>" aria-label="Main video: <?php echo esc_attr( $s['tajuk'] ); ?>">
			<?php if ( $s['thumb'] ) : ?>
				<img class="ps-rec-img" src="<?php echo esc_url( $s['thumb'] ); ?>" alt="" loading="lazy">
			<?php else : ?>
				<span class="ps-rec-ph ps-rec-ph--<?php echo esc_attr( $s['platform'] ); ?>"><i class="<?php echo esc_attr( ps_platform_icon( $s['platform'] ) ); ?>"></i></span>
			<?php endif; ?>
			<span class="ps-play"><i class="fa-solid fa-play"></i></span>
		</button>
		<div class="p-3">
			<?php if ( $s['jenis'] ) : ?><div class="ps-rec-jenis"><?php echo esc_html( $s['jenis'] ); ?></div><?php endif; ?>
			<h3 class="ps-rec-title"><?php echo esc_html( $s['tajuk'] ); ?></h3>
			<div class="ps-rec-sub">
				<?php echo $s['penceramah'] ? esc_html( $s['penceramah'] ) . ' · ' : ''; ?>
				<?php echo esc_html( $s['mula'] ? ps_fmt_date( $s['mula'], false ) : get_the_date( 'j F Y', $s['id'] ) ); ?>
			</div>
		</div>
	</div>
	<?php return ob_get_clean();
}

/* ------------------------------------------------------------------ *
 * Front page widget (theme hook)
 * ------------------------------------------------------------------ */
function ps_front_widget() {
	$set  = ps_settings();
	$live = ps_live();
	if ( $live ) {
		echo '<section class="ps-front ps-front--live"><div class="ps-in">' . ps_live_block( $live ) . '</div></section>';
		return;
	}
	$up = ps_upcoming( (int) $set['hari_akan_datang'] );
	if ( $up ) {
		echo '<section class="ps-front ps-front--up"><div class="ps-in"><a class="ps-up-link" href="' . esc_url( ps_page_url() ) . '">' . ps_upcoming_card( $up[0], true ) . '</a></div></section>';
		return;
	}
	if ( $set['rakaman_utama'] && ( $rec = ps_recordings() ) ) {
		$r = $rec[0];
		echo '<section class="ps-front ps-front--up"><div class="ps-in"><a class="ps-up-link" href="' . esc_url( ps_page_url() ) . '"><div class="ps-ui ps-upcoming ps-upcoming--compact"><div class="ps-up-icon"><i class="fa-solid fa-circle-play"></i></div><div class="ps-up-body"><div class="ps-up-eyebrow">Rakaman terkini' . ( $r['jenis'] ? ' · ' . esc_html( $r['jenis'] ) : '' ) . '</div><div class="ps-up-title">' . esc_html( $r['tajuk'] ) . '</div></div><div class="ps-up-count"><span class="btn btn-mp btn-sm">Tonton <i class="fa-solid fa-arrow-right"></i></span></div></div></a></div></section>';
	}
}
add_action( 'perepat_front_after_prayer', 'ps_front_widget', 5 ); // themes that provide this hook (e.g. Perepat)
// Optional: put the widget at the top of the front page content (any theme)
add_filter( 'the_content', function ( $c ) {
	if ( ! ps_settings()['auto_utama'] || ! is_front_page() || ! in_the_loop() || ! is_main_query() || did_action( 'perepat_front_after_prayer' ) ) { return $c; }
	static $done = false;
	if ( $done ) { return $c; }
	$done = true;
	ob_start(); ps_front_widget();
	return ob_get_clean() . $c;
}, 5 );
add_shortcode( 'siaran_utama', function () { ob_start(); ps_front_widget(); return ob_get_clean(); } );

/* Floating "LIVE" pill on other pages */
add_action( 'wp_footer', function () {
	if ( is_front_page() || is_admin() ) return;
	$pid = (int) ps_settings()['halaman'];
	if ( $pid && is_page( $pid ) ) return;
	$live = ps_live();
	if ( ! $live ) return;
	printf(
		'<a class="ps-float" href="%s"><span class="ps-dot"></span> <strong>LANGSUNG</strong> <span class="ps-float-t">%s</span></a>',
		esc_url( ps_page_url() ), esc_html( $live['tajuk'] )
	);
} );

/* Old page slug → siaran page (core only does this for posts, not pages) */
add_action( 'template_redirect', function () {
	if ( ! is_404() ) return;
	$pid = (int) ps_settings()['halaman'];
	if ( ! $pid || get_post_status( $pid ) !== 'publish' ) return;
	$old = (array) get_post_meta( $pid, '_wp_old_slug' );
	if ( ! $old ) return;
	$path = trim( (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ), '/' );
	$last = basename( $path );
	if ( $last !== '' && in_array( $last, $old, true ) ) {
		wp_safe_redirect( get_permalink( $pid ), 301 );
		exit;
	}
} );

/* ------------------------------------------------------------------ *
 * Page shortcode
 * ------------------------------------------------------------------ */
add_shortcode( 'siaran_langsung', function ( $atts ) {
	$atts  = shortcode_atts( [ 'per_page' => 9 ], $atts );
	$live  = ps_live();
	$up    = ps_upcoming();
	$jenis = isset( $_GET['jenis'] ) ? sanitize_text_field( wp_unslash( $_GET['jenis'] ) ) : '';
	if ( $jenis !== '' && ! in_array( $jenis, ps_jenis_list(), true ) ) $jenis = '';
	$recs  = ps_recordings( $jenis );
	$pp    = max( 3, (int) $atts['per_page'] );
	$pg    = max( 1, (int) ( $_GET['hal'] ?? 1 ) );
	$pages = max( 1, (int) ceil( count( $recs ) / $pp ) );
	$pg    = min( $pg, $pages );
	$slice = array_slice( $recs, ( $pg - 1 ) * $pp, $pp );
	$base  = remove_query_arg( [ 'jenis', 'hal' ] );

	ob_start(); ?>
	<div class="ps-page ps-ui">
		<?php if ( $live ) : ?>
			<div class="ps-page-live"><?php echo ps_live_block( $live, false ); ?></div>
		<?php else : ?>
			<div class="ps-none">
				<i class="fa-solid fa-tower-broadcast"></i>
				<div>
					<div class="fw-semibold">Tiada siaran langsung buat masa ini.</div>
					<div class="small text-secondary"><?php echo $up ? 'Siaran seterusnya: ' . esc_html( $up[0]['tajuk'] ) . ' — ' . esc_html( ps_fmt_date( $up[0]['mula'] ) ) . '.' : 'Siaran langsung khutbah dan ceramah akan dipaparkan di sini sebaik sahaja bermula.'; ?></div>
				</div>
			</div>
		<?php endif; ?>

		<?php if ( $up ) : ?>
			<h2 class="ps-h">Jadual siaran akan datang</h2>
			<div class="ps-up-list">
				<?php foreach ( array_slice( $up, 0, 6 ) as $u ) echo ps_upcoming_card( $u ); ?>
			</div>
		<?php endif; ?>

		<h2 class="ps-h" id="rakaman">Rakaman khutbah &amp; ceramah</h2>
		<?php $all_jenis = array_unique( array_filter( array_column( ps_recordings(), 'jenis' ) ) ); ?>
		<?php if ( count( $all_jenis ) > 1 ) : ?>
			<div class="ps-filter">
				<a class="<?php echo $jenis === '' ? 'is-on' : ''; ?>" href="<?php echo esc_url( $base . '#rakaman' ); ?>">Semua</a>
				<?php foreach ( ps_jenis_list() as $j ) : if ( ! in_array( $j, $all_jenis, true ) ) continue; ?>
					<a class="<?php echo $jenis === $j ? 'is-on' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'jenis', rawurlencode( $j ), $base ) . '#rakaman' ); ?>"><?php echo esc_html( $j ); ?></a>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<?php if ( $slice ) : ?>
			<div class="row g-4">
				<?php foreach ( $slice as $r ) echo '<div class="col-sm-6 col-lg-4">' . ps_recording_card( $r ) . '</div>'; ?>
			</div>
			<?php if ( $pages > 1 ) : ?>
				<nav class="ps-pager">
					<?php for ( $i = 1; $i <= $pages; $i++ ) : ?>
						<a class="<?php echo $i === $pg ? 'is-on' : ''; ?>" href="<?php echo esc_url( add_query_arg( array_filter( [ 'jenis' => $jenis ? rawurlencode( $jenis ) : '', 'hal' => $i > 1 ? $i : '' ] ), $base ) . '#rakaman' ); ?>"><?php echo $i; ?></a>
					<?php endfor; ?>
				</nav>
			<?php endif; ?>
		<?php else : ?>
			<p class="text-secondary">Belum ada rakaman<?php echo $jenis ? ' untuk ' . esc_html( $jenis ) : ''; ?>.</p>
		<?php endif; ?>
	</div>
	<?php return ob_get_clean();
} );

/* ------------------------------------------------------------------ *
 * Admin: meta box
 * ------------------------------------------------------------------ */
add_action( 'add_meta_boxes', function () {
	add_meta_box( 'ps_butiran', 'Butiran Siaran', 'ps_metabox', PS_CPT, 'normal', 'high' );
} );

function ps_metabox( $post ) {
	wp_nonce_field( 'ps_save', 'ps_nonce' );
	$s    = ps_get( $post->ID );
	$mula = $s['mula'] ? $s['mula']->format( 'Y-m-d\TH:i' ) : '';
	?>
	<style>
		.ps-f{margin:0 0 16px}.ps-f label{display:block;font-weight:600;margin-bottom:4px}.ps-f input[type=url],.ps-f input[type=text],.ps-f select{width:100%;max-width:640px}
		.ps-help{color:#646970;font-size:12px;margin-top:4px}.ps-mod label{display:inline-block;margin-right:18px;font-weight:400}
		.ps-st{display:inline-block;padding:2px 10px;border-radius:99px;font-size:12px;font-weight:600}
		.ps-st-live{background:#d63638;color:#fff}.ps-st-akan{background:#f0c33c;color:#1d2327}.ps-st-rakaman{background:#dcdcde;color:#1d2327}
		.ps-prev{max-width:480px;margin-top:8px}.ps-prev iframe{width:100%;aspect-ratio:16/9;border:0;border-radius:8px;background:#000}
	</style>
	<p>Status sekarang: <span class="ps-st ps-st-<?php echo esc_attr( $s['status'] ); ?>"><?php echo esc_html( [ 'live' => 'Siaran Langsung', 'akan' => 'Akan Datang', 'rakaman' => 'Rakaman' ][ $s['status'] ] ); ?></span></p>

	<div class="ps-f">
		<label for="ps_url">Pautan video YouTube / Facebook</label>
		<input type="url" id="ps_url" name="ps_url" value="<?php echo esc_attr( $s['url'] ); ?>" placeholder="https://www.youtube.com/live/… atau https://www.facebook.com/namahalaman/videos/…">
		<div class="ps-help">
			YouTube: pautan video / siaran (youtube.com/watch?v=…, youtu.be/…, youtube.com/live/…). Untuk siaran langsung saluran: <code>youtube.com/channel/UC…/live</code>.<br>
			Facebook: salin pautan video siaran langsung dari halaman Facebook masjid (… → Salin pautan). Video mesti <strong>Awam (Public)</strong>.
		</div>
		<?php if ( ! empty( $s['error'] ) ) : ?><div class="notice notice-error inline"><p><?php echo esc_html( $s['error'] ); ?></p></div><?php endif; ?>
		<?php if ( $s['embed'] ) : ?><div class="ps-prev"><iframe src="<?php echo esc_url( $s['embed'] ); ?>" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe></div><?php endif; ?>
	</div>

	<div class="ps-f">
		<label for="ps_jenis">Jenis</label>
		<select id="ps_jenis" name="ps_jenis">
			<?php foreach ( ps_jenis_list() as $j ) printf( '<option %s>%s</option>', selected( $s['jenis'], $j, false ), esc_html( $j ) ); ?>
		</select>
	</div>
	<div class="ps-f">
		<label for="ps_penceramah">Khatib / Penceramah</label>
		<input type="text" id="ps_penceramah" name="ps_penceramah" value="<?php echo esc_attr( $s['penceramah'] ); ?>" placeholder="cth. Ustaz Ahmad bin Abdullah">
	</div>
	<div class="ps-f">
		<label for="ps_mula">Tarikh &amp; masa mula</label>
		<input type="datetime-local" id="ps_mula" name="ps_mula" value="<?php echo esc_attr( $mula ); ?>">
		&nbsp; Tempoh <input type="number" name="ps_tempoh" value="<?php echo (int) $s['tempoh']; ?>" min="5" max="600" step="5" style="width:80px"> minit
		<div class="ps-help">Dalam mod <em>Automatik</em>, siaran dipapar sebagai <strong>Siaran Langsung</strong> dari masa mula hingga tamat tempoh, kemudian menjadi <strong>Rakaman</strong>.</div>
	</div>
	<div class="ps-f ps-mod">
		<label style="font-weight:600;display:block">Mod paparan</label>
		<label><input type="radio" name="ps_mod" value="auto" <?php checked( $s['mod'], 'auto' ); ?>> Automatik (ikut masa)</label>
		<label><input type="radio" name="ps_mod" value="langsung" <?php checked( $s['mod'], 'langsung' ); ?>> <strong style="color:#d63638">Sedang siaran langsung</strong> (paksa)</label>
		<label><input type="radio" name="ps_mod" value="tamat" <?php checked( $s['mod'], 'tamat' ); ?>> Tamat — jadikan rakaman</label>
	</div>
	<?php
}

add_action( 'save_post_' . PS_CPT, function ( $post_id ) {
	if ( ! isset( $_POST['ps_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ps_nonce'] ) ), 'ps_save' ) ) return;
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
	if ( ! current_user_can( 'edit_post', $post_id ) ) return;
	ps_save_fields( $post_id, wp_unslash( $_POST ) );
} );

function ps_save_fields( $post_id, $in ) {
	$url = esc_url_raw( trim( (string) ( $in['ps_url'] ?? '' ) ), [ 'https', 'http' ] );
	$url = substr( $url, 0, 500 );
	update_post_meta( $post_id, '_ps_url', $url );
	$jenis = sanitize_text_field( $in['ps_jenis'] ?? '' );
	update_post_meta( $post_id, '_ps_jenis', in_array( $jenis, ps_jenis_list(), true ) ? $jenis : '' );
	update_post_meta( $post_id, '_ps_penceramah', mb_substr( sanitize_text_field( $in['ps_penceramah'] ?? '' ), 0, 120 ) );
	$m = sanitize_text_field( $in['ps_mula'] ?? '' );
	update_post_meta( $post_id, '_ps_mula', preg_match( '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}$/', $m ) ? str_replace( 'T', ' ', $m ) : '' );
	update_post_meta( $post_id, '_ps_tempoh', min( 600, max( 5, (int) ( $in['ps_tempoh'] ?? 90 ) ) ) );
	$mod = sanitize_key( $in['ps_mod'] ?? 'auto' );
	update_post_meta( $post_id, '_ps_mod', in_array( $mod, [ 'auto', 'langsung', 'tamat' ], true ) ? $mod : 'auto' );
}

/* List table columns */
add_filter( 'manage_' . PS_CPT . '_posts_columns', function ( $c ) {
	$n = [ 'cb' => $c['cb'], 'title' => 'Tajuk', 'ps_status' => 'Status', 'ps_jenis' => 'Jenis', 'ps_mula' => 'Mula', 'ps_platform' => 'Platform' ];
	return $n;
} );
add_action( 'manage_' . PS_CPT . '_posts_custom_column', function ( $col, $id ) {
	$s = ps_get( $id );
	switch ( $col ) {
		case 'ps_status':
			$lbl = [ 'live' => 'Siaran Langsung', 'akan' => 'Akan Datang', 'rakaman' => 'Rakaman' ][ $s['status'] ];
			$bg  = [ 'live' => '#d63638;color:#fff', 'akan' => '#f0c33c', 'rakaman' => '#dcdcde' ][ $s['status'] ];
			echo '<span style="display:inline-block;padding:2px 10px;border-radius:99px;font-size:12px;font-weight:600;background:' . $bg . '">' . esc_html( $lbl ) . '</span>';
			if ( $s['mod'] !== 'auto' ) echo ' <small>(manual)</small>';
			break;
		case 'ps_jenis':    echo esc_html( $s['jenis'] ); break;
		case 'ps_mula':     echo esc_html( $s['mula'] ? $s['mula']->format( 'd/m/Y g:i A' ) : '—' ); break;
		case 'ps_platform': echo esc_html( ucfirst( $s['platform'] ?: '—' ) ); break;
	}
}, 10, 2 );

/* Row action: end live */
add_filter( 'post_row_actions', function ( $a, $post ) {
	if ( $post->post_type !== PS_CPT || ! current_user_can( 'edit_post', $post->ID ) ) return $a;
	if ( ps_get( $post->ID )['status'] === 'live' ) {
		$a['ps_tamat'] = '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ps_tamat&id=' . $post->ID ), 'ps_tamat_' . $post->ID ) ) . '" style="color:#d63638">Tamatkan siaran</a>';
	}
	return $a;
}, 10, 2 );

/* ------------------------------------------------------------------ *
 * Admin: dashboard quick-start widget
 * ------------------------------------------------------------------ */
add_action( 'wp_dashboard_setup', function () {
	if ( ! current_user_can( 'edit_posts' ) ) return;
	wp_add_dashboard_widget( 'ps_dash', '🔴 Siaran Langsung', 'ps_dashboard_widget' );
	// Move to top
	global $wp_meta_boxes;
	$w = $wp_meta_boxes['dashboard']['normal']['core']['ps_dash'] ?? null;
	if ( $w ) {
		unset( $wp_meta_boxes['dashboard']['normal']['core']['ps_dash'] );
		$wp_meta_boxes['dashboard']['normal']['high']['ps_dash'] = $w;
	}
} );

function ps_dashboard_widget() {
	if ( ! empty( $_GET['ps_msg'] ) ) {
		$m = [ 'mula' => 'Siaran langsung dimulakan dan kini dipaparkan di laman utama.', 'tamat' => 'Siaran ditamatkan dan disimpan sebagai rakaman.', 'ralat' => 'Pautan tidak sah — hanya YouTube atau Facebook.' ][ sanitize_key( $_GET['ps_msg'] ) ] ?? '';
		if ( $m ) echo '<div class="notice notice-' . ( $_GET['ps_msg'] === 'ralat' ? 'error' : 'success' ) . ' inline"><p>' . esc_html( $m ) . '</p></div>';
	}
	$live = ps_live();
	if ( $live ) {
		echo '<p><strong style="color:#d63638">● SEDANG SIARAN:</strong> ' . esc_html( $live['tajuk'] ) . '</p>';
		if ( current_user_can( 'edit_post', $live['id'] ) ) {
			echo '<p><a class="button button-primary" style="background:#d63638;border-color:#d63638" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ps_tamat&id=' . $live['id'] ), 'ps_tamat_' . $live['id'] ) ) . '">Tamatkan siaran</a> <a class="button" href="' . esc_url( get_edit_post_link( $live['id'] ) ) . '">Sunting</a></p>';
		}
		echo '<hr>';
	}
	$up = ps_upcoming( 14 );
	if ( $up ) {
		echo '<p style="margin-bottom:4px"><strong>Akan datang:</strong></p><ul style="margin-top:0">';
		foreach ( array_slice( $up, 0, 3 ) as $u ) echo '<li>' . esc_html( $u['mula']->format( 'd/m g:i A' ) . ' — ' . $u['tajuk'] ) . '</li>';
		echo '</ul>';
	}
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="ps_mula">
		<?php wp_nonce_field( 'ps_mula' ); ?>
		<p style="margin:0 0 6px"><strong>Mula siaran langsung sekarang</strong></p>
		<p style="margin:0 0 6px"><input type="url" name="ps_url" class="widefat" required placeholder="Tampal pautan YouTube / Facebook live"></p>
		<p style="margin:0 0 6px"><input type="text" name="ps_tajuk" class="widefat" required placeholder="Tajuk, cth. Khutbah Jumaat 6 November 2026" maxlength="150"></p>
		<p style="margin:0 0 6px;display:flex;gap:6px">
			<select name="ps_jenis" style="flex:1"><?php foreach ( ps_jenis_list() as $j ) echo '<option>' . esc_html( $j ) . '</option>'; ?></select>
			<input type="text" name="ps_penceramah" placeholder="Khatib / Penceramah" style="flex:1" maxlength="120">
		</p>
		<p style="margin:0"><button class="button button-primary">▶ Mula Siaran</button>
			<a class="button-link" style="margin-left:8px" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . PS_CPT ) ); ?>">Jadualkan siaran / tambah rakaman</a></p>
	</form>
	<?php
}

add_action( 'admin_post_ps_mula', function () {
	check_admin_referer( 'ps_mula' );
	if ( ! current_user_can( 'publish_posts' ) && ! current_user_can( 'edit_posts' ) ) wp_die( 'Tiada kebenaran.', 403 );
	$in  = wp_unslash( $_POST );
	$url = esc_url_raw( trim( (string) ( $in['ps_url'] ?? '' ) ) );
	$e   = ps_parse_url( $url );
	if ( ! $e || empty( $e['embed'] ) ) { wp_safe_redirect( admin_url( 'index.php?ps_msg=ralat' ) ); exit; }

	// End any other forced-live item so only one is live.
	foreach ( ps_all() as $s ) if ( $s['mod'] === 'langsung' ) update_post_meta( $s['id'], '_ps_mod', 'tamat' );

	$id = wp_insert_post( [
		'post_type'   => PS_CPT,
		'post_status' => current_user_can( 'publish_posts' ) ? 'publish' : 'pending',
		'post_title'  => mb_substr( sanitize_text_field( $in['ps_tajuk'] ?? 'Siaran Langsung' ), 0, 150 ),
	] );
	if ( $id && ! is_wp_error( $id ) ) {
		ps_save_fields( $id, [
			'ps_url'        => $url,
			'ps_jenis'      => $in['ps_jenis'] ?? '',
			'ps_penceramah' => $in['ps_penceramah'] ?? '',
			'ps_mula'       => current_datetime()->format( 'Y-m-d H:i' ),
			'ps_tempoh'     => 180,
			'ps_mod'        => 'langsung',
		] );
	}
	wp_safe_redirect( admin_url( 'index.php?ps_msg=mula' ) );
	exit;
} );

add_action( 'admin_post_ps_tamat', function () {
	$id = (int) ( $_GET['id'] ?? 0 );
	check_admin_referer( 'ps_tamat_' . $id );
	if ( get_post_type( $id ) !== PS_CPT || ! current_user_can( 'edit_post', $id ) ) wp_die( 'Tiada kebenaran.', 403 );
	update_post_meta( $id, '_ps_mod', 'tamat' );
	$ref = wp_get_referer();
	wp_safe_redirect( $ref && strpos( $ref, 'edit.php' ) !== false ? $ref : admin_url( 'index.php?ps_msg=tamat' ) );
	exit;
} );

/* ------------------------------------------------------------------ *
 * Admin: settings
 * ------------------------------------------------------------------ */
add_action( 'admin_menu', function () {
	add_submenu_page( 'edit.php?post_type=' . PS_CPT, 'Tetapan Siaran', 'Tetapan', 'manage_options', 'ps-tetapan', 'ps_settings_page' );
} );
add_action( 'admin_init', function () {
	register_setting( 'ps_settings', 'ps_settings', [ 'sanitize_callback' => function ( $v ) {
		$v = (array) $v;
		return [
			'hari_akan_datang' => min( 60, max( 0, (int) ( $v['hari_akan_datang'] ?? 7 ) ) ),
			'rakaman_utama'    => empty( $v['rakaman_utama'] ) ? 0 : 1,
			'halaman'          => (int) ( $v['halaman'] ?? 0 ),
			'jenis'            => implode( "\n", array_slice( array_filter( array_map( fn( $l ) => mb_substr( sanitize_text_field( $l ), 0, 60 ), explode( "\n", (string) ( $v['jenis'] ?? '' ) ) ) ), 0, 30 ) ),
			'auto_utama'       => empty( $v['auto_utama'] ) ? 0 : 1,
			'warna'            => sanitize_hex_color( (string) ( $v['warna'] ?? '' ) ) ?: '#527c3a',
			'warna_gelap'      => sanitize_hex_color( (string) ( $v['warna_gelap'] ?? '' ) ) ?: '#1d3314',
			'aksen'            => sanitize_hex_color( (string) ( $v['aksen'] ?? '' ) ) ?: '#8dc642',
			'fontawesome'      => in_array( $v['fontawesome'] ?? '', [ 'auto', 'cdn', 'off' ], true ) ? $v['fontawesome'] : 'auto',
			'padam_data'       => empty( $v['padam_data'] ) ? 0 : 1,
		];
	} ] );
} );
function ps_settings_page() {
	$s = ps_settings(); ?>
	<div class="wrap">
		<h1>Tetapan Siaran Langsung</h1>
		<form method="post" action="options.php">
			<?php settings_fields( 'ps_settings' ); ?>
			<table class="form-table">
				<tr><th>Halaman siaran</th><td><?php wp_dropdown_pages( [ 'name' => 'ps_settings[halaman]', 'selected' => (int) $s['halaman'], 'show_option_none' => '— Pilih —', 'option_none_value' => 0 ] ); ?>
					<?php if ( ! $s['halaman'] ) : ?> <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ps_cipta_halaman' ), 'ps_cipta_halaman' ) ); ?>">Cipta halaman "Siaran Langsung"</a><?php endif; ?>
					<p class="description">Halaman yang mengandungi shortcode <code>[siaran_langsung]</code>.</p></td></tr>
				<tr><th>Widget laman utama</th><td><label><input type="checkbox" name="ps_settings[auto_utama]" value="1" <?php checked( $s['auto_utama'], 1 ); ?>> Paparkan widget siaran secara automatik di bahagian atas kandungan laman utama</label><p class="description">Atau letak shortcode <code>[siaran_utama]</code> di mana-mana bahagian laman utama.</p></td></tr>
				<tr><th>Hebahan "akan datang" di laman utama</th><td><input type="number" min="0" max="60" name="ps_settings[hari_akan_datang]" value="<?php echo (int) $s['hari_akan_datang']; ?>" style="width:70px"> hari sebelum siaran <p class="description">0 = jangan papar siaran akan datang di laman utama.</p></td></tr>
				<tr><th>Rakaman terkini di laman utama</th><td><label><input type="checkbox" name="ps_settings[rakaman_utama]" value="1" <?php checked( $s['rakaman_utama'], 1 ); ?>> Papar rakaman terkini bila tiada siaran langsung / akan datang</label></td></tr>
				<tr><th>Senarai jenis</th><td><textarea name="ps_settings[jenis]" rows="8" cols="40"><?php echo esc_textarea( $s['jenis'] ); ?></textarea><p class="description">Satu jenis setiap baris.</p></td></tr>
				<tr><th>Warna</th><td><label>Utama <input type="color" name="ps_settings[warna]" value="<?php echo esc_attr( $s['warna'] ); ?>"></label> &nbsp; <label>Latar gelap <input type="color" name="ps_settings[warna_gelap]" value="<?php echo esc_attr( $s['warna_gelap'] ); ?>"></label> &nbsp; <label>Aksen <input type="color" name="ps_settings[aksen]" value="<?php echo esc_attr( $s['aksen'] ); ?>"></label></td></tr>
				<tr><th>Ikon Font Awesome</th><td><select name="ps_settings[fontawesome]"><?php foreach ( [ 'auto' => 'Automatik (muat jika tema belum ada)', 'cdn' => 'Sentiasa muat dari jsDelivr', 'off' => 'Jangan muat' ] as $k => $l ) { echo '<option value="' . esc_attr( $k ) . '"' . selected( $s['fontawesome'], $k, false ) . '>' . esc_html( $l ) . '</option>'; } ?></select></td></tr>
				<tr><th>Nyahpasang</th><td><label><input type="checkbox" name="ps_settings[padam_data]" value="1" <?php checked( $s['padam_data'], 1 ); ?>> Padam semua siaran, rakaman dan tetapan apabila plugin <strong>dipadam</strong></label></td></tr>
			</table>
			<?php submit_button( 'Simpan' ); ?>
		</form>
		<h2>Shortcode</h2>
		<p><code>[siaran_langsung]</code> — halaman penuh (siaran langsung, jadual, rakaman). <code>[siaran_utama]</code> — widget laman utama: siaran langsung, atau kad "akan datang" dengan kiraan detik (kosong jika tiada).</p>
	</div>
	<?php
}

add_action( 'admin_post_ps_cipta_halaman', function () {
	check_admin_referer( 'ps_cipta_halaman' );
	if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Tiada kebenaran.', 403 );
	$id = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Siaran Langsung', 'post_name' => 'siaran-langsung', 'post_content' => '<!-- wp:shortcode -->[siaran_langsung]<!-- /wp:shortcode -->' ] );
	if ( $id && ! is_wp_error( $id ) ) { $s = ps_settings(); $s['halaman'] = $id; update_option( 'ps_settings', $s ); }
	wp_safe_redirect( admin_url( 'edit.php?post_type=' . PS_CPT . '&page=ps-tetapan&settings-updated=1' ) );
	exit;
} );

add_action( 'admin_notices', function () {
	if ( ! get_transient( 'ps_welcome' ) || ! current_user_can( 'manage_options' ) ) return;
	delete_transient( 'ps_welcome' );
	echo '<div class="notice notice-success is-dismissible"><p><strong>Siaran Langsung Masjid diaktifkan.</strong> Buka <a href="' . esc_url( admin_url( 'edit.php?post_type=' . PS_CPT . '&page=ps-tetapan' ) ) . '">Tetapan</a> untuk mencipta halaman siaran dan memilih paparan laman utama. Mula siaran dari kotak <em>Siaran Langsung</em> di Papan Pemuka.</p></div>';
} );

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $l ) {
	array_unshift( $l, '<a href="' . esc_url( admin_url( 'edit.php?post_type=' . PS_CPT . '&page=ps-tetapan' ) ) . '">Tetapan</a>' );
	return $l;
} );
