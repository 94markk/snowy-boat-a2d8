<?php
namespace Delicat\V10\Admin;

use Delicat\V10\Assets;
use Delicat\V10\Context;
use Delicat\V10\Design\Tokens;
use Delicat\V10\Kernel;
use Delicat\V10\Module;
use Delicat\V10\Support\Guard;

defined( 'ABSPATH' ) || exit;

/**
 * One screen that answers "is V10 working, and if not, why not".
 *
 * V9 had a self-test page, a diagnostics overlay, a release centre, a production
 * centre and six failure ledgers, and three of those ledgers were written to by
 * code that nothing ever read - so the faults they recorded were invisible to
 * everyone including the people looking for them.
 *
 * The rule here is that every check either shows a problem a merchant can act
 * on, or it does not exist.
 */
final class Health extends Module {

	public static function kinds(): array {
		return array( Context::KIND_ADMIN );
	}

	public function register(): void {
		add_filter( 'site_status_tests', array( $this, 'site_health' ) );
	}

	/**
	 * Reported through WordPress's own Site Health rather than a bespoke page,
	 * so it appears where a merchant or their host already looks.
	 *
	 * @param array<string,array<string,mixed>> $tests
	 * @return array<string,array<string,mixed>>
	 */
	public function site_health( $tests ): array {
		$tests = is_array( $tests ) ? $tests : array();

		$tests['direct']['delicat_v10'] = array(
			'label' => __( 'Delicat Storefront V10', 'delicat-v10' ),
			'test'  => array( __CLASS__, 'run' ),
		);

		return $tests;
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function run(): array {
		$problems = self::problems();

		if ( array() === $problems ) {
			return array(
				'label'       => __( 'Delicat Storefront V10 is healthy', 'delicat-v10' ),
				'status'      => 'good',
				'badge'       => array( 'label' => __( 'Boutique', 'delicat-v10' ), 'color' => 'blue' ),
				'description' => '<p>' . esc_html(
					sprintf(
						/* translators: %d: number of modules. */
						__( '%d modules loaded for this request. Assets are built and content-addressed. No failures recorded.', 'delicat-v10' ),
						count( Kernel::instance()->booted() )
					)
				) . '</p>',
				'test'        => 'delicat_v10',
			);
		}

		$list = '';
		foreach ( $problems as $problem ) {
			$list .= '<li>' . esc_html( $problem ) . '</li>';
		}

		return array(
			'label'       => __( 'Delicat Storefront V10 needs attention', 'delicat-v10' ),
			'status'      => 'recommended',
			'badge'       => array( 'label' => __( 'Boutique', 'delicat-v10' ), 'color' => 'orange' ),
			'description' => '<ul>' . $list . '</ul>',
			'test'        => 'delicat_v10',
		);
	}

	/**
	 * Things that are actually wrong, in the words a merchant would use.
	 *
	 * @return string[]
	 */
	public static function problems(): array {
		$problems = array();

		if ( array() === Assets::manifest() ) {
			$problems[] = __( 'The stylesheet and scripts have not been built. Run `node build/build.mjs` in the plugin folder; until then the storefront falls back to unversioned assets.', 'delicat-v10' );
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			$problems[] = __( 'WooCommerce is not active. V10 renders a storefront; WooCommerce is what makes it a shop.', 'delicat-v10' );
		}

		$disabled = get_option( Guard::DISABLED, array() );
		if ( is_array( $disabled ) && array() !== $disabled ) {
			foreach ( array_keys( $disabled ) as $scope ) {
				$problems[] = sprintf(
					/* translators: %s: the name of the disabled module. */
					__( '%s was switched off after repeated failures. Deactivate and reactivate the plugin to let it try again.', 'delicat-v10' ),
					(string) $scope
				);
			}
		}

		$ledger = get_option( Guard::LEDGER, array() );
		if ( is_array( $ledger ) && array() !== $ledger ) {
			$recent = 0;
			foreach ( $ledger as $entry ) {
				if ( is_array( $entry ) && ( time() - (int) ( $entry['at'] ?? 0 ) ) < DAY_IN_SECONDS ) {
					$recent++;
				}
			}
			if ( $recent > 0 ) {
				$first      = $ledger[0];
				$problems[] = sprintf(
					/* translators: 1: number of errors, 2: the most recent error message, 3: file, 4: line. */
					__( '%1$d error(s) recorded in the last day. Most recent: "%2$s" in %3$s line %4$d.', 'delicat-v10' ),
					$recent,
					(string) ( $first['message'] ?? '' ),
					(string) ( $first['file'] ?? '' ),
					(int) ( $first['line'] ?? 0 )
				);
			}
		}

		/*
		 * A token group that has lost its keys means a filter has replaced
		 * rather than merged - which produces a storefront with no colours and
		 * is otherwise very hard to diagnose.
		 */
		$tokens = Tokens::all();
		foreach ( array( 'color', 'color-dark', 'space', 'chrome', 'layer' ) as $group ) {
			if ( empty( $tokens[ $group ] ) || ! is_array( $tokens[ $group ] ) ) {
				$problems[] = sprintf(
					/* translators: %s: the name of the token group. */
					__( 'The "%s" design tokens are missing. A plugin filtering delicat_v10_tokens has replaced the set instead of merging into it.', 'delicat-v10' ),
					$group
				);
			}
		}

		return $problems;
	}
}
