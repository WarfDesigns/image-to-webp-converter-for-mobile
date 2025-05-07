<?php
/**
 * Plugin Name: Image to WebP Converter for Mobile
 * Description: Automatically converts uploaded JPEG and PNG images to optimized WebP format (with mobile-friendly resizing) and registers the WebP files in the Media Library. Also provides a dashboard tool to bulk-scan and register any WebP images.
 * Version: 1.3
 * Author: Warf Designs LLC (Brent Warf)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * Convert a given image file (JPEG or PNG) to WebP, resizing if the width exceeds the mobile-friendly limit.
 *
 * @param string $file_path The full path to the image file.
 * @return mixed Returns the new WebP file path on success, false on failure.
 */
function itwc_convert_file_to_webp( $file_path ) {
    $file_type = wp_check_filetype( $file_path );
    $mime_type = $file_type['type'];
    
    // Only process JPEG and PNG files and ensure WebP is supported.
    if ( ! in_array( $mime_type, array( 'image/jpeg', 'image/png' ) ) || ! function_exists( 'imagewebp' ) ) {
        return false;
    }
    
    // Load the image.
    if ( $mime_type === 'image/jpeg' ) {
        $image = imagecreatefromjpeg( $file_path );
    } elseif ( $mime_type === 'image/png' ) {
        $image = imagecreatefrompng( $file_path );
    }
    
    if ( ! $image ) {
        return false;
    }
    
    // Get original dimensions.
    $width  = imagesx( $image );
    $height = imagesy( $image );
    
    // Resize for mobile if the image is too wide.
    $max_width = 1024; // Change this value if needed.
    if ( $width > $max_width ) {
        $ratio      = $max_width / $width;
        $new_width  = $max_width;
        $new_height = $height * $ratio;
        
        $resized_image = imagecreatetruecolor( $new_width, $new_height );
        
        // Preserve transparency for PNG images.
        if ( $mime_type === 'image/png' ) {
            imagealphablending( $resized_image, false );
            imagesavealpha( $resized_image, true );
        }
        
        imagecopyresampled( $resized_image, $image, 0, 0, 0, 0, $new_width, $new_height, $width, $height );
        imagedestroy( $image );
        $image = $resized_image;
    }
    
    // Generate new filename with a .webp extension.
    $webp_file = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $file_path );
    
    $quality = 80; // Adjust quality as needed (0-100).
    $result  = imagewebp( $image, $webp_file, $quality );
    imagedestroy( $image );
    
    return $result ? $webp_file : false;
}

/**
 * Automatically convert new image uploads to WebP.
 *
 * @param array $upload The upload data array.
 * @return array The (unmodified) upload data array.
 */
function itwc_convert_image_on_upload( $upload ) {
    $file_path = $upload['file'];
    $webp_file = itwc_convert_file_to_webp( $file_path );
    
    // If conversion succeeded, attempt to register the WebP file.
    if ( $webp_file && file_exists( $webp_file ) ) {
        // We'll register the WebP file once the attachment is added (via the add_attachment hook).
        // For now, nothing additional is needed here.
    }
    return $upload;
}
add_filter( 'wp_handle_upload', 'itwc_convert_image_on_upload' );

/**
 * When a new attachment is added, register its WebP version (if it exists) in the Media Library.
 *
 * @param int $post_ID Attachment post ID.
 */
function itwc_register_webp_attachment_on_add( $post_ID ) {
    $file = get_attached_file( $post_ID );
    
    // Only proceed if the attachment is a JPEG or PNG.
    $file_type = wp_check_filetype( $file );
    if ( ! in_array( $file_type['type'], array( 'image/jpeg', 'image/png' ) ) ) {
         return;
    }
    
    // Build the expected WebP file path.
    $webp_file = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $file );
    if ( file_exists( $webp_file ) ) {
         // Check if this WebP file is already registered.
         $relative_path = str_replace( wp_upload_dir()['basedir'] . '/', '', $webp_file );
         $args = array(
             'post_type'  => 'attachment',
             'meta_query' => array(
                 array(
                    'key'     => '_wp_attached_file',
                    'value'   => $relative_path,
                    'compare' => '='
                 )
             ),
         );
         $query = new WP_Query( $args );
         if ( $query->have_posts() ) {
             return;
         }
         
         // Prepare attachment data.
         $upload_dir = wp_upload_dir();
         $attachment = array(
             'guid'           => trailingslashit( $upload_dir['baseurl'] ) . $relative_path,
             'post_mime_type' => 'image/webp',
             'post_title'     => preg_replace( '/\.[^.]+$/', '', basename( $webp_file ) ),
             'post_content'   => '',
             'post_status'    => 'inherit',
         );
         // Set the original attachment as the parent.
         $attach_id = wp_insert_attachment( $attachment, $webp_file, $post_ID );
         if ( ! is_wp_error( $attach_id ) ) {
             require_once( ABSPATH . 'wp-admin/includes/image.php' );
             $attach_data = wp_generate_attachment_metadata( $attach_id, $webp_file );
             wp_update_attachment_metadata( $attach_id, $attach_data );
         }
    }
}
add_action( 'add_attachment', 'itwc_register_webp_attachment_on_add' );

