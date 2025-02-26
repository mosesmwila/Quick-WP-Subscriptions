<?php
/*
Plugin Name: Zareat Subscription Manager
Description: Restricts content access based on user package, requires manual admin approval, and handles monthly expiration with invoice management.
Version: 1.0
Author: Moses Mwila
*/

if ( ! defined( 'ABSPATH' ) ) exit;

class Manual_Subscription_Manager {

    private $table_name;
    
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'manual_subscriptions';
        
        register_activation_hook( __FILE__, array( $this, 'create_table' ) );
        add_action( 'admin_menu', array( $this, 'register_admin_pages' ) );
        add_shortcode( 'protected_content', array( $this, 'protected_content_shortcode' ) );
        add_action( 'init', array( $this, 'process_subscription_requests' ) );
        
        // Schedule daily expiration check if not already scheduled
        if ( ! wp_next_scheduled( 'msm_daily_expiration_check' ) ) {
            wp_schedule_event( time(), 'daily', 'msm_daily_expiration_check' );
        }
        add_action( 'msm_daily_expiration_check', array( $this, 'check_expirations' ) );
    }
    
    // Create the custom table on plugin activation
    public function create_table() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$this->table_name} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            package varchar(50) NOT NULL,
            start_date datetime NOT NULL,
            expiry_date datetime NOT NULL,
            approved tinyint(1) DEFAULT 0,
            invoice_url varchar(255) DEFAULT '',
            PRIMARY KEY  (id)
        ) $charset_collate;";
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );
    }
    
    // Admin menu pages
    public function register_admin_pages() {
        add_menu_page( 'Subscriptions', 'Subscriptions', 'manage_options', 'msm_subscriptions', array( $this, 'admin_subscriptions_page' ), 'dashicons-admin-users', 26 );
        add_submenu_page( 'msm_subscriptions', 'Invoices', 'Invoices', 'manage_options', 'msm_invoices', array( $this, 'admin_invoices_page' ) );
    }
    
    // Admin page for managing subscriptions
    public function admin_subscriptions_page() {
        global $wpdb;
        $subscriptions = $wpdb->get_results( "SELECT * FROM {$this->table_name} ORDER BY id DESC" );
        ?>
        <div class="wrap">
            <h1>Subscription Requests</h1>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>User</th>
                        <th>Package</th>
                        <th>Start Date</th>
                        <th>Expiry Date</th>
                        <th>Approved</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $subscriptions as $sub ) : 
                    $user = get_userdata( $sub->user_id );
                ?>
                    <tr>
                        <td><?php echo esc_html( $sub->id ); ?></td>
                        <td><?php echo esc_html( $user ? $user->display_name : 'Unknown' ); ?></td>
                        <td><?php echo esc_html( $sub->package ); ?></td>
                        <td><?php echo esc_html( $sub->start_date ); ?></td>
                        <td><?php echo esc_html( $sub->expiry_date ); ?></td>
                        <td><?php echo $sub->approved ? 'Yes' : 'No'; ?></td>
                        <td>
                            <?php if ( ! $sub->approved ) : ?>
                                <a href="<?php echo admin_url( 'admin-post.php?action=msm_approve_subscription&id=' . $sub->id ); ?>">Approve</a>
                            <?php else : ?>
                                -
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
    
    // Admin page for invoices
    public function admin_invoices_page() {
        global $wpdb;
        $subscriptions = $wpdb->get_results( "SELECT * FROM {$this->table_name} WHERE invoice_url != '' ORDER BY id DESC" );
        ?>
        <div class="wrap">
            <h1>User Invoices</h1>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>User</th>
                        <th>Invoice</th>
                        <th>Package</th>
                        <th>Expiry Date</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $subscriptions as $sub ) : 
                    $user = get_userdata( $sub->user_id );
                ?>
                    <tr>
                        <td><?php echo esc_html( $sub->id ); ?></td>
                        <td><?php echo esc_html( $user ? $user->display_name : 'Unknown' ); ?></td>
                        <td><a href="<?php echo esc_url( $sub->invoice_url ); ?>" target="_blank">Download</a></td>
                        <td><?php echo esc_html( $sub->package ); ?></td>
                        <td><?php echo esc_html( $sub->expiry_date ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
    
    // Handle subscription approval via admin
    public function process_subscription_requests() {
        if ( isset( $_GET['action'] ) && $_GET['action'] == 'msm_approve_subscription' && isset( $_GET['id'] ) ) {
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( 'Unauthorized user' );
            }
            global $wpdb;
            $sub_id = intval( $_GET['id'] );
            $subscription = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table_name} WHERE id = %d", $sub_id ) );
            if ( $subscription && ! $subscription->approved ) {
                // Approve the subscription, set start_date as now and expiry_date 30 days from now
                $now = current_time( 'mysql' );
                $expiry = date( 'Y-m-d H:i:s', strtotime( '+30 days', current_time( 'timestamp' ) ) );
                $wpdb->update( $this->table_name, array(
                    'approved'    => 1,
                    'start_date'  => $now,
                    'expiry_date' => $expiry,
                ), array( 'id' => $sub_id ) );
                
                // Send email notification to user
                $user = get_userdata( $subscription->user_id );
                if ( $user ) {
                    wp_mail( $user->user_email, 'Subscription Approved', 'Your subscription has been approved and is active until ' . $expiry );
                }
                wp_redirect( admin_url( 'admin.php?page=msm_subscriptions' ) );
                exit;
            }
        }
    }
    
    // Shortcode to restrict content
    // Usage: [protected_content]Your protected content here[/protected_content]
    public function protected_content_shortcode( $atts, $content = null ) {
        if ( ! is_user_logged_in() ) {
            return '<p>Please log in to view this content.</p>';
        }
        global $wpdb;
        $user_id = get_current_user_id();
        $subscription = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table_name} WHERE user_id = %d AND approved = 1 ORDER BY id DESC LIMIT 1", $user_id ) );
        if ( $subscription ) {
            $current_time = current_time( 'mysql' );
            if ( strtotime( $current_time ) > strtotime( $subscription->expiry_date ) ) {
                return '<p>Your subscription has expired. Please renew to regain access.</p>';
            }
            return do_shortcode( $content );
        }
        return '<p>You do not have an active subscription.</p>';
    }
    
    // Daily check for expired subscriptions, send notification and revoke access if needed.
    public function check_expirations() {
        global $wpdb;
        $now = current_time( 'mysql' );
        $expired = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table_name} WHERE approved = 1 AND expiry_date < %s", $now ) );
        foreach ( $expired as $subscription ) {
            // Notify user about expiration if not already notified.
            $user = get_userdata( $subscription->user_id );
            if ( $user ) {
                wp_mail( $user->user_email, 'Subscription Expired', 'Your subscription expired on ' . $subscription->expiry_date . '. Please renew your subscription.' );
            }
            // Optionally, update the record or take further action.
        }
    }
}

// Initialize the plugin
new Manual_Subscription_Manager();

// Clean up scheduled event on plugin deactivation.
register_deactivation_hook( __FILE__, function() {
    wp_clear_scheduled_hook( 'msm_daily_expiration_check' );
} );
