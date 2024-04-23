<?php
/*
Plugin Name: WooCommerce Order Tracking Manager
Description: A free WooCommerce plugin to manage shipping companies and notify users with tracking information.
Version: 1.0
Author: Richard Avenia
*/

// Save custom fields
function save_tracking_number_field( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! empty( $_POST['tracking_number'] ) ) {
        $order->update_meta_data( '_tracking_number', sanitize_text_field( $_POST['tracking_number'] ) );
		$order->update_meta_data( '_shipping_company', sanitize_text_field( $_POST['shipping_company'] ) );
		if ( isset( $_POST['complete_order'] ) && $order->get_status() == 'processing' ) {
			$_POST['order_status'] = 'completed';
			$order->update_status( 'completed' );
		}
    }
    $order->save();
}
add_action( 'woocommerce_process_shop_order_meta', 'save_tracking_number_field' );

// Function to inject tracking information into email template
function inject_tracking_info_into_email( $order, $sent_to_admin, $plain_text, $email ) {
    // Ensure the email is being sent to the customer and is for the "completed" status
    if ( ! $sent_to_admin && 'customer_completed_order' === $email->id ) {
        // Retrieve tracking number and shipping company ID from order
        $tracking_number = $order->get_meta( '_tracking_number', true );
        $shipping_company_id = $order->get_meta( '_shipping_company', true );

        // Retrieve shipping companies from options
        $shipping_companies = get_option('shipping_companies', array());

        // Check if shipping company ID exists in shipping companies
        if ( isset($shipping_companies[$shipping_company_id]) ) {
            $shipping_company = $shipping_companies[$shipping_company_id];
            $shipping_company_url_template = isset($shipping_company['url_template']) ? $shipping_company['url_template'] : '';

            // Replace {tracking_number} placeholder with actual tracking number in URL template
            $tracking_url = str_replace('{tracking_number}', $tracking_number, $shipping_company_url_template);

            // Output tracking information in the email with the tracking URL
            echo '<p style="color:black"><strong>Tracking Number:</strong> <a href="' . esc_url($tracking_url) . '">' . esc_html($tracking_number) . '</a></p>';
			echo '<p style="color:black">or copy and paste the following link in a browser: <a href="' . esc_url($tracking_url) . '">' . esc_url($tracking_url) . '</a></p>';
        }
    }
}

add_action( 'woocommerce_email_order_meta', 'inject_tracking_info_into_email', 10, 4 );


// Add a menu item in the WordPress admin dashboard
function add_shipping_companies_page() {
    add_menu_page(
        'Shipping Companies',
        'Shipping Companies',
        'manage_options',
        'shipping-companies',
        'shipping_companies_page_content'
    );
}
add_action('admin_menu', 'add_shipping_companies_page');


// Content for the custom admin page
function add_tracking_number_field( $order ) {
	$shipping_companies = get_option('shipping_companies', array());
    ?>
    <div class="shipping_information">
		<h4><?php _e( 'Tracking Information', 'your-text-domain' ); ?></h4>
		<p class="form-field">
			<label for="tracking_number"><?php _e( 'Tracking Number', 'your-text-domain' ); ?></label>
			<input type="text" class="short" name="tracking_number" id="tracking_number" value="<?php echo esc_attr( $order->get_meta( '_tracking_number', true ) ); ?>" />
		</p>
		<p class="form-field">
			<label for="shipping_provider"><?php _e( 'Shipping Provider', 'your-text-domain' ); ?></label>
			<select name="shipping_company" id="shipping_company">
				<?php foreach ($shipping_companies as $company_id => $company_data) : ?>
					<option <?php echo $company_data['is_default'] ? 'selected' : ''; ?> value="<?php echo esc_html($company_id); ?>"><?php echo esc_html($company_data['name']); ?></option>
                <?php endforeach; ?>
            </select>
		</p>
		<p class="form-field">
			<label style="cursor:pointer"><input style="width:fit-content; border:none" type="checkbox" name="complete_order" id="complete_order" checked />Complete Order</label>	
		</p>
    </div>
    <?php
}
add_action( 'woocommerce_admin_order_data_after_shipping_address', 'add_tracking_number_field', 10, 1 );