/**
 * Bulk-scan the uploads directory and register any unregistered WebP images in the Media Library.
 *
 * @return int Number of newly registered attachments.
 */
function itwc_register_all_webp_attachments() {
    $upload_dir = wp_upload_dir();
    $base_dir   = $upload_dir['basedir'];
    $base_url   = $upload_dir['baseurl'];
    $registered = 0;
    
    // Use RecursiveDirectoryIterator to scan for files.
    $iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base_dir ) );
    foreach ( $iterator as $file ) {
        if ( ! $file->isFile() ) {
            continue;
        }
        // Only consider .webp files.
        if ( strtolower( pathinfo( $file->getFilename(), PATHINFO_EXTENSION ) ) !== 'webp' ) {
            continue;
        }
        
        $full_path    = $file->getPathname();
        $relative_path = str_replace( trailingslashit( $base_dir ), '', $full_path );
        
        // Check if an attachment for this file already exists.
        $args = array(
            'post_type'  => 'attachment',
            'meta_query' => array(
                array(
                    'key'     => '_wp_attached_file',
                    'value'   => $relative_path,
                    'compare' => '='
                )
            ),
        );
        $query = new WP_Query( $args );
        if ( $query->have_posts() ) {
            continue;
        }
        
        // Register the WebP file.
        $attachment = array(
            'guid'           => trailingslashit( $base_url ) . $relative_path,
            'post_mime_type' => 'image/webp',
            'post_title'     => preg_replace( '/\.[^.]+$/', '', basename( $full_path ) ),
            'post_content'   => '',
            'post_status'    => 'inherit',
        );
        $attach_id = wp_insert_attachment( $attachment, $full_path, 0 );
        if ( ! is_wp_error( $attach_id ) ) {
            require_once( ABSPATH . 'wp-admin/includes/image.php' );
            $attach_data = wp_generate_attachment_metadata( $attach_id, $full_path );
            wp_update_attachment_metadata( $attach_id, $attach_data );
            $registered++;
        }
    }
    
    return $registered;
}

/**
 * Add a top-level admin menu for the WebP Converter.
 */
function itwc_add_admin_menu() {
    add_menu_page(
        'WebP Converter',       // Page title.
        'WebP Converter',       // Menu title.
        'manage_options',       // Capability.
        'itwc-webp-converter',  // Menu slug.
        'itwc_admin_page',      // Callback function.
        'dashicons-format-image', // Icon.
        65                      // Menu position.
    );
}
add_action( 'admin_menu', 'itwc_add_admin_menu' );

/**
 * Render the admin page for bulk converting and registering WebP files.
 */
function itwc_admin_page() {
    // Process conversion if the bulk conversion form is submitted.
    if ( isset( $_POST['itwc_convert_all'] ) && check_admin_referer( 'itwc_convert_all_action', 'itwc_nonce' ) ) {
        $converted = 0;
        $failed    = 0;
        
        // Query all JPEG and PNG attachments.
        $args  = array(
            'post_type'      => 'attachment',
            'post_mime_type' => array( 'image/jpeg', 'image/png' ),
            'posts_per_page' => -1,
            'post_status'    => 'inherit',
        );
        $query = new WP_Query( $args );
        
        if ( $query->have_posts() ) {
            foreach ( $query->posts as $attachment ) {
                $file_path = get_attached_file( $attachment->ID );
                // Skip if already a WebP file.
                if ( file_exists( $file_path ) && ! preg_match( '/\.webp$/i', $file_path ) ) {
                    $result = itwc_convert_file_to_webp( $file_path );
                    if ( $result ) {
                        $converted++;
                    } else {
                        $failed++;
                    }
                }
            }
        }
        wp_reset_postdata();
        
        echo '<div class="updated notice"><p>';
        echo sprintf( 'Conversion complete. %d image(s) converted. %d image(s) failed to convert.', $converted, $failed );
        echo '</p></div>';
    }
    
    // Run the bulk registration of unregistered WebP files.
    $registered_count = itwc_register_all_webp_attachments();
    if ( $registered_count > 0 ) {
        echo '<div class="updated notice"><p>';
        echo sprintf( 'Bulk registration complete. %d WebP image(s) were added to the Media Library.', $registered_count );
        echo '</p></div>';
    }
    ?>
    <div class="wrap">
        <h1>WebP Converter</h1>
        <p>This tool converts all JPEG and PNG images in your Media Library to optimized WebP format with a mobile-friendly width limit of 1024px, and automatically registers the WebP files in your Media Library.</p>
        <form method="post">
            <?php wp_nonce_field( 'itwc_convert_all_action', 'itwc_nonce' ); ?>
            <p>
                <input type="submit" name="itwc_convert_all" class="button button-primary" value="Convert All Media Files">
            </p>
        </form>
    </div>
    <?php
}
?>
