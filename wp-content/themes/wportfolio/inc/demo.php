<?php

function ocdi_import_files() {
	return array(
		array(
			'import_file_name'           => 'WPortfolio Demo',
			'import_file_url'            => 'https://wpxozosoft.xyz/wptheme/WPortfolio/demo.xml',
			'import_preview_image_url'   => 'https://wpxozosoft.xyz/wptheme/WPortfolio/demo.jpg',
		),
		
	);
}

add_filter( 'ocdi/import_files', 'ocdi_import_files' );

function wportfolio_after_import_setup() {
	// Assign menus to their locations.
	$main_menu = get_term_by( 'name', 'header', 'nav_menu' );

	set_theme_mod( 'nav_menu_locations', array(
			'menu-1' => $main_menu->term_id, // replace 'main-menu' here with the menu location identifier from register_nav_menu() function
		)
	);

	// Assign front page and posts page (blog page).
	$front_page_id = get_page_by_title( 'home' );

	update_option( 'show_on_front', 'page' );
	update_option( 'page_on_front', $front_page_id->ID );

}
add_action( 'pt-ocdi/after_import', 'wportfolio_after_import_setup' );


?>