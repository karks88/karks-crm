<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles temporary storage/parsing of an uploaded CSV between the upload
 * step and the column-mapping/import step of the customer importer.
 */
class KCRM_CSV_Import {

	const MAX_FILE_SIZE = 5242880; // 5MB.

	/**
	 * Moves an uploaded CSV into a non-public storage folder and returns a
	 * token that later steps can use to find it again.
	 *
	 * @return string|WP_Error Token on success.
	 */
	public static function store_upload( array $file ) {
		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'kcrm_import_no_file', __( 'No file was uploaded.', 'karks-crm' ) );
		}

		if ( ! empty( $file['error'] ) && UPLOAD_ERR_OK !== $file['error'] ) {
			return new WP_Error( 'kcrm_import_upload_error', __( 'The file failed to upload.', 'karks-crm' ) );
		}

		if ( $file['size'] > self::MAX_FILE_SIZE ) {
			return new WP_Error( 'kcrm_import_too_large', __( 'That file is too large (5MB max).', 'karks-crm' ) );
		}

		$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( 'csv' !== $ext ) {
			return new WP_Error( 'kcrm_import_bad_type', __( 'Please upload a .csv file.', 'karks-crm' ) );
		}

		$dir = self::storage_dir();
		if ( ! $dir ) {
			return new WP_Error( 'kcrm_import_storage', __( 'Could not create a storage location for the upload.', 'karks-crm' ) );
		}

		$token = bin2hex( random_bytes( 16 ) );
		$path  = trailingslashit( $dir ) . $token . '.csv';

		if ( ! move_uploaded_file( $file['tmp_name'], $path ) ) {
			return new WP_Error( 'kcrm_import_move_failed', __( 'Could not save the uploaded file.', 'karks-crm' ) );
		}

		return $token;
	}

	/** @return string|false Absolute path for a token, or false if the token is invalid/missing. */
	public static function path_for_token( $token ) {
		if ( ! preg_match( '/^[a-f0-9]{32}$/', (string) $token ) ) {
			return false;
		}
		$path = trailingslashit( self::storage_dir() ) . $token . '.csv';
		return file_exists( $path ) ? $path : false;
	}

	public static function delete( $token ) {
		$path = self::path_for_token( $token );
		if ( $path ) {
			wp_delete_file( $path );
		}
	}

	/** @return array First row of the CSV (raw header labels). */
	public static function read_header( $path ) {
		$handle = fopen( $path, 'r' );
		if ( ! $handle ) {
			return array();
		}
		$header = fgetcsv( $handle );
		fclose( $handle );
		return is_array( $header ) ? $header : array();
	}

	/**
	 * @return array List of data rows (each an indexed array of cell values), header row excluded.
	 */
	public static function read_rows( $path ) {
		$rows   = array();
		$handle = fopen( $path, 'r' );
		if ( ! $handle ) {
			return $rows;
		}
		fgetcsv( $handle ); // Skip header.
		while ( ( $row = fgetcsv( $handle ) ) !== false ) {
			$rows[] = $row;
		}
		fclose( $handle );
		return $rows;
	}

	/**
	 * Non-public uploads subfolder, created on first use with access blockers.
	 *
	 * @return string|false
	 */
	private static function storage_dir() {
		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) {
			return false;
		}

		$dir = trailingslashit( $upload['basedir'] ) . 'kcrm-imports';

		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$index = trailingslashit( $dir ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}

		$htaccess = trailingslashit( $dir ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Deny from all\n" );
		}

		return is_dir( $dir ) ? $dir : false;
	}
}
