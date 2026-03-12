<?php
/**
 * Plugin Name: Auto SEO Keyword Linker
 * Description: Keyword-to-link transformer with CSV import/export, suggestion scanning, Unicode-aware matching, and sitewide blacklisting.
 * Version: 1.5.0
 * License: GPL-2.0-or-later
 * Text Domain: auto-seo-keyword-linker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AKL_OPT', 'akl_settings' );
define( 'AKL_PAGE_SLUG', 'auto-keyword-linker' );

add_action( 'admin_menu', function() {
	add_options_page(
		'Keyword Links',
		'Keyword Links',
		'manage_options',
		AKL_PAGE_SLUG,
		'akl_page'
	);
} );

add_action( 'admin_init', function() {
	register_setting( 'akl_options', AKL_OPT, 'akl_sanitize' );

	add_settings_section(
		'main',
		'Configuration',
		'__return_false',
		AKL_PAGE_SLUG
	);

	add_settings_field( 'pairs', 'Keywords and URLs', 'akl_f_pairs', AKL_PAGE_SLUG, 'main' );
	add_settings_field( 'max', 'Max Links Per Page', 'akl_f_max', AKL_PAGE_SLUG, 'main' );
	add_settings_field( 'ext', 'External Link Behaviour', 'akl_f_ext', AKL_PAGE_SLUG, 'main' );
	add_settings_field( 'blacklist', 'Sitewide Blacklist', 'akl_f_blacklist', AKL_PAGE_SLUG, 'main' );
	add_settings_field( 'scanner_words', 'Scanner Phrase Length', 'akl_f_scanner_words', AKL_PAGE_SLUG, 'main' );
	add_settings_field( 'scanner_min_hits', 'Scanner Minimum Occurrences', 'akl_f_scanner_min_hits', AKL_PAGE_SLUG, 'main' );
	add_settings_field( 'scanner_excluded', 'Scanner Excluded Phrases', 'akl_f_scanner_excluded', AKL_PAGE_SLUG, 'main' );
} );

/*
 * Default settings.
 */
function akl_get_settings() {
	$defaults = [
		'pairs'            => '',
		'bl'               => '',
		'max'              => 1,
		'tab'              => 0,
		'nf'               => 0,
		'scanner_words'    => 3,
		'scanner_min_hits' => 2,
		'scanner_excluded' => '',
	];

	$settings = get_option( AKL_OPT, [] );

	if ( ! is_array( $settings ) ) {
		$settings = [];
	}

	return wp_parse_args( $settings, $defaults );
}

/*
 * Quote normalisation for internal matching.
 */
function akl_normalize_quotes( $text ) {
	return str_replace(
		[ '’', '‘', '“', '”', '`', '´' ],
		[ "'", "'", '"', '"', "'", "'" ],
		(string) $text
	);
}

/*
 * Create a stable internal keyword form for deduplication and matching.
 */
