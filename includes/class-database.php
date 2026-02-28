<?php
/**
 * Database handler for contact form submissions
 *
 * @package EasyMultiStepForm
 */

namespace EasyMultiStepForm\Includes;

class Database {

	/**
	 * Create submission table
	 */
	public static function create_submission_table() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'emsf_submissions';
		$charset    = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS $table_name (
			id mediumint(9) NOT NULL AUTO_INCREMENT,
			name varchar(100) NOT NULL,
			email varchar(100) NOT NULL,
			phone varchar(20),
			message longtext NOT NULL,
			status varchar(20) DEFAULT 'new',
			fields_data longtext DEFAULT NULL,
			ip_address varchar(45),
			user_agent text,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY email (email),
			KEY status (status),
			KEY created_at (created_at)
		) $charset;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Save submission to database
	 *
	 * @param array $data Submission data.
	 * @return int|false Submission ID or false on failure.
	 */
	public static function save_submission( $data ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'emsf_submissions';

		$submission = array(
			'name'        => sanitize_text_field( $data['name'] ?? '' ),
			'email'       => sanitize_email( $data['email'] ?? '' ),
			'phone'       => sanitize_text_field( $data['phone'] ?? '' ),
			'message'     => wp_kses_post( $data['message'] ?? '' ),
			'fields_data' => ! empty( $data['fields_data'] ) ? wp_json_encode( $data['fields_data'] ) : null,
			'ip_address'  => self::get_client_ip(),
			'user_agent'  => sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ?? '' ),
		);

		// Suppress errors to allow graceful failure
		$wpdb->suppress_errors();

		$result = $wpdb->insert(
			$table_name,
			$submission,
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		// Fallback: If insert failed (likely due to missing column), try inserting without fields_data
		if ( false === $result ) {
			unset( $submission['fields_data'] );
			$result = $wpdb->insert(
				$table_name,
				$submission,
				array( '%s', '%s', '%s', '%s', '%s', '%s' )
			);
		}

		return false !== $result ? $wpdb->insert_id : false;
	}

	/**
	 * Get client IP address
	 *
	 * @return string Client IP address.
	 */
	public static function get_client_ip() {
		$ip = '';

		if ( ! empty( $_SERVER['HTTP_CLIENT_IP'] ) ) {
			$ip = sanitize_text_field( $_SERVER['HTTP_CLIENT_IP'] );
		} elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$ip = sanitize_text_field( $_SERVER['HTTP_X_FORWARDED_FOR'] );
		} elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( $_SERVER['REMOTE_ADDR'] );
		}

		return $ip;
	}

	/**
	 * Get submission by ID
	 *
	 * @param int $submission_id Submission ID.
	 * @return object|null Submission object or null.
	 */
	public static function get_submission( $submission_id ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'emsf_submissions';

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM $table_name WHERE id = %d",
				$submission_id
			)
		);
	}

	/**
	 * Get all submissions
	 *
	 * @param array $args Query arguments.
	 * @return array Submissions array.
	 */
	public static function get_submissions( $args = array() ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'emsf_submissions';

		$defaults = array(
			'status'  => 'all',
			'search'  => '',
			'orderby' => 'created_at',
			'order'   => 'DESC',
			'limit'   => 50,
			'offset'  => 0,
		);

		$args = wp_parse_args( $args, $defaults );

		$where_clauses = array();

		if ( 'all' !== $args['status'] ) {
			$where_clauses[] = $wpdb->prepare( 'status = %s', $args['status'] );
		}

		if ( ! empty( $args['search'] ) ) {
			$search          = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where_clauses[] = $wpdb->prepare( '(name LIKE %s OR email LIKE %s OR message LIKE %s)', $search, $search, $search );
		}

		$where = ! empty( $where_clauses ) ? 'WHERE ' . implode( ' AND ', $where_clauses ) : '';

		// Validate orderby and order to prevent injection
		$allowed_orderby = array( 'id', 'name', 'email', 'status', 'created_at' );
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
		$order           = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

		$query = $wpdb->prepare(
			"SELECT * FROM $table_name $where ORDER BY $orderby $order LIMIT %d OFFSET %d",
			$args['limit'],
			$args['offset']
		);

		return $wpdb->get_results( $query );
	}

	/**
	 * Get daily submission counts for the last X days
	 * 
	 * @param int $days Number of days to look back.
	 * @return array Daily counts [ 'labels' => [], 'data' => [] ].
	 */
	public static function get_daily_submission_counts( $days = 7 ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'emsf_submissions';

		// Get counts grouped by date
		$query = $wpdb->prepare( "
			SELECT DATE(created_at) as date, COUNT(*) as count 
			FROM $table_name 
			WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
			GROUP BY DATE(created_at) 
			ORDER BY date ASC
		", $days );

		$results = $wpdb->get_results( $query, ARRAY_A );

		// Prepare continuous date range
		$chart_data = array(
			'labels' => array(),
			'data'   => array(),
		);

		// Map results for easy lookup
		$counts_by_date = array();
		if ( $results ) {
			foreach ( $results as $row ) {
				$counts_by_date[ $row['date'] ] = (int) $row['count'];
			}
		}

		// Fill in missing dates
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$date = date( 'Y-m-d', strtotime( "-$i days" ) );
			$chart_data['labels'][] = date( 'M j', strtotime( $date ) ); // e.g. "Feb 12"
			$chart_data['data'][]   = isset( $counts_by_date[ $date ] ) ? $counts_by_date[ $date ] : 0;
		}

		return $chart_data;
	}

	/**
	 * Update submission status
	 *
	 * @param int    $submission_id Submission ID.
	 * @param string $status New status.
	 * @return bool True on success, false on failure.
	 */
	public static function update_submission_status( $submission_id, $status ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'emsf_submissions';

		$result = $wpdb->update(
			$table_name,
			array( 'status' => sanitize_text_field( $status ) ),
			array( 'id' => intval( $submission_id ) ),
			array( '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}
}