// Content for the custom admin page
function shipping_companies_page_content() {
    if (isset($_POST['submit_shipping_company'])) {
        // Handle form submission to add a new shipping company
        $shipping_company_id = uniqid(); // Generate a unique ID for the shipping company
        $shipping_company_name = sanitize_text_field($_POST['shipping_company_name']);
        $shipping_company_url_template = sanitize_text_field($_POST['shipping_company_url_template']);
        // Save shipping company data into the database
        // We can use custom post types or WordPress options API to store this data
        // For simplicity, let's assume we're using the options API
        $shipping_companies = get_option('shipping_companies', array());
        $is_default = (empty($shipping_companies) || isset($_POST['set_default'])) ? true : false; // Set as default if there are no shipping companies or if checkbox is checked
        $shipping_companies[$shipping_company_id] = array(
            'name' => $shipping_company_name,
            'url_template' => $shipping_company_url_template,
            'is_default' => $is_default,
        );
        // Clear any existing default if it's not the first company being added
        if ($is_default && count($shipping_companies) > 1) {
            foreach ($shipping_companies as $key => $value) {
                if ($key !== $shipping_company_id) {
                    $shipping_companies[$key]['is_default'] = false;
                }
            }
        }
        update_option('shipping_companies', $shipping_companies);
        echo '<div class="updated"><p>Shipping company added successfully.</p></div>';
    }

    // Handle deleting shipping companies
    if (isset($_GET['delete_shipping_company'])) {
        $deleted_shipping_company_id = sanitize_text_field($_GET['delete_shipping_company']);
        $shipping_companies = get_option('shipping_companies', array());
        if (isset($shipping_companies[$deleted_shipping_company_id])) {
            unset($shipping_companies[$deleted_shipping_company_id]);
            update_option('shipping_companies', $shipping_companies);
            // Redirect back to the same page without the delete_shipping_company query parameter
            wp_redirect(admin_url('admin.php?page=shipping-companies'));
            exit();
        }
    }

    // Handle setting shipping company as default
    if (isset($_GET['set_default_shipping_company'])) {
        $default_shipping_company_id = sanitize_text_field($_GET['set_default_shipping_company']);
        $shipping_companies = get_option('shipping_companies', array());
        if (isset($shipping_companies[$default_shipping_company_id])) {
            foreach ($shipping_companies as $key => $value) {
                $shipping_companies[$key]['is_default'] = ($key === $default_shipping_company_id) ? true : false;
            }
            update_option('shipping_companies', $shipping_companies);
            echo '<div class="updated"><p>Default shipping company set successfully.</p></div>';
			// Redirect back to the same page without the $default_shipping_company_id query parameter
            wp_redirect(admin_url('admin.php?page=shipping-companies'));
            exit();
        }
    }

    $star_image_url = plugin_dir_url( __FILE__ ) . 'star.png';

    // Display existing shipping companies
    $shipping_companies = get_option('shipping_companies', array());
    ?>
    <div class="wrap">
        <h2>Shipping Companies</h2>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col" style="font-weight: bold;">Shipping Company Name</th>
                    <th scope="col" style="font-weight: bold;">URL Template</th>
                    <th scope="col" style="width:50px; font-weight: bold;">Default</th>
                    <th scope="col" style="width:200px; font-weight: bold;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($shipping_companies as $company_id => $company_data) : ?>
                    <tr>
                        <td><?php echo esc_html($company_data['name']); ?></td>
                        <td><?php echo esc_html($company_data['url_template']); ?></td>
                        <td><?php echo $company_data['is_default'] ? '<img style="width:20px;height:20px;" src="' . esc_url( $star_image_url ) . '" alt="Default">' : ''; ?></td>
                        <td>
                            <?php if (!$company_data['is_default']) : ?>
                                <a href="<?php echo esc_url(add_query_arg('set_default_shipping_company', $company_id)); ?>">Set as Default</a> | 
                                <a href="<?php echo esc_url(add_query_arg('delete_shipping_company', $company_id)); ?>" onclick="return confirm('Are you sure you want to delete this shipping company?')">Delete</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <hr>
        <form method="post">
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="shipping_company_name">Shipping Company Name</label></th>
                    <td><input type="text" name="shipping_company_name" id="shipping_company_name" required style="width:40em" placeholder="Shipping Company"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="shipping_company_url_template">URL Template</label></th>
                    <td><input type="text" name="shipping_company_url_template" id="shipping_company_url_template" required style="width:40em" placeholder="https://shipping-company.com/tracking?id={tracking_number}"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="set_default">Set as Default</label></th>
                    <td><input type="checkbox" name="set_default" id="set_default" <?php echo empty($shipping_companies) ? 'checked' : ''; ?>></td>
                </tr>
            </table>
            <?php submit_button('Add Shipping Company', 'primary', 'submit_shipping_company'); ?>
        </form>
    </div>
    <?php
}