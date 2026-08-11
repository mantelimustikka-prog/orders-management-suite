<?php
/**
 * Plugin Name: Centralized Network Orders - All-in-One (Orders + SMS + Auto Status)
 * Plugin URI:  https://example.com/
 * Description: Network master view of orders + TextMagic SMS notifications (status-driven templates) + automatic order status change rules. Single-file plugin combining the network orders UI, SMS, and auto status features plus a modal to add shipment tracking.
 * Version: 1.0.6
 * Author: Custom Snippet
 * License: GPLv2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
//require_once('includes/network-packing-slip.php');
/* -------------------------------------------------------------------------
 * CONSTANTS / OPTION KEYS
 * ------------------------------------------------------------------------- */
define( 'RC_TNO_OPTION_PREFIX', 'rc_tno_' );
define( 'RC_TEXTMAGIC_OPTION_KEY', RC_TNO_OPTION_PREFIX . 'textmagic_settings' );
define( 'RC_TEXTMAGIC_TEMPLATES_KEY', RC_TNO_OPTION_PREFIX . 'textmagic_templates' );
define( 'RC_TEXTMAGIC_LOGS_KEY', RC_TNO_OPTION_PREFIX . 'textmagic_logs' );
define( 'RC_AUTO_STATUS_RULES_OPTION', RC_TNO_OPTION_PREFIX . 'auto_status_rules' );

/* -------------------------------------------------------------------------
 * NETWORK ORDERS (Network Admin) - Master view + bulk actions + preview
 * ------------------------------------------------------------------------- */

/* Helper: build URL for the network orders admin page */
if ( ! function_exists( 'rc_build_orders_url_final' ) ) {
    function rc_build_orders_url_final( $args = array() ) {
        $base = network_admin_url( 'admin.php?page=network-orders-view' );
        $current = array();
        if ( isset( $_GET['s'] ) ) $current['s'] = wp_unslash( $_GET['s'] );
        if ( isset( $_GET['status_filter'] ) ) $current['status_filter'] = wp_unslash( $_GET['status_filter'] );
        if ( isset( $_GET['per_page'] ) ) $current['per_page'] = wp_unslash( $_GET['per_page'] );
        $all = array_merge( $current, $args );
        return esc_url_raw( add_query_arg( $all, $base ) );
    }
}

/* Temporary hook flag callback used to detect whether WC fired the hook */
if ( ! function_exists( 'rc_network_orders_status_change_flag' ) ) {
    function rc_network_orders_status_change_flag( $order_id, $old_status, $new_status, $order ) {
        if ( ! isset( $GLOBALS['rc_network_orders_hook_fired'] ) || ! is_array( $GLOBALS['rc_network_orders_hook_fired'] ) ) {
            $GLOBALS['rc_network_orders_hook_fired'] = array();
        }
        $blog_id = function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 0;
        $key = intval( $blog_id ) . ':' . intval( $order_id );
        $GLOBALS['rc_network_orders_hook_fired'][ $key ] = true;
    }
}

/* Network-level menu (master orders view) */
add_action( 'network_admin_menu', 'rc_add_central_orders_menu_final' );
function rc_add_central_orders_menu_final() {
    add_menu_page(
        'Network Orders',
        'Network Orders',
        'manage_network',
        'network-orders-view',
        'rc_render_central_orders_table_final',
        'dashicons-cart',
        25
    );
}


