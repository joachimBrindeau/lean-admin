<?php

declare(strict_types=1);

/**
 * Admin Tweaks module.
 *
 * Admin-only WordPress chrome cleanups. Each tweak is an opt-in toggle stored
 * in a single option and applied only in wp-admin.
 */

namespace LeanAdmin\AdminTweaks;

use LeanAdmin\Config\PluginConstants;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AdminTweaksModule {

    /** Option storing the enabled map (slug => bool). */
    public const OPTION = PluginConstants::OPTION_ADMIN_TWEAKS;

	private const PAGE  = 'lean-admin-tweaks';
	private const GROUP = PluginConstants::ADMIN_TWEAKS_GROUP;

	private const LEAN_SEO_TWEAK_OPTION = 'lean_seo_tweak';

    /** @var array<string, array{label:string,description:string,default:bool,callback:callable}> */
    private array $tweaks;

    public function __construct() {
        $this->tweaks = self::definitions();
    }

    public function register(): void {
        add_action( 'admin_menu', [ $this, 'registerPage' ], 101 );
        add_action( 'admin_init', [ $this, 'registerSettings' ] );

        // Admin-only chrome: never attach these cleanup hooks on the frontend.
        if ( is_admin() ) {
            $this->applyEnabled();
        }
    }

	/**
	 * Enabled map merged over per-tweak defaults.
	 *
	 * @return array<string, bool>
	 */
	public function enabledMap(): array {
		$saved = get_option( self::OPTION, [] );
		$saved = is_array( $saved ) ? $saved : [];
		$saved = $this->migrateLeanSeoAdminTweaks( $saved );

		$map = [];
		foreach ( $this->tweaks as $key => $tweak ) {
			$map[ $key ] = isset( $saved[ $key ] ) ? (bool) $saved[ $key ] : $tweak['default'];
		}

		return $map;
	}

	public function applyEnabled(): void {
		$enabled = $this->enabledMap();
		foreach ( $this->tweaks as $key => $tweak ) {
			if ( ! empty( $enabled[ $key ] ) ) {
				( $tweak['callback'] )();
			}
		}
	}

	public function registerPage(): void {
		add_submenu_page(
			'tools.php',
			__( 'Admin Tweaks', 'lean-admin' ),
			__( 'Admin Tweaks', 'lean-admin' ),
			'manage_options',
			self::PAGE,
			[ $this, 'renderPage' ]
		);
	}

	public function registerSettings(): void {
		register_setting(
            self::GROUP,
            self::OPTION,
            [
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize' ],
				'default'           => [],
			]
        );

		add_settings_section( PluginConstants::ADMIN_TWEAKS_SECTION, '', '__return_false', self::PAGE );

		foreach ( $this->tweaks as $key => $tweak ) {
			add_settings_field(
				$key,
				esc_html( $tweak['label'] ),
				[ $this, 'renderField' ],
				self::PAGE,
				PluginConstants::ADMIN_TWEAKS_SECTION,
				[
					'key'         => $key,
					'description' => $tweak['description'],
				]
			);
		}
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, bool>
	 */
	public function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : [];
		$clean = [];
		foreach ( array_keys( $this->tweaks ) as $key ) {
			$clean[ $key ] = ! empty( $input[ $key ] );
		}

		return $clean;
	}

	/**
	 * @param array{key:string,description:string} $args
	 */
	public function renderField( array $args ): void {
		$enabled = $this->enabledMap();
		$key     = $args['key'];

		printf(
			'<label><input type="checkbox" name="%s[%s]" value="1" %s /> %s</label>',
			esc_attr( self::OPTION ),
			esc_attr( $key ),
			checked( $enabled[ $key ] ?? false, true, false ),
			esc_html( $args['description'] )
		);
	}

	public function renderPage(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'lean-admin' ), 403 );
		}
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php echo esc_html__( 'Admin Tweaks', 'lean-admin' ); ?></h1>
			<p class="description"><?php echo esc_html__( 'Opt-in cleanups for the WordPress admin chrome.', 'lean-admin' ); ?></p>
			<form action="options.php" method="post">
				<?php
				settings_fields( self::GROUP );
				do_settings_sections( self::PAGE );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

    /**
     * Tweak catalog. Callbacks are self-contained (only core WP calls) so they
     * port cleanly and add their own hooks when invoked.
     *
     * @return array<string, array{label:string,description:string,default:bool,callback:callable}>
     */
    public static function definitions(): array {
        return [
            'clean_admin_bar'    => [
                'label'       => 'Clean admin bar',
                'description' => 'Remove the WordPress logo, comments, and new-content nodes from the admin bar.',
                'default'     => false,
                'callback'    => static function (): void {
                    add_action(
                        'wp_before_admin_bar_render',
                        static function (): void {
							global $wp_admin_bar;
							if ( ! $wp_admin_bar ) {
								return;
							}
							$wp_admin_bar->remove_node( 'wp-logo' );
							$wp_admin_bar->remove_node( 'comments' );
							$wp_admin_bar->remove_node( 'new-content' );
						}
                    );
                },
            ],
            'clean_dashboard'    => [
                'label'       => 'Clean dashboard',
                'description' => 'Remove the default dashboard widgets and the welcome panel.',
                'default'     => false,
                'callback'    => static function (): void {
                    add_action(
                        'wp_dashboard_setup',
                        static function (): void {
							remove_meta_box( 'dashboard_primary', 'dashboard', 'side' );
							remove_meta_box( 'dashboard_quick_press', 'dashboard', 'side' );
							remove_meta_box( 'dashboard_right_now', 'dashboard', 'normal' );
							remove_meta_box( 'dashboard_activity', 'dashboard', 'normal' );
							remove_meta_box( 'dashboard_site_health', 'dashboard', 'normal' );
							remove_action( 'welcome_panel', 'wp_welcome_panel' );
						}
                    );
                },
            ],
            'clean_admin_footer' => [
                'label'       => 'Clean admin footer',
                'description' => 'Hide the WordPress version and the “Thank you for creating with WordPress” footer text.',
                'default'     => false,
                'callback'    => static function (): void {
                    add_filter( 'update_footer', '__return_empty_string', 11 );
                    add_filter( 'admin_footer_text', '__return_empty_string', 11 );
                },
            ],
            'hide_comments_ui'   => [
                'label'       => 'Hide comments UI',
                'description' => 'Remove the Comments menu and comment metaboxes from admin screens.',
                'default'     => false,
                'callback'    => static function (): void {
                    add_action(
                        'admin_menu',
                        static function (): void {
							remove_menu_page( 'edit-comments.php' );
						}
                    );
                    add_action(
                        'admin_init',
                        static function (): void {
							foreach ( get_post_types() as $post_type ) {
								remove_meta_box( 'commentsdiv', $post_type, 'normal' );
								remove_meta_box( 'commentstatusdiv', $post_type, 'normal' );
							}
						}
                    );
                },
            ],
        ];
    }

	/**
	 * Preserve old Lean SEO admin chrome settings once.
	 *
	 * @param array<string, mixed> $saved
	 * @return array<string, mixed>
	 */
	private function migrateLeanSeoAdminTweaks( array $saved ): array {
		$lean_seo_tweaks = get_option( self::LEAN_SEO_TWEAK_OPTION, [] );
		if ( ! is_array( $lean_seo_tweaks ) ) {
			return $saved;
		}

		$changed = false;
		foreach (
			[
				'clean_admin_bar'    => 'clean_admin_bar',
				'clean_dashboard'    => 'clean_dashboard',
				'clean_admin_footer' => 'clean_admin_footer',
				'hide_comments_ui'   => 'disable_comments',
			] as $target_key => $lean_seo_key
		) {
			if ( array_key_exists( $target_key, $saved ) || ! array_key_exists( $lean_seo_key, $lean_seo_tweaks ) ) {
				continue;
			}

			$saved[ $target_key ] = $this->leanSeoTweakEnabled( $lean_seo_tweaks[ $lean_seo_key ] );
			$changed              = true;
		}

		if ( $changed ) {
			update_option( self::OPTION, $this->sanitize( $saved ) );
		}

		return $saved;
	}

	private function leanSeoTweakEnabled( mixed $entry ): bool {
		if ( is_array( $entry ) ) {
			return ! empty( $entry['value'] );
		}

		return ! empty( $entry );
	}
}
