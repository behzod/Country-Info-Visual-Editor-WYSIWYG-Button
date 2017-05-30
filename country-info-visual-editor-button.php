<?php
/*
Plugin Name: Country Info Visual Editor (WYSIWYG) Button
Plugin URI: https://github.com/behzod/Country-Info-Visual-Editor-WYSIWYG-Button
Description: Adds a visual editor button for pulling country info from The World Bank API.
Version: 1.0
Author: Behzod Saidov
Author URI: https://github.com/behzod
*/

define( 'CI_WB_API_BASE_URL', 'http://api.worldbank.org' );
define( 'CI_NUMBER_OF_YEARS_TO_CHECK_BACK', 5 );

function ci_enqueue_plugin_scripts( $plugin_array ) {
	$plugin_array['country_info_visual_editor_button'] = plugin_dir_url( __FILE__ ) . 'assets/button.js';

	return $plugin_array;
}

function ci_register_buttons_editor( $buttons ) {
	array_push( $buttons, 'country_code' );

	return $buttons;
}

// Add the TinyMCE button if the user can edit posts/pages and the the visual editor is not disabled in the user profile
add_action(
	'admin_init', function () {
	if ( ! current_user_can( 'edit_posts' ) && ! current_user_can( 'edit_pages' ) ) {
		return;
	}
	if ( get_user_option( 'rich_editing' ) != 'true' ) {
		return;
	}
	add_filter( 'mce_external_plugins', 'ci_enqueue_plugin_scripts' );
	add_filter( 'mce_buttons', 'ci_register_buttons_editor' );
}
);

/**
 * Returns country indicators for number of years as a string with comma separated values
 *
 * @param $country_code
 * @param $indicator
 * @param $number_of_year_to_check
 *
 * @return string
 */
function ci_get_country_indicators( $country_code, $indicator, $number_of_year_to_check ) {
	$current_year = date( 'Y' );
	$start_year   = $current_year - $number_of_year_to_check;

	$indicators_api_url = '';
	switch ( $indicator ) {
		case 'population':
			$indicators_api_url = CI_WB_API_BASE_URL . '/countries/' . $country_code .
				'/indicators/SP.POP.TOTL?format=json&date=' . $start_year . ':' . $current_year;
			break;
		case 'gdp':
			$indicators_api_url = CI_WB_API_BASE_URL . '/countries/' . $country_code .
				'/indicators/NY.GDP.MKTP.CD?format=json&date=' . $start_year . ':' . $current_year;
			break;
	}

	$api_request = wp_remote_get( $indicators_api_url );
	if ( is_wp_error( $api_request ) ) {
		return 'Error: Can\'t connect to the API.';
	}
	$api_result = json_decode( wp_remote_retrieve_body( $api_request ), true );
	$indicators = $api_result[1];

	$formatted_array = array();
	foreach ( $indicators as $indicator ) {
		$value             = empty( $indicator['value'] ) ? 'N/A' : number_format( $indicator['value'] );
		$formatted_array[] = sprintf( '%s (%s)', $value, $indicator['date'] );
	}

	return implode( ', ', $formatted_array );
}

/**
 * Echos a message as an AJAX response and dies immediately.
 *
 * @param $message
 */
function ci_ajax_error_message( $message ) {
	echo '<p><strong>' . $message . '</strong></p>';
	wp_die();
}

function ci_get_country_info() {
	// Ignore the request if the current user doesn't have sufficient permissions
	if ( ! current_user_can( 'edit_posts' ) && ! current_user_can( 'edit_pages' ) ) {
		return;
	}

	// Read and sanitize the country code from the post call
	$country_code = sanitize_text_field( $_POST['country_code'] );
	// Validate the country code
	if ( ! isset( $country_code ) || strlen( $country_code ) != 2 ) {
		ci_ajax_error_message( 'Invalid country code. 2 letter ISO code is expected.' );
	}

	// More validation
	// Include the countries array constant (CI_COUNTRIES_ARRAY) with valid ISO codes
	require 'lib/countries.php';
	// Check if the entered country code exists in the counties array
	if ( ! array_key_exists( strtoupper( $country_code ), CI_COUNTRIES_ARRAY ) ) {
		ci_ajax_error_message( 'Invalid country code!' );
	}

	// Start the building the info text
	$country_info_text = '<p>';

	// Get general info about the country
	$api_request = wp_remote_get( CI_WB_API_BASE_URL . '/countries/' . $country_code . '?format=json' );
	if ( is_wp_error( $api_request ) ) {
		ci_ajax_error_message( 'Error: Can\'t connect to the API.' );
	}
	$api_result   = json_decode( wp_remote_retrieve_body( $api_request ), true );
	$country_info = $api_result[1][0];
	$country_info_text .= '<strong>Country name: </strong>' . $country_info['name'] . '<br />';
	$country_info_text .= '<strong>Capital City: </strong>' . $country_info['capitalCity'] . '<br />';
	$country_info_text .= '<strong>ISO Code: </strong>' . $country_info['iso2Code'] . '<br />';
	$country_info_text .= '<strong>Region: </strong>' . $country_info['region']['value'] . '<br />';
	$country_info_text .= '<strong>Income Level: </strong>' . $country_info['incomeLevel']['value'] . '<br />';


	// Get the population info
	$country_info_text .= '<strong>Population: </strong>';
	$country_info_text .= ci_get_country_indicators( $country_code, 'population', CI_NUMBER_OF_YEARS_TO_CHECK_BACK );
	$country_info_text .= '<br />';

	// Get the GDP info
	$country_info_text .= '<strong>GDP (in current USD): </strong>';
	$country_info_text .= ci_get_country_indicators( $country_code, 'gdp', CI_NUMBER_OF_YEARS_TO_CHECK_BACK );
	$country_info_text .= '<br />';

	$country_info_text .= '</p>';

	// Clean the HTML result before echoing
	$safe_tags = array(
		'br'     => array(),
		'p'      => array(),
		'strong' => array(),
	);
	echo wp_kses( $country_info_text, $safe_tags );

	// Terminate immediately and return a proper response
	wp_die();
}

add_action( 'wp_ajax_ci_get_country_info', 'ci_get_country_info' );
