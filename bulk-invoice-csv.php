<?php
/*
Plugin Name: Bulk Invoice CSV
Plugin URI: https://github.com/julioirawan/WP-Bulk-Invoice-CSV
Description: A plugin to generate bulk invoices in CSV format.
Version: 1.0.1
Author: Julio
GitHub Plugin URI: https://github.com/julioirawan/WP-Bulk-Invoice-CSV
*/

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Add menu item to admin panel
function bulk_invoice_csv_add_menu() {
    add_menu_page(
        'Bulk Invoice CSV',
        'Bulk Invoice CSV',
        'manage_options',
        'bulk-invoice-csv',
        'bulk_invoice_csv_settings_page',
        'dashicons-media-spreadsheet',
        20
    );
}
add_action('admin_menu', 'bulk_invoice_csv_add_menu');

// Settings page content
function bulk_invoice_csv_settings_page() {
    ?>
    <div class="wrap">
        <h1>Bulk Invoice CSV</h1>
        <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="bulk_invoice_csv_upload">
            
            <h3>Upload Student Schedule File</h3>
            <input type="file" name="student_schedule_file" accept=".csv">
            
            <h3>Select Date Range</h3>
            <input type="date" name="start_date"> to 
            <input type="date" name="end_date">
            
            <h3>Enter Holidays (one per line)</h3>
            <textarea name="holidays" rows="5" cols="50"></textarea>
            
            <br><br>
            <input type="submit" name="generate_csv" value="Generate CSV" class="button button-primary">
        </form>
        <br>
        <button id="downloadCsv" class="button button-secondary" onclick="window.location.href='<?php echo esc_url(admin_url('admin-post.php?action=bulk_invoice_csv_download')); ?>'">Download CSV</button>
    </div>
    <?php
}

// Handle file upload and CSV processing
function bulk_invoice_csv_handle_upload() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }
    
    if (!isset($_FILES['student_schedule_file']) || $_FILES['student_schedule_file']['error'] !== UPLOAD_ERR_OK) {
        wp_die(__('File upload failed. Please try again.'));
    }
    
    $file = $_FILES['student_schedule_file'];
    $file_type = pathinfo($file['name'], PATHINFO_EXTENSION);
    
    if ($file_type !== 'csv') {
        wp_die(__('Invalid file type. Please upload a CSV file.'));
    }
    
    $upload_dir = wp_upload_dir();
    $target_path = $upload_dir['path'] . '/' . basename($file['name']);
    
    if (!move_uploaded_file($file['tmp_name'], $target_path)) {
        wp_die(__('Failed to save uploaded file.'));
    }
    
    session_start();
    $_SESSION['bulk_invoice_csv_file'] = $target_path;
    $_SESSION['start_date'] = $_POST['start_date'] ?? '';
    $_SESSION['end_date'] = $_POST['end_date'] ?? '';
    $_SESSION['holidays'] = empty(trim($_POST['holidays'] ?? '')) ? [] : explode("\n", trim($_POST['holidays']));
    
    wp_redirect(admin_url('admin.php?page=bulk-invoice-csv&upload_success=1'));
    exit;
}
add_action('admin_post_bulk_invoice_csv_upload', 'bulk_invoice_csv_handle_upload');

// Function to process CSV data correctly
function bulk_invoice_csv_process_file() {
    session_start();
    if (!isset($_SESSION['bulk_invoice_csv_file'])) {
        error_log('No file uploaded for processing.');
        return [];
    }
    
    $file_path = $_SESSION['bulk_invoice_csv_file'];
    if (!file_exists($file_path)) {
        error_log('Uploaded file not found.');
        return [];
    }
    
    $csv_data = array_map('str_getcsv', file($file_path));
    $headers = array_shift($csv_data);
    if (!$headers) {
        error_log('Missing CSV headers.');
        return [];
    }
    
    $processed_data = [];
    foreach ($csv_data as $row) {
        $row_data = array_combine($headers, $row);
        if (!$row_data) {
            continue;
        }
        
        $student_name = $row_data['Student Name'];
        $email = $row_data['Email'];
        $phone = $row_data['Phone'];
        $teacher = $row_data['Teacher'];
        $course_days_raw = $row_data['Course Days'];
        $price_per_session = (int)$row_data['Price Per Session'];
        $reschedule_adjustment = isset($row_data['Reschedule Adjustment']) ? (int)$row_data['Reschedule Adjustment'] : 0;
        
        // Parse course days
        $course_days = [];
        foreach (explode(", ", $course_days_raw) as $day_entry) {
            $parts = explode(" ", $day_entry);
            $day = $parts[0];
            $sessions = isset($parts[1]) ? (int)$parts[1] : 1;
            $course_days[$day] = $sessions;
        }
        
        // Generate session dates within range
        $start_date = strtotime($_SESSION['start_date']);
        $end_date = strtotime($_SESSION['end_date']);
        $holidays = array_map(function($date) {
    $dateTime = DateTime::createFromFormat('d/m/Y', trim($date));
    return $dateTime ? $dateTime->getTimestamp() : false;
}, $_SESSION['holidays']);

// Remove any invalid dates
$holidays = array_filter($holidays);

        
        $sliced_items = [];
        while ($start_date <= $end_date) {
    $day_name = date('l', $start_date);
    $current_date = date('Y-m-d', $start_date);
    $holiday_dates = array_map(function($timestamp) {
        return date('Y-m-d', $timestamp);
    }, $holidays);
    
    if (isset($course_days[$day_name]) && !in_array($current_date, $holiday_dates)) {
        $sessions = $course_days[$day_name];
        $formatted_date = date('j F Y', $start_date);
        $sliced_items[] = "$sessions|Course ($day_name, $formatted_date)||$price_per_session";
    }
    $start_date = strtotime("+1 day", $start_date);
}

        // Apply Reschedule Adjustment
        if ($reschedule_adjustment > 0) {
    $adjustment_value = $price_per_session * -1;
    $sliced_items[] = "$reschedule_adjustment|Reschedule Adjustment||$adjustment_value";
}
        
        $processed_data[] = [
            'sliced_title' => "Invoice_{$student_name}" . date('_F_Y', strtotime($_SESSION['start_date'])),
            'sliced_description' => $teacher,
            'sliced_author_id' => "",
            'sliced_number' => "",
            'sliced_created' => "",
            'sliced_due' => date('Y-m-10 23:59:00', strtotime($_SESSION['start_date'])),
            'sliced_valid' => "",
            'sliced_items' => implode("\n", $sliced_items),
            'sliced_status' => "unpaid",
            'sliced_client_email' => $email,
            'sliced_client_name' => $student_name,
            'sliced_client_business' => $student_name,
            'sliced_client_address' => "",
            'sliced_client_extra' => $phone
        
        ];
    }
    
    return $processed_data;
}