function akl_normalize_keyword_for_matching( $keyword ) {
	$keyword = html_entity_decode( (string) $keyword, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$keyword = akl_normalize_quotes( $keyword );
	$keyword = wp_strip_all_tags( $keyword );
	$keyword = preg_replace( '/\s+/u', ' ', trim( $keyword ) );

	return $keyword;
}

function akl_f_pairs() {
	$o = akl_get_settings();

	printf(
		'<textarea name="%1$s[pairs]" rows="12" class="large-text code" placeholder="keyword|https://example.com">%2$s</textarea><p class="description">One rule per line using the format <code>keyword|https://example.com</code>.</p>',
		esc_attr( AKL_OPT ),
		esc_textarea( $o['pairs'] )
	);
}

function akl_f_max() {
	$o = akl_get_settings();

	printf(
		'<input type="number" name="%1$s[max]" value="%2$d" min="1" max="50" /> <p class="description">Maximum replacements per keyword on a page.</p>',
		esc_attr( AKL_OPT ),
		absint( $o['max'] )
	);
}

function akl_f_ext() {
	$o = akl_get_settings();

	printf(
		'<label><input type="checkbox" name="%1$s[tab]" value="1" %2$s /> Open external links in a new tab</label><br>',
		esc_attr( AKL_OPT ),
		checked( ! empty( $o['tab'] ), true, false )
	);

	printf(
		'<label><input type="checkbox" name="%1$s[nf]" value="1" %2$s /> Add <code>nofollow</code> to external links</label>',
		esc_attr( AKL_OPT ),
		checked( ! empty( $o['nf'] ), true, false )
	);
}

function akl_f_blacklist() {
	$o = akl_get_settings();

	printf(
		'<textarea name="%1$s[bl]" rows="5" class="large-text code" placeholder="/checkout&#10;/cart&#10;/my-account">%2$s</textarea><p class="description">One exclusion per line. Partial URL matching is supported.</p>',
		esc_attr( AKL_OPT ),
		esc_textarea( $o['bl'] )
	);
}

function akl_f_scanner_words() {
	$o = akl_get_settings();

	printf(
		'<input type="number" name="%1$s[scanner_words]" value="%2$d" min="2" max="6" /> <p class="description">Number of words per suggested phrase.</p>',
		esc_attr( AKL_OPT ),
		absint( $o['scanner_words'] )
	);
}

function akl_f_scanner_min_hits() {
	$o = akl_get_settings();

	printf(
		'<input type="number" name="%1$s[scanner_min_hits]" value="%2$d" min="1" max="20" /> <p class="description">Only show phrases found at least this many times across scanned content.</p>',
		esc_attr( AKL_OPT ),
		absint( $o['scanner_min_hits'] )
	);
}

function akl_f_scanner_excluded() {
	$o = akl_get_settings();

	printf(
		'<textarea name="%1$s[scanner_excluded]" rows="5" class="large-text code" placeholder="example phrase">%2$s</textarea><p class="description">One phrase per line. Excluded phrases will not be shown in scan results.</p>',
		esc_attr( AKL_OPT ),
		esc_textarea( $o['scanner_excluded'] )
	);
}

/*
 * Build a cleaned, deduplicated rule list from raw textarea input.
 */
function akl_clean_pairs_for_storage( $pairs_raw ) {
	$lines     = preg_split( '/\r\n|\r|\n/', (string) $pairs_raw );
	$cleaned   = [];
	$seen_keys = [];

	foreach ( $lines as $line ) {
		$line = trim( $line );

		if ( '' === $line || false === strpos( $line, '|' ) ) {
			continue;
		}

		list( $keyword, $url ) = array_map( 'trim', explode( '|', $line, 2 ) );

		$keyword = akl_normalize_keyword_for_matching( $keyword );
		$url     = esc_url_raw( $url );

		if ( '' === $keyword || '' === $url ) {
			continue;
		}

		$dedupe_key = mb_strtolower( $keyword, 'UTF-8' );

		if ( isset( $seen_keys[ $dedupe_key ] ) ) {
			continue;
		}

		$seen_keys[ $dedupe_key ] = true;
		$cleaned[]                = $keyword . '|' . $url;
	}

	return implode( "\n", $cleaned );
}

/*
 * Sanitise settings before saving.
 */
function akl_sanitize( $input ) {
	$input = is_array( $input ) ? $input : [];

	$pairs            = isset( $input['pairs'] ) ? akl_clean_pairs_for_storage( wp_unslash( $input['pairs'] ) ) : '';
	$bl               = isset( $input['bl'] ) ? sanitize_textarea_field( wp_unslash( $input['bl'] ) ) : '';
	$scanner_excluded = isset( $input['scanner_excluded'] ) ? sanitize_textarea_field( wp_unslash( $input['scanner_excluded'] ) ) : '';
	$max              = isset( $input['max'] ) ? absint( $input['max'] ) : 1;
	$scanner_words    = isset( $input['scanner_words'] ) ? absint( $input['scanner_words'] ) : 3;
	$scanner_min_hits = isset( $input['scanner_min_hits'] ) ? absint( $input['scanner_min_hits'] ) : 2;

	return [
		'pairs'            => $pairs,
		'bl'               => $bl,
		'max'              => max( 1, min( 50, $max ) ),
		'tab'              => ! empty( $input['tab'] ) ? 1 : 0,
		'nf'               => ! empty( $input['nf'] ) ? 1 : 0,
		'scanner_words'    => max( 2, min( 6, $scanner_words ) ),
		'scanner_min_hits' => max( 1, min( 20, $scanner_min_hits ) ),
		'scanner_excluded' => $scanner_excluded,
	];
}

add_action( 'admin_init', function() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if (
		isset( $_GET['page'], $_GET['akl_export'], $_GET['_wpnonce'] ) &&
		AKL_PAGE_SLUG === $_GET['page'] &&
		'1' === $_GET['akl_export'] &&
		wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'akl_export' )
	) {
		$o     = akl_get_settings();
		$lines = preg_split( '/\r\n|\r|\n/', $o['pairs'] );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=akl-keywords.csv' );

		$out = fopen( 'php://output', 'w' );

		if ( false === $out ) {
			exit;
		}

		fputcsv( $out, [ 'Keyword', 'URL' ] );

		foreach ( $lines as $line ) {
			$line = trim( $line );

			if ( '' === $line || false === strpos( $line, '|' ) ) {
				continue;
			}

			$row = explode( '|', $line, 2 );

			fputcsv( $out, [ trim( $row[0] ), trim( $row[1] ) ] );
		}

		fclose( $out );
		exit;
	}

	if (
		isset( $_POST['akl_import_nonce'] ) &&
		wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['akl_import_nonce'] ) ), 'akl_import' )
	) {
		if ( empty( $_FILES['csv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['csv']['tmp_name'] ) ) {
			return;
		}

		$handle = fopen( $_FILES['csv']['tmp_name'], 'r' );

		if ( false === $handle ) {
			return;
		}

		$new_rows = [];

		fgetcsv( $handle );

		while ( false !== ( $data = fgetcsv( $handle ) ) ) {
			if ( count( $data ) < 2 ) {
				continue;
			}

			$keyword = akl_normalize_keyword_for_matching( $data[0] );
			$url     = esc_url_raw( trim( (string) $data[1] ) );

			if ( '' === $keyword || '' === $url ) {
				continue;
			}

			$new_rows[] = $keyword . '|' . $url;
		}

		fclose( $handle );

		if ( ! empty( $new_rows ) ) {
			$o = akl_get_settings();

			$combined   = implode( "\n", array_merge( [ $o['pairs'] ], $new_rows ) );
			$o['pairs'] = akl_clean_pairs_for_storage( $combined );

			update_option( AKL_OPT, $o );
		}

		wp_safe_redirect(
			add_query_arg(
				[
					'page'       => AKL_PAGE_SLUG,
					'akl_notice' => 'imported',
				],
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}
} );

add_action( 'admin_notices', function() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( ! isset( $_GET['page'], $_GET['akl_notice'] ) ) {
		return;
	}

	if ( AKL_PAGE_SLUG !== $_GET['page'] ) {
		return;
	}

	if ( 'imported' === $_GET['akl_notice'] ) {
		echo '<div class="notice notice-success is-dismissible"><p>Keyword CSV imported and merged.</p></div>';
	}
} );

function akl_page() {
	$export_url = wp_nonce_url(
		add_query_arg(
			[
				'page'       => AKL_PAGE_SLUG,
				'akl_export' => '1',
			],
			admin_url( 'options-general.php' )
		),
		'akl_export'
	);
	?>
	<div class="wrap">
		<h1>Auto SEO Keyword Linker <small>v1.5.0</small></h1>

		<div style="display:grid;grid-template-columns:2fr 1fr;gap:20px;align-items:start;">
			<div>
				<form method="post" action="options.php">
					<?php
					settings_fields( 'akl_options' );
					do_settings_sections( AKL_PAGE_SLUG );
					submit_button();
					?>
				</form>

				<div class="card" style="padding:16px;">
					<h2>CSV Import / Export</h2>

					<form method="post" enctype="multipart/form-data" style="margin-bottom:15px;">
						<?php wp_nonce_field( 'akl_import', 'akl_import_nonce' ); ?>
						<input type="file" name="csv" accept=".csv,text/csv" />
						<input type="submit" class="button" value="Import and Merge" />
					</form>

					<a href="<?php echo esc_url( $export_url ); ?>" class="button button-secondary">Export to CSV</a>
				</div>
			</div>

			<div>
				<div class="card" style="padding:16px;">
					<h3 style="margin-top:0;">Suggestion Scanner</h3>
					<p>Scan recent published posts for repeated phrase candidates.</p>
					<button type="button" class="button" id="akl-scan">Find Suggestions</button>
					<div id="akl-res" style="margin-top:10px;"></div>
				</div>
			</div>
		</div>
	</div>

	<script>
	jQuery(function($){
		$('#akl-scan').on('click', function(){
			const $button = $(this);
			const $result = $('#akl-res');

			$button.prop('disabled', true).text('Scanning...');
			$result.html('');

			$.post(ajaxurl, {
				action: 'akl_scan',
				nonce: '<?php echo esc_js( wp_create_nonce( 'akl_scan' ) ); ?>'
			})
			.done(function(response){
				if (response.success && response.data && response.data.length) {
					let html = '<table class="widefat striped"><thead><tr><th>Phrase</th><th>Occurrences</th><th>Target</th></tr></thead><tbody>';

					response.data.forEach(function(item) {
						html += '<tr>';
						html += '<td>' + $('<div>').text(item.k).html() + '</td>';
						html += '<td>' + $('<div>').text(item.hits).html() + '</td>';
						html += '<td><small>' + $('<div>').text(item.u).html() + '</small></td>';
						html += '</tr>';
					});

					html += '</tbody></table>';
					$result.html(html);
				} else {
					$result.text('No suggestions found.');
				}
			})
			.fail(function(){
				$result.text('Scanner request failed.');
			})
			.always(function(){
				$button.prop('disabled', false).text('Find Suggestions');
			});
		});
	});
	</script>
	<?php
}

/*
 * Parse stored rules into a structured list.
 */
function akl_parse_pairs( $pairs_raw ) {
	$rules = [];
	$lines = preg_split( '/\r\n|\r|\n/', (string) $pairs_raw );

	foreach ( $lines as $line ) {
		$line = trim( $line );

		if ( '' === $line || false === strpos( $line, '|' ) ) {
			continue;
		}

		list( $keyword, $url ) = array_map( 'trim', explode( '|', $line, 2 ) );

		if ( '' === $keyword || '' === $url ) {
			continue;
		}

		$url = esc_url_raw( $url );

		if ( '' === $url ) {
			continue;
		}

		$rules[] = [
			'k'     => $keyword,
			'k_key' => mb_strtolower( akl_normalize_keyword_for_matching( $keyword ), 'UTF-8' ),
			'u'     => $url,
		];
	}

	usort( $rules, function( $a, $b ) {
		return mb_strlen( $b['k'] ) <=> mb_strlen( $a['k'] );
	} );

	return $rules;
}

function akl_is_blacklisted( $blacklist_raw ) {
	if ( '' === trim( (string) $blacklist_raw ) ) {
		return false;
	}

	$current_url = home_url( add_query_arg( [], $_SERVER['REQUEST_URI'] ?? '' ) );
	$lines       = preg_split( '/\r\n|\r|\n/', (string) $blacklist_raw );

	foreach ( $lines as $line ) {
		$line = trim( $line );

		if ( '' === $line ) {
			continue;
		}

		if ( false !== strpos( $current_url, $line ) ) {
			return true;
		}
	}

	return false;
}

/*
 * Compare URLs in a normalised form to avoid self-links.
 */
function akl_normalize_url_for_compare( $url ) {
	$url = esc_url_raw( (string) $url );

	if ( '' === $url ) {
		return '';
	}

	$parts = wp_parse_url( $url );

	if ( empty( $parts['host'] ) ) {
		return '';
	}

	$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '';
	$host   = strtolower( $parts['host'] );
	$path   = isset( $parts['path'] ) ? untrailingslashit( $parts['path'] ) : '';
	$query  = isset( $parts['query'] ) ? '?' . $parts['query'] : '';

	return $scheme . '://' . $host . $path . $query;
}

add_action( 'wp_ajax_akl_scan', function() {
	check_ajax_referer( 'akl_scan', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( [ 'message' => 'Permission denied.' ], 403 );
	}

	$settings         = akl_get_settings();
	$phrase_words     = max( 2, min( 6, absint( $settings['scanner_words'] ) ) );
	$min_hits         = max( 1, min( 20, absint( $settings['scanner_min_hits'] ) ) );
	$existing_rules   = akl_parse_pairs( $settings['pairs'] );
	$excluded_lines   = preg_split( '/\r\n|\r|\n/', (string) $settings['scanner_excluded'] );
	$existing_map     = [];
	$excluded_map     = [];
	$phrase_hits      = [];
	$phrase_targets   = [];
	$phrase_title_hit = [];

	foreach ( $existing_rules as $rule ) {
		$existing_map[ $rule['k_key'] ] = true;
	}

	foreach ( $excluded_lines as $line ) {
		$line = akl_normalize_keyword_for_matching( $line );

		if ( '' === $line ) {
			continue;
		}

		$excluded_map[ mb_strtolower( $line, 'UTF-8' ) ] = true;
	}

	$posts = get_posts(
		[
			'numberposts' => 30,
			'post_status' => 'publish',
			'post_type'   => 'post',
		]
	);

	foreach ( $posts as $post ) {
		$content_words = preg_split(
			'/\s+/u',
			wp_strip_all_tags( strip_shortcodes( $post->post_content ) ),
			-1,
			PREG_SPLIT_NO_EMPTY
		);

		$title_text = akl_normalize_keyword_for_matching( get_the_title( $post->ID ) );
		$total      = count( $content_words );

		if ( $total < $phrase_words ) {
			continue;
		}

		for ( $i = 0; $i <= $total - $phrase_words; $i++ ) {
			$chunk = array_slice( $content_words, $i, $phrase_words );
			$raw   = trim( implode( ' ', $chunk ) );
			$norm  = akl_normalize_keyword_for_matching( $raw );
			$key   = mb_strtolower( $norm, 'UTF-8' );

			if ( '' === $norm ) {
				continue;
			}

			if ( mb_strlen( $norm, 'UTF-8' ) < 12 ) {
				continue;
			}

			if ( isset( $existing_map[ $key ] ) || isset( $excluded_map[ $key ] ) ) {
				continue;
			}

			if ( preg_match( '/^\p{N}+$/u', str_replace( ' ', '', $norm ) ) ) {
				continue;
			}

			$phrase_hits[ $key ]    = isset( $phrase_hits[ $key ] ) ? $phrase_hits[ $key ] + 1 : 1;
			$phrase_targets[ $key ] = get_permalink( $post->ID );

			if ( false !== mb_stripos( $title_text, $norm, 0, 'UTF-8' ) ) {
				$phrase_title_hit[ $key ] = true;
			}
		}
	}

	$suggestions = [];

	foreach ( $phrase_hits as $key => $hits ) {
		if ( $hits < $min_hits ) {
			continue;
		}

		$display_phrase = $key;

		if ( ! empty( $phrase_title_hit[ $key ] ) ) {
			$score = $hits + 1;
		} else {
			$score = $hits;
		}

		$suggestions[] = [
			'k'     => $display_phrase,
			'hits'  => $hits,
			'score' => $score,
			'u'     => $phrase_targets[ $key ],
		];
	}

	usort( $suggestions, function( $a, $b ) {
		if ( $b['score'] === $a['score'] ) {
			return mb_strlen( $b['k'] ) <=> mb_strlen( $a['k'] );
		}

		return $b['score'] <=> $a['score'];
	} );

	$suggestions = array_slice( $suggestions, 0, 10 );

	wp_send_json_success( $suggestions );
} );

add_filter( 'the_content', function( $content ) {
	if (
		is_admin() ||
		! is_singular() ||
		! in_the_loop() ||
		! is_main_query()
	) {
		return $content;
	}

	if ( ! is_string( $content ) || '' === trim( $content ) ) {
		return $content;
	}

	$settings = akl_get_settings();

	if ( empty( $settings['pairs'] ) ) {
		return $content;
	}

	if ( akl_is_blacklisted( $settings['bl'] ) ) {
		return $content;
	}

	$rules = akl_parse_pairs( $settings['pairs'] );

	if ( empty( $rules ) ) {
		return $content;
	}

	$limit           = max( 1, absint( $settings['max'] ) );
	$home_host       = wp_parse_url( home_url(), PHP_URL_HOST );
	$current_url     = akl_normalize_url_for_compare( home_url( add_query_arg( [], $_SERVER['REQUEST_URI'] ?? '' ) ) );
	$link_counts     = [];
	$parts           = preg_split( '#(<[^>]+>)#', $content, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
	$result          = '';
	$skip_stack      = [];

	foreach ( $parts as $part ) {
		if ( isset( $part[0] ) && '<' === $part[0] ) {
			if ( preg_match( '#^<(a|code|pre|script|style|h[1-6])\b#i', $part, $matches ) ) {
				$skip_stack[] = strtolower( $matches[1] );
			}

			if ( preg_match( '#^</(a|code|pre|script|style|h[1-6])>#i', $part ) ) {
				array_pop( $skip_stack );
			}

			$result .= $part;
			continue;
		}

		if ( ! empty( $skip_stack ) ) {
			$result .= $part;
			continue;
		}

		$text = $part;

		foreach ( $rules as $rule ) {
			$key     = $rule['k'];
			$url     = $rule['u'];
			$key_idx = $rule['k_key'];

			if ( ( $link_counts[ $key_idx ] ?? 0 ) >= $limit ) {
				continue;
			}

			$pattern = '#(?<!\pL)' . preg_quote( $key, '#' ) . '(?!\pL)#iu';

			$text = preg_replace_callback(
				$pattern,
				function( $matches ) use ( &$link_counts, $key_idx, $limit, $url, $home_host, $settings, $current_url ) {
					if ( ( $link_counts[ $key_idx ] ?? 0 ) >= $limit ) {
						return $matches[0];
					}

					$target_url = akl_normalize_url_for_compare( $url );

					if ( '' !== $current_url && '' !== $target_url && $current_url === $target_url ) {
						return $matches[0];
					}

					$link_counts[ $key_idx ]++;

					$target_host = wp_parse_url( $url, PHP_URL_HOST );
					$is_external = $target_host && $home_host && strtolower( $target_host ) !== strtolower( $home_host );

					$attributes = '';

					if ( $is_external ) {
						if ( ! empty( $settings['tab'] ) ) {
							$attributes .= ' target="_blank"';
						}

						if ( ! empty( $settings['nf'] ) ) {
							$attributes .= ' rel="nofollow noopener noreferrer"';
						} elseif ( ! empty( $settings['tab'] ) ) {
							$attributes .= ' rel="noopener noreferrer"';
						}
					}

					return '<a href="' . esc_url( $url ) . '"' . $attributes . '>' . $matches[0] . '</a>';
				},
				$text,
				1
			);
		}

		$result .= $text;
	}

	return $result;
}, 20 );
