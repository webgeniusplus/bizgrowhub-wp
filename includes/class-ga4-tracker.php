<?php
namespace InsightHub;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * GA4 Tracker — Injects Google Analytics 4 gtag.js on the frontend.
 * 
 * Measurement ID is set from the BizGrowHub dashboard via REST API.
 * When measurement_id exists → script auto-injected on all frontend pages.
 * Admin pages are excluded.
 */
class GA4_Tracker {

    private $measurement_id;

    public function __construct() {
        // Only on frontend, not admin
        if ( is_admin() ) {
            return;
        }

        $this->measurement_id = get_option( INSIGHT_HUB_OPTION_GA4_MEASUREMENT_ID, '' );

        if ( empty( $this->measurement_id ) ) {
            return;
        }

        // Validate format (G-XXXXXXXXXX)
        if ( ! preg_match( '/^G-[A-Z0-9]+$/i', $this->measurement_id ) ) {
            error_log( 'INSIGHT_HUB GA4: Invalid measurement ID format: ' . $this->measurement_id );
            return;
        }

        add_action( 'wp_head', [ $this, 'inject_gtag' ], 1 );
    }

    /**
     * Inject gtag.js script in <head>
     * Priority 1 = as early as possible (Google recommends it high in <head>)
     */
    public function inject_gtag() {
        $id = esc_attr( $this->measurement_id );
        ?>
<!-- BizGrowHub GA4 -->
<script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo $id; ?>"></script>
<script>
window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}
gtag('js', new Date());
gtag('config', '<?php echo $id; ?>');
</script>
<!-- /BizGrowHub GA4 -->
        <?php
    }
}