/* Enqueue CSS & JS for network/admin views */
add_action( 'network_admin_enqueue_scripts', 'rc_network_orders_enqueue_scripts_final' );
add_action( 'admin_enqueue_scripts', 'rc_network_orders_enqueue_scripts_final' );
function rc_network_orders_enqueue_scripts_final( $hook ) {
    if ( ! ( isset( $_GET['page'] ) && in_array( $_GET['page'], array( 'network-orders-view', 'network-orders-view-site' ), true ) ) ) {
        return;
    }

    wp_register_style( 'rc-network-orders-admin-css', false );
    wp_enqueue_style( 'rc-network-orders-admin-css' );

    $css = '
    .rc-notice { padding:8px 10px; margin:8px 0; border-left:4px solid #46b450; background:#f1fff4; display:inline-block; }
    .rc-notice.error { border-color:#d63638; background:#fff1f1; }
    .rc-bulk-toolbar { display:flex; gap:8px; align-items:center; }
    .rc-bulk-summary { margin-left:10px; color:#555; font-size:13px; }
    .rc-products { font-size:13px; color:#333; }
    .rc-status-badge { min-width:58px; text-align:center; display:inline-block; }
    .rc-row-msg { display:block; font-size:12px; margin-top:4px; }
    .rc-actions { display:flex; gap:6px; align-items:center; flex-wrap:wrap; }
    .rc-modal-overlay { position:fixed; inset:0; background:rgba(0,0,0,0.6); z-index:99999; display:flex; align-items:center; justify-content:center; padding:20px; }
    .rc-modal { background:#fff; border-radius:6px; max-width:800px; width:100%; max-height:90vh; overflow:auto; box-shadow:0 10px 30px rgba(0,0,0,0.3); padding:18px; position:relative; }
    .rc-modal .rc-modal-close { position:absolute; top:8px; right:8px; border:none; background:transparent; font-size:18px; cursor:pointer; color:#666; }
    .rc-modal h2 { margin-top:0; }
    .rc-order-meta { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:12px; }
    .rc-line-items { width:100%; border-collapse:collapse; margin-bottom:12px; }
    .rc-line-items th, .rc-line-items td { border:1px solid #e5e5e5; padding:8px; text-align:left; }
    .rc-line-items th { background:#fafafa; font-weight:600; }
    @media (max-width:700px) { .rc-order-meta { flex-direction:column; } }
    .table-view-list th:nth-last-child(1), .table-view-list td:nth-last-child(1) { width: 8% !important; min-width: 90px; }
    .rc-country-code { font-size:1.2em; color:#8c8f94; font-weight:600; text-transform:uppercase; display:inline-block; margin-top:2px; }
    .rc-contact { font-size:13px; color:#333; line-height:1.3; }
    .rc-status-bar { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:12px; align-items:center; }
    .rc-status-pill { display:inline-flex; gap:8px; align-items:center; padding:6px 10px; border-radius:20px; background:#f1f1f1; font-size:13px; color:#222; text-decoration:none; border:1px solid #e1e1e1; }
    .rc-status-pill.active { background:#0073aa; color:#fff; border-color:#0073aa; }
    .rc-status-update-group { display:inline-flex; gap:6px; align-items:center; flex-wrap:nowrap; white-space:nowrap; }
    .rc-status-update-group .button { margin:0; white-space:nowrap; flex: 0 0 auto; }
    .rc-status-update-group select { min-width:140px; max-width:260px; flex: 0 0 auto; }
    @media (max-width:640px) {
        .rc-status-update-group { display:flex; flex-wrap:wrap; }
        .rc-status-update-group select { min-width:120px; }
    }
    .rc-pagination { display:flex; gap:6px; align-items:center; flex-wrap:nowrap; overflow:auto; max-width:640px; }
    .rc-pagination .button, .rc-pagination span.button { flex: 0 0 auto; white-space:nowrap; }
    @media (max-width:640px) {
        .rc-pagination { max-width:360px; }
    }
    .rc-controls-right { display:flex; gap:12px; align-items:center; }
    .rc-search-row { width:100%; margin-top:10px; display:flex; justify-content:flex-start; gap:8px; align-items:center; }
    .rc-search-row input[type="search"] { width:360px; max-width:100%; }
    @media (max-width:640px) {
        .rc-search-row { flex-direction:column; align-items:flex-start; gap:6px; }
        .rc-search-row input[type="search"] { width:100%; }
    }
    .rc-track-success { color: #2b9d2b; font-weight:600; margin-left:6px; font-size:12px; display:inline-block; vertical-align:middle; margin-top:2px; }
    ';

    wp_add_inline_style( 'rc-network-orders-admin-css', $css );

    wp_register_script( 'rc-network-orders-js', false, array( 'jquery' ), '1.0', true );
    wp_enqueue_script( 'rc-network-orders-js' );

    // Carrier list used by "Track Shipment" plugin (common carriers). 'Royal Mail' default.
    $carriers = array(
        'Royal Mail',
        'DHL',
        'DPD',
        'UPS',
        'FedEx',
        'USPS',
        'GLS',
        'Hermes',
        'TNT',
        'Parcelforce',
        'Canada Post',
        'Australia Post',
        'Aramex',
        'China Post',
        'EMS',
        'Other',
    );

    $ajax_data = array(
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'rc_network_orders_ajax' ),
        'carriers' => $carriers,
    );
    wp_localize_script( 'rc-network-orders-js', 'rcNetworkOrders', $ajax_data );

    $script = <<<'JS'
(function($){
    'use strict';
    if ( typeof window.rcNetworkOrders === 'undefined' ) {
        window.rcNetworkOrders = { ajax_url: (typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php'), nonce: '' };
    }

    function escapeHtml(str) {
        if ( typeof str !== 'string' ) return str;
        return str.replace(/&/g, '&amp;').replace(/</g, '&lt;')
                  .replace(/>/g, '&gt;').replace(/"/g, '&quot;')
                  .replace(/'/g, '&#039;');
    }

    function badgeColor(status) {
        if (status === 'processing') return '#c6e1c6';
        if (status === 'completed') return '#e5e5e5';
        if (status === 'ghost') return '#f3f3f3';
        if (['failed','cancelled','refunded'].indexOf(status) !== -1) return '#f8b9b9';
        if (status === 'shipped') return '#cfeef2';
        return '#f8dda7';
    }

    $(function(){
        // Bulk select summary
        $(document).on('change', '#rc-select-all', function(){
            var checked = $(this).is(':checked');
            $('input[name="rc_bulk_select[]"]').prop('checked', checked);
            updateBulkSummary();
        });
        $(document).on('change', 'input[name="rc_bulk_select[]"]', updateBulkSummary);
        function updateBulkSummary(){ var count = $('input[name="rc_bulk_select[]"]:checked').length; $('.rc-bulk-summary').text(count + ' selected'); }

        // Bulk update (legacy button)
        $(document).on('click', '#rc-bulk-update-btn', function(e){
            e.preventDefault();
            var new_status = $('#rc-bulk-status-select').val();
            var selected = [];
            $('input[name="rc_bulk_select[]"]:checked').each(function(){ selected.push($(this).val()); });
            if ( selected.length === 0 ) { alert('No orders selected.'); return; }
            if ( ! confirm('Update ' + selected.length + ' orders to status: ' + new_status + '?' ) ) { return; }
            $('#rc-bulk-update-btn, #rc-bulk-status-select').prop('disabled', true);
            $.post(rcNetworkOrders.ajax_url, {
                action: 'rc_bulk_update_network_order_status_ajax',
                security: rcNetworkOrders.nonce,
                items: selected,
                new_status: new_status
            }).done(function(response){
                if ( response && response.success ) {
                    var updated = response.data.updated || [], errors = response.data.errors || [];
                    updated.forEach(function(key){
                        var parts = key.split(':'); var blogid = parts[0], orderid = parts[1];
                        var selector = 'tr[data-blog-id="' + blogid + '"][data-order-id="' + orderid + '"]';
                        var $row = $(selector);
                        $row.find('.rc-status-badge').text(new_status).css('background', badgeColor(new_status));
                    });
                    if ( errors.length ) { alert('Some updates failed: ' + errors.join(', ')); }
                } else {
                    var msg = (response && response.data && response.data.message) ? response.data.message : 'Bulk update failed';
                    alert(msg);
                }
            }).fail(function(xhr,status,err){ console.error('Bulk AJAX error:', status, err, xhr.responseText); alert('Bulk request failed'); })
            .always(function(){ $('#rc-bulk-update-btn, #rc-bulk-status-select').prop('disabled', false); updateBulkSummary(); });
        });

        // Update status using the dropdown + button
        $(document).on('click', '#rc-bulk-update-btn-status', function(e){
            e.preventDefault();
            var selected_status = $('#status_filter').val();
            if ( ! selected_status || selected_status === 'all' ) {
                alert('Please select a specific status in the dropdown to update to (cannot use "All").');
                return;
            }
            var selected = [];
            $('input[name="rc_bulk_select[]"]:checked').each(function(){ selected.push($(this).val()); });
            if ( selected.length === 0 ) {
                alert('No orders selected.');
                return;
            }
            if ( ! confirm('Update ' + selected.length + ' orders to status: ' + selected_status + '?' ) ) {
                return;
            }
            $('#rc-bulk-update-btn-status, #status_filter').prop('disabled', true);

            $.post(rcNetworkOrders.ajax_url, {
                action: 'rc_bulk_update_network_order_status_ajax',
                security: rcNetworkOrders.nonce,
                items: selected,
                new_status: selected_status
            }).done(function(response){
                if ( response && response.success ) {
                    var updated = response.data.updated || [], errors = response.data.errors || [];
                    updated.forEach(function(key){
                        var parts = key.split(':'); var blogid = parts[0], orderid = parts[1];
                        var selector = 'tr[data-blog-id="' + blogid + '"][data-order-id="' + orderid + '"]';
                        var $row = $(selector);
                        $row.find('.rc-status-badge').text(selected_status).css('background', badgeColor(selected_status));
                    });
                    if ( errors.length ) { alert('Some updates failed: ' + errors.join(', ')); }
                } else {
                    var msg = (response && response.data && response.data.message) ? response.data.message : 'Bulk update failed';
                    alert(msg);
                }
            }).fail(function(xhr,status,err){ console.error('Bulk AJAX error (status button):', status, err, xhr.responseText); alert('Bulk request failed'); })
            .always(function(){ $('#rc-bulk-update-btn-status, #status_filter').prop('disabled', false); updateBulkSummary(); });
        });

        /* Order Preview */
        $(document).on('click', '.rc-order-preview-btn', function(e){
            e.preventDefault();
            var $btn = $(this);
            var blog_id = $btn.data('blog-id');
            var order_id = $btn.data('order-id');
            if ( ! blog_id || ! order_id ) { alert('Missing order identifiers.'); return; }
            showModal('<div style="padding:20px; text-align:center;">Loading preview&hellip;</div>');
            $.post(rcNetworkOrders.ajax_url, {
                action: 'rc_get_order_preview_ajax',
                security: rcNetworkOrders.nonce,
                blog_id: blog_id,
                order_id: order_id
            }).done(function(response){
                if ( response && response.success && response.data && response.data.html ) {
                    showModal(response.data.html);
                } else {
                    var msg = (response && response.data && response.data.message) ? response.data.message : 'Could not load preview';
                    showModal('<div style="padding:20px; text-align:center; color:#d63638;">' + msg + '</div>');
                }
            }).fail(function(xhr, status, err){ console.error('Preview AJAX error:', status, err, xhr.responseText); showModal('<div style="padding:20px; text-align:center; color:#d63638;">Request failed</div>'); });
        });

        /* Track Shipment modal open */
        $(document).on('click', '.rc-add-tracking-btn', function(e){
            e.preventDefault();
            var $btn = $(this);
            var blog_id = $btn.data('blog-id');
            var order_id = $btn.data('order-id');
            var order_num = $btn.data('order-num') || '';
            var site_name = $btn.data('site-name') || '';
            if ( ! blog_id || ! order_id ) { alert('Missing order identifiers.'); return; }

            var modalHtml = '<div style="padding:6px 0 12px;"><h2>Add Tracking Number</h2>';
            modalHtml += '<p>Order: <strong>#' + escapeHtml(order_num) + '</strong> — Site: <strong>' + escapeHtml(site_name) + '</strong></p>';
            modalHtml += '<form id="rc-track-form" style="margin-top:8px;">';
            modalHtml += '<table class="form-table"><tbody>';
            modalHtml += '<tr><th><label for="rc_tracking_number">Tracking number</label></th><td><input id="rc_tracking_number" name="tracking_number" type="text" class="regular-text" required /></td></tr>';
            modalHtml += '<tr><th><label for="rc_tracking_carrier">Carrier</label></th><td><select id="rc_tracking_carrier" name="carrier" required style="min-width:220px;">';
            // populate carriers from localized data
            if ( Array.isArray(rcNetworkOrders.carriers) ) {
                for ( var i = 0; i < rcNetworkOrders.carriers.length; i++ ) {
                    var c = rcNetworkOrders.carriers[i];
                    var sel = (c === 'Royal Mail') ? ' selected' : '';
                    modalHtml += '<option value="' + escapeHtml(c) + '"' + sel + '>' + escapeHtml(c) + '</option>';
                }
            } else {
                modalHtml += '<option value="Royal Mail" selected>Royal Mail</option>';
            }
            modalHtml += '</select></td></tr>';
            modalHtml += '<tr><th><label for="rc_tracking_note">Note (optional)</label></th><td><textarea id="rc_tracking_note" name="note" rows="3" style="width:100%;"></textarea></td></tr>';
            modalHtml += '</tbody></table>';
            modalHtml += '<p class="submit"><button type="button" id="rc_track_submit" class="button button-primary">Save Tracking</button> <button type="button" id="rc_track_cancel" class="button">Cancel</button></p>';
            modalHtml += '</form></div>';

            showModal(modalHtml);
            // store identifiers on modal overlay for later use
            $('.rc-modal-overlay').data('rc-blog-id', blog_id).data('rc-order-id', order_id);
        });

        // Submit tracking from modal
        $(document).on('click', '#rc_track_submit', function(e){
            e.preventDefault();
            var $overlay = $('.rc-modal-overlay');
            var blog_id = $overlay.data('rc-blog-id');
            var order_id = $overlay.data('rc-order-id');
            var tracking_number = $.trim($('#rc_tracking_number').val());
            var carrier = $.trim($('#rc_tracking_carrier').val());
            var note = $.trim($('#rc_tracking_note').val());
            if ( tracking_number === '' ) { alert('Please enter a tracking number.'); return; }
            if ( carrier === '' ) { alert('Please select a carrier.'); return; }

            var $btn = $(this);
            $btn.prop('disabled', true).text('Saving…');

            $.post(rcNetworkOrders.ajax_url, {
                action: 'rc_save_tracking_ajax',
                security: rcNetworkOrders.nonce,
                blog_id: blog_id,
                order_id: order_id,
                tracking_number: tracking_number,
                carrier: carrier,
                note: note
            }).done(function(response){
                if ( response && response.success ) {
                    // update UI: show small success indicator in the row and status change if provided
                    var selector = 'tr[data-blog-id="' + blog_id + '"][data-order-id="' + order_id + '"]';
                    var $row = $(selector);
                    if ( $row.length ) {
                        $row.find('.rc-track-success').remove();
                        $row.find('td:last').append('<span class="rc-track-success">Tracking saved</span>');
                        if ( response.data && response.data.new_status ) {
                            $row.find('.rc-status-badge').text(response.data.new_status).css('background', badgeColor(response.data.new_status));
                        }
                    }
                    closeModal();
                } else {
                    var msg = (response && response.data && response.data.message) ? response.data.message : 'Save failed';
                    alert(msg);
                }
            }).fail(function(xhr,status,err){
                console.error('Save tracking AJAX error:', status, err, xhr && xhr.responseText);
                alert('Request failed while saving tracking.');
            }).always(function(){
                $btn.prop('disabled', false).text('Save Tracking');
            });
        });

        $(document).on('click', '#rc_track_cancel', function(e){
            e.preventDefault();
            closeModal();
        });

        function showModal(html){
            var $overlay = $('.rc-modal-overlay');
            if ( $overlay.length === 0 ) {
                var modalHtml = '<div class="rc-modal-overlay" role="dialog" aria-modal="true"><div class="rc-modal" role="document"><button type="button" class="rc-modal-close" aria-label="Close">&times;</button><div class="rc-modal-content"></div></div></div>';
                $overlay = $(modalHtml).appendTo('body');
                $overlay.on('click', function(e){ if ( e.target === this ) closeModal(); });
                $overlay.find('.rc-modal-close').on('click', closeModal);
                $(document).on('keydown.rc_modal', function(e){ if ( e.key === 'Escape' ) closeModal(); });
            }
            $overlay.find('.rc-modal-content').html(html);
            $overlay.show();

            // If the tracking modal is open (tracking input present), focus and allow Enter to submit
            var $trackingInput = $overlay.find('#rc_tracking_number');
            if ( $trackingInput.length ) {
                // small delay to ensure element is focusable (helps in some browsers)
                setTimeout(function(){
                    try {
                        $trackingInput.focus();
                        // select content if any
                        $trackingInput.select();
                    } catch (e) {
                        // ignore
                    }
                }, 40);

                // Bind Enter key on the tracking input to trigger Save button
                $trackingInput.off('keydown.rc_track_enter').on('keydown.rc_track_enter', function(ev){
                    // Support both 'Enter' and keyCode 13 for broader compatibility
                    if ( ev.key === 'Enter' || ev.keyCode === 13 ) {
                        ev.preventDefault();
                        var $submit = $overlay.find('#rc_track_submit');
                        if ( $submit.length ) {
                            $submit.trigger('click');
                        }
                    }
                });
            }
        }
        function closeModal(){ $('.rc-modal-overlay').remove(); $(document).off('keydown.rc_modal'); }

    });
})(jQuery);
JS;
    wp_add_inline_script( 'rc-network-orders-js', $script );
}

/* -------------------------------------------------------------------------
 * AJAX: Get order preview HTML
 * ------------------------------------------------------------------------- */
add_action( 'wp_ajax_rc_get_order_preview_ajax', 'rc_get_order_preview_ajax_fixed' );
function rc_get_order_preview_ajax_fixed() {
    if ( ! current_user_can( 'manage_network' ) ) {
        wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
    }
    check_ajax_referer( 'rc_network_orders_ajax', 'security' );

    $blog_id  = isset( $_POST['blog_id'] ) ? intval( $_POST['blog_id'] ) : 0;
    $order_id = isset( $_POST['order_id'] ) ? intval( $_POST['order_id'] ) : 0;
    if ( ! $blog_id || ! $order_id ) wp_send_json_error( array( 'message' => 'Invalid parameters.' ), 400 );

    switch_to_blog( $blog_id );
    if ( ! class_exists( 'WooCommerce' ) ) { restore_current_blog(); wp_send_json_error( array( 'message' => 'WooCommerce not active on target site.' ), 500 ); }
    $order = wc_get_order( $order_id );
    if ( ! $order ) { restore_current_blog(); wp_send_json_error( array( 'message' => 'Order not found.' ), 404 ); }

    // Site-local statuses mapping
    $site_status_label = '';
    if ( function_exists( 'wc_get_order_statuses' ) ) {
        $site_statuses = wc_get_order_statuses();
        $site_map = array();
        foreach ( $site_statuses as $k => $label ) {
            $slug = preg_replace( '/^wc-/', '', $k );
            $site_map[ $slug ] = $label;
        }
        $order_status_slug = $order->get_status();
        if ( isset( $site_map[ $order_status_slug ] ) ) {
            $site_status_label = $site_map[ $order_status_slug ];
        }
    }
    if ( empty( $site_status_label ) ) {
        $site_status_label = $order->get_status();
    }

    // Build preview HTML
    $order_number = esc_html( $order->get_order_number() );
    $date = $order->get_date_created() ? esc_html( $order->get_date_created()->date( 'Y-m-d H:i' ) ) : '';
    $status_label = esc_html( $site_status_label );
    $customer = esc_html( trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) );
    $email = esc_html( $order->get_billing_email() );
    $phone = esc_html( $order->get_billing_phone() );
    $raw_billing_address = $order->get_formatted_billing_address();
    $billing_address = $raw_billing_address ? wp_kses_post( $raw_billing_address ) : '';
    $raw_shipping_address = $order->get_formatted_shipping_address();
    $shipping_address = $raw_shipping_address ? wp_kses_post( $raw_shipping_address ) : '';
    $total = wp_kses_post( $order->get_formatted_order_total() );
    $edit_url = esc_url( admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' ) );

    $items_html = '<table class="rc-line-items"><thead><tr><th>SKU / Product</th><th>Qty</th><th>Unit</th><th>Line Total</th></tr></thead><tbody>';
    foreach ( $order->get_items() as $item ) {
        $product = $item->get_product();
        if ( $product && is_object( $product ) ) {
            $sku = $product->get_sku();
            $sku = empty($sku) ? 'ID:' . $product->get_id() : esc_html($sku);
            $name = esc_html( $product->get_name() );
        } else {
            $sku = esc_html( $item->get_name() );
            $name = esc_html( $item->get_name() );
        }
        $qty = intval( $item->get_quantity() );
        $unit = wc_price( $item->get_total() / max( 1, $qty ) );
        $line_total = wc_price( $item->get_total() );
        $items_html .= '<tr><td>' . $sku . ' / ' . $name . '</td><td>' . $qty . '</td><td>' . $unit . '</td><td>' . $line_total . '</td></tr>';
    }
    $items_html .= '</tbody></table>';

    $totals_html = '<div><strong>Order Total: </strong>' . $total . '</div>';

    $html = '<div>';
    $html .= '<h2>Order #' . $order_number . ' <small style="color:#666;">' . $date . '</small></h2>';
    $html .= '<div class="rc-order-meta"><div><strong>Status:</strong> <span style="background:#ddd;padding:3px 6px;border-radius:3px;">' . $status_label . '</span></div>';
    $html .= '<div><strong>Customer:</strong> ' . $customer . '</div>';
    if ( $email ) $html .= '<div><strong>Email:</strong> ' . $email . '</div>';
    if ( $phone ) $html .= '<div><strong>Phone:</strong> ' . $phone . '</div>';
    $html .= '</div>';
    $html .= '<h3>Billing Address</h3><div>' . $billing_address . '</div>';
    $html .= '<h3>Shipping Address</h3><div>' . $shipping_address . '</div>';
    $html .= '<h3>Items</h3>' . $items_html;
    $html .= $totals_html;
    $html .= '<div style="margin-top:12px;"><a href="' . $edit_url . '" target="_blank" class="button">Open in site admin</a></div>';
    $html .= '</div>';

    restore_current_blog();
    wp_send_json_success( array( 'html' => $html ) );
}

/* -------------------------------------------------------------------------
 * AJAX: save tracking number for an order (network admin -> child site)
 * ------------------------------------------------------------------------- */
add_action( 'wp_ajax_rc_save_tracking_ajax', 'rc_save_tracking_ajax' );
function rc_save_tracking_ajax() {
    if ( ! current_user_can( 'manage_network' ) ) {
        wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
    }
    check_ajax_referer( 'rc_network_orders_ajax', 'security' );

    $blog_id = isset( $_POST['blog_id'] ) ? intval( $_POST['blog_id'] ) : 0;
    $order_id = isset( $_POST['order_id'] ) ? intval( $_POST['order_id'] ) : 0;
    $tracking = isset( $_POST['tracking_number'] ) ? sanitize_text_field( wp_unslash( $_POST['tracking_number'] ) ) : '';
    $carrier = isset( $_POST['carrier'] ) ? sanitize_text_field( wp_unslash( $_POST['carrier'] ) ) : '';
    $note = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';

    if ( ! $blog_id || ! $order_id || $tracking === '' ) {
        wp_send_json_error( array( 'message' => 'Missing parameters.' ), 400 );
    }

    // Allowed carriers list must match the one localized to JS
    $allowed_carriers = array(
        'Royal Mail','DHL','DPD','UPS','FedEx','USPS','GLS','Hermes','TNT','Parcelforce',
        'Canada Post','Australia Post','Aramex','China Post','EMS','Other'
    );

    if ( $carrier === '' || ! in_array( $carrier, $allowed_carriers, true ) ) {
        wp_send_json_error( array( 'message' => 'Invalid or missing carrier. Please select a carrier from the list.' ), 400 );
    }

    // Switch to child site and save tracking meta
    switch_to_blog( $blog_id );

    if ( ! function_exists( 'wc_get_order' ) ) {
        restore_current_blog();
        wp_send_json_error( array( 'message' => 'WooCommerce not available on target site.' ), 500 );
    }

    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        restore_current_blog();
        wp_send_json_error( array( 'message' => 'Order not found on target site.' ), 404 );
    }

    // Save common meta keys to maximize compatibility with different shipment plugins
    try {
        // 1) simple meta keys
        update_post_meta( $order_id, '_tracking_number', $tracking );
        update_post_meta( $order_id, 'tracking_number', $tracking );

        // 2) _wc_shipment_tracking_items (common plugin formats) - store as single-element array
        $item = array(
            'tracking_provider' => $carrier,
            'tracking_number'   => $tracking,
            'date_shipped'      => current_time( 'mysql' ),
            'formatted_tracking_link' => '',
            'tracking_provider_id' => '',
        );
        // Try to merge with existing arrays if present
        $existing_wc = get_post_meta( $order_id, '_wc_shipment_tracking_items', true );
        if ( ! is_array( $existing_wc ) ) $existing_wc = array();
        $existing_wc[] = $item;
        update_post_meta( $order_id, '_wc_shipment_tracking_items', $existing_wc );

        // 3) _shipment_tracking_items (alternate)
        $existing_alt = get_post_meta( $order_id, '_shipment_tracking_items', true );
        if ( ! is_array( $existing_alt ) ) $existing_alt = array();
        $existing_alt[] = $item;
        update_post_meta( $order_id, '_shipment_tracking_items', $existing_alt );

        // 4) add an order note
        $current_user = wp_get_current_user();
        $note_text = sprintf( 'Tracking number added by %s (network admin): %s', $current_user->user_login, $tracking );
        if ( $carrier ) {
            $note_text .= ' (Carrier: ' . $carrier . ')';
        }
        if ( $note ) {
            $note_text .= ' — ' . $note;
        }
        // Use WC order note API when available
        if ( is_callable( array( $order, 'add_order_note' ) ) ) {
            $order->add_order_note( $note_text );
        } else {
            wp_insert_comment( array(
                'comment_post_ID' => $order_id,
                'comment_author' => $current_user->display_name ?: $current_user->user_login,
                'comment_content' => $note_text,
                'comment_type' => 'order_note',
                'comment_author_IP' => '',
                'comment_agent' => 'network-orders',
                'comment_approved' => 1,
            ) );
        }

        // 5) Change order status to 'completed' (if not already)
        try {
            $current_status = $order->get_status();
            if ( $current_status !== 'completed' ) {
                // Force the order to completed status. This will run WooCommerce hooks for status change.
                $order->update_status( 'completed', 'Order marked Completed after tracking number added via Network Orders.' );
                $new_status = 'completed';
            } else {
                $new_status = $current_status;
            }
        } catch ( \Throwable $e ) {
            // Log if debug
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( sprintf( '[RC-Tracking][ERROR] Failed to set completed status on blog %d order %d: %s', $blog_id, $order_id, $e->getMessage() ) );
            }
            $new_status = $order->get_status();
        }

        restore_current_blog();
        wp_send_json_success( array( 'message' => 'Tracking saved.', 'new_status' => $new_status ) );
    } catch ( Exception $e ) {
        restore_current_blog();
        wp_send_json_error( array( 'message' => 'Failed to save tracking: ' . $e->getMessage() ), 500 );
    }
}

/* -------------------------------------------------------------------------
 * BULK UPDATE HANDLER (same as before)
 * ------------------------------------------------------------------------- */
add_action( 'wp_ajax_rc_bulk_update_network_order_status_ajax', 'rc_bulk_update_network_order_status_ajax_safe' );
function rc_bulk_update_network_order_status_ajax_safe() {
    if ( ! current_user_can( 'manage_network' ) ) {
        wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
    }

    check_ajax_referer( 'rc_network_orders_ajax', 'security' );

    $items = isset( $_POST['items'] ) && is_array( $_POST['items'] ) ? $_POST['items'] : array();
    $new_status = isset( $_POST['new_status'] ) ? sanitize_text_field( wp_unslash( $_POST['new_status'] ) ) : '';

    if ( empty( $new_status ) || ! preg_match( '/^[a-z0-9\-\_]+$/i', $new_status ) ) {
        wp_send_json_error( array( 'message' => 'Invalid status.' ), 400 );
    }

    if ( empty( $items ) ) {
        wp_send_json_error( array( 'message' => 'No orders selected.' ), 400 );
    }

    $max_bulk = 200;
    if ( count( $items ) > $max_bulk ) {
        wp_send_json_error( array( 'message' => "Too many orders selected. Max {$max_bulk}." ), 400 );
    }

    $GLOBALS['rc_network_orders_hook_fired'] = array();
    add_action( 'woocommerce_order_status_changed', 'rc_network_orders_status_change_flag', 10, 4 );

    $updated = array();
    $errors  = array();

    foreach ( $items as $item ) {
        $parts = explode( ':', $item );
        if ( count( $parts ) !== 2 ) {
            $errors[] = $item;
            continue;
        }
        $blog_id = intval( $parts[0] );
        $order_id = intval( $parts[1] );
        if ( ! $blog_id || ! $order_id ) {
            $errors[] = $item;
            continue;
        }

        switch_to_blog( $blog_id );

        if ( ! class_exists( 'WooCommerce' ) ) {
            restore_current_blog();
            $errors[] = $item;
            continue;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            restore_current_blog();
            $errors[] = $item;
            continue;
        }

        $user = wp_get_current_user();
        $note = sprintf( 'Bulk status updated from Network Orders page by %s (user ID %d).', $user->user_login, (int) $user->ID );

        try {
            $old_status = $order->get_status();
            $order->update_status( $new_status, $note );

            $key = intval( $blog_id ) . ':' . intval( $order->get_id() );
            $did_fire = ! empty( $GLOBALS['rc_network_orders_hook_fired'][ $key ] );

            if ( ! $did_fire ) {
                do_action( 'woocommerce_order_status_changed', $order->get_id(), $old_status, $new_status, $order );
                do_action( "woocommerce_order_status_{$new_status}", $order->get_id(), $order );
                do_action( "woocommerce_order_status_{$old_status}_to_{$new_status}", $order->get_id(), $order );
            }

            $updated[] = "{$blog_id}:{$order_id}";
        } catch ( \Throwable $e ) {
            $errors[] = "{$blog_id}:{$order_id}";
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( sprintf( '[NetworkOrders][ERROR] blog:%d order:%d update failed: %s', $blog_id, $order_id, $e->getMessage() ) );
            }
        }

        restore_current_blog();
    }

    remove_action( 'woocommerce_order_status_changed', 'rc_network_orders_status_change_flag', 10 );
    unset( $GLOBALS['rc_network_orders_hook_fired'] );

    wp_send_json_success( array( 'updated' => $updated, 'errors' => $errors ) );
}

/* -------------------------------------------------------------------------
 * Helper: search matching
 * ------------------------------------------------------------------------- */
function rc_order_matches_search_final( $order, $q ) {
    if ( $q === '' ) return true;
    $q = mb_strtolower( trim( $q ) );
    if ( ! empty( $order['first_name'] ) && mb_stripos( mb_strtolower( $order['first_name'] ), $q ) !== false ) return true;
    if ( ! empty( $order['last_name'] ) && mb_stripos( mb_strtolower( $order['last_name'] ), $q ) !== false ) return true;
    if ( ! empty( $order['customer'] ) && mb_stripos( mb_strtolower( $order['customer'] ), $q ) !== false ) return true;
    if ( ! empty( $order['order_num'] ) && mb_stripos( mb_strtolower( (string) $order['order_num'] ), $q ) !== false ) return true;
    if ( ! empty( $order['email'] ) && mb_stripos( mb_strtolower( $order['email'] ), $q ) !== false ) return true;
    if ( ! empty( $order['zip'] ) && mb_stripos( mb_strtolower( $order['zip'] ), $q ) !== false ) return true;
    if ( ! empty( $order['phone'] ) ) {
        $phone_clean = preg_replace('/\D+/', '', $order['phone']);
        $q_digits = preg_replace('/\D+/', '', $q);
        if ( $q_digits !== '' ) {
            if ( strpos( $phone_clean, $q_digits ) !== false ) return true;
        } else {
            if ( mb_stripos( mb_strtolower( $order['phone'] ), $q ) !== false ) return true;
        }
    }
    return false;
}

/* -------------------------------------------------------------------------
 * Main render for central orders table
 * ------------------------------------------------------------------------- */
function rc_render_central_orders_table_final() {
    if ( ! current_user_can( 'manage_network' ) ) {
        wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
    }

    // Inputs
    $search_query   = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
    $status_filter  = isset($_GET['status_filter']) ? sanitize_text_field(wp_unslash($_GET['status_filter'])) : 'all';
    $per_page_param = isset($_GET['per_page']) ? sanitize_text_field(wp_unslash($_GET['per_page'])) : '15';
    $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;

    // Per-page options
    $allowed_per_page = array('10','15','25','50','100','200','300','500','700','1000');
    if (!in_array($per_page_param, $allowed_per_page, true)) $per_page_param = '15';
    $per_page = intval($per_page_param);

    // Target sites (adjust to your multisite IDs)
    $target_blog_ids = array(1,2,8,9,12);
    $all_orders = array();

    // Collect statuses across child sites
    $all_status_labels = array();
    foreach ( $target_blog_ids as $blog_id ) {
        switch_to_blog( $blog_id );
        if ( class_exists( 'WooCommerce' ) && function_exists('wc_get_order_statuses') ) {
            $site_statuses = wc_get_order_statuses();
            foreach ( $site_statuses as $key => $label ) {
                $slug = preg_replace('/^wc-/', '', $key);
                if ( ! isset( $all_status_labels[ $slug ] ) ) {
                    $all_status_labels[ $slug ] = $label;
                }
            }
        }
        restore_current_blog();
    }
    // Ensure ghost status exists
    $all_status_labels['ghost'] = 'Ghost Orders';

    if ( empty( $all_status_labels ) ) {
        $all_status_labels = array(
            'pending'    => 'Pending Payment',
            'processing' => 'Processing',
            'on-hold'    => 'On Hold',
            'completed'  => 'Completed',
            'cancelled'  => 'Cancelled',
            'refunded'   => 'Refunded',
            'failed'     => 'Failed',
            'shipped'    => 'Shipped',
            'ghost'      => 'Ghost Orders',
        );
    }

    // Determine per-site fetch limit
    $blogs_count = max(1, count($target_blog_ids));
    if ( $search_query !== '' ) {
        $per_site_limit = 1000;
    } else {
        $needed = $paged * $per_page;
        $per_site_limit = (int) ceil( $needed / $blogs_count );
        $per_site_limit = max( $per_site_limit + 10, 50 );
        $per_site_limit = min( $per_site_limit, 1000 );
    }

    // Customer aggregate map used for "Total Orders" and "Cancelled counts"
    $customer_map = array();

    // Aggregate orders (capture site-specific status label)
    foreach ( $target_blog_ids as $blog_id ) {
        switch_to_blog( $blog_id );
        if ( class_exists( 'WooCommerce' ) ) {
            $site_name = get_bloginfo('name');

            // Site-local status mapping
            $site_status_map = array();
            if ( function_exists( 'wc_get_order_statuses' ) ) {
                $site_statuses = wc_get_order_statuses();
                foreach ( $site_statuses as $k => $label ) {
                    $slug = preg_replace( '/^wc-/', '', $k );
                    $site_status_map[ $slug ] = $label;
                }
            }

            $order_args = array(
                'limit' => $per_site_limit,
                'orderby' => 'date',
                'order' => 'DESC',
            );
            $orders = wc_get_orders( $order_args );
            foreach ( $orders as $order ) {
                // Basic product summary
                $products_arr = array();
                foreach ( $order->get_items() as $item ) {
                    $product = $item->get_product();
                    if ( $product && is_object( $product ) ) {
                        $sku = $product->get_sku();
                        $sku = empty($sku) ? 'ID:' . $product->get_id() : $sku;
                    } else {
                        $sku = $item->get_name();
                    }
                    $qty = $item->get_quantity();
                    $products_arr[] = $sku . '(' . $qty . ')';
                }
                $products_str = implode(' + ', $products_arr);

                // Raw status
                $status_slug = $order->get_status();

                // Detect incomplete/ghost orders:
                // Consider ghost if missing status, missing order number, and missing basic customer identifiers.
                $order_number = $order->get_order_number();
                $first = trim( (string) $order->get_billing_first_name() );
                $last  = trim( (string) $order->get_billing_last_name() );
                $email = trim( (string) $order->get_billing_email() );
                $phone = trim( (string) $order->get_billing_phone() );

                $is_ghost = false;
                if ( empty( $status_slug ) || $status_slug === '' ) {
                    $is_ghost = true;
                }
                // also treat as ghost when both order number and any contact are missing
                if ( empty( $order_number ) && empty( $email ) && empty( $phone ) && ( empty( $first ) && empty( $last ) ) ) {
                    $is_ghost = true;
                }

                if ( $is_ghost ) {
                    $status_slug = 'ghost';
                    $status_label_local = $all_status_labels['ghost'];
                } else {
                    $status_label_local = isset( $site_status_map[ $status_slug ] ) ? $site_status_map[ $status_slug ] : $status_slug;
                }

                // Build customer key: prefer email, else phone, else name (lowercased). Fallback to blog:order id to avoid merging unknown customers.
                if ( $email !== '' ) {
                    $customer_key = 'e:' . strtolower( $email );
                } elseif ( $phone !== '' ) {
                    $customer_key = 'p:' . preg_replace('/\D+/', '', $phone);
                } elseif ( $first !== '' || $last !== '' ) {
                    $customer_key = 'n:' . strtolower( trim( $first . ' ' . $last ) );
                } else {
                    $customer_key = 'o:' . intval( $blog_id ) . ':' . intval( $order->get_id() );
                }

                // Update customer map counts
                if ( ! isset( $customer_map[ $customer_key ] ) ) {
                    $customer_map[ $customer_key ] = array( 'total' => 0, 'cancelled' => 0 );
                }
                $customer_map[ $customer_key ]['total']++;

                if ( $status_slug === 'cancelled' ) {
                    $customer_map[ $customer_key ]['cancelled']++;
                }

                $all_orders[] = array(
                    'blog_id' => $blog_id,
                    'site_name' => $site_name,
                    'order_id' => $order->get_id(),
                    'order_num' => $order_number,
                    'date' => $order->get_date_created() ? $order->get_date_created()->date('Y-m-d H:i') : '',
                    'timestamp' => $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0,
                    'status' => $status_slug,
                    'status_label' => $status_label_local,
                    'total' => $order->get_formatted_order_total(),
                    'customer' => trim( $first . ' ' . $last ),
                    'first_name' => $first,
                    'last_name' => $last,
                    'payment_method' => $order->get_payment_method_title() ? $order->get_payment_method_title() : '',
                    'shipping_method' => ( function() use ( $order ) {
                        $methods = array();
                        foreach ( $order->get_shipping_methods() as $shipping_item ) {
                            $methods[] = $shipping_item->get_method_title();
                        }
                        return implode( ', ', $methods );
                    } )(),
                    'zip' => $order->get_billing_postcode() ? $order->get_billing_postcode() : '',
                    'country_code' => $order->get_billing_country() ? $order->get_billing_country() : '',
                    'products' => $products_str,
                    'email' => $email,
                    'phone' => $phone,
                    'edit_url' => admin_url('post.php?post=' . $order->get_id() . '&action=edit'),
                    'customer_key' => $customer_key,
                );
                if ( get_option('woocommerce_custom_orders_table_enabled') === 'yes' ) {
                    $all_orders[count($all_orders)-1]['edit_url'] = admin_url('admin.php?page=wc-orders&action=edit&id=' . $order->get_id());
                }
            }
        }
        restore_current_blog();
    }

    // Sort
    usort( $all_orders, function($a,$b){ return $b['timestamp'] <=> $a['timestamp']; } );

    // Enrich each order with aggregated customer totals
    foreach ( $all_orders as $idx => $o ) {
        $key = $o['customer_key'] ?? '';
        $total = 1;
        $cancelled = 0;
        if ( $key !== '' && isset( $customer_map[ $key ] ) ) {
            $total = intval( $customer_map[ $key ]['total'] );
            $cancelled = intval( $customer_map[ $key ]['cancelled'] );
        }
        $all_orders[ $idx ]['customer_total'] = $total;
        $all_orders[ $idx ]['customer_cancelled'] = $cancelled;
    }

    // Apply search filter first
    if ( $search_query !== '' ) {
        $searched_orders = array();
        foreach ( $all_orders as $o ) {
            if ( rc_order_matches_search_final( $o, $search_query ) ) $searched_orders[] = $o;
        }
    } else {
        $searched_orders = $all_orders;
    }

    // Compute status counts from searched_orders
    $status_counts = array();
    foreach ( $all_status_labels as $slug => $label ) $status_counts[ $slug ] = 0;
    foreach ( $searched_orders as $o ) {
        $s = $o['status'];
        if ( ! isset( $status_counts[ $s ] ) ) $status_counts[ $s ] = 0;
        $status_counts[ $s ]++;
    }

    // Apply status filter
    if ( $status_filter !== 'all' ) {
        $filtered_orders = array();
        foreach ( $searched_orders as $o ) {
            if ( $o['status'] === $status_filter ) $filtered_orders[] = $o;
        }
    } else {
        $filtered_orders = $searched_orders;
    }

    // Pagination
    $total_orders = count($filtered_orders);
    $total_pages = max(1, (int) ceil( $total_orders / $per_page ) );
    if ( $paged > $total_pages ) $paged = $total_pages;
    $offset = ($paged - 1) * $per_page;
    $page_orders = array_slice( $filtered_orders, $offset, $per_page );
    $showing_from = $total_orders ? ($offset + 1) : 0;
    $showing_to = min( $offset + count($page_orders), $total_orders );

    // Status bar markup: include only statuses with count > 0 (except All)
    $status_bar_html = '<div class="rc-status-bar">';
    $all_active = ($status_filter === 'all') ? ' active' : '';
    $status_bar_html .= '<a class="rc-status-pill'. $all_active .'" href="'.esc_url(rc_build_orders_url_final(array('status_filter'=>'all','paged'=>1))).'">All <span style="opacity:.7;margin-left:6px;">('.intval(count($searched_orders)).')</span></a>';
    foreach ( $all_status_labels as $slug => $label ) {
        $count = isset($status_counts[$slug]) ? intval($status_counts[$slug]) : 0;
        if ( $count <= 0 ) continue; // hide statuses with zero orders
        $active = ($status_filter === $slug) ? ' active' : '';
        $status_bar_html .= '<a class="rc-status-pill'.$active.'" href="'.esc_url(rc_build_orders_url_final(array('status_filter'=>$slug,'paged'=>1))).'">'.esc_html($label).' <span style="opacity:.7;margin-left:6px;">('.$count.')</span></a>';
    }
    $status_bar_html .= '</div>';

    $wc_statuses_for_select = $all_status_labels;
    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline">Central Network Orders (<?php echo esc_html( count($target_blog_ids) ); ?> Stores)</h1>

        <!-- Status bar -->
        <?php echo $status_bar_html; ?>

        <hr class="wp-header-end">

        <!-- Controls -->
        <div class="tablenav top" style="margin-top:20px;display:flex;gap:12px;justify-content:space-between;align-items:center;flex-wrap:wrap;">
            <div style="display:flex;gap:12px;align-items:center;">
                <form method="get" action="" style="display:inline-flex;gap:6px;align-items:center;">
                    <input type="hidden" name="page" value="network-orders-view" />
                    <label for="per_page_select">Orders per page:</label>
                    <select id="per_page_select" name="per_page">
                        <?php foreach ($allowed_per_page as $opt) : ?>
                            <option value="<?php echo esc_attr($opt); ?>" <?php selected($per_page_param,$opt); ?>><?php echo esc_html($opt); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="hidden" name="status_filter" value="<?php echo esc_attr($status_filter); ?>" />
                    <?php if (!empty($search_query)) : ?>
                        <input type="hidden" name="s" value="<?php echo esc_attr($search_query); ?>" />
                    <?php endif; ?>
                    <input type="submit" class="button" value="Apply">
                </form>

                <form method="get" action="" style="display:inline-flex;align-items:center;">
                    <input type="hidden" name="page" value="network-orders-view" />

                    <!-- Update status button + select -->
                    <div class="rc-status-update-group">
                        <button type="button" id="rc-bulk-update-btn-status" class="button">Update status</button>

                        <select name="status_filter" id="status_filter">
                            <option value="all" <?php selected($status_filter,'all'); ?>>All Statuses</option>
                            <?php foreach ($wc_statuses_for_select as $slug => $label) : ?>
                                <option value="<?php echo esc_attr($slug); ?>" <?php selected($status_filter,$slug); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <input type="hidden" name="per_page" value="<?php echo esc_attr($per_page_param); ?>" />
                    <?php if (!empty($search_query)) : ?>
                        <input type="hidden" name="s" value="<?php echo esc_attr($search_query); ?>" />
                    <?php endif; ?>
                    <input type="submit" class="button" value="Filter">
                </form>
            </div>

            <div class="rc-controls-right">
                <div style="font-size:13px;color:#555;">Showing <?php echo intval($showing_from); ?>–<?php echo intval($showing_to); ?> of <?php echo intval($total_orders); ?> orders</div>
                <div class="rc-pagination">
                    <?php
                    if ( $total_pages > 1 ) {
                        $page_links = array();
                        $start = max(1, $paged - 2);
                        $end = min($total_pages, $paged + 2);
                        if ($start > 1) {
                            $page_links[] = '<a class="button" href="'.esc_url(rc_build_orders_url_final(array('paged'=>1))).'">1</a>';
                            if ($start > 2) $page_links[] = '<span class="button" style="pointer-events:none;opacity:.6;">&hellip;</span>';
                        }
                        for ($i=$start;$i<=$end;$i++){
                            if ($i==$paged) $page_links[] = '<span class="button" style="background:#0073aa;color:#fff;border-color:#0073aa;">'.$i.'</span>';
                            else $page_links[] = '<a class="button" href="'.esc_url(rc_build_orders_url_final(array('paged'=>$i))).'">'.$i.'</a>';
                        }
                        if ($end < $total_pages) {
                            if ($end < $total_pages - 1) $page_links[] = '<span class="button" style="pointer-events:none;opacity:.6;">&hellip;</span>';
                            $page_links[] = '<a class="button" href="'.esc_url(rc_build_orders_url_final(array('paged'=>$total_pages))).'">'.$total_pages.'</a>';
                        }
                        $prev = $paged>1?'<a class="button" href="'.esc_url(rc_build_orders_url_final(array('paged'=>$paged-1))).'">&laquo; Prev</a>':'<span class="button" style="pointer-events:none;opacity:.6;">&laquo; Prev</span>';
                        $next = $paged<$total_pages?'<a class="button" href="'.esc_url(rc_build_orders_url_final(array('paged'=>$paged+1))).'">Next &raquo;</a>':'<span class="button" style="pointer-events:none;opacity:.6;">Next &raquo;</span>';
                        echo $prev . ' ' . implode(' ', $page_links) . ' ' . $next;
                    }
                    ?>
                </div>
            </div>
        </div>

        <!-- Search row -->
        <div class="rc-search-row">
            <form method="get" action="" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <input type="hidden" name="page" value="network-orders-view" />
                <input type="hidden" name="status_filter" value="<?php echo esc_attr($status_filter); ?>" />
                <input type="hidden" name="per_page" value="<?php echo esc_attr($per_page_param); ?>" />
                <label class="screen-reader-text" for="order-search-input">Search Orders:</label>
                <input type="search" id="order-search-input" name="s" value="<?php echo esc_attr($search_query); ?>" placeholder="Search zip, first/last name, order #, email, phone..." />
                <input type="submit" id="search-submit" class="button" value="Search">
            </form>
        </div>

        <!-- Orders table -->
        <table class="wp-list-table widefat fixed striped table-view-list" style="margin-top:10px;">
            <thead>
                <tr>
                    <th style="width:3%;"><input type="checkbox" id="rc-select-all" /></th>
                    <th style="width:9%;">Store</th>
                    <th style="width:10%;">Order # / Zip</th>
                    <th style="width:7%;">Action</th> <!-- moved Action here between Order # and Products -->
                    <th style="width:15%;">Products</th>
                    <th style="width:8%;">Total Orders</th> <!-- new column -->
                    <th style="width:10%;">Date & Time</th>
                    <th style="width:10%;">Name / Country</th>
                    <th style="width:10%;">Phone / Email</th>
                    <th style="width:8%;">Payment Method</th>
                    <th style="width:8%;">Shipping Method</th>
                    <th style="width:7%;">Status</th>
                    <th style="width:7%;">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty($page_orders) ) : ?>
                    <tr><td colspan="13" style="text-align:center;padding:20px;">No matching orders found.</td></tr>
                <?php else :
                    $saved_status_colors = get_option( 'rc_tno_status_colors', array() );
                    if ( ! is_array( $saved_status_colors ) ) {
                        $saved_status_colors = array();
                    }
                    $default_badge_colors = array(
                        'processing' => '#c6e1c6',
                        'completed'  => '#e5e5e5',
                        'ghost'      => '#efefef',
                        'failed'     => '#f8b9b9',
                        'cancelled'  => '#f8b9b9',
                        'refunded'   => '#f8b9b9',
                        'shipped'    => '#cfeef2',
                    );
                    foreach ( $page_orders as $o ) :
                        if ( isset( $saved_status_colors[ $o['status'] ] ) && '' !== $saved_status_colors[ $o['status'] ] ) {
                            $badge_bg = $saved_status_colors[ $o['status'] ];
                        } elseif ( isset( $default_badge_colors[ $o['status'] ] ) ) {
                            $badge_bg = $default_badge_colors[ $o['status'] ];
                        } else {
                            $badge_bg = '#f8dda7';
                        }
                        $status_label_display = isset($o['status_label']) ? $o['status_label'] : $o['status'];
                        // Build Total Orders display "T(n) : C(m)"
                        $t_total = isset($o['customer_total']) ? intval($o['customer_total']) : 1;
                        $c_cancel = isset($o['customer_cancelled']) ? intval($o['customer_cancelled']) : 0;
                        $total_orders_display = 'T(' . $t_total . ') : C(' . $c_cancel . ')';
                        ?>
                        <tr data-blog-id="<?php echo esc_attr($o['blog_id']); ?>" data-order-id="<?php echo esc_attr($o['order_id']); ?>">
                            <td style="vertical-align:middle;"><input type="checkbox" name="rc_bulk_select[]" value="<?php echo esc_attr($o['blog_id'].':'.$o['order_id']); ?>" /></td>
                            <td><strong><?php echo esc_html($o['site_name']); ?></strong></td>
                            <td>
                                <div><a href="<?php echo esc_url($o['edit_url']); ?>" target="_blank">#<?php echo esc_html($o['order_num']); ?></a></div>
                                <?php if (!empty($o['zip'])): ?><div style="font-size:11px;color:#646970;margin-top:2px;"><code><?php echo esc_html($o['zip']); ?></code></div><?php endif;?>
                            </td>

                            <!-- Action column (moved here) -->
                            <td style="vertical-align:middle;">
                                <button type="button"
                                    class="button rc-add-tracking-btn"
                                    data-blog-id="<?php echo esc_attr($o['blog_id']); ?>"
                                    data-order-id="<?php echo esc_attr($o['order_id']); ?>"
                                    data-order-num="<?php echo esc_attr($o['order_num']); ?>"
                                    data-site-name="<?php echo esc_attr($o['site_name']); ?>"
                                    title="Add Tracking">
                                    <span class="dashicons dashicons-location" aria-hidden="true"></span>
                                </button>
                                <button type="button" class="button rc-order-preview-btn" data-blog-id="<?php echo esc_attr($o['blog_id']); ?>" data-order-id="<?php echo esc_attr($o['order_id']); ?>" style="margin-left:6px;">
                                    <span class="dashicons dashicons-visibility" aria-hidden="true"></span>
                                </button>
                            </td>

                            <td class="rc-products"><?php echo esc_html($o['products']); ?></td>

                            <td style="font-weight:600;"><?php echo esc_html( $total_orders_display ); ?></td>

                            <td><?php echo esc_html($o['date']); ?></td>
                            <td>
                                <div><?php echo esc_html($o['customer']); ?></div>
                                <?php if (!empty($o['country_code'])): ?><div class="rc-country-code"><?php echo esc_html($o['country_code']); ?></div><?php endif;?>
                            </td>
                            <td class="rc-contact">
                                <?php if ( ! empty( $o['phone'] ) ) : ?><div><?php echo esc_html($o['phone']); ?></div><?php endif; ?>
                                <?php if ( ! empty( $o['email'] ) ) : ?><div><?php echo esc_html($o['email']); ?></div><?php endif; ?>
                            </td>
                            <td><?php echo esc_html( $o['payment_method'] ); ?></td>
                            <td><?php echo esc_html( $o['shipping_method'] ); ?></td>
                            <td><span class="rc-status-badge" style="background:<?php echo esc_attr($badge_bg); ?>; padding:3px 6px; border-radius:4px; font-size:10px; font-weight:600; text-transform:uppercase; display:inline-block;"><?php echo esc_html($status_label_display); ?></span></td>
                            <td><?php echo wp_kses_post($o['total']); ?></td>
                        </tr>
                    <?php endforeach;
                endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

/* -------------------------------------------------------------------------
 * SINGLE-FILE: SMS Notifications (TextMagic) + Admin UI + logging
 * ------------------------------------------------------------------------- */

/* Ensure top-level admin menu 'Network Orders-site Settings' exists (site admin) */
add_action( 'admin_menu', 'rc_ensure_network_orders_site_settings_menu', 5 );
function rc_ensure_network_orders_site_settings_menu() {
    if ( ! is_admin() ) return;
    $capability = 'manage_options';
    $top_slug = 'rc-network-orders-site-settings';
    add_menu_page(
        'Network Orders-site Settings',
        'Network Orders-site Settings',
        $capability,
        $top_slug,
        'rc_network_orders_site_settings_overview',
        'dashicons-admin-site',
        55
    );
}
function rc_network_orders_site_settings_overview() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
    }
    ?>
    <div class="wrap">
        <h1>Network Orders-site Settings</h1>
        <p>Site-level settings related to Network Orders.</p>
        <ul>
            <li><a href="<?php echo esc_url( admin_url( 'admin.php?page=rc-textmagic-sms-settings' ) ); ?>">SMS Notifications</a></li>
            <li><a href="<?php echo esc_url( admin_url( 'admin.php?page=rc-auto-status-rules' ) ); ?>">Auto Status Rules</a></li>
            <li><a href="<?php echo esc_url( admin_url( 'admin.php?page=rc-status-colors' ) ); ?>">Order Status Colors</a></li>
        </ul>
    </div>
    <?php
}

/* Register SMS submenu */
add_action( 'admin_menu', 'rc_textmagic_add_admin_menu_submenu', 6 );
function rc_textmagic_add_admin_menu_submenu() {
    if ( ! is_admin() ) return;
    $parent_slug = 'rc-network-orders-site-settings';
    $capability = 'manage_options';
    add_submenu_page(
        $parent_slug,
        'TextMagic SMS Notifications',
        'SMS Notifications',
        $capability,
        'rc-textmagic-sms-settings',
        'rc_textmagic_settings_page'
    );
}

/* Register auto status rules submenu */
add_action( 'admin_menu', 'rc_auto_status_admin_menu_submenu', 7 );
function rc_auto_status_admin_menu_submenu() {
    if ( ! is_admin() ) return;
    $parent = 'rc-network-orders-site-settings';
    add_submenu_page(
        $parent,
        'Auto Order Status Rules',
        'Auto Status Rules',
        'manage_options',
        'rc-auto-status-rules',
        'rc_auto_status_rules_page'
    );
}

/* Register order status colors submenu */
add_action( 'admin_menu', 'rc_status_colors_admin_menu_submenu', 8 );
function rc_status_colors_admin_menu_submenu() {
    if ( ! is_admin() ) return;
    add_submenu_page(
        'rc-network-orders-site-settings',
        'Order Status Colors',
        'Order Status Colors',
        'manage_options',
        'rc-status-colors',
        'rc_status_colors_settings_page'
    );
}

function rc_status_colors_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
    }

    // Collect all statuses from all network sites
    $target_blog_ids = array( 1, 2, 8, 9, 12 );
    $all_status_labels = array();
    foreach ( $target_blog_ids as $blog_id ) {
        switch_to_blog( $blog_id );
        if ( class_exists( 'WooCommerce' ) && function_exists( 'wc_get_order_statuses' ) ) {
            foreach ( wc_get_order_statuses() as $key => $label ) {
                $slug = preg_replace( '/^wc-/', '', $key );
                if ( ! isset( $all_status_labels[ $slug ] ) ) {
                    $all_status_labels[ $slug ] = $label;
                }
            }
        }
        restore_current_blog();
    }
    if ( empty( $all_status_labels ) ) {
        $all_status_labels = array(
            'pending'    => 'Pending Payment',
            'processing' => 'Processing',
            'on-hold'    => 'On Hold',
            'completed'  => 'Completed',
            'cancelled'  => 'Cancelled',
            'refunded'   => 'Refunded',
            'failed'     => 'Failed',
            'shipped'    => 'Shipped',
        );
    }
    $all_status_labels['ghost'] = 'Ghost Orders';

    // Handle save
    $saved_message = '';
    if ( isset( $_POST['rc_status_colors_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['rc_status_colors_nonce'] ) ), 'rc_status_colors_save' ) ) {
        $colors = array();
        foreach ( array_keys( $all_status_labels ) as $slug ) {
            $field = 'rc_color_' . sanitize_key( $slug );
            if ( isset( $_POST[ $field ] ) ) {
                $color = sanitize_hex_color( wp_unslash( $_POST[ $field ] ) );
                if ( $color ) {
                    $colors[ $slug ] = $color;
                }
            }
        }
        update_option( 'rc_tno_status_colors', $colors );
        $saved_message = '<div class="rc-notice">Colors saved successfully.</div>';
    }

    $saved_colors = get_option( 'rc_tno_status_colors', array() );
    if ( ! is_array( $saved_colors ) ) {
        $saved_colors = array();
    }

    // Default colors
    $default_colors = array(
        'processing' => '#c6e1c6',
        'completed'  => '#e5e5e5',
        'ghost'      => '#efefef',
        'failed'     => '#f8b9b9',
        'cancelled'  => '#f8b9b9',
        'refunded'   => '#f8b9b9',
        'shipped'    => '#cfeef2',
    );
    ?>
    <div class="wrap">
        <h1>Order Status Colors</h1>
        <p>Assign a display color to each order status. These colors are used in the Network Orders view to style the status badges.</p>
        <?php echo $saved_message; ?>
        <form method="post" action="">
            <?php wp_nonce_field( 'rc_status_colors_save', 'rc_status_colors_nonce' ); ?>
            <table class="widefat striped" style="max-width:520px;">
                <thead>
                    <tr>
                        <th style="width:50%;">Order Status</th>
                        <th style="width:50%;">Color</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $all_status_labels as $slug => $label ) :
                        $field_name = 'rc_color_' . sanitize_key( $slug );
                        if ( isset( $saved_colors[ $slug ] ) && '' !== $saved_colors[ $slug ] ) {
                            $current_color = $saved_colors[ $slug ];
                        } elseif ( isset( $default_colors[ $slug ] ) ) {
                            $current_color = $default_colors[ $slug ];
                        } else {
                            $current_color = '#f8dda7';
                        }
                        ?>
                        <tr>
                            <td><label for="<?php echo esc_attr( $field_name ); ?>"><?php echo esc_html( $label ); ?> <small style="color:#888;">(<?php echo esc_html( $slug ); ?>)</small></label></td>
                            <td>
                                <input type="color"
                                    id="<?php echo esc_attr( $field_name ); ?>"
                                    name="<?php echo esc_attr( $field_name ); ?>"
                                    value="<?php echo esc_attr( $current_color ); ?>"
                                    style="width:60px;height:32px;padding:2px;cursor:pointer;"
                                />
                                <span style="margin-left:8px;font-family:monospace;font-size:12px;" id="<?php echo esc_attr( $field_name ); ?>_val"><?php echo esc_html( $current_color ); ?></span>
                                <script>
                                (function(){
                                    var inp = document.getElementById(<?php echo wp_json_encode( $field_name ); ?>);
                                    var lbl = document.getElementById(<?php echo wp_json_encode( $field_name . '_val' ); ?>);
                                    if(inp && lbl){inp.addEventListener('input',function(){lbl.textContent=this.value;});}
                                })();
                                </script>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="submit">
                <input type="submit" class="button button-primary" value="Save Colors" />
            </p>
        </form>
    </div>
    <?php
}

/* Register order status hook early */
add_action( 'init', 'rc_textmagic_register_hooks', 5 );
function rc_textmagic_register_hooks() {
    add_action( 'woocommerce_order_status_changed', 'rc_textmagic_send_sms_on_status_change', 20, 4 );
}

/* Simple logger for SMS attempts */
function rc_textmagic_log_send( $entry ) {
    $key = RC_TEXTMAGIC_LOGS_KEY;
    $max = 50;
    $logs = get_option( $key, array() );
    if ( ! is_array( $logs ) ) $logs = array();
    $entry['time'] = current_time( 'mysql' );
    array_unshift( $logs, $entry );
    if ( count( $logs ) > $max ) $logs = array_slice( $logs, 0, $max );
    update_option( $key, $logs );
}

/* SMS admin page (settings + templates + test + logs) */
function rc_textmagic_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( __( 'Insufficient permissions' ) );

    // Save API settings
    if ( isset( $_POST['rc_textmagic_settings_nonce'] ) && wp_verify_nonce( wp_unslash( $_POST['rc_textmagic_settings_nonce'] ), 'rc_textmagic_save_settings' ) ) {
        if ( isset( $_POST['rc_textmagic_settings'] ) && is_array( $_POST['rc_textmagic_settings'] ) ) {
            $raw = wp_unslash( $_POST['rc_textmagic_settings'] );
            $sanitized = array(
                'username' => isset( $raw['username'] ) ? sanitize_text_field( $raw['username'] ) : '',
                'api_key'  => isset( $raw['api_key'] ) ? sanitize_text_field( $raw['api_key'] ) : '',
                'sender'   => isset( $raw['sender'] ) ? sanitize_text_field( $raw['sender'] ) : '',
                'enabled'  => isset( $raw['enabled'] ) && $raw['enabled'] ? 1 : 0,
            );
            update_option( RC_TEXTMAGIC_OPTION_KEY, $sanitized );
            echo '<div class="updated"><p>Settings saved.</p></div>';
        }
    }

    // Add new template
    if ( isset( $_POST['rc_textmagic_add_template_nonce'] ) && wp_verify_nonce( wp_unslash( $_POST['rc_textmagic_add_template_nonce'] ), 'rc_textmagic_add_template' ) ) {
        $new_slug = isset( $_POST['rc_new_status_slug'] ) ? sanitize_text_field( wp_unslash( $_POST['rc_new_status_slug'] ) ) : '';
        $new_template = isset( $_POST['rc_new_status_template'] ) ? sanitize_textarea_field( wp_unslash( $_POST['rc_new_status_template'] ) ) : '';
        if ( $new_slug === '' || $new_template === '' ) {
            echo '<div class="error"><p>Please select a status and enter a template.</p></div>';
        } else {
            $templates = get_option( RC_TEXTMAGIC_TEMPLATES_KEY, array() );
            if ( ! is_array( $templates ) ) $templates = array();
            $templates[ $new_slug ] = $new_template;
            update_option( RC_TEXTMAGIC_TEMPLATES_KEY, $templates );
            echo '<div class="updated"><p>Template added for status: ' . esc_html( $new_slug ) . '.</p></div>';
        }
    }

    // Save edited templates
    if ( isset( $_POST['rc_textmagic_templates_nonce'] ) && wp_verify_nonce( wp_unslash( $_POST['rc_textmagic_templates_nonce'] ), 'rc_textmagic_save_templates' ) ) {
        $raw_slugs = isset( $_POST['existing_status_slug'] ) && is_array( $_POST['existing_status_slug'] ) ? wp_unslash( $_POST['existing_status_slug'] ) : array();
        $raw_templates = isset( $_POST['existing_status_template'] ) && is_array( $_POST['existing_status_template'] ) ? wp_unslash( $_POST['existing_status_template'] ) : array();
        $new_templates = array();
        foreach ( $raw_slugs as $i => $slug ) {
            $s = sanitize_text_field( trim( $slug ) );
            if ( $s === '' ) continue;
            $tpl = isset( $raw_templates[ $i ] ) ? sanitize_textarea_field( $raw_templates[ $i ] ) : '';
            if ( $tpl === '' ) {
                continue;
            }
            $new_templates[ $s ] = $tpl;
        }
        update_option( RC_TEXTMAGIC_TEMPLATES_KEY, $new_templates );
        echo '<div class="updated"><p>Templates updated.</p></div>';
    }

    // Delete template
    if ( isset( $_GET['rc_delete_template'] ) && isset( $_GET['_wpnonce'] ) ) {
        $slug = sanitize_text_field( wp_unslash( $_GET['rc_delete_template'] ) );
        $nonce = wp_unslash( $_GET['_wpnonce'] );
        if ( wp_verify_nonce( $nonce, 'rc_textmagic_delete_template_' . $slug ) ) {
            $templates = get_option( RC_TEXTMAGIC_TEMPLATES_KEY, array() );
            if ( isset( $templates[ $slug ] ) ) {
                unset( $templates[ $slug ] );
                update_option( RC_TEXTMAGIC_TEMPLATES_KEY, $templates );
                echo '<div class="updated"><p>Template for ' . esc_html( $slug ) . ' deleted.</p></div>';
            }
        } else {
            echo '<div class="error"><p>Invalid nonce for delete.</p></div>';
        }
    }

    // Test send
    if ( isset( $_POST['rc_textmagic_test_send_nonce'] ) && wp_verify_nonce( wp_unslash( $_POST['rc_textmagic_test_send_nonce'] ), 'rc_textmagic_test_send' ) ) {
        if ( ! current_user_can( 'manage_options' ) ) wp_die();
        $to = isset( $_POST['rc_test_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['rc_test_phone'] ) ) : '';
        $msg = isset( $_POST['rc_test_message'] ) ? sanitize_text_field( wp_unslash( $_POST['rc_test_message'] ) ) : '';
        if ( empty( $to ) || empty( $msg ) ) {
            echo '<div class="error"><p>Please provide both phone and message.</p></div>';
        } else {
            $settings = get_option( RC_TEXTMAGIC_OPTION_KEY, array() );
            $full_msg = str_replace( '{site_name}', get_bloginfo( 'name' ), $msg );
            $res = rc_textmagic_send_message( $to, $full_msg, $settings );
            if ( is_wp_error( $res ) ) {
                echo '<div class="error"><p>Send failed: ' . esc_html( $res->get_error_message() ) . '</p></div>';
            } else {
                echo '<div class="updated"><p>Test SMS queued/sent. Check logs below.</p></div>';
            }
        }
    }

    $settings = get_option( RC_TEXTMAGIC_OPTION_KEY, array( 'username' => '', 'api_key' => '', 'sender' => '', 'enabled' => 1 ) );
    $templates = get_option( RC_TEXTMAGIC_TEMPLATES_KEY, array() );
    if ( ! is_array( $templates ) ) $templates = array();

    // Get WC statuses for select
    $wc_statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
    $status_options = array();
    foreach ( $wc_statuses as $k => $label ) {
        $slug = preg_replace( '/^wc-/', '', $k );
        $status_options[ $slug ] = $label;
    }
    ?>
    <div class="wrap">
        <h1>TextMagic SMS Notifications</h1>

        <form method="post" action="">
            <?php wp_nonce_field( 'rc_textmagic_save_settings', 'rc_textmagic_settings_nonce' ); ?>

            <h2>TextMagic API Settings</h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="rc_username">API Username</label></th>
                    <td><input name="rc_textmagic_settings[username]" type="text" id="rc_username" value="<?php echo esc_attr( $settings['username'] ?? '' ); ?>" class="regular-text" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="rc_api_key">API Key / Token</label></th>
                    <td><input name="rc_textmagic_settings[api_key]" type="password" id="rc_api_key" value="<?php echo esc_attr( $settings['api_key'] ?? '' ); ?>" class="regular-text" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="rc_sender">Sender ID (From)</label></th>
                    <td><input name="rc_textmagic_settings[sender]" type="text" id="rc_sender" value="<?php echo esc_attr( $settings['sender'] ?? '' ); ?>" class="regular-text" />
                        <p class="description">Optional; if provided it will be used as the 'from' field when sending SMS.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Enable SMS Notifications</th>
                    <td><label><input type="checkbox" name="rc_textmagic_settings[enabled]" value="1" <?php checked( 1, $settings['enabled'] ?? 0 ); ?> /> Enabled</label></td>
                </tr>
            </table>

            <p><input type="submit" class="button" value="Save Settings" /></p>
        </form>

        <h2>Available template variables</h2>
        <ul>
            <li><strong>{first_name}</strong> — Customer billing first name</li>
            <li><strong>{last_name}</strong> — Customer billing last name</li>
            <li><strong>{customer_name}</strong> — Full customer name (first + last)</li>
            <li><strong>{order_number}</strong> — Order number shown to customer</li>
            <li><strong>{order_total}</strong> — Formatted order total (amount + currency symbol)</li>
            <li><strong>{order_currency}</strong> — Order currency code (e.g. USD)</li>
            <li><strong>{order_status}</strong> — New order status slug</li>
            <li><strong>{site_name}</strong> — Site name</li>
            <li><strong>{order_link}</strong> — Admin edit link for the order</li>
            <li><strong>{phone}</strong> — Billing phone (as stored)</li>
        </ul>

        <h2>Add new status SMS</h2>
        <form method="post" action="">
            <?php wp_nonce_field( 'rc_textmagic_add_template', 'rc_textmagic_add_template_nonce' ); ?>
            <table class="form-table">
                <tr>
                    <th style="width:24%"><label for="rc_new_status_slug">Select status</label></th>
                    <td>
                        <select id="rc_new_status_slug" name="rc_new_status_slug" required>
                            <option value="">— Select status —</option>
                            <?php foreach ( $status_options as $slug => $label ) : ?>
                                <option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label . ' (' . $slug . ')' ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="rc_new_status_template">SMS template</label></th>
                    <td>
                        <textarea id="rc_new_status_template" name="rc_new_status_template" rows="3" style="width:100%;" class="rc-sms-template"></textarea>
                        <div class="rc-sms-counter" data-target="#rc_new_status_template" style="margin-top:6px; font-size:13px; color:#333;">
                            <span class="rc-sms-encoding">Encoding: <strong>—</strong></span>
                            &nbsp;|&nbsp;
                            <span class="rc-sms-length">Effective chars: <strong>0</strong></span>
                            &nbsp;|&nbsp;
                            <span class="rc-sms-units">SMS units: <strong>0</strong></span>
                            &nbsp;|&nbsp;
                            <span class="rc-sms-remaining">Remaining in segment: <strong>0</strong></span>
                        </div>
                    </td>
                </tr>
            </table>
            <p><input type="submit" class="button-primary" value="Add new status SMS" /></p>
        </form>

        <h2>Existing status SMS templates</h2>
        <p>Edit messages inline and press "Save all templates" to persist changes. Clearing a template (empty textarea) and saving will remove it.</p>

        <form method="post" action="">
            <?php wp_nonce_field( 'rc_textmagic_save_templates', 'rc_textmagic_templates_nonce' ); ?>
            <table class="widefat fixed striped rc-textmagic-templates">
                <thead>
                    <tr>
                        <th style="width:24%;">Status</th>
                        <th>SMS template (live counter)</th>
                        <th style="width:10%;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if ( empty( $templates ) ) {
                        echo '<tr><td colspan="3">No templates defined yet.</td></tr>';
                    } else {
                        foreach ( $templates as $slug => $tpl ) {
                            $label = isset( $status_options[ $slug ] ) ? $status_options[ $slug ] . " ({$slug})" : $slug;
                            $textarea_id = 'rc_existing_tpl_' . esc_attr( $slug );
                            ?>
                            <tr>
                                <td style="vertical-align:top;">
                                    <strong><?php echo esc_html( $label ); ?></strong>
                                    <input type="hidden" name="existing_status_slug[]" value="<?php echo esc_attr( $slug ); ?>" />
                                </td>
                                <td>
                                    <textarea id="<?php echo $textarea_id; ?>" class="rc-sms-template" name="existing_status_template[]" rows="3" style="width:100%;"><?php echo esc_textarea( $tpl ); ?></textarea>
                                    <div class="rc-sms-counter" data-target="#<?php echo $textarea_id; ?>" style="margin-top:6px; font-size:13px; color:#333;">
                                        <span class="rc-sms-encoding">Encoding: <strong>—</strong></span>
                                        &nbsp;|&nbsp;
                                        <span class="rc-sms-length">Effective chars: <strong>0</strong></span>
                                        &nbsp;|&nbsp;
                                        <span class="rc-sms-units">SMS units: <strong>0</strong></span>
                                        &nbsp;|&nbsp;
                                        <span class="rc-sms-remaining">Remaining in segment: <strong>0</strong></span>
                                    </div>
                                </td>
                                <td style="vertical-align:top;">
                                    <?php
                                    $del_url = add_query_arg( array(
                                        'page' => 'rc-textmagic-sms-settings',
                                        'rc_delete_template' => $slug,
                                    ), admin_url( 'admin.php' ) );
                                    $del_url = wp_nonce_url( $del_url, 'rc_textmagic_delete_template_' . $slug );
                                    ?>
                                    <a class="button" href="<?php echo esc_url( $del_url ); ?>" onclick="return confirm('Delete template for <?php echo esc_js( $slug ); ?>?');">Delete</a>
                                </td>
                            </tr>
                            <?php
                        }
                    }
                    ?>
                </tbody>
            </table>

            <p><input type="submit" class="button-primary" value="Save all templates" /></p>
        </form>

        <h2>Test SMS</h2>
        <form method="post" action="">
            <?php wp_nonce_field( 'rc_textmagic_test_send', 'rc_textmagic_test_send_nonce' ); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="rc_test_phone">Phone (international)</label></th>
                    <td><input type="text" id="rc_test_phone" name="rc_test_phone" class="regular-text" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="rc_test_message">Message</label></th>
                    <td><input type="text" id="rc_test_message" name="rc_test_message" class="regular-text" value="Test message from {site_name}" /></td>
                </tr>
            </table>
            <p><input type="submit" class="button" value="Send Test SMS" /></p>
        </form>

        <h2>Recent send logs (last 50)</h2>
        <?php
        $logs = get_option( RC_TEXTMAGIC_LOGS_KEY, array() );
        if ( empty( $logs ) ) {
            echo '<p>No send attempts yet.</p>';
        } else {
            echo '<table class="widefat fixed striped"><thead><tr><th>Time</th><th>To</th><th>Text</th><th>Result</th><th>Info</th></tr></thead><tbody>';
            foreach ( $logs as $l ) {
                echo '<tr>';
                echo '<td>' . esc_html( $l['time'] ?? '' ) . '</td>';
                echo '<td>' . esc_html( $l['to'] ?? '' ) . '</td>';
                echo '<td>' . esc_html( mb_strimwidth( $l['text'] ?? '', 0, 120, '…' ) ) . '</td>';
                echo '<td>' . esc_html( $l['result'] ?? '' ) . '</td>';
                $info = '';
                if ( isset( $l['http_code'] ) ) $info .= 'HTTP ' . intval( $l['http_code'] ) . ' ';
                if ( isset( $l['error'] ) ) $info .= esc_html( $l['error'] );
                if ( isset( $l['response'] ) && is_array( $l['response'] ) ) $info .= ' ' . esc_html( wp_json_encode( $l['response'] ) );
                echo '<td style="font-size:12px;max-width:320px;word-break:break-word;">' . $info . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        ?>

    </div>

    <style>
        .rc-sms-counter strong { color:#0073aa; }
        .rc-sms-counter { line-height:1.6; }
        .rc-textmagic-templates th:nth-child(1),
        .rc-textmagic-templates td:nth-child(1) {
            width:24%;
            vertical-align:middle;
        }
        .rc-textmagic-templates th:nth-child(3),
        .rc-textmagic-templates td:nth-child(3) {
            width:10%;
            text-align:center;
            vertical-align:middle;
        }
    </style>

    <script>
    (function(){
        "use strict";

        var GSM7_BASIC_STR = "@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
        var GSM7_EXT_STR = "^{}\\[~]|€";
        var gsm7BasicSet = new Set(GSM7_BASIC_STR.split(''));
        var gsm7ExtSet = new Set(GSM7_EXT_STR.split(''));

        function countGsm7Septets(text) {
            var septets = 0;
            for (var i = 0; i < text.length; i++) {
                var ch = text.charAt(i);
                if (gsm7BasicSet.has(ch)) septets += 1;
                else if (gsm7ExtSet.has(ch)) septets += 2;
                else return -1;
            }
            return septets;
        }

        function codePointLength(str) { return Array.from(str).length; }

        function smsMetrics(text) {
            if (!text) text = '';
            var septetCount = countGsm7Septets(text);
            if (septetCount >= 0) {
                var singleLimit = 160, concatLimit = 153;
                if (septetCount <= singleLimit) { var segments = 1, perSeg = singleLimit, used = septetCount; }
                else { var segments = Math.ceil(septetCount / concatLimit), perSeg = concatLimit, used = septetCount - ((segments - 1) * concatLimit); }
                return { encoding: 'GSM-7', chars: septetCount, segments: segments, perSegment: perSeg, remaining: perSeg - used };
            } else {
                var cpLen = codePointLength(text);
                var singleLimit = 70, concatLimit = 67;
                if (cpLen <= singleLimit) { var segments = 1, perSeg = singleLimit, used = cpLen; }
                else { var segments = Math.ceil(cpLen / concatLimit), perSeg = concatLimit, used = cpLen - ((segments - 1) * concatLimit); }
                return { encoding: 'UCS-2', chars: cpLen, segments: segments, perSegment: perSeg, remaining: perSeg - used };
            }
        }

        function updateCounterForTextarea(textareaEl, counterEl) {
            var metrics = smsMetrics(textareaEl.value || '');
            var encEl = counterEl.querySelector('.rc-sms-encoding strong');
            var lenEl = counterEl.querySelector('.rc-sms-length strong');
            var unitsEl = counterEl.querySelector('.rc-sms-units strong');
            var remEl = counterEl.querySelector('.rc-sms-remaining strong');
            if (encEl) encEl.textContent = metrics.encoding;
            if (lenEl) lenEl.textContent = metrics.chars;
            if (unitsEl) unitsEl.textContent = metrics.segments;
            if (remEl) remEl.textContent = metrics.remaining;
        }

        function attachCounters() {
            var counters = document.querySelectorAll('.rc-sms-counter');
            counters.forEach(function(counter){
                var target = counter.getAttribute('data-target');
                var textarea = target ? document.querySelector(target) : null;
                if (!textarea) textarea = counter.parentNode.querySelector('textarea.rc-sms-template');
                if (!textarea) return;
                updateCounterForTextarea(textarea, counter);
                textarea.addEventListener('input', function(){ updateCounterForTextarea(textarea, counter); });
                textarea.addEventListener('paste', function(){ setTimeout(function(){ updateCounterForTextarea(textarea, counter); }, 50); });
            });
        }

        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', attachCounters);
        else attachCounters();
    })();
    </script>
    <?php
}

/* -------------------------------------------------------------------------
 * Send SMS on WC order status changed
 * ------------------------------------------------------------------------- */
function rc_textmagic_send_sms_on_status_change( $order_id, $old_status, $new_status, $order ) {
    if ( ! $order || ! is_object( $order ) ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;
    }

    $settings = get_option( RC_TEXTMAGIC_OPTION_KEY, array( 'username' => '', 'api_key' => '', 'sender' => '', 'enabled' => 1 ) );
    if ( empty( $settings['enabled'] ) ) return;

    $templates = get_option( RC_TEXTMAGIC_TEMPLATES_KEY, array() );
    if ( empty( $templates ) || ! is_array( $templates ) ) return;

    if ( ! isset( $templates[ $new_status ] ) ) return;

    $meta_key = '_rc_textmagic_sent_' . $new_status;
    if ( get_post_meta( $order->get_id(), $meta_key, true ) ) return;

    $phone = $order->get_billing_phone();
    if ( empty( $phone ) ) return;

    $template = $templates[ $new_status ];
    $first_name = $order->get_billing_first_name();
    $last_name  = $order->get_billing_last_name();
    $customer_name = trim( $first_name . ' ' . $last_name );
    $order_total_raw = method_exists( $order, 'get_formatted_order_total' ) ? $order->get_formatted_order_total() : '';
    $order_total = wp_strip_all_tags( $order_total_raw );
    $order_currency = method_exists( $order, 'get_currency' ) ? $order->get_currency() : '';

    $replacements = array(
        '{first_name}'     => $first_name,
        '{last_name}'      => $last_name,
        '{customer_name}'  => $customer_name,
        '{order_number}'   => $order->get_order_number(),
        '{order_total}'    => $order_total,
        '{order_currency}' => $order_currency,
        '{order_status}'   => $new_status,
        '{site_name}'      => get_bloginfo( 'name' ),
        '{order_link}'     => admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' ),
        '{phone}'          => $phone,
    );
    $message = strtr( $template, $replacements );

    $phone_clean = preg_replace( '/[^\d\+]/', '', $phone );
    if ( $phone_clean === '' ) return;

    $res = rc_textmagic_send_message( $phone_clean, $message, $settings );
    if ( is_wp_error( $res ) ) return;

    update_post_meta( $order->get_id(), $meta_key, current_time( 'mysql' ) );
}

/* -------------------------------------------------------------------------
 * Send via TextMagic REST API
 * ------------------------------------------------------------------------- */
function rc_textmagic_send_message( $to, $text, $settings = null ) {
    if ( is_null( $settings ) ) $settings = get_option( RC_TEXTMAGIC_OPTION_KEY, array() );

    $username = isset( $settings['username'] ) ? trim( $settings['username'] ) : '';
    $api_key  = isset( $settings['api_key'] ) ? trim( $settings['api_key'] ) : '';
    $sender   = isset( $settings['sender'] ) ? trim( $settings['sender'] ) : '';

    $log = array( 'to' => $to, 'text' => $text, 'username' => $username ? substr( $username, 0, 6 ) . '...' : '' );

    if ( $username === '' || $api_key === '' ) {
        $err = new WP_Error( 'textmagic_no_credentials', 'TextMagic username or API key not configured.' );
        $log['result'] = 'error';
        $log['error'] = $err->get_error_message();
        rc_textmagic_log_send( $log );
        return $err;
    }

    $endpoint = 'https://rest.textmagic.com/api/v2/messages';
    $payload = array( 'phones' => $to, 'text' => $text );
    if ( $sender !== '' ) $payload['from'] = $sender;

    $auth_header = 'Basic ' . base64_encode( $username . ':' . $api_key );

    if ( function_exists( 'wp_safe_remote_post' ) ) {
        $args = array(
            'body'    => $payload,
            'timeout' => 15,
            'headers' => array(
                'Authorization' => $auth_header,
                'Accept'        => 'application/json',
            ),
        );
        $response = wp_safe_remote_post( $endpoint, $args );
        if ( is_wp_error( $response ) ) {
            $log['result'] = 'error';
            $log['error'] = $response->get_error_message();
            rc_textmagic_log_send( $log );
            return $response;
        }
        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $decoded = json_decode( $body, true );
        if ( $code >= 200 && $code < 300 ) {
            $log['result'] = 'success';
            $log['http_code'] = $code;
            $log['response'] = is_array( $decoded ) ? $decoded : $body;
            rc_textmagic_log_send( $log );
            return array( 'response' => $decoded, 'http_code' => $code );
        } else {
            $msg = '';
            if ( isset( $decoded['message'] ) ) $msg = $decoded['message'];
            elseif ( isset( $decoded['errors'] ) ) $msg = wp_json_encode( $decoded['errors'] );
            else $msg = 'HTTP ' . $code . ' - ' . $body;
            $err = new WP_Error( 'textmagic_api_error', $msg, array( 'http_code' => $code, 'response' => $decoded ) );
            $log['result'] = 'error';
            $log['http_code'] = $code;
            $log['error'] = $msg;
            rc_textmagic_log_send( $log );
            return $err;
        }
    }

    if ( function_exists( 'curl_init' ) ) {
        $ch = curl_init();
        curl_setopt( $ch, CURLOPT_URL, $endpoint );
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
        curl_setopt( $ch, CURLOPT_POST, true );
        curl_setopt( $ch, CURLOPT_POSTFIELDS, http_build_query( $payload ) );
        curl_setopt( $ch, CURLOPT_USERPWD, $username . ':' . $api_key );
        curl_setopt( $ch, CURLOPT_HTTPHEADER, array( 'Accept: application/json', 'Content-Type: application/x-www-form-urlencoded' ) );
        curl_setopt( $ch, CURLOPT_TIMEOUT, 15 );
        $response_body = curl_exec( $ch );
        $curl_errno = curl_errno( $ch );
        $curl_error = curl_error( $ch );
        $http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        curl_close( $ch );

        if ( $curl_errno ) {
            $err = new WP_Error( 'textmagic_curl_error', 'cURL error: ' . $curl_error );
            $log['result'] = 'error';
            $log['error'] = $curl_error;
            rc_textmagic_log_send( $log );
            return $err;
        }

        if ( empty( $response_body ) ) {
            $err = new WP_Error( 'textmagic_no_response', 'No response from TextMagic API.' );
            $log['result'] = 'error';
            $log['error'] = 'empty response';
            rc_textmagic_log_send( $log );
            return $err;
        }

        $decoded = json_decode( $response_body, true );
        if ( $http_code >= 200 && $http_code < 300 ) {
            $log['result'] = 'success';
            $log['http_code'] = $http_code;
            $log['response'] = is_array( $decoded ) ? $decoded : $response_body;
            rc_textmagic_log_send( $log );
            return array( 'response' => $decoded, 'http_code' => $http_code );
        }

        $err_msg = '';
        if ( isset( $decoded['message'] ) ) $err_msg = is_array( $decoded['message'] ) ? wp_json_encode( $decoded['message'] ) : $decoded['message'];
        elseif ( isset( $decoded['errors'] ) ) $err_msg = is_array( $decoded['errors'] ) ? wp_json_encode( $decoded['errors'] ) : (string) $decoded['errors'];
        else $err_msg = 'HTTP ' . $http_code . ' - ' . wp_json_encode( $decoded );

        $err = new WP_Error( 'textmagic_api_error', $err_msg, array( 'http_code' => $http_code, 'response' => $decoded ) );
        $log['result'] = 'error';
        $log['http_code'] = $http_code;
        $log['error'] = $err_msg;
        rc_textmagic_log_send( $log );
        return $err;
    }

    $err = new WP_Error( 'textmagic_no_transport', 'No HTTP transport available (wp_remote_post or cURL required).' );
    $log['result'] = 'error';
    $log['error'] = $err->get_error_message();
    rc_textmagic_log_send( $log );
    return $err;
}

/* -------------------------------------------------------------------------
 * AUTOMATIC ORDER STATUS RULES (per-site)
 * ------------------------------------------------------------------------- */

/* Admin page for rules (submenu already registered earlier) */
function rc_auto_status_rules_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( __( 'Insufficient permissions' ) );
    }

    // Load rules
    $rules = get_option( RC_AUTO_STATUS_RULES_OPTION, array() );

    // Add rule
    if ( isset( $_POST['rc_auto_status_add_nonce'] ) && wp_verify_nonce( wp_unslash( $_POST['rc_auto_status_add_nonce'] ), 'rc_auto_status_add' ) ) {
        $raw = wp_unslash( $_POST );
        $current_status = isset( $raw['current_status'] ) ? sanitize_text_field( $raw['current_status'] ) : '';
        $payment_method = isset( $raw['payment_method'] ) ? sanitize_text_field( $raw['payment_method'] ) : 'any';
        $target_status  = isset( $raw['target_status'] ) ? sanitize_text_field( $raw['target_status'] ) : '';
        $delay_minutes  = isset( $raw['delay_minutes'] ) ? $raw['delay_minutes'] : '';

        $errors = array();
        if ( $current_status === '' ) $errors[] = 'Current order status is required.';
        if ( $target_status === '' ) $errors[] = 'Updated order status is required.';
        if ( $delay_minutes === '' || ! preg_match( '/^\d+(\.\d+)?$/', (string) $delay_minutes ) || floatval( $delay_minutes ) <= 0 ) {
            $errors[] = 'Delay (minutes) must be a positive number (fraction allowed).';
        }

        if ( empty( $errors ) ) {
            $rule_id = uniqid( 'rcas_', true );
            $rule = array(
                'id' => $rule_id,
                'current_status' => $current_status,
                'payment_method' => $payment_method,
                'target_status' => $target_status,
                'delay_minutes' => (float) $delay_minutes,
                'created' => current_time( 'mysql' ),
            );
            $rules[] = $rule;
            update_option( RC_AUTO_STATUS_RULES_OPTION, $rules );
            echo '<div class="updated"><p>Rule added.</p></div>';
        } else {
            echo '<div class="error"><p>' . implode( '<br>', array_map( 'esc_html', $errors ) ) . '</p></div>';
        }
    }

    // Delete rule
    if ( isset( $_GET['rc_auto_action'] ) && $_GET['rc_auto_action'] === 'delete' && isset( $_GET['rule_id'] ) ) {
        $rule_id = sanitize_text_field( wp_unslash( $_GET['rule_id'] ) );
        if ( isset( $_GET['_wpnonce'] ) && wp_verify_nonce( wp_unslash( $_GET['_wpnonce'] ), 'rc_auto_delete_' . $rule_id ) ) {
            $new = array();
            foreach ( $rules as $r ) {
                if ( isset( $r['id'] ) && $r['id'] === $rule_id ) {
                    continue;
                }
                $new[] = $r;
            }
            update_option( RC_AUTO_STATUS_RULES_OPTION, $new );
            $rules = $new;
            echo '<div class="updated"><p>Rule deleted.</p></div>';
        } else {
            echo '<div class="error"><p>Invalid nonce for delete.</p></div>';
        }
    }

    // Render UI
    $wc_statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
    $status_options = array();
    foreach ( $wc_statuses as $k => $label ) {
        $slug = preg_replace( '/^wc-/', '', $k );
        $status_options[ $slug ] = $label;
    }

    $payment_options = array( 'any' => 'Any payment method' );
    if ( function_exists( 'WC' ) ) {
        try {
            $gateways = wc()->payment_gateways()->payment_gateways();
            foreach ( $gateways as $id => $gw ) {
                $title = is_object( $gw ) && isset( $gw->title ) ? $gw->title : $id;
                $payment_options[ $id ] = $title;
            }
        } catch ( Exception $e ) {
            // ignore
        }
    }

    ?>
    <div class="wrap">
        <h1>Automatic Order Status Rules (per-site)</h1>

        <p>Define rules that automatically change an order's status after a configured delay when the order reaches a specific status (optionally restricted by payment method).</p>

        <h2>Add new rule</h2>
        <form method="post" action="">
            <?php wp_nonce_field( 'rc_auto_status_add', 'rc_auto_status_add_nonce' ); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="rc_current_status">Current Order status</label></th>
                    <td>
                        <select id="rc_current_status" name="current_status" required>
                            <option value="">— select —</option>
                            <?php foreach ( $status_options as $slug => $label ) : ?>
                                <option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="rc_payment_method">If payment method</label></th>
                    <td>
                        <select id="rc_payment_method" name="payment_method">
                            <?php foreach ( $payment_options as $pm_id => $pm_label ) : ?>
                                <option value="<?php echo esc_attr( $pm_id ); ?>"><?php echo esc_html( $pm_label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">Choose "Any payment method" to ignore payment gateway.</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="rc_target_status">Updated Order status</label></th>
                    <td>
                        <select id="rc_target_status" name="target_status" required>
                            <option value="">— select —</option>
                            <?php foreach ( $status_options as $slug => $label ) : ?>
                                <option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="rc_delay_minutes">Delay (minutes)</label></th>
                    <td>
                        <input id="rc_delay_minutes" name="delay_minutes" type="text" pattern="^\d+(\.\d+)?$" placeholder="e.g. 15 or 0.5" required />
                        <p class="description">Positive fractional number allowed. After this many minutes the rule will attempt to update the order's status.</p>
                    </td>
                </tr>
            </table>

            <p><input type="submit" class="button-primary" value="Add Rule" /></p>
        </form>

        <h2>Existing rules</h2>
        <?php if ( empty( $rules ) ) : ?>
            <p>No rules defined.</p>
        <?php else : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Trigger status</th>
                        <th>Payment method</th>
                        <th>Target status</th>
                        <th>Delay (minutes)</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $rules as $r ) : ?>
                        <tr>
                            <td><?php echo esc_html( $r['id'] ); ?></td>
                            <td><?php echo esc_html( isset( $status_options[ $r['current_status'] ] ) ? $status_options[ $r['current_status'] ] : $r['current_status'] ); ?></td>
                            <td><?php
                                $pm = $r['payment_method'] ?? 'any';
                                echo esc_html( isset( $payment_options[ $pm ] ) ? $payment_options[ $pm ] : $pm );
                                ?></td>
                            <td><?php echo esc_html( isset( $status_options[ $r['target_status'] ] ) ? $status_options[ $r['target_status'] ] : $r['target_status'] ); ?></td>
                            <td><?php echo esc_html( rtrim( rtrim( number_format_i18n( (float) $r['delay_minutes'], 4 ), '0' ), '.' ) ); ?></td>
                            <td><?php echo esc_html( $r['created'] ?? '' ); ?></td>
                            <td>
                                <?php
                                $del_url = add_query_arg( array(
                                    'page' => 'rc-auto-status-rules',
                                    'rc_auto_action' => 'delete',
                                    'rule_id' => $r['id'],
                                ), admin_url( 'admin.php' ) );
                                $del_url = wp_nonce_url( $del_url, 'rc_auto_delete_' . $r['id'] );
                                ?>
                                <a class="button" href="<?php echo esc_url( $del_url ); ?>" onclick="return confirm('Delete this rule?');">Delete</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h2>Notes</h2>
        <ul>
            <li>The scheduled update will only run if the order is still in the configured trigger (current) status when the timer expires.</li>
            <li>If the order's status has changed in the meantime (including manually), the scheduled update will not apply.</li>
            <li>When the plugin updates the order status, WooCommerce's normal status-change hooks run; that will trigger SMS templates configured above.</li>
        </ul>
    </div>
    <?php
}

/* Listen for order status changes and schedule/unschedule tasks */
add_action( 'woocommerce_order_status_changed', 'rc_auto_status_maybe_schedule', 15, 4 );
function rc_auto_status_maybe_schedule( $order_id, $old_status, $new_status, $order ) {
    if ( ! $order || ! is_object( $order ) ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;
    }

    $rules = get_option( RC_AUTO_STATUS_RULES_OPTION, array() );
    if ( empty( $rules ) ) return;

    foreach ( $rules as $rule ) {
        if ( ! isset( $rule['id'], $rule['current_status'], $rule['target_status'], $rule['delay_minutes'] ) ) continue;

        $rule_id = $rule['id'];

        if ( $new_status === $rule['current_status'] ) {
            $pmatch = ( ! empty( $rule['payment_method'] ) && $rule['payment_method'] !== 'any' )
                ? ( $order->get_payment_method() === $rule['payment_method'] )
                : true;

            if ( $pmatch ) {
                $meta_key = '_rc_auto_status_scheduled_' . $rule_id;
                $existing_ts = get_post_meta( $order->get_id(), $meta_key, true );
                if ( $existing_ts ) continue;

                $delay_minutes = (float) $rule['delay_minutes'];
                $delay_seconds = (int) max( 1, round( $delay_minutes * 60 ) );
                $timestamp = time() + $delay_seconds;

                $payload = array(
                    'order_id' => $order->get_id(),
                    'rule_id' => $rule_id,
                    'trigger_status' => $rule['current_status'],
                    'target_status' => $rule['target_status'],
                );

                if ( function_exists( 'wp_schedule_single_event' ) ) {
                    wp_schedule_single_event( $timestamp, 'rc_auto_status_change_execute', array( $payload ) );
                    update_post_meta( $order->get_id(), $meta_key, (int) $timestamp );
                }
            }
        } else {
            $meta_key = '_rc_auto_status_scheduled_' . $rule_id;
            $existing_ts = get_post_meta( $order->get_id(), $meta_key, true );
            if ( $existing_ts ) {
                if ( function_exists( 'wp_unschedule_event' ) ) {
                    $payload = array(
                        'order_id' => $order->get_id(),
                        'rule_id' => $rule_id,
                        'trigger_status' => $rule['current_status'],
                        'target_status' => $rule['target_status'],
                    );
                    wp_unschedule_event( (int) $existing_ts, 'rc_auto_status_change_execute', array( $payload ) );
                }
                delete_post_meta( $order->get_id(), $meta_key );
            }
        }
    }
}

/* Scheduled callback */
add_action( 'rc_auto_status_change_execute', 'rc_auto_status_execute_callback' );
function rc_auto_status_execute_callback( $payload = array() ) {
    if ( ! is_array( $payload ) ) return;

    $order_id = isset( $payload['order_id'] ) ? intval( $payload['order_id'] ) : 0;
    $rule_id  = isset( $payload['rule_id'] ) ? sanitize_text_field( $payload['rule_id'] ) : '';
    $trigger_status = isset( $payload['trigger_status'] ) ? sanitize_text_field( $payload['trigger_status'] ) : '';
    $target_status  = isset( $payload['target_status'] ) ? sanitize_text_field( $payload['target_status'] ) : '';

    if ( ! $order_id || $rule_id === '' || $target_status === '' ) return;

    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        delete_post_meta( $order_id, '_rc_auto_status_scheduled_' . $rule_id );
        return;
    }

    delete_post_meta( $order_id, '_rc_auto_status_scheduled_' . $rule_id );

    $rules = get_option( RC_AUTO_STATUS_RULES_OPTION, array() );
    $found = false;
    foreach ( $rules as $r ) {
        if ( isset( $r['id'] ) && $r['id'] === $rule_id ) {
            $found = $r; break;
        }
    }
    if ( ! $found ) return;

    $current_status = $order->get_status();
    if ( $current_status !== $trigger_status ) return;

    if ( ! empty( $found['payment_method'] ) && $found['payment_method'] !== 'any' ) {
        $pmatch = ( $order->get_payment_method() === $found['payment_method'] );
        if ( ! $pmatch ) return;
    }

    if ( $current_status === $target_status ) return;

    try {
        $note = sprintf( 'Automatic status change by rule %s: %s -> %s (scheduled action).', $rule_id, $trigger_status, $target_status );
        $order->update_status( $target_status, $note );
    } catch ( \Throwable $e ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf( '[RC-AutoStatus][ERROR] order %d rule %s update_status failed: %s', $order_id, $rule_id, $e->getMessage() ) );
        }
    }
}

/* -------------------------------------------------------------------------
 * END OF PLUGIN
 * ------------------------------------------------------------------------- */