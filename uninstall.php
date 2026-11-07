<?php
/** Runs when the plugin is DELETED from wp-admin. Broadcasts are only removed if "Padam semua …" was ticked in Tetapan. */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
$s = get_option( 'ps_settings' );
delete_site_transient( 'ps_gh_release' );
if ( empty( $s['padam_data'] ) ) { return; }
foreach ( get_posts( [ 'post_type' => 'mp_siaran', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ] ) as $id ) { wp_delete_post( $id, true ); }
delete_option( 'ps_settings' );