// Function to generate CSV and allow download
function bulk_invoice_csv_generate_file() {
    session_start();
    $processed_data = bulk_invoice_csv_process_file();
    
    if (empty($processed_data)) {
        wp_die(__('No valid data to generate CSV.'));
    }
    
    // Automatically delete the uploaded file after processing
    if (isset($_SESSION['bulk_invoice_csv_file'])) {
        $file_path = $_SESSION['bulk_invoice_csv_file'];
        if (file_exists($file_path)) {
            unlink($file_path);
        }
        unset($_SESSION['bulk_invoice_csv_file']);
    }
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="bulk_invoice.csv"');
    
    $csv_file = fopen('php://output', 'w');
    fputcsv($csv_file, array_keys($processed_data[0])); // Add headers
    
    foreach ($processed_data as $row) {
        fputcsv($csv_file, $row); // Write each row
    }
    
    fclose($csv_file);
    exit;
}
add_action('admin_post_bulk_invoice_csv_download', 'bulk_invoice_csv_generate_file');

//Github Auto Update
if ( !class_exists( 'WP_GitHub_Updater' ) ) {
    class WP_GitHub_Updater {
        private $plugin_file;
        private $github_repo;
        private $current_version;

        public function __construct( $plugin_file, $github_repo, $current_version ) {
            $this->plugin_file = $plugin_file;
            $this->github_repo = $github_repo;
            $this->current_version = $current_version;

            add_filter( 'pre_set_site_transient_update_plugins', [$this, 'check_for_update'] );
            add_filter( 'plugins_api', [$this, 'plugin_info'], 10, 3 );
        }

        public function check_for_update( $transient ) {
            if ( empty( $transient->checked ) ) {
                return $transient;
            }

            $repo_url = "https://api.github.com/repos/{$this->github_repo}/releases/latest";
            $response = wp_remote_get( $repo_url );

            if ( is_wp_error( $response ) ) {
                return $transient;
            }

            $release_data = json_decode( wp_remote_retrieve_body( $response ) );

            if ( isset( $release_data->tag_name ) && version_compare( $this->current_version, $release_data->tag_name, '<' ) ) {
                $plugin_slug = plugin_basename( $this->plugin_file );

                $transient->response[$plugin_slug] = (object) [
                    'slug' => $plugin_slug,
                    'new_version' => $release_data->tag_name,
                    'package' => $release_data->assets[0]->browser_download_url ?? '',
                    'url' => "https://github.com/{$this->github_repo}"
                ];
            }

            return $transient;
        }

        public function plugin_info( $res, $action, $args ) {
            if ( $action !== 'plugin_information' ) {
                return $res;
            }

            if ( $args->slug !== plugin_basename( $this->plugin_file ) ) {
                return $res;
            }

            $repo_url = "https://api.github.com/repos/{$this->github_repo}";
            $response = wp_remote_get( $repo_url );

            if ( is_wp_error( $response ) ) {
                return $res;
            }

            $repo_data = json_decode( wp_remote_retrieve_body( $response ) );

            $res = (object) [
                'name' => $repo_data->name ?? 'GitHub Plugin',
                'slug' => plugin_basename( $this->plugin_file ),
                'version' => $this->current_version,
                'author' => 'Julio',
                'homepage' => "https://github.com/{$this->github_repo}",
                'sections' => [
                    'description' => $repo_data->description ?? 'GitHub Plugin',
                ],
                'download_link' => "https://github.com/{$this->github_repo}/archive/main.zip"
            ];

            return $res;
        }
    }

    new WP_GitHub_Updater( __FILE__, 'julioirawan/WP-Bulk-Invoice-CSV', '1.0' );
}
